<?php
/**
 * Classes that override a method of PDO, PDOStatement, Reflection, SplHeap,
 * SplPriorityQueue or XMLReader in a way PHP 8.0 no longer accepts (tranche
 * 8.0-bic-ext).
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Stmt;

defined( 'ABSPATH' ) || exit;

/**
 * Rule ids, all a compile error from 8.0 that 7.4 accepted:
 *
 * - class.internal_variadic_signature  PDO::query(), PDOStatement::setFetchMode(),
 *                                      ReflectionClass::newInstance(),
 *                                      ReflectionFunction::invoke() and
 *                                      ReflectionMethod::invoke() end in a
 *                                      variadic parameter from 8.0; an override
 *                                      without one is not compatible.
 * - class.splheap_compare_signature    SplHeap::compare() and
 *                                      SplPriorityQueue::compare() take two mixed
 *                                      parameters from 8.0; an override that
 *                                      types either of them, requires more
 *                                      than two or accepts fewer than two is
 *                                      not compatible.
 * - class.xmlreader_static_method      XMLReader::open() and XMLReader::xml() are
 *                                      static from 8.0; an override that is not
 *                                      static is an error.
 *
 * Only a class that names the internal class directly after "extends" is
 * judged. An override with a variadic parameter is taken as compatible
 * without comparing types. SplMinHeap and SplMaxHeap are left out: a typed
 * compare() there was already an error on 7.4.
 */
final class Php80BicExtInternalOverride implements Rule {

	/**
	 * Parent class => methods whose 8.0 signature ends in a variadic.
	 */
	const VARIADIC = array(
		'pdo'                => array( 'query' => 'PDO::query( string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs )' ),
		'pdostatement'       => array( 'setfetchmode' => 'PDOStatement::setFetchMode( int $mode, mixed ...$args )' ),
		'reflectionclass'    => array( 'newinstance' => 'ReflectionClass::newInstance( mixed ...$args )' ),
		'reflectionobject'   => array( 'newinstance' => 'ReflectionClass::newInstance( mixed ...$args )' ),
		'reflectionfunction' => array( 'invoke' => 'ReflectionFunction::invoke( mixed ...$args )' ),
		'reflectionmethod'   => array( 'invoke' => 'ReflectionMethod::invoke( ?object $object, mixed ...$args )' ),
	);

	/**
	 * Parent classes whose compare() got two mixed parameters.
	 */
	const COMPARE = array(
		'splheap'          => 'SplHeap::compare( mixed $value1, mixed $value2 )',
		'splpriorityqueue' => 'SplPriorityQueue::compare( mixed $priority1, mixed $priority2 )',
	);

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Stmt\Class_::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Stmt\Class_ $node    Class declaration.
	 * @param Context     $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		$parent = Php80BicExtLocal::class_name( $node->extends );

		if ( null === $parent ) {
			return;
		}

		foreach ( $node->getMethods() as $method ) {
			$name = $method->name->toLowerString();

			if ( isset( self::VARIADIC[ $parent ][ $name ] ) && ! self::has_variadic( $method ) ) {
				$finding          = $context->report( 'class.internal_variadic_signature', $method, array( 'breaks' => '8.0' ) );
				$finding->subject = $node->extends->getLast() . '::' . $method->name->toString() . '()';
				$finding->instead = 'a signature compatible with ' . self::VARIADIC[ $parent ][ $name ] . ', for example one that ends in ...$args';
			}

			if ( 'compare' === $name && isset( self::COMPARE[ $parent ] ) && self::incompatible_compare( $method ) ) {
				$finding          = $context->report( 'class.splheap_compare_signature', $method, array( 'breaks' => '8.0' ) );
				$finding->subject = $node->extends->getLast() . '::compare()';
				$finding->instead = 'untyped (or mixed) $a and $b, as in ' . self::COMPARE[ $parent ];
			}

			if ( 'xmlreader' === $parent && ( 'open' === $name || 'xml' === $name ) && ! $method->isStatic() ) {
				$finding          = $context->report( 'class.xmlreader_static_method', $method, array( 'breaks' => '8.0' ) );
				$finding->subject = 'XMLReader::' . $name . '() overridden without static';
				$finding->instead = 'a static method (which 7.4 does not accept), or another method name';
			}
		}
	}

	/**
	 * Whether the method has a variadic parameter.
	 *
	 * @param Stmt\ClassMethod $method Method.
	 * @return bool
	 */
	private static function has_variadic( Stmt\ClassMethod $method ) {
		foreach ( $method->params as $param ) {
			if ( $param->variadic ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a compare() override types one of its first two parameters,
	 * requires more than two, or accepts fewer than two.
	 *
	 * @param Stmt\ClassMethod $method Method.
	 * @return bool
	 */
	private static function incompatible_compare( Stmt\ClassMethod $method ) {
		$required = 0;

		foreach ( $method->params as $index => $param ) {
			if ( null === $param->default && ! $param->variadic ) {
				$required++;
			}

			if ( $index > 1 || null === $param->type ) {
				continue;
			}

			if ( ! $param->type instanceof Node\Identifier || 'mixed' !== $param->type->toLowerString() ) {
				return true;
			}
		}

		// Fewer than two parameters and no variadic: the second is not accepted.
		if ( count( $method->params ) < 2 && ! self::has_variadic( $method ) ) {
			return true;
		}

		return $required > 2;
	}
}
