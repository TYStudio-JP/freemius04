<?php
/**
 * Uses of internal classes that PHP 8.1 deprecates.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr;

defined( 'ABSPATH' ) || exit;

/**
 * Rule ids, all deprecated in 8.1:
 *
 * - call.mysqli_parent_init       parent::init() in a class that extends mysqli
 * - constant.pdo_fetch_serialize  PDO::FETCH_SERIALIZE passed as a fetch mode
 * - argument.soap_ssl_method      new SoapClient( $wsdl, array( 'ssl_method' => ... ) )
 *
 * Each is reported only where the class is named in the source. A call on a
 * variable, such as $link->init(), could be a method of any class and is
 * left alone.
 */
final class Php81DepObjects implements Rule {

	/**
	 * Methods that were seen, on real runtimes, to raise the notice when
	 * they are given the fetch mode.
	 */
	const TAKES_FETCH_MODE = array(
		'fetchall'     => true,
		'setfetchmode' => true,
		'query'        => true,
	);

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Expr\StaticCall::class, Expr\ClassConstFetch::class, Expr\New_::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node    $node    Static call, class constant or "new".
	 * @param Context $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( ! $node->class instanceof Node\Name ) {
			return;
		}

		if ( $node instanceof Expr\StaticCall ) {
			$this->parent_init( $node, $context );
		} elseif ( $node instanceof Expr\ClassConstFetch ) {
			$this->fetch_serialize( $node, $context );
		} else {
			$this->soap_client( $node, $context );
		}
	}

	/**
	 * Lower-case full name of the class a name refers to.
	 *
	 * @param Node\Name $name Name as written.
	 * @return string
	 */
	private static function class_name( Node\Name $name ) {
		$resolved = $name->getAttribute( 'resolvedName' );

		return strtolower( $resolved instanceof Node\Name ? $resolved->toString() : $name->toString() );
	}

	/**
	 * Reports parent::init() inside a class whose parent is mysqli.
	 *
	 * @param Expr\StaticCall $node    Static call.
	 * @param Context         $context Scan state.
	 */
	private function parent_init( Expr\StaticCall $node, Context $context ) {
		if ( 'parent' !== strtolower( $node->class->toString() ) || ! $node->name instanceof Node\Identifier || 'init' !== strtolower( $node->name->toString() ) ) {
			return;
		}

		$class = $node->getAttribute( 'parent' );

		while ( null !== $class && ! $class instanceof Node\Stmt\ClassLike ) {
			$class = $class->getAttribute( 'parent' );
		}

		if ( ! $class instanceof Node\Stmt\Class_ || ! $class->extends instanceof Node\Name || 'mysqli' !== self::class_name( $class->extends ) ) {
			return;
		}

		$finding          = $context->report( 'call.mysqli_parent_init', $node, array( 'deprecated' => '8.1' ) );
		$finding->subject = 'mysqli::init';
		$finding->instead = 'parent::__construct()';
		$finding->guarded = $context->function_is_guarded( 'mysqli::init' );
	}

	/**
	 * Reports PDO::FETCH_SERIALIZE where it is handed to a fetch method. The
	 * constant itself can still be read without a notice.
	 *
	 * @param Expr\ClassConstFetch $node    Class constant.
	 * @param Context              $context Scan state.
	 */
	private function fetch_serialize( Expr\ClassConstFetch $node, Context $context ) {
		if ( ! $node->name instanceof Node\Identifier || 'FETCH_SERIALIZE' !== $node->name->toString() || 'pdo' !== self::class_name( $node->class ) ) {
			return;
		}

		$parent = $node->getAttribute( 'parent' );

		while ( $parent instanceof Expr\BinaryOp\BitwiseOr ) {
			$parent = $parent->getAttribute( 'parent' );
		}

		if ( ! $parent instanceof Node\Arg || $parent->unpack || null !== $parent->name ) {
			return;
		}

		$call = $parent->getAttribute( 'parent' );

		if ( ! ( $call instanceof Expr\MethodCall || $call instanceof Expr\NullsafeMethodCall ) || ! $call->name instanceof Node\Identifier ) {
			return;
		}

		if ( ! isset( self::TAKES_FETCH_MODE[ strtolower( $call->name->toString() ) ] ) ) {
			return;
		}

		$finding          = $context->report( 'constant.pdo_fetch_serialize', $node, array( 'deprecated' => '8.1' ) );
		$finding->subject = 'PDO::FETCH_SERIALIZE';
		$finding->guarded = $context->function_is_guarded( 'pdo::fetch_serialize' );
	}

	/**
	 * Reports the ssl_method option written out in the options of a new
	 * SoapClient.
	 *
	 * @param Expr\New_ $node    Object creation.
	 * @param Context   $context Scan state.
	 */
	private function soap_client( Expr\New_ $node, Context $context ) {
		if ( 'soapclient' !== self::class_name( $node->class ) || count( $node->args ) < 2 ) {
			return;
		}

		foreach ( $node->args as $arg ) {
			if ( ! $arg instanceof Node\Arg || $arg->unpack || null !== $arg->name ) {
				return;
			}
		}

		$options = $node->args[1]->value;

		if ( ! $options instanceof Expr\Array_ ) {
			return;
		}

		foreach ( $options->items as $item ) {
			if ( $item instanceof Node\ArrayItem && $item->key instanceof Node\Scalar\String_ && 'ssl_method' === $item->key->value ) {
				$finding          = $context->report( 'argument.soap_ssl_method', $item, array( 'deprecated' => '8.1' ) );
				$finding->subject = 'ssl_method';
				$finding->instead = "'stream_context' => stream_context_create( array( 'ssl' => array( 'crypto_method' => ... ) ) )";
				$finding->guarded = $context->function_is_guarded( 'soapclient::__construct' );
			}
		}
	}
}
