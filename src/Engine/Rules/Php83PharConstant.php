<?php
/**
 * Classes that extend Phar and declare one of its constants again.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: class.phar_constant_redeclared. A compile error from 8.3.
 *
 * The constants of Phar have a declared type from 8.3, and a constant of the
 * same name without a type in a child class is then incompatible.
 *
 * Only "extends Phar" is reported. On the runtimes PharData turned out not to
 * be affected (the same declaration there still loads on 8.3 to 8.5), and a
 * constant with a type is 8.3 syntax and may be compatible.
 */
final class Php83PharConstant implements Rule {

	/**
	 * Constants Phar itself declares on the 8.3 runtime. The ones it inherits
	 * from the directory iterators are not part of the 8.3 change.
	 */
	const CONSTANTS = array(
		'BZ2',
		'GZ',
		'NONE',
		'PHAR',
		'TAR',
		'ZIP',
		'COMPRESSED',
		'PHP',
		'PHPS',
		'MD5',
		'OPENSSL',
		'OPENSSL_SHA256',
		'OPENSSL_SHA512',
		'SHA1',
		'SHA256',
		'SHA512',
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
	 * @param Node\Stmt\Class_ $node    Class declaration.
	 * @param Context          $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( ! $node->extends instanceof Node\Name ) {
			return;
		}

		$resolved = $node->extends->getAttribute( 'resolvedName' );
		$parent   = $resolved instanceof Node\Name ? $resolved->toString() : $node->extends->toString();

		if ( 'phar' !== strtolower( $parent ) ) {
			return;
		}

		foreach ( $node->getConstants() as $declaration ) {
			if ( null !== $declaration->type ) {
				continue;
			}

			foreach ( $declaration->consts as $constant ) {
				// Constant names are case-sensitive.
				if ( ! in_array( $constant->name->toString(), self::CONSTANTS, true ) ) {
					continue;
				}

				$finding          = $context->report( 'class.phar_constant_redeclared', $declaration, array( 'breaks' => '8.3' ) );
				$finding->subject = 'Phar::' . $constant->name->toString();
				$finding->instead = 'remove the declaration and use the constant of Phar, or give the constant another name';
			}
		}
	}
}
