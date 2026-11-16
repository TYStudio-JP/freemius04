<?php
/**
 * Constructors named after their class, the PHP 4 way.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\FuncCall;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Name;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Stmt\Class_;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Stmt\ClassMethod;
use TYStudio\UpgradeWarden\Vendor\PhpParser\NodeFinder;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: syntax.php4_constructor. Deprecated in 7.0. From 8.0 such a method
 * is an ordinary method: "new" no longer calls it, and PHP says nothing. The
 * object is simply left without its set-up, which is why this is the kind of
 * change that is hard to find by trying the site.
 *
 * Classes in a namespace never had this behaviour and are skipped.
 */
final class OldConstructor implements Rule {

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Class_::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Class_  $node    Class declaration.
	 * @param Context $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( null === $node->name ) {
			return;
		}

		if ( isset( $node->namespacedName ) && $node->namespacedName instanceof Name && count( $node->namespacedName->getParts() ) > 1 ) {
			return;
		}

		$class  = strtolower( $node->name->toString() );
		$method = null;

		foreach ( $node->getMethods() as $candidate ) {
			$name = strtolower( $candidate->name->toString() );

			if ( '__construct' === $name ) {
				return;
			}

			if ( $name === $class ) {
				$method = $candidate;
			}
		}

		if ( null === $method ) {
			return;
		}

		$finding = $context->report(
			'syntax.php4_constructor',
			$method,
			array(
				'deprecated' => '7.0',
				'changed'    => '8.0',
			)
		);

		$finding->subject = $node->name->toString();
		$finding->instead = '__construct()';
		$finding->fix     = $this->fix_for( $method, $context );
	}

	/**
	 * What a fix needs: where the name is, and how to write a method of the old
	 * name that passes its arguments on, for code that calls it directly.
	 *
	 * @param ClassMethod $method  The old-style constructor.
	 * @param Context     $context Scan state.
	 * @return array Empty when the method is not a plain one.
	 */
	private function fix_for( ClassMethod $method, Context $context ) {
		if ( $method->isStatic() || $method->isAbstract() || $method->byRef || null === $method->stmts ) {
			return array();
		}

		$name_end = $method->name->getEndFilePos();
		$open     = $context->skip_space( $name_end + 1 );

		if ( -1 === $open || '(' !== $context->code[ $open ] ) {
			return array();
		}

		$after = $method->params ? $method->params[ count( $method->params ) - 1 ]->getEndFilePos() + 1 : $open + 1;
		$close = $context->skip_space( $after );

		if ( -1 === $close || ')' !== $context->code[ $close ] ) {
			return array();
		}

		$forward = array();

		foreach ( $method->params as $param ) {
			if ( ! $param->var instanceof Node\Expr\Variable || ! is_string( $param->var->name ) ) {
				return array();
			}

			$forward[] = ( $param->variadic ? '...' : '' ) . '$' . $param->var->name;
		}

		// A body that reads its arguments by position gets all of them passed on.
		$finder   = new NodeFinder();
		$by_index = $finder->findFirst(
			$method->stmts,
			static function ( Node $inner ) {
				return $inner instanceof FuncCall
					&& $inner->name instanceof Name
					&& in_array( strtolower( $inner->name->getLast() ), array( 'func_get_args', 'func_get_arg', 'func_num_args' ), true );
			}
		);

		if ( null !== $by_index ) {
			$forward = array( '...func_get_args()' );
		}

		// The line the method starts on gives the indentation for the new method.
		$line_start = strrpos( substr( $context->code, 0, $method->getStartFilePos() ), "\n" );
		$line_start = false === $line_start ? 0 : $line_start + 1;
		$indent     = $context->text( $line_start, $method->getStartFilePos() - 1 );

		if ( '' !== trim( $indent ) ) {
			return array();
		}

		return array(
			'kind'       => 'php4_constructor',
			'name_start' => $method->name->getStartFilePos(),
			'name_end'   => $name_end,
			'method_end' => $method->getEndFilePos(),
			'head'       => $context->text( $method->getStartFilePos(), $close ),
			'forward'    => implode( ', ', $forward ),
			'indent'     => $indent,
		);
	}
}
