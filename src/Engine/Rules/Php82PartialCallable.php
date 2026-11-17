<?php
/**
 * Callables that call_user_func() accepts and $callable() does not.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Scalar\String_;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Stmt;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: callable.partially_supported. Deprecated in 8.2.
 *
 *     call_user_func( 'self::method' );            also parent:: and static::
 *     usort( $list, array( 'self', 'method' ) );   also 'parent' and 'static'
 *     call_user_func( array( $this, 'parent::method' ) );
 *     call_user_func( array( 'Foo', 'Bar::method' ) );
 *
 * A string or an array is a callable only by the way it is used, so this
 * looks at literals written in the callable argument of the functions in
 * CALLBACK_AT, each of which has a fixture, and of Closure::fromCallable().
 * A callable that arrives in a variable is not seen (fixture
 * runtime.partially_supported_callable_variable), and neither is one given to
 * a function of the plugin or of WordPress, which may call it from a place
 * where "self" means nothing.
 *
 * The forms with self, parent and static need a class around them; outside
 * of one they are an Error on every version and are not reported.
 */
final class Php82PartialCallable implements Rule {

	/**
	 * Function => position of its callable argument.
	 */
	const CALLBACK_AT = array(
		'call_user_func'              => 0,
		'call_user_func_array'        => 0,
		'forward_static_call'         => 0,
		'forward_static_call_array'   => 0,
		'is_callable'                 => 0,
		'array_map'                   => 0,
		'array_filter'                => 1,
		'array_walk'                  => 1,
		'array_reduce'                => 1,
		'usort'                       => 1,
		'uasort'                      => 1,
		'uksort'                      => 1,
		'preg_replace_callback'       => 1,
		'array_walk_recursive'        => 1,
		'iterator_apply'              => 1,
		'register_shutdown_function'  => 0,
		'spl_autoload_register'       => 0,
		'set_error_handler'           => 0,
	);

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Expr\FuncCall::class, Expr\StaticCall::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Expr    $node    Function call or static method call.
	 * @param Context $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( $node instanceof Expr\StaticCall ) {
			if ( ! $node->class instanceof Node\Name || ! $node->name instanceof Node\Identifier || 'fromcallable' !== strtolower( $node->name->toString() ) ) {
				return;
			}

			$resolved = $node->class->getAttribute( 'resolvedName' );
			$resolved = $resolved instanceof Node\Name ? $resolved : $node->class;

			if ( 'closure' !== strtolower( $resolved->toString() ) ) {
				return;
			}

			$called = 'Closure::fromCallable';
			$at     = 0;
		} else {
			$called = InternalFunctions::global_name( $node->name );

			if ( null === $called || ! isset( self::CALLBACK_AT[ $called ] ) || isset( $context->declared_functions[ $called ] ) ) {
				return;
			}

			$at = self::CALLBACK_AT[ $called ];
		}

		if ( ! isset( $node->args[ $at ] ) ) {
			return;
		}

		// With named or spread arguments the position says nothing.
		for ( $index = 0; $index <= $at; $index++ ) {
			$arg = $node->args[ $index ];

			if ( ! $arg instanceof Node\Arg || $arg->unpack || null !== $arg->name ) {
				return;
			}
		}

		$form = self::form( $node->args[ $at ]->value );

		if ( null === $form ) {
			return;
		}

		if ( $form['needs_class'] && ! self::in_class( $node ) ) {
			return;
		}

		$finding          = $context->report( 'callable.partially_supported', $node->args[ $at ]->value, array( 'deprecated' => '8.2' ) );
		$finding->subject = $called . ': ' . $form['shown'];
		$finding->instead = $form['instead'];
		$finding->guarded = $context->function_is_guarded( Php82TentativeReturnType::VERSION_GUARD );
	}

	/**
	 * Recognises a deprecated callable literal.
	 *
	 * @param Node $value Argument value.
	 * @return array|null Keys shown, instead, needs_class; null for anything else.
	 */
	private static function form( Node $value ) {
		$keywords = array(
			'self'   => 'self::class',
			'parent' => 'parent::class',
			'static' => 'static::class',
		);

		if ( $value instanceof String_ ) {
			if ( 1 !== preg_match( '/^(self|parent|static)::([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)$/i', $value->value, $match ) ) {
				return null;
			}

			return array(
				'shown'       => '"' . $value->value . '"',
				'instead'     => 'array( ' . $keywords[ strtolower( $match[1] ) ] . ', \'' . $match[2] . '\' )',
				'needs_class' => true,
			);
		}

		if ( ! $value instanceof Expr\Array_ || 2 !== count( $value->items ) ) {
			return null;
		}

		foreach ( $value->items as $item ) {
			if ( null === $item || null !== $item->key || $item->byRef || $item->unpack ) {
				return null;
			}
		}

		$first  = $value->items[0]->value;
		$second = $value->items[1]->value;

		if ( ! $second instanceof String_ ) {
			return null;
		}

		$keyword = $first instanceof String_ ? strtolower( $first->value ) : '';

		// array( anything, 'Class::method' ), also with self, parent or static.
		if ( 1 === preg_match( '/^\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff\\\\]*::[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/', $second->value ) ) {
			return array(
				'shown'       => '[..., "' . $second->value . '"]',
				'instead'     => 'A closure that makes the call, for example function () { return parent::method(); }',
				'needs_class' => isset( $keywords[ $keyword ] ) || 1 === preg_match( '/^(self|parent|static)::/i', $second->value ),
			);
		}

		if ( ! isset( $keywords[ $keyword ] ) || 1 !== preg_match( '/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/', $second->value ) ) {
			return null;
		}

		return array(
			'shown'       => '["' . $first->value . '", "' . $second->value . '"]',
			'instead'     => 'array( ' . $keywords[ $keyword ] . ', \'' . $second->value . '\' )',
			'needs_class' => true,
		);
	}

	/**
	 * Whether the node is inside a class, a trait or an enum, with no plain
	 * function in between.
	 *
	 * @param Node $node Node.
	 * @return bool
	 */
	private static function in_class( Node $node ) {
		$parent = $node->getAttribute( 'parent' );

		while ( $parent instanceof Node ) {
			if ( $parent instanceof Stmt\Function_ || $parent instanceof Stmt\Interface_ ) {
				return false;
			}

			if ( $parent instanceof Stmt\ClassLike ) {
				return true;
			}

			$parent = $parent->getAttribute( 'parent' );
		}

		return false;
	}
}
