<?php
/**
 * Class constants of internal classes that PHP 8.5 deprecates.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: constant.class_deprecated (tranche 8.5-dep).
 *
 * - DateTimeInterface::RFC7231, also read through DateTime:: and
 *   DateTimeImmutable:: (the global DATE_RFC7231 is in the catalog).
 * - The driver specific PDO:: constants that the PHP 8.5 runtime here
 *   defines and showed deprecated: PDO::MYSQL_ATTR_* and PDO::SQLITE_*.
 *   The DBLIB, FB, ODBC and PGSQL ones, and MYSQL_ATTR_MAX_BUFFER_SIZE,
 *   MYSQL_ATTR_READ_DEFAULT_FILE, MYSQL_ATTR_READ_DEFAULT_GROUP (libmysql
 *   builds only) and MYSQL_ATTR_LOCAL_INFILE_DIRECTORY (PHP 8.1+, no fixture
 *   that runs on every version) are not reported.
 *
 * Only the class written by name is recognised: self::, static::, a subclass
 * or an object are not. defined( 'PDO::...' ) does not keep the notice away,
 * so only a PHP version check marks a finding as guarded.
 */
final class Php85DepClassConstants implements Rule {

	/**
	 * PDO constant => what to use instead (PHP 8.4+).
	 */
	const PDO = array(
		'MYSQL_ATTR_USE_BUFFERED_QUERY'      => 'Pdo\Mysql::ATTR_USE_BUFFERED_QUERY',
		'MYSQL_ATTR_LOCAL_INFILE'            => 'Pdo\Mysql::ATTR_LOCAL_INFILE',
		'MYSQL_ATTR_INIT_COMMAND'            => 'Pdo\Mysql::ATTR_INIT_COMMAND',
		'MYSQL_ATTR_COMPRESS'                => 'Pdo\Mysql::ATTR_COMPRESS',
		'MYSQL_ATTR_DIRECT_QUERY'            => 'Pdo\Mysql::ATTR_DIRECT_QUERY',
		'MYSQL_ATTR_FOUND_ROWS'              => 'Pdo\Mysql::ATTR_FOUND_ROWS',
		'MYSQL_ATTR_IGNORE_SPACE'            => 'Pdo\Mysql::ATTR_IGNORE_SPACE',
		'MYSQL_ATTR_SSL_KEY'                 => 'Pdo\Mysql::ATTR_SSL_KEY',
		'MYSQL_ATTR_SSL_CERT'                => 'Pdo\Mysql::ATTR_SSL_CERT',
		'MYSQL_ATTR_SSL_CA'                  => 'Pdo\Mysql::ATTR_SSL_CA',
		'MYSQL_ATTR_SSL_CAPATH'              => 'Pdo\Mysql::ATTR_SSL_CAPATH',
		'MYSQL_ATTR_SSL_CIPHER'              => 'Pdo\Mysql::ATTR_SSL_CIPHER',
		'MYSQL_ATTR_SSL_VERIFY_SERVER_CERT'  => 'Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT',
		'MYSQL_ATTR_SERVER_PUBLIC_KEY'       => 'Pdo\Mysql::ATTR_SERVER_PUBLIC_KEY',
		'MYSQL_ATTR_MULTI_STATEMENTS'        => 'Pdo\Mysql::ATTR_MULTI_STATEMENTS',
		'SQLITE_ATTR_EXTENDED_RESULT_CODES'  => 'Pdo\Sqlite::ATTR_EXTENDED_RESULT_CODES',
		'SQLITE_ATTR_OPEN_FLAGS'             => 'Pdo\Sqlite::OPEN_FLAGS',
		'SQLITE_ATTR_READONLY_STATEMENT'     => 'Pdo\Sqlite::ATTR_READONLY_STATEMENT',
		'SQLITE_DETERMINISTIC'               => 'Pdo\Sqlite::DETERMINISTIC',
		'SQLITE_OPEN_READONLY'               => 'Pdo\Sqlite::OPEN_READONLY',
		'SQLITE_OPEN_READWRITE'              => 'Pdo\Sqlite::OPEN_READWRITE',
		'SQLITE_OPEN_CREATE'                 => 'Pdo\Sqlite::OPEN_CREATE',
	);

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Node\Expr\ClassConstFetch::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node\Expr\ClassConstFetch $node    Class constant fetch.
	 * @param Context                   $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( ! $node->class instanceof Node\Name || ! $node->name instanceof Node\Identifier ) {
			return;
		}

		$resolved = $node->class->getAttribute( 'resolvedName' );
		$class    = $resolved instanceof Node\Name ? $resolved : $node->class;

		if ( 1 !== count( $class->getParts() ) ) {
			return;
		}

		$class    = strtolower( $class->getLast() );
		$constant = $node->name->toString();

		if ( 'RFC7231' === $constant && in_array( $class, array( 'datetimeinterface', 'datetime', 'datetimeimmutable' ), true ) ) {
			$finding          = $context->report( 'constant.class_deprecated', $node, array( 'deprecated' => '8.5' ) );
			$finding->subject = 'DateTimeInterface::RFC7231';
			$finding->instead = "'D, d M Y H:i:s \\G\\M\\T' on a date in UTC";
		} elseif ( 'pdo' === $class && array_key_exists( $constant, self::PDO ) ) {
			$finding          = $context->report( 'constant.class_deprecated', $node, array( 'deprecated' => '8.5' ) );
			$finding->subject = 'PDO::' . $constant;
			$finding->instead = self::PDO[ $constant ] . ' (PHP 8.4+)';
			$finding->guarded = self::after_new_api_check( $node );
		} else {
			return;
		}

		$finding->guarded = $finding->guarded || $context->constant_is_guarded( Php85DepFunctionArguments::VERSION_GUARD );
	}

	/**
	 * Whether the node sits in the else branch of a check for the PHP 8.4
	 * driver classes, as in
	 *
	 *     if ( defined( 'Pdo\Sqlite::ATTR_OPEN_FLAGS' ) ) { ... } elseif ( ... ) { PDO::SQLITE_ATTR_OPEN_FLAGS }
	 *     class_exists( 'Pdo\Mysql' ) ? Pdo\Mysql::ATTR_INIT_COMMAND : PDO::MYSQL_ATTR_INIT_COMMAND
	 *
	 * On PHP 8.5 the first branch runs, so the old constant is never read.
	 * Php85DepMethods uses the same test for the PDO::sqlite*() methods.
	 *
	 * @param Node $node Constant fetch.
	 * @return bool
	 */
	public static function after_new_api_check( Node $node ) {
		$child  = $node;
		$parent = $node->getAttribute( 'parent' );

		while ( null !== $parent ) {
			$conditions = array();

			if ( $parent instanceof Node\Expr\Ternary && $parent->else === $child ) {
				$conditions[] = $parent->cond;
			} elseif ( $parent instanceof Node\Stmt\If_ && ( $child instanceof Node\Stmt\Else_ || $child instanceof Node\Stmt\ElseIf_ ) ) {
				$conditions[] = $parent->cond;

				foreach ( $parent->elseifs as $elseif ) {
					if ( $elseif === $child ) {
						break;
					}

					$conditions[] = $elseif->cond;
				}
			}

			foreach ( $conditions as $condition ) {
				if ( self::checks_new_api( $condition ) ) {
					return true;
				}
			}

			$child  = $parent;
			$parent = $parent->getAttribute( 'parent' );
		}

		return false;
	}

	/**
	 * Whether a condition calls defined(), class_exists() or method_exists()
	 * on a Pdo\Mysql or Pdo\Sqlite name written as a string, or tests
	 * "instanceof Pdo\Mysql" or "instanceof Pdo\Sqlite".
	 *
	 * @param Node\Expr $condition Condition.
	 * @return bool
	 */
	private static function checks_new_api( Node\Expr $condition ) {
		$finder = new \TYStudio\UpgradeWarden\Vendor\PhpParser\NodeFinder();
		$found  = $finder->findFirst(
			$condition,
			static function ( Node $node ) {
				if ( $node instanceof Node\Expr\Instanceof_ && $node->class instanceof Node\Name ) {
					$resolved = $node->class->getAttribute( 'resolvedName' );
					$class    = $resolved instanceof Node\Name ? $resolved : $node->class;

					return 1 === preg_match( '/^pdo\\\\(mysql|sqlite)$/i', $class->toString() );
				}

				if ( ! $node instanceof Node\Expr\FuncCall || ! isset( $node->args[0] ) || ! $node->args[0] instanceof Node\Arg ) {
					return false;
				}

				$name  = InternalFunctions::global_name( $node->name );
				$first = $node->args[0]->value;

				return in_array( $name, array( 'defined', 'class_exists', 'method_exists' ), true )
					&& $first instanceof Node\Scalar\String_
					&& 1 === preg_match( '/^\\\\?pdo\\\\(mysql|sqlite)\b/i', $first->value );
			}
		);

		return null !== $found;
	}
}
