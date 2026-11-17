<?php
/**
 * A class that extends an internal class and redeclares one of its constants,
 * which PHP 8.4 gave a type.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Stmt\Class_;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Stmt\ClassConst;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: class.typed_constant_redeclared. Breaks in 8.4.
 *
 *     class Local_Pdo extends PDO {
 *         const FETCH_ASSOC = 2;
 *     }
 *
 * PHP 8.4 typed the class constants of the date, PDO, Reflection, SPL,
 * sqlite3 and XMLReader extensions (intl too, which no runtime here has). A
 * child class that redeclares such a constant without a type is a compile
 * error from 8.4 ("Type of Local_Pdo::FETCH_ASSOC must be compatible with
 * PDO::FETCH_ASSOC of type int"), even when the value has the right type.
 *
 * The list below was read from the 8.3 and 8.4 runtimes with Reflection: the
 * constants of non-final internal classes that have a type on 8.4 and existed
 * without one on 8.3 (inherited ones included, so DateTime carries the
 * constants of DateTimeInterface and Phar those of RecursiveDirectoryIterator).
 *
 * Reported only when the class extends the internal class directly, by its
 * global name, and the redeclared constant has no type of its own (a typed
 * constant needs 8.3 and is checked by PHP for compatibility already). A
 * grandchild through a user class is not followed.
 */
final class Php84BicCoreTypedClassConstant implements Rule {

	/**
	 * Internal class, lower case => constants typed in 8.4.
	 */
	const TYPED = array(
		'arrayiterator' => array(
			'STD_PROP_LIST', 'ARRAY_AS_PROPS',
		),
		'arrayobject' => array(
			'STD_PROP_LIST', 'ARRAY_AS_PROPS',
		),
		'cachingiterator' => array(
			'CALL_TOSTRING', 'CATCH_GET_CHILD', 'TOSTRING_USE_KEY', 'TOSTRING_USE_CURRENT',
			'TOSTRING_USE_INNER', 'FULL_CACHE',
		),
		'dateperiod' => array(
			'EXCLUDE_START_DATE', 'INCLUDE_END_DATE',
		),
		'datetime' => array(
			'ATOM', 'COOKIE', 'ISO8601', 'ISO8601_EXPANDED', 'RFC822', 'RFC850', 'RFC1036', 'RFC1123',
			'RFC7231', 'RFC2822', 'RFC3339', 'RFC3339_EXTENDED', 'RSS', 'W3C',
		),
		'datetimeimmutable' => array(
			'ATOM', 'COOKIE', 'ISO8601', 'ISO8601_EXPANDED', 'RFC822', 'RFC850', 'RFC1036', 'RFC1123',
			'RFC7231', 'RFC2822', 'RFC3339', 'RFC3339_EXTENDED', 'RSS', 'W3C',
		),
		'datetimezone' => array(
			'AFRICA', 'AMERICA', 'ANTARCTICA', 'ARCTIC', 'ASIA', 'ATLANTIC', 'AUSTRALIA', 'EUROPE',
			'INDIAN', 'PACIFIC', 'UTC', 'ALL', 'ALL_WITH_BC', 'PER_COUNTRY',
		),
		'filesystemiterator' => array(
			'CURRENT_MODE_MASK', 'CURRENT_AS_PATHNAME', 'CURRENT_AS_FILEINFO', 'CURRENT_AS_SELF',
			'KEY_MODE_MASK', 'KEY_AS_PATHNAME', 'FOLLOW_SYMLINKS', 'KEY_AS_FILENAME',
			'NEW_CURRENT_AND_KEY', 'OTHER_MODE_MASK', 'SKIP_DOTS', 'UNIX_PATHS',
		),
		'globiterator' => array(
			'CURRENT_MODE_MASK', 'CURRENT_AS_PATHNAME', 'CURRENT_AS_FILEINFO', 'CURRENT_AS_SELF',
			'KEY_MODE_MASK', 'KEY_AS_PATHNAME', 'FOLLOW_SYMLINKS', 'KEY_AS_FILENAME',
			'NEW_CURRENT_AND_KEY', 'OTHER_MODE_MASK', 'SKIP_DOTS', 'UNIX_PATHS',
		),
		'multipleiterator' => array(
			'MIT_NEED_ANY', 'MIT_NEED_ALL', 'MIT_KEYS_NUMERIC', 'MIT_KEYS_ASSOC',
		),
		'pdo' => array(
			'PARAM_NULL', 'PARAM_BOOL', 'PARAM_INT', 'PARAM_STR', 'PARAM_LOB', 'PARAM_STMT',
			'PARAM_INPUT_OUTPUT', 'PARAM_STR_NATL', 'PARAM_STR_CHAR', 'PARAM_EVT_ALLOC',
			'PARAM_EVT_FREE', 'PARAM_EVT_EXEC_PRE', 'PARAM_EVT_EXEC_POST', 'PARAM_EVT_FETCH_PRE',
			'PARAM_EVT_FETCH_POST', 'PARAM_EVT_NORMALIZE', 'FETCH_DEFAULT', 'FETCH_LAZY',
			'FETCH_ASSOC', 'FETCH_NUM', 'FETCH_BOTH', 'FETCH_OBJ', 'FETCH_BOUND', 'FETCH_COLUMN',
			'FETCH_CLASS', 'FETCH_INTO', 'FETCH_FUNC', 'FETCH_GROUP', 'FETCH_UNIQUE', 'FETCH_KEY_PAIR',
			'FETCH_CLASSTYPE', 'FETCH_SERIALIZE', 'FETCH_PROPS_LATE', 'FETCH_NAMED', 'ATTR_AUTOCOMMIT',
			'ATTR_PREFETCH', 'ATTR_TIMEOUT', 'ATTR_ERRMODE', 'ATTR_SERVER_VERSION',
			'ATTR_CLIENT_VERSION', 'ATTR_SERVER_INFO', 'ATTR_CONNECTION_STATUS', 'ATTR_CASE',
			'ATTR_CURSOR_NAME', 'ATTR_CURSOR', 'ATTR_ORACLE_NULLS', 'ATTR_PERSISTENT',
			'ATTR_STATEMENT_CLASS', 'ATTR_FETCH_TABLE_NAMES', 'ATTR_FETCH_CATALOG_NAMES',
			'ATTR_DRIVER_NAME', 'ATTR_STRINGIFY_FETCHES', 'ATTR_MAX_COLUMN_LEN',
			'ATTR_EMULATE_PREPARES', 'ATTR_DEFAULT_FETCH_MODE', 'ATTR_DEFAULT_STR_PARAM',
			'ERRMODE_SILENT', 'ERRMODE_WARNING', 'ERRMODE_EXCEPTION', 'CASE_NATURAL', 'CASE_LOWER',
			'CASE_UPPER', 'NULL_NATURAL', 'NULL_EMPTY_STRING', 'NULL_TO_STRING', 'ERR_NONE',
			'FETCH_ORI_NEXT', 'FETCH_ORI_PRIOR', 'FETCH_ORI_FIRST', 'FETCH_ORI_LAST', 'FETCH_ORI_ABS',
			'FETCH_ORI_REL', 'CURSOR_FWDONLY', 'CURSOR_SCROLL',
		),
		'phar' => array(
			'CURRENT_MODE_MASK', 'CURRENT_AS_PATHNAME', 'CURRENT_AS_FILEINFO', 'CURRENT_AS_SELF',
			'KEY_MODE_MASK', 'KEY_AS_PATHNAME', 'FOLLOW_SYMLINKS', 'KEY_AS_FILENAME',
			'NEW_CURRENT_AND_KEY', 'OTHER_MODE_MASK', 'SKIP_DOTS', 'UNIX_PATHS',
		),
		'phardata' => array(
			'CURRENT_MODE_MASK', 'CURRENT_AS_PATHNAME', 'CURRENT_AS_FILEINFO', 'CURRENT_AS_SELF',
			'KEY_MODE_MASK', 'KEY_AS_PATHNAME', 'FOLLOW_SYMLINKS', 'KEY_AS_FILENAME',
			'NEW_CURRENT_AND_KEY', 'OTHER_MODE_MASK', 'SKIP_DOTS', 'UNIX_PATHS',
		),
		'recursivearrayiterator' => array(
			'STD_PROP_LIST', 'ARRAY_AS_PROPS', 'CHILD_ARRAYS_ONLY',
		),
		'recursivecachingiterator' => array(
			'CALL_TOSTRING', 'CATCH_GET_CHILD', 'TOSTRING_USE_KEY', 'TOSTRING_USE_CURRENT',
			'TOSTRING_USE_INNER', 'FULL_CACHE',
		),
		'recursivedirectoryiterator' => array(
			'CURRENT_MODE_MASK', 'CURRENT_AS_PATHNAME', 'CURRENT_AS_FILEINFO', 'CURRENT_AS_SELF',
			'KEY_MODE_MASK', 'KEY_AS_PATHNAME', 'FOLLOW_SYMLINKS', 'KEY_AS_FILENAME',
			'NEW_CURRENT_AND_KEY', 'OTHER_MODE_MASK', 'SKIP_DOTS', 'UNIX_PATHS',
		),
		'recursiveiteratoriterator' => array(
			'LEAVES_ONLY', 'SELF_FIRST', 'CHILD_FIRST', 'CATCH_GET_CHILD',
		),
		'recursiveregexiterator' => array(
			'USE_KEY', 'INVERT_MATCH', 'MATCH', 'GET_MATCH', 'ALL_MATCHES', 'SPLIT', 'REPLACE',
		),
		'recursivetreeiterator' => array(
			'LEAVES_ONLY', 'SELF_FIRST', 'CHILD_FIRST', 'CATCH_GET_CHILD', 'BYPASS_CURRENT',
			'BYPASS_KEY', 'PREFIX_LEFT', 'PREFIX_MID_HAS_NEXT', 'PREFIX_MID_LAST',
			'PREFIX_END_HAS_NEXT', 'PREFIX_END_LAST', 'PREFIX_RIGHT',
		),
		'reflectionattribute' => array(
			'IS_INSTANCEOF',
		),
		'reflectionclass' => array(
			'IS_IMPLICIT_ABSTRACT', 'IS_EXPLICIT_ABSTRACT', 'IS_FINAL', 'IS_READONLY',
		),
		'reflectionclassconstant' => array(
			'IS_PUBLIC', 'IS_PROTECTED', 'IS_PRIVATE', 'IS_FINAL',
		),
		'reflectionenum' => array(
			'IS_IMPLICIT_ABSTRACT', 'IS_EXPLICIT_ABSTRACT', 'IS_FINAL', 'IS_READONLY',
		),
		'reflectionenumbackedcase' => array(
			'IS_PUBLIC', 'IS_PROTECTED', 'IS_PRIVATE', 'IS_FINAL',
		),
		'reflectionenumunitcase' => array(
			'IS_PUBLIC', 'IS_PROTECTED', 'IS_PRIVATE', 'IS_FINAL',
		),
		'reflectionfunction' => array(
			'IS_DEPRECATED',
		),
		'reflectionmethod' => array(
			'IS_STATIC', 'IS_PUBLIC', 'IS_PROTECTED', 'IS_PRIVATE', 'IS_ABSTRACT', 'IS_FINAL',
		),
		'reflectionobject' => array(
			'IS_IMPLICIT_ABSTRACT', 'IS_EXPLICIT_ABSTRACT', 'IS_FINAL', 'IS_READONLY',
		),
		'reflectionproperty' => array(
			'IS_STATIC', 'IS_READONLY', 'IS_PUBLIC', 'IS_PROTECTED', 'IS_PRIVATE',
		),
		'regexiterator' => array(
			'USE_KEY', 'INVERT_MATCH', 'MATCH', 'GET_MATCH', 'ALL_MATCHES', 'SPLIT', 'REPLACE',
		),
		'spldoublylinkedlist' => array(
			'IT_MODE_LIFO', 'IT_MODE_FIFO', 'IT_MODE_DELETE', 'IT_MODE_KEEP',
		),
		'splfileobject' => array(
			'DROP_NEW_LINE', 'READ_AHEAD', 'SKIP_EMPTY', 'READ_CSV',
		),
		'splpriorityqueue' => array(
			'EXTR_BOTH', 'EXTR_PRIORITY', 'EXTR_DATA',
		),
		'splqueue' => array(
			'IT_MODE_LIFO', 'IT_MODE_FIFO', 'IT_MODE_DELETE', 'IT_MODE_KEEP',
		),
		'splstack' => array(
			'IT_MODE_LIFO', 'IT_MODE_FIFO', 'IT_MODE_DELETE', 'IT_MODE_KEEP',
		),
		'spltempfileobject' => array(
			'DROP_NEW_LINE', 'READ_AHEAD', 'SKIP_EMPTY', 'READ_CSV',
		),
		'sqlite3' => array(
			'OK', 'DENY', 'IGNORE', 'CREATE_INDEX', 'CREATE_TABLE', 'CREATE_TEMP_INDEX',
			'CREATE_TEMP_TABLE', 'CREATE_TEMP_TRIGGER', 'CREATE_TEMP_VIEW', 'CREATE_TRIGGER',
			'CREATE_VIEW', 'DELETE', 'DROP_INDEX', 'DROP_TABLE', 'DROP_TEMP_INDEX', 'DROP_TEMP_TABLE',
			'DROP_TEMP_TRIGGER', 'DROP_TEMP_VIEW', 'DROP_TRIGGER', 'DROP_VIEW', 'INSERT', 'PRAGMA',
			'READ', 'SELECT', 'TRANSACTION', 'UPDATE', 'ATTACH', 'DETACH', 'ALTER_TABLE', 'REINDEX',
			'ANALYZE', 'CREATE_VTABLE', 'DROP_VTABLE', 'FUNCTION', 'SAVEPOINT', 'COPY', 'RECURSIVE',
		),
		'xmlreader' => array(
			'NONE', 'ELEMENT', 'ATTRIBUTE', 'TEXT', 'CDATA', 'ENTITY_REF', 'ENTITY', 'PI', 'COMMENT',
			'DOC', 'DOC_TYPE', 'DOC_FRAGMENT', 'NOTATION', 'WHITESPACE', 'SIGNIFICANT_WHITESPACE',
			'END_ELEMENT', 'END_ENTITY', 'XML_DECLARATION', 'LOADDTD', 'DEFAULTATTRS', 'VALIDATE',
			'SUBST_ENTITIES',
		),
	);

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Class_::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Class_  $node    Class declaration.
	 * @param Context $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( null === $node->extends ) {
			return;
		}

		$parent = self::global_class( $node->extends );

		if ( null === $parent || ! isset( self::TYPED[ strtolower( $parent ) ] ) ) {
			return;
		}

		$typed = self::TYPED[ strtolower( $parent ) ];

		foreach ( $node->stmts as $statement ) {
			if ( ! $statement instanceof ClassConst || null !== $statement->type ) {
				continue;
			}

			foreach ( $statement->consts as $constant ) {
				$name = $constant->name->toString();

				if ( ! in_array( $name, $typed, true ) ) {
					continue;
				}

				$finding          = $context->report( 'class.typed_constant_redeclared', $constant, array( 'breaks' => '8.4' ) );
				$finding->subject = $parent . '::' . $name;
				$finding->instead = 'a constant with another name, or ' . $parent . '::' . $name . ' itself';
			}
		}
	}

	/**
	 * The name of the global class a name refers to, or null when it names a
	 * class in a namespace.
	 *
	 * @param Node\Name $name Class name.
	 * @return string|null
	 */
	private static function global_class( Node\Name $name ) {
		$resolved = $name->getAttribute( 'resolvedName' );

		if ( $resolved instanceof Node\Name ) {
			$name = $resolved;
		}

		return 1 === count( $name->getParts() ) ? $name->getLast() : null;
	}
}
