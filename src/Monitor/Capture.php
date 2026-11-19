<?php
/**
 * Listens to what PHP says while the site runs.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Monitor;

defined( 'ABSPATH' ) || exit;

/**
 * A scan reads code and cannot know the values it will handle. count( null )
 * looks like any other call; PHP 7 answers it with a warning and PHP 8 with a
 * fatal error. The messages PHP raises on the live site show exactly those
 * places.
 *
 * What is kept:
 *  - every deprecation notice of PHP itself (E_DEPRECATED);
 *  - a warning or notice only when it is on record as the early sign of a
 *    later error (see Signatures);
 *  - a fatal error, when it is a compile error or an uncaught Error of PHP
 *    (TypeError, ValueError, ...). An uncaught exception of the site's own
 *    code and running out of memory or time are not PHP compatibility.
 *
 * The handler only listens. It passes every message on to the handler that
 * was there before and otherwise lets PHP do what it would have done, so
 * logs and displays stay as they are.
 *
 * No WordPress calls: the class also runs inside the fixture harness.
 */
final class Capture {

	/**
	 * Distinct messages kept per request. A page that raises thousands of
	 * notices must not cost more than a page that raises a few.
	 */
	const MAX_EVENTS = 40;

	/**
	 * Longest message kept, in bytes.
	 */
	const MAX_MESSAGE = 600;

	/**
	 * Events of this request by fingerprint.
	 *
	 * @var array<string,array{type:string,message:string,file:string,line:int,match:?array}>
	 */
	private static $events = array();

	/**
	 * The error handler that was set before this one, or null.
	 *
	 * @var callable|null
	 */
	private static $previous;

	/**
	 * Known messages.
	 *
	 * @var Signatures|null
	 */
	private static $signatures;

	/**
	 * Receives the events when the request ends.
	 *
	 * @var callable|null
	 */
	private static $sink;

	/**
	 * Whether the handlers are in place.
	 *
	 * @var bool
	 */
	private static $installed = false;

	/**
	 * True while the handler itself runs, so that a message raised by it is
	 * not looked at again.
	 *
	 * @var bool
	 */
	private static $busy = false;

	/**
	 * Starts listening.
	 *
	 * @param Signatures $signatures Known messages.
	 * @param callable   $sink       Called at the end of the request with the
	 *                               list of events, when there is at least one.
	 */
	public static function install( Signatures $signatures, $sink ) {
		self::$signatures = $signatures;
		self::$sink       = $sink;

		if ( self::$installed ) {
			return;
		}

		self::$installed = true;
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- The run-time log is this handler. It only listens and passes every message on.
		self::$previous = set_error_handler( array( __CLASS__, 'on_error' ) );

		register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );
	}

	/**
	 * Error handler. Notes what is of interest and passes the message on.
	 *
	 * @param int    $type    Error level.
	 * @param string $message Message.
	 * @param string $file    File.
	 * @param int    $line    Line.
	 * @return bool False, so that PHP goes on to handle the message itself,
	 *              unless the previous handler decided otherwise.
	 */
	public static function on_error( $type, $message, $file = '', $line = 0 ) {
		if ( ! self::$busy ) {
			self::$busy = true;

			try {
				self::note( (int) $type, (string) $message, (string) $file, (int) $line );
			} catch ( \Throwable $error ) {
				// Listening must never be the thing that breaks a page.
				unset( $error );
			}

			self::$busy = false;
		}

		if ( null !== self::$previous ) {
			return call_user_func_array( self::$previous, func_get_args() );
		}

		return false;
	}

	/**
	 * Looks at the error that ended the request, if any, and hands the events over.
	 */
	public static function on_shutdown() {
		try {
			$last = error_get_last();

			if ( is_array( $last ) ) {
				$fatal = self::fatal( $last );

				if ( null !== $fatal ) {
					self::add( $fatal['type'], $fatal['message'], (string) $last['file'], (int) $last['line'], true );
				}
			}

			if ( self::$events && null !== self::$sink ) {
				$events       = array_values( self::$events );
				self::$events = array();

				call_user_func( self::$sink, $events );
			}
		} catch ( \Throwable $error ) {
			unset( $error );
		}
	}

	/**
	 * Turns the last error of a request into type and message, or null when
	 * it is not a fatal error this plugin is about.
	 *
	 * @param array $last Result of error_get_last().
	 * @return array{type:string,message:string}|null
	 */
	public static function fatal( array $last ) {
		$type    = isset( $last['type'] ) ? (int) $last['type'] : 0;
		$message = isset( $last['message'] ) ? (string) $last['message'] : '';

		// E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR.
		if ( ! in_array( $type, array( 1, 4, 16, 64 ), true ) ) {
			return null;
		}

		// "Uncaught TypeError: message in /path/file.php:12", then the stack trace.
		// The message itself may hold " in ": the last one is the place.
		$parts = explode( "\nStack trace:", $message, 2 );

		if ( 1 === preg_match( '/^Uncaught ([A-Za-z_\\\\][\w\\\\]*): (.*) in [^\n]+:\d+$/s', $parts[0], $found ) ) {
			$class = ltrim( $found[1], '\\' );

			// Only errors PHP itself throws. An exception of the site's own code
			// says nothing about the PHP version, and its text may hold visitor data.
			if ( 'Error' !== $class && ! is_subclass_of( $class, 'Error' ) ) {
				return null;
			}

			return array(
				'type'    => $class,
				'message' => $found[2],
			);
		}

		if ( 0 === strpos( $message, 'Uncaught ' ) || 0 === strpos( $message, 'Allowed memory size' ) || 0 === strpos( $message, 'Maximum execution time' ) || 0 === strpos( $message, 'Out of memory' ) ) {
			return null;
		}

		return array(
			'type'    => Signatures::TYPE_NAMES[ $type ],
			'message' => $message,
		);
	}

	/**
	 * The events noted so far in this request.
	 *
	 * @return array[]
	 */
	public static function events() {
		return array_values( self::$events );
	}

	/**
	 * Forgets the events of this request. For tests.
	 */
	public static function reset() {
		self::$events = array();
	}

	/**
	 * Decides whether a message is kept.
	 *
	 * @param int    $type    Error level.
	 * @param string $message Message.
	 * @param string $file    File.
	 * @param int    $line    Line.
	 */
	private static function note( $type, $message, $file, $line ) {
		// error_reporting() and the @ operator are not consulted on purpose. A
		// live site usually hides deprecations, and @ hides the message only:
		// when PHP turns the deprecation into an error, @ does not stop it.
		// 8192 is E_DEPRECATED, 2 is E_WARNING, 8 is E_NOTICE.
		if ( 8192 === $type ) {
			self::add( 'E_DEPRECATED', $message, $file, $line, true );
		} elseif ( 2 === $type || 8 === $type ) {
			self::add( Signatures::TYPE_NAMES[ $type ], $message, $file, $line, false );
		}
	}

	/**
	 * Shortens a long message without splitting a UTF-8 character, which the
	 * database would refuse to store. A message that is not valid UTF-8 at all
	 * has its bytes outside ASCII replaced.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	public static function cut( $message ) {
		if ( strlen( $message ) > self::MAX_MESSAGE ) {
			$message = substr( $message, 0, self::MAX_MESSAGE );

			// Drop the bytes of a character that the cut left incomplete.
			for ( $i = 0; $i < 3 && ! preg_match( '//u', $message ); $i++ ) {
				$message = substr( $message, 0, -1 );
			}
		}

		if ( ! preg_match( '//u', $message ) ) {
			$message = (string) preg_replace( '/[\x80-\xFF]/', '?', $message );
		}

		return $message;
	}

	/**
	 * Adds an event once per place and message.
	 *
	 * @param string $type          Error level name or class of the uncaught error.
	 * @param string $message       Message.
	 * @param string $file          File.
	 * @param int    $line          Line.
	 * @param bool   $keep_unknown  Whether to keep a message that is not on record.
	 */
	private static function add( $type, $message, $file, $line, $keep_unknown ) {
		$key = md5( $type . '|' . $message . '|' . $file . '|' . $line );

		if ( isset( self::$events[ $key ] ) || count( self::$events ) >= self::MAX_EVENTS ) {
			return;
		}

		$match = null === self::$signatures ? null : self::$signatures->match( $type, $message );

		if ( null === $match && ! $keep_unknown ) {
			return;
		}

		self::$events[ $key ] = array(
			'type'    => $type,
			'message' => self::cut( $message ),
			'file'    => $file,
			'line'    => $line,
			'match'   => $match,
		);
	}
}
