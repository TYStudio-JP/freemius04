<?php
/**
 * What a rule can see and do while a file is being walked.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine;

use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Scan state shared by the rules.
 */
final class Context {

	/**
	 * Source code of the file.
	 *
	 * @var string
	 */
	public $code;

	/**
	 * The catalog of internal functions and constants.
	 *
	 * @var Catalog
	 */
	public $catalog;

	/**
	 * Namespace the walker is in; empty for the global namespace.
	 *
	 * @var string
	 */
	public $namespace = '';

	/**
	 * Lower-case names of functions declared in the global namespace, in this
	 * file or elsewhere in the same plugin or theme.
	 *
	 * @var array<string,true>
	 */
	public $declared_functions = array();

	/**
	 * Lower-case names brought in with "use function".
	 *
	 * @var array<string,true>
	 */
	public $imported_functions = array();

	/**
	 * Number of loops and switches around the node being visited, counted
	 * from the start of the function it is in.
	 *
	 * @var int
	 */
	public $loop_depth = 0;

	/**
	 * Findings so far.
	 *
	 * @var Finding[]
	 */
	public $findings = array();

	/**
	 * Guards around the node being visited, innermost last. Each is an array
	 * with the keys functions, constants (name => true) and version (bool).
	 *
	 * @var array[]
	 */
	private $guards = array();

	/**
	 * Constructor.
	 *
	 * @param string  $code    Source code.
	 * @param Catalog $catalog Catalog.
	 */
	public function __construct( $code, Catalog $catalog ) {
		$this->code    = $code;
		$this->catalog = $catalog;
	}

	/**
	 * Records a finding at a node.
	 *
	 * @param string $rule Rule id.
	 * @param Node   $node Node the finding is about.
	 * @param array  $when Keys deprecated, changed, breaks: version or null.
	 * @return Finding The new finding, for the rule to complete.
	 */
	public function report( $rule, Node $node, array $when ) {
		$finding             = new Finding();
		$finding->rule       = $rule;
		$finding->line       = $node->getStartLine();
		$finding->start      = $node->getStartFilePos();
		$finding->end        = $node->getEndFilePos();
		$finding->deprecated = isset( $when['deprecated'] ) ? $when['deprecated'] : null;
		$finding->changed    = isset( $when['changed'] ) ? $when['changed'] : null;
		$finding->breaks     = isset( $when['breaks'] ) ? $when['breaks'] : null;

		$this->findings[] = $finding;

		return $finding;
	}

	/**
	 * Source text between two offsets, both included.
	 *
	 * @param int $start First byte.
	 * @param int $end   Last byte.
	 * @return string
	 */
	public function text( $start, $end ) {
		return $end < $start ? '' : substr( $this->code, $start, $end - $start + 1 );
	}

	/**
	 * Offset of the first byte at or after $from that is not white space, or
	 * -1 at the end of the file.
	 *
	 * @param int $from Offset to start at.
	 * @return int
	 */
	public function skip_space( $from ) {
		$length = strlen( $this->code );

		while ( $from < $length && false !== strpos( " \t\r\n", $this->code[ $from ] ) ) {
			++$from;
		}

		return $from < $length ? $from : -1;
	}

	/**
	 * Enters code that runs only under a condition.
	 *
	 * @param array $guard Keys functions, constants (name => true) and version (bool).
	 */
	public function push_guard( array $guard ) {
		$this->guards[] = $guard;
	}

	/**
	 * Leaves the innermost guarded code.
	 */
	public function pop_guard() {
		array_pop( $this->guards );
	}

	/**
	 * Whether the code being visited is behind a function_exists() check for
	 * this function, or behind a PHP version check.
	 *
	 * @param string $name Lower-case function name.
	 * @return bool
	 */
	public function function_is_guarded( $name ) {
		foreach ( $this->guards as $guard ) {
			if ( $guard['version'] || isset( $guard['functions'][ $name ] ) ) {
				return true;
			}

			if ( $guard['resource'] && array_key_exists( $name, Guards::RELEASES_A_FORMER_RESOURCE ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the code being visited is behind a defined() check for this
	 * constant, or behind a PHP version check.
	 *
	 * @param string $name Constant name.
	 * @return bool
	 */
	public function constant_is_guarded( $name ) {
		foreach ( $this->guards as $guard ) {
			if ( $guard['version'] || isset( $guard['constants'][ $name ] ) ) {
				return true;
			}
		}

		return false;
	}
}
