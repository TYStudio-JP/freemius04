<?php
/**
 * ZipArchive::FL_RECOMPRESS, deprecated in PHP 8.3.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: constant.ziparchive_fl_recompress.
 *
 * Reported where the constant is read through the class name ZipArchive.
 * defined( 'ZipArchive::FL_RECOMPRESS' ) is true on 8.3 as well and does not
 * keep the notice away (seen on the runtimes); only a PHP version check marks
 * it as guarded. self::, static:: and parent::FL_RECOMPRESS are reported in a
 * class that names ZipArchive directly after "extends" and does not declare
 * the constant itself; a subclass of a subclass is not followed.
 */
final class Php83ZipFlRecompress implements Rule {

	/**
	 * A name no constant has, so that only a version check counts as a guard.
	 */
	const VERSION_GUARD = '8.3: version check only';

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Node\Expr\ClassConstFetch::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node\Expr\ClassConstFetch $node    Class constant.
	 * @param Context                   $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( ! $node->class instanceof Node\Name || ! $node->name instanceof Node\Identifier || 'FL_RECOMPRESS' !== $node->name->toString() ) {
			return;
		}

		$resolved = $node->class->getAttribute( 'resolvedName' );
		$class    = $resolved instanceof Node\Name ? $resolved : $node->class;

		$written = strtolower( $class->toString() );

		if ( in_array( $written, array( 'self', 'static', 'parent' ), true ) ) {
			if ( ! self::in_zip_subclass( $node, 'parent' === $written ) ) {
				return;
			}
		} elseif ( 'ziparchive' !== $written ) {
			return;
		}

		$finding          = $context->report( 'constant.ziparchive_fl_recompress', $node, array( 'deprecated' => '8.3' ) );
		$finding->subject = 'ZipArchive::FL_RECOMPRESS';
		$finding->instead = 'leave the flag out; libzip no longer supports it';
		$finding->guarded = $context->constant_is_guarded( self::VERSION_GUARD );
	}

	/**
	 * Whether the node is in a class that extends ZipArchive directly and,
	 * unless the constant is read through parent::, does not declare
	 * FL_RECOMPRESS itself.
	 *
	 * @param Node $node        Node.
	 * @param bool $parent_only Read through parent::.
	 * @return bool
	 */
	private static function in_zip_subclass( Node $node, $parent_only ) {
		for ( $parent = $node->getAttribute( 'parent' ); null !== $parent; $parent = $parent->getAttribute( 'parent' ) ) {
			if ( ! $parent instanceof Node\Stmt\ClassLike ) {
				continue;
			}

			if ( ! $parent instanceof Node\Stmt\Class_ || ! $parent->extends instanceof Node\Name ) {
				return false;
			}

			$resolved = $parent->extends->getAttribute( 'resolvedName' );
			$name     = $resolved instanceof Node\Name ? $resolved : $parent->extends;

			if ( 'ziparchive' !== strtolower( $name->toString() ) ) {
				return false;
			}

			if ( $parent_only ) {
				return true;
			}

			foreach ( $parent->getConstants() as $declaration ) {
				foreach ( $declaration->consts as $constant ) {
					if ( 'FL_RECOMPRESS' === $constant->name->toString() ) {
						return false;
					}
				}
			}

			return true;
		}

		return false;
	}
}
