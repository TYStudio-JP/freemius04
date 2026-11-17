<?php
/**
 * Classes that extend a DOM class and declare a method or property that
 * PHP 8.3 added to that DOM class.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: class.dom_member_conflict. A compile error from 8.3.
 *
 * The new methods have declared return types and the new properties have
 * declared types, so an older declaration of the same name without a type is
 * incompatible and the file no longer loads.
 *
 * Reported: a class that names the DOM class directly after "extends", and
 * declares one of the new methods without a return type or one of the new
 * properties without a type. A declaration with a type may be compatible and
 * is left alone; a class that extends a DOM class through another class is
 * not followed.
 */
final class Php83DomMemberConflict implements Rule {

	/**
	 * Methods added in 8.3, lower case, by the class that declares them.
	 */
	const METHODS = array(
		'domnode'             => array( 'contains', 'getrootnode', 'isequalnode' ),
		'domelement'          => array( 'getattributenames', 'toggleattribute', 'replacechildren', 'insertadjacentelement', 'insertadjacenttext' ),
		'domdocument'         => array( 'replacechildren' ),
		'domdocumentfragment' => array( 'replacechildren' ),
	);

	/**
	 * Properties added in 8.3, by the class that declares them.
	 */
	const PROPERTIES = array(
		'domnode'    => array( 'isConnected', 'parentElement' ),
		'domelement' => array( 'className', 'id' ),
	);

	/**
	 * DOM classes that extend DOMNode, lower case, with their parent.
	 */
	const PARENTS = array(
		'domnode'                  => null,
		'domelement'               => 'domnode',
		'domdocument'              => 'domnode',
		'domdocumentfragment'      => 'domnode',
		'domattr'                  => 'domnode',
		'domcharacterdata'         => 'domnode',
		'domtext'                  => 'domcharacterdata',
		'domcomment'               => 'domcharacterdata',
		'domcdatasection'          => 'domtext',
		'domdocumenttype'          => 'domnode',
		'domentity'                => 'domnode',
		'domentityreference'       => 'domnode',
		'domnotation'              => 'domnode',
		'domprocessinginstruction' => 'domnode',
	);

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Node\Stmt\Class_::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node\Stmt\Class_ $node    Class declaration, named or anonymous.
	 * @param Context          $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( ! $node->extends instanceof Node\Name ) {
			return;
		}

		$resolved = $node->extends->getAttribute( 'resolvedName' );
		$parent   = strtolower( $resolved instanceof Node\Name ? $resolved->toString() : $node->extends->toString() );

		if ( ! array_key_exists( $parent, self::PARENTS ) ) {
			return;
		}

		$methods    = array();
		$properties = array();

		for ( $class = $parent; null !== $class; $class = self::PARENTS[ $class ] ) {
			$methods    = array_merge( $methods, isset( self::METHODS[ $class ] ) ? self::METHODS[ $class ] : array() );
			$properties = array_merge( $properties, isset( self::PROPERTIES[ $class ] ) ? self::PROPERTIES[ $class ] : array() );
		}

		foreach ( $node->getMethods() as $method ) {
			if ( null !== $method->returnType || ! in_array( strtolower( $method->name->toString() ), $methods, true ) ) {
				continue;
			}

			$finding          = $context->report( 'class.dom_member_conflict', $method, array( 'breaks' => '8.3' ) );
			$finding->subject = $node->extends->getLast() . '::' . $method->name->toString() . '()';
			$finding->instead = 'rename the method, or give it the signature of the method PHP 8.3 added';
		}

		foreach ( $node->getProperties() as $property ) {
			if ( null !== $property->type ) {
				continue;
			}

			foreach ( $property->props as $item ) {
				// Property names are case-sensitive.
				if ( ! in_array( $item->name->toString(), $properties, true ) ) {
					continue;
				}

				$finding          = $context->report( 'class.dom_member_conflict', $property, array( 'breaks' => '8.3' ) );
				$finding->subject = $node->extends->getLast() . '::$' . $item->name->toString();
				$finding->instead = 'rename the property, or remove the declaration and use the property PHP 8.3 added';
			}
		}
	}
}
