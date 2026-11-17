<?php
/**
 * Session handler classes without create_sid() or validateId(), deprecated
 * in PHP 8.6 (tranche 8.6-dep, Session).
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: class.session_handler_missing_methods. Documented only, from
 * UPGRADING of PHP 8.6, "4. Deprecated Functionality", Session:
 *
 *   "It is now deprecated to pass an object that does not implement the
 *   create_sid() and validateId() methods."
 *   "A deprecation is now emitted when implementing SessionHandlerInterface
 *   on a class which doesn't define the create_sid() or validateId()
 *   methods, as those will be moved from SessionUpdateTimestampHandlerInterface
 *   and SessionIdInterface to SessionHandlerInterface."
 *
 * Reported at a class that names SessionHandlerInterface in its implements
 * list and declares no create_sid() (unless it also implements
 * SessionIdInterface) or no validateId() (unless it also implements
 * SessionUpdateTimestampHandlerInterface). Such an object is also what
 * session_set_save_handler() will complain about. A class that extends
 * another class or uses a trait may get the methods from there and is not
 * reported.
 *
 * Note: in the php-8.6.0RC7 source the notice at the class declaration is an
 * E_WARNING ("... which will be required in PHP 9.0"), while the notes say
 * "a deprecation"; the call to session_set_save_handler() raises
 * E_DEPRECATED there. The finding follows the notes: deprecated in 8.6.
 */
final class Php86DepSessionHandler implements Rule {

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Node\Stmt\Class_::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node\Stmt\Class_ $node    Class.
	 * @param Context          $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( null !== $node->extends || array() === $node->implements ) {
			return;
		}

		$interfaces = array();

		foreach ( $node->implements as $name ) {
			$resolved = $name->getAttribute( 'resolvedName' );
			$name     = $resolved instanceof Node\Name ? $resolved : $name;

			if ( 1 === count( $name->getParts() ) ) {
				$interfaces[ strtolower( $name->getLast() ) ] = true;
			}
		}

		if ( ! isset( $interfaces['sessionhandlerinterface'] ) ) {
			return;
		}

		$methods = array();

		foreach ( $node->stmts as $statement ) {
			if ( $statement instanceof Node\Stmt\TraitUse ) {
				return;
			}

			if ( $statement instanceof Node\Stmt\ClassMethod ) {
				$methods[ $statement->name->toLowerString() ] = true;
			}
		}

		$missing = array();

		if ( ! isset( $methods['create_sid'] ) && ! isset( $interfaces['sessionidinterface'] ) ) {
			$missing[] = 'create_sid()';
		}

		if ( ! isset( $methods['validateid'] ) && ! isset( $interfaces['sessionupdatetimestamphandlerinterface'] ) ) {
			$missing[] = 'validateId()';
		}

		if ( array() === $missing ) {
			return;
		}

		$finding           = $context->report( 'class.session_handler_missing_methods', null === $node->name ? $node : $node->name, array( 'deprecated' => '8.6' ) );
		$finding->subject  = 'SessionHandlerInterface without ' . implode( ' and ', $missing );
		$finding->instead  = 'declare ' . implode( ' and ', $missing ) . ' in the class';
		$finding->evidence = 'documented';
	}
}
