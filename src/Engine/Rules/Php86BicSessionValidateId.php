<?php
/**
 * SessionHandler subclasses that declare validateId() without a return type
 * (tranche 8.6-bic).
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: signature.session_validate_id_return_type. Documented for 8.6 only.
 *
 * The PHP 8.6 upgrade notes, word for word:
 *
 *     SessionHandler::validateId() has been added and delegates to the
 *     configured save handler. A subclass that declares validateId() without
 *     a return type now emits a deprecation notice for the tentative bool
 *     return type.
 *
 *     class My_Handler extends SessionHandler {
 *         public function validateId( $id ) { ... }   // no ": bool"
 *     }
 *
 * On 7.4 to 8.5 SessionHandler has no validateId() and such a class is
 * declared without a diagnostic (fixtures
 * signature.session_validate_id_return_type~8.6-bic*). No 8.6 runtime:
 * evidence "documented".
 *
 * Reported when the class names SessionHandler itself in "extends", declares
 * validateId() with no return type and no #[ReturnTypeWillChange]. A class
 * that also implements SessionUpdateTimestampHandlerInterface is left out:
 * the runtimes showed that one is deprecated since 8.1 already, which
 * signature.tentative_return_type_missing reports.
 *
 * The rest of the bullet (a warning when open() is overridden without
 * calling parent::open()) depends on what runs and is not reported.
 */
final class Php86BicSessionValidateId implements Rule {

	const VERSION_GUARD = '8.6-bic: version check only';

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
		if ( ! $node->extends instanceof Node\Name || 'sessionhandler' !== self::resolved( $node->extends ) ) {
			return;
		}

		foreach ( $node->implements as $interface ) {
			if ( 'sessionupdatetimestamphandlerinterface' === self::resolved( $interface ) ) {
				return;
			}
		}

		foreach ( $node->getMethods() as $method ) {
			if ( 'validateid' !== strtolower( $method->name->toString() ) || null !== $method->returnType || self::will_change( $method ) ) {
				continue;
			}

			$finding           = $context->report( 'signature.session_validate_id_return_type', $method, array( 'deprecated' => '8.6' ) );
			$finding->subject  = 'SessionHandler::validateId()';
			$finding->evidence = 'documented';
			$finding->instead  = 'declare ": bool" (PHP 7.0 and later accept it), or put #[\ReturnTypeWillChange] above the method; documented for PHP 8.6, not verified on an 8.6 runtime';

			// A class at the top of a file is declared when the file is compiled.
			$around = $node->getAttribute( 'parent' );

			if ( null !== $around && ! $around instanceof Node\Stmt\Namespace_ ) {
				$finding->guarded = $context->function_is_guarded( self::VERSION_GUARD );
			}
		}
	}

	/**
	 * Lower-case fully qualified name, without the leading backslash.
	 *
	 * @param Node\Name $name Name.
	 * @return string
	 */
	private static function resolved( Node\Name $name ) {
		$resolved = $name->getAttribute( 'resolvedName' );
		$resolved = $resolved instanceof Node\Name ? $resolved : $name;

		return strtolower( ltrim( $resolved->toString(), '\\' ) );
	}

	/**
	 * Whether the method carries #[ReturnTypeWillChange].
	 *
	 * @param Node\Stmt\ClassMethod $method Method.
	 * @return bool
	 */
	private static function will_change( Node\Stmt\ClassMethod $method ) {
		foreach ( $method->attrGroups as $group ) {
			foreach ( $group->attrs as $attribute ) {
				if ( 'returntypewillchange' === strtolower( $attribute->name->getLast() ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
