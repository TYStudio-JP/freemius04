<?php
/**
 * Contract of a scan rule.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * A rule looks at one node at a time and reports what it finds.
 *
 * Every version a rule puts into a finding has to be backed by a fixture in
 * tools/fixtures/ that ran on real PHP runtimes, or by the generated catalog.
 * The test suite refuses a rule whose fixture is missing or not verified.
 */
interface Rule {

	/**
	 * Node classes the rule wants to see.
	 *
	 * @return string[] Fully qualified class names.
	 */
	public function node_types();

	/**
	 * Examines a node.
	 *
	 * @param Node    $node    Node of one of the classes from node_types().
	 * @param Context $context Scan state.
	 */
	public function inspect( Node $node, Context $context );
}
