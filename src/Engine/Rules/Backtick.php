<?php
/**
 * The backtick operator: `command`.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\ShellExec;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: syntax.backtick. Deprecated in 8.5. The notice is raised when the
 * file is compiled, whether or not the command is ever run.
 *
 * No automatic fix yet: turning the command into a string for shell_exec()
 * needs its quotes and escapes rewritten, and that rewrite has no fixture.
 */
final class Backtick implements Rule {

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( ShellExec::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ShellExec $node    Backtick expression.
	 * @param Context   $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		$finding          = $context->report( 'syntax.backtick', $node, array( 'deprecated' => '8.5' ) );
		$finding->instead = 'shell_exec()';
	}
}
