<?php
/**
 * Methods of internal classes, and constructor arguments, that PHP 8.5
 * deprecates.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule ids (tranche 8.5-dep):
 *
 * - call.reflection_set_accessible  ->setAccessible(): ReflectionProperty and
 *                                    ReflectionMethod, no effect since 8.1
 * - call.pdo_driver_method          ->sqliteCreateFunction(), ->sqliteCreateCollation(),
 *                                    ->sqliteCreateAggregate()
 * - call.pdo_uri_dsn                new PDO( 'uri:...' )
 * - call.splobjectstorage_method    attach(), detach(), contains() of SplObjectStorage
 * - call.finfo_buffer_context       finfo::buffer() with a third ($context) argument
 * - argument.arrayobject_object     new ArrayObject / ArrayIterator / RecursiveArrayIterator
 *                                    with an object written in the call
 *
 * The receiver of a method call is usually not known from the syntax tree.
 * setAccessible() and the sqlite*() names exist on hardly anything else, so
 * those are reported by name, except on $this and in a file that declares a
 * method of that name. attach(), detach(), contains() and buffer() are common
 * names, so they are reported only where the receiver is known to be the
 * internal class: see known_class(). A sqlite*() call in the else branch of
 * a check for Pdo\Sqlite (instanceof, class_exists(), method_exists()) is
 * marked as guarded.
 *
 * The PDO::pgsql*() methods are deprecated too, but the runtimes here have no
 * pdo_pgsql to show it, so they are not reported.
 */
final class Php85DepMethods implements Rule {

	const SQLITE_METHODS = array(
		'sqlitecreatefunction'  => 'Pdo\Sqlite::createFunction()',
		'sqlitecreatecollation' => 'Pdo\Sqlite::createCollation()',
		'sqlitecreateaggregate' => 'Pdo\Sqlite::createAggregate()',
	);

	const STORAGE_METHODS = array(
		'attach'   => 'offsetSet()',
		'detach'   => 'offsetUnset()',
		'contains' => 'offsetExists()',
	);

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Node\Expr\MethodCall::class, Node\Expr\NullsafeMethodCall::class, Node\Expr\StaticCall::class, Node\Expr\New_::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node\Expr $node    Method call or object creation.
	 * @param Context   $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( $node instanceof Node\Expr\New_ ) {
			$this->creation( $node, $context );

			return;
		}

		if ( ! $node->name instanceof Node\Identifier || $node->isFirstClassCallable() ) {
			return;
		}

		$method = $node->name->toLowerString();

		if ( $node instanceof Node\Expr\StaticCall ) {
			// parent::attach() in a direct subclass of SplObjectStorage.
			if ( isset( self::STORAGE_METHODS[ $method ] ) && $node->class instanceof Node\Name && 'parent' === $node->class->toLowerString() ) {
				$class = self::enclosing_class( $node );

				if ( null !== $class && 'splobjectstorage' === self::parent_of( $class ) ) {
					$this->storage( $node, $method, $context );
				}
			}

			return;
		}

		$on_this = $node->var instanceof Node\Expr\Variable && 'this' === $node->var->name;

		if ( 'setaccessible' === $method ) {
			// The Reflection method takes exactly one argument; with none it throws on every version.
			if ( $on_this || self::declares_method( $context, 'setAccessible' ) || 1 !== count( $node->args ) ) {
				return;
			}

			$finding          = $context->report( 'call.reflection_set_accessible', $node, array( 'deprecated' => '8.5' ) );
			$finding->subject = '->setAccessible()';
			$finding->instead = 'nothing: since PHP 8.1 reflection reaches private and protected members without it';
			$finding->guarded = $context->function_is_guarded( Php85DepFunctionArguments::VERSION_GUARD );

			return;
		}

		if ( isset( self::SQLITE_METHODS[ $method ] ) ) {
			if ( $on_this || self::declares_method( $context, $node->name->toString() ) ) {
				return;
			}

			$finding          = $context->report( 'call.pdo_driver_method', $node, array( 'deprecated' => '8.5' ) );
			$finding->subject = 'PDO::' . $node->name->toString() . '()';
			$finding->instead = self::SQLITE_METHODS[ $method ] . ' (PHP 8.4+, on a Pdo\Sqlite connection)';
			$finding->guarded = Php85DepClassConstants::after_new_api_check( $node ) || $context->function_is_guarded( Php85DepFunctionArguments::VERSION_GUARD );

			return;
		}

		if ( isset( self::STORAGE_METHODS[ $method ] ) ) {
			if ( $on_this ) {
				$class = self::enclosing_class( $node );

				if ( null === $class || 'splobjectstorage' !== self::parent_of( $class ) || self::class_declares( $class, $method ) ) {
					return;
				}
			} elseif ( 'splobjectstorage' !== self::known_class( $node->var ) ) {
				return;
			}

			$this->storage( $node, $method, $context );

			return;
		}

		if ( 'buffer' === $method && 'finfo' === self::known_class( $node->var ) ) {
			$has_context = false;

			foreach ( $node->args as $index => $arg ) {
				if ( ! $arg instanceof Node\Arg || $arg->unpack ) {
					return;
				}

				if ( null === $arg->name ? 2 === $index : 'context' === strtolower( $arg->name->toString() ) ) {
					$has_context = true;
				}
			}

			if ( ! $has_context ) {
				return;
			}

			$finding          = $context->report( 'call.finfo_buffer_context', $node, array( 'deprecated' => '8.5' ) );
			$finding->subject = 'finfo::buffer( ..., $context )';
			$finding->instead = 'finfo::buffer() without the $context argument';
			$finding->guarded = $context->function_is_guarded( Php85DepFunctionArguments::VERSION_GUARD );
		}
	}

	/**
	 * Reports a call of attach(), detach() or contains() on SplObjectStorage.
	 *
	 * @param Node\Expr $node    Call.
	 * @param string    $method  Lower-case method name.
	 * @param Context   $context Scan state.
	 */
	private function storage( Node\Expr $node, $method, Context $context ) {
		$finding          = $context->report( 'call.splobjectstorage_method', $node, array( 'deprecated' => '8.5' ) );
		$finding->subject = 'SplObjectStorage::' . $method . '()';
		$finding->instead = 'SplObjectStorage::' . self::STORAGE_METHODS[ $method ];
		$finding->guarded = $context->function_is_guarded( Php85DepFunctionArguments::VERSION_GUARD );
	}

	/**
	 * new PDO( 'uri:...' ) and new ArrayObject( <object> ).
	 *
	 * @param Node\Expr\New_ $node    Object creation.
	 * @param Context        $context Scan state.
	 */
	private function creation( Node\Expr\New_ $node, Context $context ) {
		$class = self::global_class( $node->class );

		if ( null === $class || $node->isFirstClassCallable() || ! isset( $node->args[0] ) ) {
			return;
		}

		$first = $node->args[0];

		if ( ! $first instanceof Node\Arg || $first->unpack ) {
			return;
		}

		// A named first argument: dsn for PDO, array for the array classes.
		if ( null !== $first->name ) {
			$first = null;

			foreach ( $node->args as $arg ) {
				if ( $arg instanceof Node\Arg && null !== $arg->name && in_array( strtolower( $arg->name->toString() ), array( 'pdo' === $class ? 'dsn' : 'array' ), true ) ) {
					$first = $arg;
				}
			}

			if ( null === $first ) {
				return;
			}
		}

		if ( 'pdo' === $class ) {
			$head = $first->value;

			while ( $head instanceof Node\Expr\BinaryOp\Concat ) {
				$head = $head->left;
			}

			// "uri:$path": the literal start of an interpolated string.
			if ( $head instanceof Node\Scalar\InterpolatedString && isset( $head->parts[0] ) && $head->parts[0] instanceof Node\InterpolatedStringPart ) {
				$head = new Node\Scalar\String_( $head->parts[0]->value );
			}

			if ( ! $head instanceof Node\Scalar\String_ || 0 !== strpos( $head->value, 'uri:' ) ) {
				return;
			}

			$finding          = $context->report( 'call.pdo_uri_dsn', $node, array( 'deprecated' => '8.5' ) );
			$finding->subject = "new PDO( 'uri:...' )";
			$finding->instead = 'the DSN itself';
			$finding->guarded = $context->function_is_guarded( Php85DepFunctionArguments::VERSION_GUARD );

			return;
		}

		if ( ! in_array( $class, array( 'arrayobject', 'arrayiterator', 'recursivearrayiterator' ), true ) ) {
			return;
		}

		$value     = $first->value;
		$is_object = $value instanceof Node\Expr\New_
			|| $value instanceof Node\Expr\Cast\Object_
			|| $value instanceof Node\Expr\Closure
			|| $value instanceof Node\Expr\ArrowFunction
			|| ( $value instanceof Node\Expr\Variable && 'this' === $value->name );

		if ( ! $is_object ) {
			return;
		}

		$finding          = $context->report( 'argument.arrayobject_object', $node, array( 'deprecated' => '8.5' ) );
		$finding->subject = 'new ' . $node->class->toString() . '( <object> )';
		$finding->instead = 'an array, for example get_object_vars()';
		$finding->guarded = $context->function_is_guarded( Php85DepFunctionArguments::VERSION_GUARD );
	}

	/**
	 * Lower-case name of the global class a name refers to, or null.
	 *
	 * @param mixed $name Class name node or expression.
	 * @return string|null
	 */
	private static function global_class( $name ) {
		if ( ! $name instanceof Node\Name || $name->isSpecialClassName() ) {
			return null;
		}

		$resolved = $name->getAttribute( 'resolvedName' );
		$name     = $resolved instanceof Node\Name ? $resolved : $name;

		return 1 === count( $name->getParts() ) ? strtolower( $name->getLast() ) : null;
	}

	/**
	 * The lower-case class of the object an expression holds, when the code
	 * makes it certain, or null.
	 *
	 * Known are: new Foo(...) itself; a local variable in a function or
	 * method that is only ever assigned new Foo(...) there, or is a parameter
	 * declared as Foo and never assigned another object; and $this->prop where
	 * the class declares the property with the type Foo or only ever assigns
	 * new Foo(...) to it (a public property without a type is never known).
	 * Code outside functions is not looked at.
	 *
	 * @param Node\Expr $expr Expression.
	 * @return string|null
	 */
	public static function known_class( Node\Expr $expr ) {
		if ( $expr instanceof Node\Expr\New_ ) {
			return self::global_class( $expr->class );
		}

		if ( $expr instanceof Node\Expr\Variable && is_string( $expr->name ) && 'this' !== $expr->name ) {
			return self::variable_class( $expr );
		}

		if ( $expr instanceof Node\Expr\PropertyFetch && $expr->var instanceof Node\Expr\Variable && 'this' === $expr->var->name && $expr->name instanceof Node\Identifier ) {
			return self::property_class( $expr );
		}

		return null;
	}

	/**
	 * Class of a local variable, see known_class().
	 *
	 * @param Node\Expr\Variable $variable Variable.
	 * @return string|null
	 */
	private static function variable_class( Node\Expr\Variable $variable ) {
		$scope = $variable->getAttribute( 'parent' );

		while ( null !== $scope && ! $scope instanceof Node\FunctionLike ) {
			$scope = $scope->getAttribute( 'parent' );
		}

		// Arrow functions share the variables of the code around them.
		if ( ! $scope instanceof Node\Stmt\Function_ && ! $scope instanceof Node\Stmt\ClassMethod && ! $scope instanceof Node\Expr\Closure ) {
			return null;
		}

		$name  = $variable->name;
		$class = null;

		foreach ( $scope->getParams() as $param ) {
			if ( $param->var instanceof Node\Expr\Variable && $name === $param->var->name ) {
				if ( $param->byRef || $param->variadic ) {
					return null;
				}

				$type  = $param->type instanceof Node\NullableType ? $param->type->type : $param->type;
				$class = self::global_class( $type );

				if ( null === $class ) {
					return null;
				}
			}
		}

		if ( $scope instanceof Node\Expr\Closure ) {
			foreach ( $scope->uses as $use ) {
				if ( $name === $use->var->name ) {
					return null;
				}
			}
		}

		$writes = array();

		if ( ! self::collect_writes( $scope->getStmts(), $name, $writes ) ) {
			return null;
		}

		foreach ( $writes as $value ) {
			$assigned = $value instanceof Node\Expr\New_ ? self::global_class( $value->class ) : null;

			if ( null === $assigned || ( null !== $class && $assigned !== $class ) ) {
				return null;
			}

			$class = $assigned;
		}

		return $class;
	}

	/**
	 * Collects the values assigned to a local variable in a body, not looking
	 * into nested functions. Returns false when the variable is written in a
	 * way whose value is not visible (by reference, list(), foreach, global,
	 * static, catch, ++, .= and the like).
	 *
	 * @param mixed  $nodes  Node or nodes.
	 * @param string $name   Variable name.
	 * @param array  $writes Assigned expressions, filled in.
	 * @return bool
	 */
	private static function collect_writes( $nodes, $name, array &$writes ) {
		if ( $nodes instanceof Node ) {
			$nodes = array( $nodes );
		}

		if ( ! is_array( $nodes ) ) {
			return true;
		}

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof Node ) {
				continue;
			}

			if ( $node instanceof Node\Expr\Closure ) {
				foreach ( $node->uses as $use ) {
					if ( $use->byRef && $name === $use->var->name ) {
						return false;
					}
				}

				continue;
			}

			if ( $node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike ) {
				continue;
			}

			if ( $node instanceof Node\Expr\Assign && self::is_variable( $node->var, $name ) ) {
				$writes[] = $node->expr;
			} elseif ( ( $node instanceof Node\Expr\AssignRef || $node instanceof Node\Expr\AssignOp ) && self::is_variable( $node->var, $name ) ) {
				return false;
			} elseif ( $node instanceof Node\Expr\AssignRef && self::is_variable( $node->expr, $name ) ) {
				return false;
			} elseif ( ( $node instanceof Node\Expr\PreInc || $node instanceof Node\Expr\PreDec || $node instanceof Node\Expr\PostInc || $node instanceof Node\Expr\PostDec ) && self::is_variable( $node->var, $name ) ) {
				return false;
			} elseif ( $node instanceof Node\Expr\List_ || $node instanceof Node\Stmt\Foreach_ || $node instanceof Node\Stmt\Global_ || $node instanceof Node\Stmt\Static_ || $node instanceof Node\Stmt\Catch_ ) {
				$finder = new \TYStudio\UpgradeWarden\Vendor\PhpParser\NodeFinder();

				if ( $node instanceof Node\Stmt\Foreach_ ) {
					$parts = array( $node->keyVar, $node->valueVar );
				} elseif ( $node instanceof Node\Stmt\Catch_ ) {
					$parts = array( $node->var );
				} else {
					$parts = array( $node );
				}

				$found = $finder->findFirst(
					array_filter( $parts ),
					static function ( Node $inner ) use ( $name ) {
						return self::is_variable( $inner, $name );
					}
				);

				if ( null !== $found ) {
					return false;
				}
			} elseif ( $node instanceof Node\Expr\Variable && $node->name instanceof Node\Expr ) {
				// $$name may be anything.
				return false;
			}

			foreach ( $node->getSubNodeNames() as $sub ) {
				if ( ! self::collect_writes( $node->$sub, $name, $writes ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Whether a node is the variable $name.
	 *
	 * @param mixed  $node Node.
	 * @param string $name Variable name.
	 * @return bool
	 */
	private static function is_variable( $node, $name ) {
		return $node instanceof Node\Expr\Variable && $name === $node->name;
	}

	/**
	 * Class of $this->prop, see known_class().
	 *
	 * @param Node\Expr\PropertyFetch $fetch Property fetch on $this.
	 * @return string|null
	 */
	private static function property_class( Node\Expr\PropertyFetch $fetch ) {
		$class = self::enclosing_class( $fetch );

		if ( null === $class ) {
			return null;
		}

		$name     = $fetch->name->toString();
		$known    = null;
		$declared = false;

		foreach ( $class->stmts as $statement ) {
			if ( $statement instanceof Node\Stmt\Property ) {
				foreach ( $statement->props as $property ) {
					if ( $name !== $property->name->toString() ) {
						continue;
					}

					$declared = true;
					$type     = $statement->type instanceof Node\NullableType ? $statement->type->type : $statement->type;

					if ( null !== $type ) {
						$known = self::global_class( $type );

						if ( null === $known ) {
							return null;
						}
					} elseif ( $statement->isPublic() ) {
						return null;
					}
				}
			} elseif ( $statement instanceof Node\Stmt\ClassMethod ) {
				foreach ( $statement->params as $param ) {
					if ( 0 !== $param->flags && $param->var instanceof Node\Expr\Variable && $name === $param->var->name ) {
						// A promoted constructor parameter.
						return null;
					}
				}
			}
		}

		if ( ! $declared ) {
			return null;
		}

		$finder  = new \TYStudio\UpgradeWarden\Vendor\PhpParser\NodeFinder();
		$writes  = $finder->find(
			$class->stmts,
			static function ( Node $node ) use ( $name ) {
				if ( ! $node instanceof Node\Expr\Assign && ! $node instanceof Node\Expr\AssignRef && ! $node instanceof Node\Expr\AssignOp ) {
					return false;
				}

				return $node->var instanceof Node\Expr\PropertyFetch
					&& $node->var->var instanceof Node\Expr\Variable
					&& 'this' === $node->var->var->name
					&& $node->var->name instanceof Node\Identifier
					&& $name === $node->var->name->toString();
			}
		);

		foreach ( $writes as $write ) {
			if ( ! $write instanceof Node\Expr\Assign || ! $write->expr instanceof Node\Expr\New_ ) {
				return null;
			}

			$assigned = self::global_class( $write->expr->class );

			if ( null === $assigned || ( null !== $known && $assigned !== $known ) ) {
				return null;
			}

			$known = $assigned;
		}

		return $known;
	}

	/**
	 * The named class around a node, or null.
	 *
	 * @param Node $node Node.
	 * @return Node\Stmt\Class_|null
	 */
	private static function enclosing_class( Node $node ) {
		$parent = $node->getAttribute( 'parent' );

		while ( null !== $parent ) {
			if ( $parent instanceof Node\Expr\Closure && $parent->static ) {
				return null;
			}

			if ( $parent instanceof Node\Stmt\ClassLike ) {
				return $parent instanceof Node\Stmt\Class_ && null !== $parent->name ? $parent : null;
			}

			$parent = $parent->getAttribute( 'parent' );
		}

		return null;
	}

	/**
	 * Lower-case name of the global class a class extends, or null.
	 *
	 * @param Node\Stmt\Class_ $class Class.
	 * @return string|null
	 */
	private static function parent_of( Node\Stmt\Class_ $class ) {
		return null === $class->extends ? null : self::global_class( $class->extends );
	}

	/**
	 * Whether a class declares a method itself.
	 *
	 * @param Node\Stmt\Class_ $class  Class.
	 * @param string           $method Lower-case method name.
	 * @return bool
	 */
	private static function class_declares( Node\Stmt\Class_ $class, $method ) {
		foreach ( $class->getMethods() as $declared ) {
			if ( $method === $declared->name->toLowerString() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the file declares a method or function of this name anywhere.
	 *
	 * @param Context $context Scan state.
	 * @param string  $name    Method name.
	 * @return bool
	 */
	private static function declares_method( Context $context, $name ) {
		return 1 === preg_match( '/function\s+&?\s*' . preg_quote( $name, '/' ) . '\s*\(/i', $context->code );
	}
}
