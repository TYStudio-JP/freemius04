<?php
/**
 * Overrides of mysqli_stmt::execute() without the $params parameter
 * (tranche 8.1-bic).
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Stmt\Class_;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: class.mysqli_stmt_execute_signature. Breaks in 8.1.
 *
 *     class Logged_Statement extends mysqli_stmt {
 *         public function execute() { ... }
 *     }
 *
 * PHP 8.1 gave mysqli_stmt::execute() an optional ?array $params. An override
 * that takes no parameters is no longer compatible: a fatal error when the
 * class is declared.
 *
 * Reported only for a class that extends mysqli_stmt itself and declares
 * execute() with no parameters at all. An override with an optional
 * parameter is compatible; one with a required parameter was already a fatal
 * error on 8.0, which is not this change.
 */
final class Php81BicMysqliStmtExecute implements Rule {

	/**
	 * Asked of Context::function_is_guarded() to learn about a version check
	 * only. Not a valid function name, so no function_exists() matches it.
	 */
	const VERSION_GUARD = '8.1-bic: version check only';

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Class_::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Class_  $node    Class declaration, named or anonymous.
	 * @param Context $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( ! $node->extends instanceof Node\Name ) {
			return;
		}

		$resolved = $node->extends->getAttribute( 'resolvedName' );
		$resolved = $resolved instanceof Node\Name ? $resolved : $node->extends;

		if ( 'mysqli_stmt' !== strtolower( $resolved->toString() ) ) {
			return;
		}

		$method = $node->getMethod( 'execute' );

		if ( null === $method || $method->params ) {
			return;
		}

		$finding          = $context->report( 'class.mysqli_stmt_execute_signature', $method, array( 'breaks' => '8.1' ) );
		$finding->subject = 'mysqli_stmt::execute() override without $params';
		$finding->instead = 'public function execute( $params = null )';

		// A class at the top of a file is declared when the file is compiled.
		// One inside an "if" or a function is declared only when that code runs.
		$around = $node->getAttribute( 'parent' );

		if ( null !== $around && ! $around instanceof Node\Stmt\Namespace_ ) {
			$finding->guarded = $context->function_is_guarded( self::VERSION_GUARD );
		}
	}
}
