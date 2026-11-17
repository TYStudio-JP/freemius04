<?php
/**
 * Names that PHP 8.6 deprecates: "let", "is" and "_" (tranche 8.6-dep, Core).
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule ids (tranche 8.6-dep). There is no PHP 8.6 runtime here, so every
 * finding is "documented", from UPGRADING of PHP 8.6, "4. Deprecated
 * Functionality", Core:
 *
 * - syntax.let_is_name
 *   "Using "let" or "is" as the name of a class, interface, trait, enum,
 *   function, or constant (including via define()), as the alias passed to
 *   class_alias(), or as the alias name in a use, use function, or use const
 *   declaration is now deprecated."
 *
 * - syntax.underscore_constant_name
 *   "Using "_" as a constant name (via const or define()), or as the alias
 *   name for a class or constant in a use declaration, is now deprecated.
 *   This extends the PHP 8.4 deprecation of "_" as a class name."
 *   (A class named "_" is syntax.underscore_class_name, from 8.4.)
 *
 * Case follows the php-8.6.0RC7 source: class, function and alias names are
 * compared without regard to case. A constant is compared without regard to
 * case in the global namespace, and only as lower-case "let" or "is" after
 * a namespace separator; "_" has no case.
 *
 * define() and class_alias() are reported only with the name written as a
 * string literal. A function or method that a file declares itself under the
 * name define or class_alias is not looked at.
 */
final class Php86DepReservedNames implements Rule {

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array(
			Node\Stmt\Class_::class,
			Node\Stmt\Interface_::class,
			Node\Stmt\Trait_::class,
			Node\Stmt\Enum_::class,
			Node\Stmt\Function_::class,
			Node\Stmt\Const_::class,
			Node\Stmt\Use_::class,
			Node\Stmt\GroupUse::class,
			Node\Expr\FuncCall::class,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node    $node    Node of one of the types above.
	 * @param Context $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( $node instanceof Node\Stmt\ClassLike || $node instanceof Node\Stmt\Function_ ) {
			if ( null !== $node->name && self::is_let_or_is( $node->name->toString() ) ) {
				$this->let_is( $node->name, $node->name->toString(), $node instanceof Node\Stmt\Function_ ? 'function name' : 'class name', $context );
			}

			return;
		}

		if ( $node instanceof Node\Stmt\Const_ ) {
			foreach ( $node->consts as $const ) {
				$name = isset( $const->namespacedName ) && $const->namespacedName instanceof Node\Name ? $const->namespacedName->toString() : $const->name->toString();

				$this->constant( $const->name, $name, $context );
			}

			return;
		}

		if ( $node instanceof Node\Stmt\Use_ || $node instanceof Node\Stmt\GroupUse ) {
			foreach ( $node->uses as $item ) {
				$type  = Node\Stmt\Use_::TYPE_UNKNOWN !== $item->type ? $item->type : $node->type;
				$alias = $item->getAlias()->toString();

				if ( self::is_let_or_is( $alias ) ) {
					$this->let_is( $item, $alias, 'alias in a use declaration', $context );
				} elseif ( '_' === $alias && ( Node\Stmt\Use_::TYPE_NORMAL === $type || Node\Stmt\Use_::TYPE_CONSTANT === $type ) ) {
					$this->underscore( $item, 'alias in a use declaration', $context );
				}
			}

			return;
		}

		$this->call( $node, $context );
	}

	/**
	 * define( 'let', ... ) and class_alias( ..., 'let' ).
	 *
	 * @param Node\Expr\FuncCall $node    Call.
	 * @param Context            $context Scan state.
	 */
	private function call( Node\Expr\FuncCall $node, Context $context ) {
		$function = InternalFunctions::global_name( $node->name );

		if ( ( 'define' !== $function && 'class_alias' !== $function ) || isset( $context->declared_functions[ $function ] ) ) {
			return;
		}

		$wanted = 'define' === $function ? array( 0, 'constant_name' ) : array( 1, 'alias' );
		$value  = null;

		foreach ( $node->args as $index => $arg ) {
			if ( ! $arg instanceof Node\Arg || $arg->unpack ) {
				return;
			}

			if ( null === $arg->name ? $wanted[0] === $index : $wanted[1] === $arg->name->toString() ) {
				$value = $arg->value;
			}
		}

		if ( ! $value instanceof Node\Scalar\String_ ) {
			return;
		}

		if ( 'define' === $function ) {
			$this->constant( $value, ltrim( $value->value, '\\' ), $context );

			return;
		}

		$alias = ltrim( $value->value, '\\' );
		$last  = false === strrpos( $alias, '\\' ) ? $alias : substr( $alias, strrpos( $alias, '\\' ) + 1 );

		if ( self::is_let_or_is( $last ) ) {
			$this->let_is( $value, $last, 'alias passed to class_alias()', $context );
		}
	}

	/**
	 * A constant declared with const or define().
	 *
	 * @param Node   $at      Node to report at.
	 * @param string $name    Full name of the constant, namespace included.
	 * @param Context $context Scan state.
	 */
	private function constant( Node $at, $name, Context $context ) {
		$at_slash = strrpos( $name, '\\' );

		if ( false === $at_slash ) {
			if ( '_' === $name ) {
				$this->underscore( $at, 'constant name', $context );
			} elseif ( self::is_let_or_is( $name ) ) {
				$this->let_is( $at, $name, 'constant name', $context );
			}

			return;
		}

		$last = substr( $name, $at_slash + 1 );

		if ( '_' === $last ) {
			$this->underscore( $at, 'constant name', $context );
		} elseif ( 'let' === $last || 'is' === $last ) {
			$this->let_is( $at, $last, 'constant name', $context );
		}
	}

	/**
	 * Reports "let" or "is".
	 *
	 * @param Node    $at      Node to report at.
	 * @param string  $name    The name as written.
	 * @param string  $what    What the name is used as.
	 * @param Context $context Scan state.
	 */
	private function let_is( Node $at, $name, $what, Context $context ) {
		$finding           = $context->report( 'syntax.let_is_name', $at, array( 'deprecated' => '8.6' ) );
		$finding->subject  = '"' . $name . '" as ' . $what;
		$finding->instead  = 'another name';
		$finding->evidence = 'documented';
	}

	/**
	 * Reports "_".
	 *
	 * @param Node    $at      Node to report at.
	 * @param string  $what    What the name is used as.
	 * @param Context $context Scan state.
	 */
	private function underscore( Node $at, $what, Context $context ) {
		$finding           = $context->report( 'syntax.underscore_constant_name', $at, array( 'deprecated' => '8.6' ) );
		$finding->subject  = '"_" as ' . $what;
		$finding->instead  = 'another name';
		$finding->evidence = 'documented';
	}

	/**
	 * Whether a name is "let" or "is", in any case.
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	private static function is_let_or_is( $name ) {
		$lower = strtolower( $name );

		return 'let' === $lower || 'is' === $lower;
	}
}
