<?php
/**
 * An override of DOMImplementation::createDocument() without a return type,
 * deprecated in PHP 8.4.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Stmt\Class_;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Stmt\ClassMethod;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: signature.dom_create_document_return_type. Deprecated in 8.4.
 *
 *     class Local_Implementation extends DOMImplementation {
 *         public function createDocument( $namespace = null, $qualified_name = '', $doctype = null ) { ... }
 *     }
 *
 * The 8.4 notes say the tentative return type changed from
 * DOMDocument|false to DOMDocument. The runtimes show that up to 8.3 the
 * method had no tentative return type at all, and that 8.4 adds DOMDocument:
 * an override without a return type raises E_DEPRECATED when the class is
 * declared from 8.4 on.
 *
 * Reported only for a class that extends DOMImplementation directly, by its
 * global name, whose createDocument() has no return type and no
 * #[\ReturnTypeWillChange] attribute. An override with a return type that
 * does not fit is left alone: union types do not parse on 7.4, so no fixture
 * here can show it.
 */
final class Php84BicCoreDomCreateDocument implements Rule {

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
		if ( null === $node->extends ) {
			return;
		}

		$parent   = $node->extends;
		$resolved = $parent->getAttribute( 'resolvedName' );

		if ( $resolved instanceof Node\Name ) {
			$parent = $resolved;
		}

		if ( 1 !== count( $parent->getParts() ) || 'domimplementation' !== strtolower( $parent->getLast() ) ) {
			return;
		}

		foreach ( $node->stmts as $statement ) {
			if ( ! $statement instanceof ClassMethod || 'createdocument' !== $statement->name->toLowerString() ) {
				continue;
			}

			if ( null !== $statement->returnType || self::will_change( $statement ) ) {
				continue;
			}

			$finding          = $context->report( 'signature.dom_create_document_return_type', $statement->name, array( 'deprecated' => '8.4' ) );
			$finding->subject = 'DOMImplementation::createDocument()';
			$finding->instead = 'the return type DOMDocument, or #[\ReturnTypeWillChange] while older PHP versions are supported';
		}
	}

	/**
	 * Whether a method carries #[\ReturnTypeWillChange].
	 *
	 * @param ClassMethod $method Method.
	 * @return bool
	 */
	private static function will_change( ClassMethod $method ) {
		foreach ( $method->attrGroups as $group ) {
			foreach ( $group->attrs as $attribute ) {
				$name     = $attribute->name;
				$resolved = $name->getAttribute( 'resolvedName' );

				if ( $resolved instanceof Node\Name ) {
					$name = $resolved;
				}

				if ( 'returntypewillchange' === strtolower( $name->toString() ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
