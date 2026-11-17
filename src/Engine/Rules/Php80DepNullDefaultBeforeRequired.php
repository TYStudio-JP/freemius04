<?php
/**
 * A parameter with a null default declared before a required one.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\ConstFetch;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id (tranche 8.0-dep): signature.null_default_before_required.
 *
 * Parameters (signature.required_after_optional) leaves out optional
 * parameters whose default is null, because the runtimes show that PHP
 * treats them differently depending on the type:
 *
 * - no type:          function f( $a = null, $b )         deprecated from 8.0
 * - nullable type:    function f( ?Foo $a = null, $b )    deprecated from 8.1
 * - the type mixed:   function f( mixed $a = null, $b )   deprecated from 8.3
 * - a plain type:     function f( Foo $a = null, $b )     the exception the 8.0
 *                     notes allow; from 8.4 it is signature.implicit_nullable
 *                     (Parameters), so it is not reported here.
 *
 * A union type that names null (int|null $a = null) is deprecated from 8.3
 * as well, but cannot be shown by a fixture (PHP 7.4 does not parse it), so
 * it is not reported. All of these notices are raised when the file is
 * compiled, so nothing guards them.
 */
final class Php80DepNullDefaultBeforeRequired implements Rule {

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
			if ( $index >= $last_required ) {
				break;
			}

			if ( ! $param->default instanceof ConstFetch || 'null' !== strtolower( $param->default->name->toString() ) ) {
				continue;
			}

			$since = self::since( $param->type );

			if ( null === $since ) {
				continue;
			}

			$finding          = $context->report( 'signature.null_default_before_required', $param, array( 'deprecated' => $since ) );
			$finding->subject = is_string( $param->var->name ) ? '$' . $param->var->name . ' = null' : '';
			$finding->instead = 'make the parameter required, or move it after the required ones';
		}
	}

	/**
	 * The version from which PHP raises the deprecation for a null default
	 * with this type, or null when it is not reported here.
	 *
	 * @param mixed $type Type node or null.
	 * @return string|null
	 */
	private static function since( $type ) {
		if ( null === $type ) {
			return '8.0';
		}

		if ( $type instanceof Node\NullableType ) {
			return '8.1';
		}

		if ( $type instanceof Node\Identifier && 'mixed' === $type->toLowerString() ) {
			return '8.3';
		}

		return null;
	}
}
