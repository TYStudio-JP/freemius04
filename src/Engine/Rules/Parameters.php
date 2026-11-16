<?php
/**
 * Parameter declarations that a newer PHP deprecates.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\ConstFetch;

defined( 'ABSPATH' ) || exit;

/**
 * Rule ids:
 *
 * - signature.implicit_nullable        function f( Foo $a = null ): deprecated in 8.4
 * - signature.required_after_optional  function f( $a = 1, $b ): deprecated in 8.0
 *
 * Both notices are raised when the file is compiled, so no run-time check
 * around the function keeps them away.
 */
final class Parameters implements Rule {

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array(
			Node\Stmt\Function_::class,
			Node\Stmt\ClassMethod::class,
			Node\Expr\Closure::class,
			Node\Expr\ArrowFunction::class,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node\FunctionLike $node    Function, method or closure.
	 * @param Context           $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		$params        = $node->getParams();
		$last_required = -1;

		foreach ( $params as $index => $param ) {
			if ( null === $param->default && ! $param->variadic ) {
				$last_required = $index;
			}
		}

		foreach ( $params as $index => $param ) {
			if ( null === $param->default ) {
				continue;
			}

			$null_default = $param->default instanceof ConstFetch && 'null' === strtolower( $param->default->name->toString() );

			if ( $null_default && null !== $param->type ) {
				$this->implicit_nullable( $param, $context );
			}

			// With a null default the notice differs between versions and has
			// no verified fixture yet, so only other defaults are reported.
			if ( $index < $last_required && ! $null_default ) {
				$finding          = $context->report( 'signature.required_after_optional', $param, array( 'deprecated' => '8.0' ) );
				$finding->subject = is_string( $param->var->name ) ? '$' . $param->var->name : '';
			}
		}
	}

	/**
	 * Reports a typed parameter that is only nullable through its null default.
	 *
	 * @param Node\Param $param   Parameter with a type and a null default.
	 * @param Context    $context Scan state.
	 */
	private function implicit_nullable( Node\Param $param, Context $context ) {
		$type = $param->type;
		$fix  = array();

		if ( $type instanceof Node\NullableType || $type instanceof Node\IntersectionType ) {
			return;
		}

		if ( $type instanceof Node\UnionType ) {
			foreach ( $type->types as $member ) {
				if ( 'null' === strtolower( $member->toString() ) ) {
					return;
				}
			}

			// Reported without a fix: "A|B|null" would be the rewrite, but union
			// types need PHP 8.0, so the fixture harness, which compares with
			// PHP 7.4, cannot verify it yet.
		} else {
			if ( in_array( strtolower( $type->toString() ), array( 'mixed', 'null' ), true ) ) {
				return;
			}

			$fix = array(
				'kind' => 'insert',
				'at'   => $type->getStartFilePos(),
				'text' => '?',
			);
		}

		$finding          = $context->report( 'signature.implicit_nullable', $param, array( 'deprecated' => '8.4' ) );
		$finding->subject = is_string( $param->var->name ) ? '$' . $param->var->name : '';
		$finding->fix     = $fix;
	}
}
