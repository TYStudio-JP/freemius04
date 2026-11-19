<?php
/**
 * Messages of PHP itself that are known from the verified fixtures.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Monitor;

defined( 'ABSPATH' ) || exit;

/**
 * The scanner reads code; this reads what PHP said while the code ran.
 *
 * data/catalog/messages.php is generated from the records of the real PHP
 * runtimes (tools/build-messages.php). It maps a message, with its numbers and
 * names blanked, to the change it belongs to: the rule id and the versions
 * from which PHP deprecates, changes or refuses the code. A message that is
 * not in the list is simply not known here; nothing is guessed.
 *
 * No WordPress calls: the class also runs inside the fixture harness.
 */
final class Signatures {

	/**
	 * Names of the error levels the monitor looks at. Numeric keys on purpose:
	 * some of the E_ constants are themselves deprecated in newer versions.
	 */
	const TYPE_NAMES = array(
		1    => 'E_ERROR',
		2    => 'E_WARNING',
		4    => 'E_PARSE',
		8    => 'E_NOTICE',
		16   => 'E_CORE_ERROR',
		64   => 'E_COMPILE_ERROR',
		8192 => 'E_DEPRECATED',
	);

	/**
	 * What normalize() blanks, in this order. The build of the list drops a
	 * message that two fixtures with different claims share after blanking, so
	 * a pattern that blanks too much shows up there, not on a site.
	 */
	const BLANKS = array(
		'/"[^"\n]*"/'                              => '"?"',
		'/\'[^\'\n]*\'/'                           => "'?'",
		'/, called in .+ on line \d+( and defined)?$/' => '',
		'/, [^,]+ given$/'                         => ', ? given',
		'/\$\w+/'                                  => '$?',
		'/^Declaration of .+ (should|must) be compatible with .+$/s' => 'Declaration of ? $1 be compatible with ?',
		'/^Return type of .+ should either be compatible with .+, or the /' => 'Return type of ? should either be compatible with ?, or the ',
		'/Object of class [\w\\\\]+ /'             => 'Object of class ? ',
		'/^assert\(\): .* failed$/s'               => 'assert(): ? failed',
		'/Use of undefined constant \S+/'          => 'Use of undefined constant ?',
		'/Creation of dynamic property [\w\\\\@]+::/' => 'Creation of dynamic property ?::',
		'/^[\w\\\\]+ implements the Serializable interface/' => '? implements the Serializable interface',
		'/\d+/'                                    => '0',
	);

	/**
	 * PHP 7's warning about a method whose signature does not fit the method
	 * it overrides. PHP 8.0 makes it a fatal error when the overridden method
	 * belongs to a class written in PHP (fixtures
	 * class.incompatible_method_signature~*). For a class of PHP itself the
	 * outcome depends on how that class changed, so nothing is said then.
	 */
	const INCOMPATIBLE_OVERRIDE = '/^Declaration of .+ should be compatible with ([\w\\\\]+)::/s';

	/**
	 * A warning of PHP 7 that PHP 8.0 turns into an error for nearly every
	 * internal function: a wrong argument type or count. The fixtures show it
	 * for a dozen functions; a few functions changed what they accept in 8.0
	 * instead, so for a function without a fixture of its own this is "likely",
	 * not "verified".
	 */
	const LIKELY_ARGUMENT_ERROR = '/^[A-Za-z_][\w:\\\\]*\(\) expects (parameter \d+ to be |exactly \d+ parameters?, |at least \d+ parameters?, |at most \d+ parameters?, )/';

	/**
	 * File that holds the generated list.
	 *
	 * @var string
	 */
	private $file;

	/**
	 * The list, loaded on first use.
	 *
	 * @var array<string,array>|null
	 */
	private $map;

	/**
	 * Constructor.
	 *
	 * @param string $dir Catalog directory.
	 */
	public function __construct( $dir ) {
		$this->file = rtrim( $dir, '/\\' ) . '/messages.php';
	}

	/**
	 * The form in which messages are compared: numbers, quoted values, variable
	 * and class names and the type in "..., X given" are blanked, because they
	 * differ from call to call while the message stays the same one.
	 *
	 * @param string $type    Error level name such as "E_DEPRECATED", or the
	 *                        class of an uncaught error such as "TypeError".
	 * @param string $message Message without file and line.
	 * @return string
	 */
	public static function normalize( $type, $message ) {
		$message = trim( (string) $message );

		foreach ( self::BLANKS as $pattern => $replacement ) {
			$message = (string) preg_replace( $pattern, $replacement, $message );
		}

		return $type . ': ' . $message;
	}

	/**
	 * What is known about a message.
	 *
	 * @param string $type    Error level name or class of the uncaught error.
	 * @param string $message Message without file and line.
	 * @return array{rule:string,deprecated:?string,changed:?string,breaks:?string,evidence:string}|null
	 *         evidence is "verified" (the message is on record from a real
	 *         runtime) or "likely" (it only has the shape of one that is).
	 */
	public function match( $type, $message ) {
		if ( null === $this->map ) {
			$map       = is_readable( $this->file ) ? include $this->file : array();
			$this->map = is_array( $map ) ? $map : array();
		}

		$key = self::normalize( $type, $message );

		if ( isset( $this->map[ $key ] ) ) {
			list( $rule, $deprecated, $changed, $breaks ) = $this->map[ $key ];

			return array(
				'rule'       => $rule,
				'deprecated' => $deprecated,
				'changed'    => $changed,
				'breaks'     => $breaks,
				'evidence'   => 'verified',
			);
		}

		if ( 'E_WARNING' === $type && 1 === preg_match( self::INCOMPATIBLE_OVERRIDE, (string) $message, $found ) ) {
			$parent = $found[1];

			if ( ( class_exists( $parent, false ) || interface_exists( $parent, false ) ) && ! ( new \ReflectionClass( $parent ) )->isInternal() ) {
				return array(
					'rule'       => 'class.incompatible_method_signature',
					'deprecated' => null,
					'changed'    => null,
					'breaks'     => '8.0',
					'evidence'   => 'verified',
				);
			}

			return null;
		}

		if ( 'E_WARNING' === $type && 1 === preg_match( self::LIKELY_ARGUMENT_ERROR, (string) $message ) ) {
			return array(
				'rule'       => 'runtime.internal_argument_warning',
				'deprecated' => null,
				'changed'    => null,
				'breaks'     => '8.0',
				'evidence'   => 'likely',
			);
		}

		return null;
	}

	/**
	 * Number of known messages.
	 *
	 * @return int
	 */
	public function count() {
		$this->match( '', '' );

		return count( $this->map );
	}
}
