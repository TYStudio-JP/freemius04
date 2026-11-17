<?php
/**
 * The SID constant of the session extension, deprecated in PHP 8.4.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: constant.session_sid.
 *
 * SID only exists after session_start(), which is why the generated catalog,
 * built from a list of the constants of a fresh runtime, does not have it.
 *
 * A file that defines a constant SID of its own is left alone.
 */
final class Php84DepSessionSid implements Rule {

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Node\Expr\ConstFetch::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node\Expr\ConstFetch $node    Constant fetch.
	 * @param Context              $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		$resolved = $node->name->getAttribute( 'resolvedName' );
		$name     = $resolved instanceof Node\Name ? $resolved : $node->name;

		if ( 1 !== count( $name->getParts() ) || 'SID' !== $name->getLast() ) {
			return;
		}

		if ( preg_match( '/\bdefine\s*\(\s*[\'"]SID[\'"]|\bconst\s+SID\b/', $context->code ) ) {
			return;
		}

		$finding          = $context->report( 'constant.session_sid', $node, array( 'deprecated' => '8.4' ) );
		$finding->subject = 'SID';
		$finding->instead = 'session_name() . "=" . session_id(), or cookie-based sessions';
		// defined( "SID" ) is true exactly when a session is active, and then reading
		// the constant raises the notice (seen on the 8.4 runtime): only a PHP version
		// check is a guard.
		$finding->guarded = $context->constant_is_guarded( '8.4-dep: version check only' );
	}
}
