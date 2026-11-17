<?php
/**
 * SQLite3::enableExceptions( false ), deprecated in PHP 8.3.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: call.sqlite3_enable_exceptions_false.
 *
 * The notes name enableExceptions( false ). Without an argument the default is
 * false, and the 8.3 runtime raises the same deprecation, so that is reported
 * too. enableExceptions( true ) is fine; an argument from a variable is known
 * at run time only (fixture runtime.sqlite3_enable_exceptions_false).
 *
 * The method name alone says nothing about the class, so a call is reported
 * only when the object is known to be an SQLite3:
 *
 * - ( new SQLite3( ... ) )->enableExceptions( false )
 * - $db->enableExceptions( false ), after $db = new SQLite3( ... ) earlier in
 *   the same function (or in the file outside of functions)
 * - $this->db->enableExceptions( false ), after $this->db = new SQLite3( ... )
 *   earlier in the same class
 * - $db->enableExceptions( false ) where $db is a parameter declared as
 *   SQLite3 (or ?SQLite3) and not given anything else before the call
 * - $this->enableExceptions( false ) in a class that extends SQLite3 and does
 *   not declare enableExceptions() itself
 */
final class Php83Sqlite3EnableExceptions implements Rule {

	/**
	 * A name no function has, so that only a version check counts as a guard.
	 */
	const VERSION_GUARD = '8.3: version check only';

	/**
	 * The scan the names below belong to.
	 *
	 * @var Context|null
	 */
	private $context;

	/**
	 * Variables holding an SQLite3: scope id => ( name => true ).
	 *
	 * @var array<int,array<string,bool>>
	 */
	private $variables = array();

	/**
	 * Variables given something other than an SQLite3: scope id => ( name => true ).
	 *
	 * @var array<int,array<string,bool>>
	 */
	private $reassigned = array();

	/**
	 * Properties of $this holding an SQLite3: class id => ( name => true ).
	 *
	 * @var array<int,array<string,bool>>
	 */
	private $properties = array();

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Node\Expr\Assign::class, Node\Expr\MethodCall::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node\Expr\Assign|Node\Expr\MethodCall $node    Assignment or method call.
	 * @param Context                               $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( $this->context !== $context ) {
			$this->context    = $context;
			$this->variables  = array();
			$this->reassigned = array();
			$this->properties = array();
		}

		if ( $node instanceof Node\Expr\Assign ) {
			$this->remember( $node );
			return;
		}

		if ( ! $node->name instanceof Node\Identifier || 'enableexceptions' !== strtolower( $node->name->toString() ) ) {
			return;
		}

		if ( ! self::disables( $node ) || ! $this->is_sqlite3( $node->var, $node ) ) {
			return;
		}

		$finding          = $context->report( 'call.sqlite3_enable_exceptions_false', $node, array( 'deprecated' => '8.3' ) );
		$finding->subject = 'SQLite3::enableExceptions( false )';
		$finding->instead = 'enableExceptions( true ), and catch SQLite3Exception where a warning was expected';
		$finding->guarded = $context->function_is_guarded( self::VERSION_GUARD );
	}

	/**
	 * Notes which variable or property now holds an SQLite3, and forgets one
	 * that is given something else.
	 *
	 * @param Node\Expr\Assign $node Assignment.
	 */
	private function remember( Node\Expr\Assign $node ) {
		$holds = $node->expr instanceof Node\Expr\New_ && self::names_sqlite3( $node->expr->class );
		$var   = $node->var;

		if ( $var instanceof Node\Expr\Variable && is_string( $var->name ) && 'this' !== $var->name ) {
			$scope = self::scope_of( $node );

			if ( $holds ) {
				$this->variables[ $scope ][ $var->name ] = true;
				unset( $this->reassigned[ $scope ][ $var->name ] );
			} else {
				unset( $this->variables[ $scope ][ $var->name ] );
				$this->reassigned[ $scope ][ $var->name ] = true;
			}
		} elseif ( $var instanceof Node\Expr\PropertyFetch && $var->var instanceof Node\Expr\Variable && 'this' === $var->var->name && $var->name instanceof Node\Identifier ) {
			$class = self::class_of( $node );

			if ( null === $class ) {
				return;
			}

			$id = spl_object_id( $class );

			if ( $holds ) {
				$this->properties[ $id ][ $var->name->toString() ] = true;
			} else {
				unset( $this->properties[ $id ][ $var->name->toString() ] );
			}
		}
	}

	/**
	 * Whether the call turns exceptions off: no argument, or a literal false.
	 *
	 * @param Node\Expr\MethodCall $node Call.
	 * @return bool
	 */
	private static function disables( Node\Expr\MethodCall $node ) {
		if ( array() === $node->args ) {
			return true;
		}

		if ( 1 !== count( $node->args ) || ! $node->args[0] instanceof Node\Arg || $node->args[0]->unpack ) {
			return false;
		}

		$arg = $node->args[0];

		if ( null !== $arg->name && 'enable' !== $arg->name->toString() ) {
			return false;
		}

		return $arg->value instanceof Node\Expr\ConstFetch && 'false' === strtolower( $arg->value->name->toString() );
	}

	/**
	 * Whether the object a method is called on is known to be an SQLite3.
	 *
	 * @param Node\Expr $object Object expression.
	 * @param Node      $call   The call, for its surroundings.
	 * @return bool
	 */
	private function is_sqlite3( Node\Expr $object, Node $call ) {
		if ( $object instanceof Node\Expr\New_ ) {
			return self::names_sqlite3( $object->class );
		}

		if ( $object instanceof Node\Expr\Variable && 'this' === $object->name ) {
			$class = self::class_of( $call );

			return $class instanceof Node\Stmt\Class_ && $class->extends instanceof Node\Name && self::names_sqlite3( $class->extends ) && null === $class->getMethod( 'enableExceptions' );
		}

		if ( $object instanceof Node\Expr\Variable && is_string( $object->name ) ) {
			$scope = self::scope_of( $call );

			if ( isset( $this->variables[ $scope ][ $object->name ] ) ) {
				return true;
			}

			return ! isset( $this->reassigned[ $scope ][ $object->name ] ) && self::sqlite3_parameter( $call, $object->name );
		}

		if ( $object instanceof Node\Expr\PropertyFetch && $object->var instanceof Node\Expr\Variable && 'this' === $object->var->name && $object->name instanceof Node\Identifier ) {
			$class = self::class_of( $call );

			return null !== $class && isset( $this->properties[ spl_object_id( $class ) ][ $object->name->toString() ] );
		}

		return false;
	}

	/**
	 * Whether the function a node is in has a parameter of that name declared
	 * as SQLite3 or ?SQLite3.
	 *
	 * @param Node   $node Node.
	 * @param string $name Variable name.
	 * @return bool
	 */
	private static function sqlite3_parameter( Node $node, $name ) {
		for ( $parent = $node->getAttribute( 'parent' ); null !== $parent; $parent = $parent->getAttribute( 'parent' ) ) {
			if ( ! $parent instanceof Node\FunctionLike ) {
				continue;
			}

			foreach ( $parent->getParams() as $param ) {
				if ( ! $param->var instanceof Node\Expr\Variable || $name !== $param->var->name || $param->variadic ) {
					continue;
				}

				$type = $param->type instanceof Node\NullableType ? $param->type->type : $param->type;

				return self::names_sqlite3( $type );
			}

			return false;
		}

		return false;
	}

	/**
	 * Whether a class reference names the global class SQLite3.
	 *
	 * @param Node $name Class reference.
	 * @return bool
	 */
	private static function names_sqlite3( $name ) {
		if ( ! $name instanceof Node\Name ) {
			return false;
		}

		$resolved = $name->getAttribute( 'resolvedName' );
		$name     = $resolved instanceof Node\Name ? $resolved : $name;

		return 'sqlite3' === strtolower( $name->toString() );
	}

	/**
	 * Id of the function a node is in, or 0 outside of functions.
	 *
	 * @param Node $node Node.
	 * @return int
	 */
	private static function scope_of( Node $node ) {
		for ( $parent = $node->getAttribute( 'parent' ); null !== $parent; $parent = $parent->getAttribute( 'parent' ) ) {
			if ( $parent instanceof Node\FunctionLike ) {
				return spl_object_id( $parent );
			}
		}

		return 0;
	}

	/**
	 * The class, trait or enum a node is in.
	 *
	 * @param Node $node Node.
	 * @return Node\Stmt\ClassLike|null
	 */
	private static function class_of( Node $node ) {
		for ( $parent = $node->getAttribute( 'parent' ); null !== $parent; $parent = $parent->getAttribute( 'parent' ) ) {
			if ( $parent instanceof Node\Stmt\ClassLike ) {
				return $parent;
			}
		}

		return null;
	}
}
