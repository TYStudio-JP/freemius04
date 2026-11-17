<?php
/**
 * PHP 8.6 deprecations (tranche 8.6-dep): code that looks like a deprecated
 * use and is fine, and so must stay unreported, plus a few cases that must
 * be reported and have no fixture of their own.
 *
 * Only the rule ids of this tranche are looked at, so that a rule somebody
 * else adds does not make these tests fail.
 *
 * @package UpgradeWarden
 */

$tests = array();

$php86_mine = static function ( $code, array $declared = array() ) {
	$ids = array(
		'syntax.class_constant_namespace',
		'syntax.return_in_finally',
		'signature.debuginfo_nullable_return',
		'syntax.constructor_return_value',
		'syntax.constructor_generator',
		'syntax.function_named_readonly',
		'syntax.let_is_name',
		'syntax.underscore_constant_name',
		'call.define_third_argument',
		'call.is_a_string_without_allow_string',
		'call.arrayiterator_method',
		'call.splfileobject_csv_method',
		'call.mysqli_deprecated_method',
		'call.mysqli_stmt_without_query',
		'argument.object_instead_of_array',
		'class.session_handler_missing_methods',
	);

	$found = array();

	foreach ( upgw_scanner()->scan( "<?php\n" . $code, $declared )->findings as $finding ) {
		if ( in_array( $finding->rule, $ids, true ) ) {
			$found[] = $finding->rule;
		}
	}

	return $found;
};

$php86_clean = static function ( $code, $message, array $declared = array() ) use ( $php86_mine ) {
	upgw_same( array(), $php86_mine( $code, $declared ), $message . "\n      code: " . $code );
};

$tests['8.6-dep: class constants, properties and names near "namespace" are not reported'] = static function () use ( $php86_clean ) {
	$php86_clean( 'class R { protected $namespace = "shop/v1"; const NS = "a"; const NAMESPACE_V1 = "b"; }', 'A property named namespace and other constant names.' );
	$php86_clean( 'define( "NAMESPACE", "x" ); const NAMESPACE_ROOT = 1;', 'Global constants are not class constants.' );
	$php86_clean( 'echo $route::NAMESPACE; echo Route::namespace;', 'Reading a constant is not declaring one.' );
	$php86_clean( 'enum E: string { case NamespaceRoot = "n"; const NS = "x"; }', 'Other enum case and constant names.' );
};

$tests['8.6-dep: an enum case named namespace is a class constant and is reported'] = static function () use ( $php86_mine ) {
	// php-8.6.0RC7, Zend/tests/deprecate_namespace_class_constant.phpt:
	// "Declaring enum constant called 'namespace' is deprecated" for a case.
	upgw_same( array( 'syntax.class_constant_namespace' ), $php86_mine( 'enum E: string { case Namespace = "n"; }' ), 'A backed enum case.' );
	upgw_same( array( 'syntax.class_constant_namespace' ), $php86_mine( 'enum E { case namespace; }' ), 'A pure enum case in lower case.' );
};

$tests['8.6-dep: returns outside a finally block are not reported'] = static function () use ( $php86_clean, $php86_mine ) {
	$php86_clean( 'function f() { try { return 1; } catch ( Exception $e ) { return 2; } finally { echo "x"; } }', 'Return in try and catch.' );
	$php86_clean( 'function f() { try { $a = 1; } finally { $a = 2; } return $a; }', 'Return after the try statement.' );
	$php86_clean( 'function f() { try { $a = 1; } finally { $c = function () { return 1; }; $d = fn() => 2; } }', 'Return in a closure declared in finally.' );
	$php86_clean( 'function f() { try { $a = 1; } finally { $o = new class { public function g() { return 1; } }; } }', 'Return in a method of an anonymous class in finally.' );

	upgw_same( array( 'syntax.return_in_finally' ), $php86_mine( 'function f() { try { $a = 1; } finally { try { return 1; } catch ( Exception $e ) {} } }' ), 'A try nested in finally is still in finally.' );
	upgw_same( array( 'syntax.return_in_finally' ), $php86_mine( 'try { $a = 1; } finally { return; }' ), 'Top-level code too.' );
};

$tests['8.6-dep: __debugInfo() with other return types is not reported'] = static function () use ( $php86_clean, $php86_mine ) {
	$php86_clean( 'class A { public function __debugInfo(): array { return array(); } }', 'Plain array.' );
	$php86_clean( 'class A { public function __debugInfo() { return array(); } }', 'No return type.' );
	$php86_clean( 'class A { public function debugInfo(): ?array { return null; } public function __toArray(): ?array { return null; } }', 'Other methods with ?array.' );
	$php86_clean( 'class A { public function __debugInfo(): array|false { return array(); } }', 'array|false is not array|null.' );

	upgw_same( array( 'signature.debuginfo_nullable_return' ), $php86_mine( 'class A { public function __debugInfo(): null|array { return array(); } }' ), 'null|array.' );
	upgw_same( array( 'signature.debuginfo_nullable_return' ), $php86_mine( 'class A { public function __DEBUGINFO(): ?ARRAY { return array(); } }' ), 'Case does not matter.' );
};

$tests['8.6-dep: constructors without a value or a yield of their own are not reported'] = static function () use ( $php86_clean, $php86_mine ) {
	$php86_clean( 'class A { public function __construct( $a ) { if ( ! $a ) { return; } $this->a = $a; } }', 'A bare return in a constructor.' );
	$php86_clean( 'class A { public function __construct() { $this->f = function () { return 1; }; $this->g = fn() => 2; } }', 'Returns of closures in a constructor.' );
	$php86_clean( 'class A { public function __construct() { $this->f = function () { yield 1; }; } }', 'A generator closure in a constructor.' );
	$php86_clean( 'class A { public function build() { return 1; } public function items() { yield 1; } }', 'Other methods.' );
	$php86_clean( 'function __construct() { return 1; }', 'A function, not a method, named __construct.' );
	$php86_clean( 'class A { public function __construct() { $o = new class { public function __invoke() { return 1; } }; } }', 'Return in an anonymous class inside a constructor.' );

	upgw_same( array( 'syntax.constructor_return_value' ), $php86_mine( 'trait T { public function __construct() { return $this; } }' ), 'A constructor in a trait.' );
	upgw_same( array( 'syntax.constructor_generator' ), $php86_mine( 'class A { public function __CONSTRUCT() { $x = yield; } }' ), 'Case does not matter.' );
};

$tests['8.6-dep: readonly as anything but a function name is not reported'] = static function () use ( $php86_clean ) {
	$php86_clean( 'class A { public function readonly() { return 1; } public readonly int $x; }', 'A method named readonly and a readonly property.' );
	$php86_clean( 'function readonly_value() { return 1; } echo readonly_value();', 'Another function name.' );
	$php86_clean( '$value = readonly();', 'A call without a declaration in this file.' );
};

$tests['8.6-dep: names near "let", "is" and "_" are not reported'] = static function () use ( $php86_clean, $php86_mine ) {
	$php86_clean( 'class Letter {} interface IsActive {} trait Lets {} function is_valid() {} function let_it_be() {}', 'Longer names.' );
	$php86_clean( 'class A { const IS = 1; const LET = 2; public function is() {} public function let() {} public $is; }', 'Class constants, methods and properties.' );
	$php86_clean( 'define( "IS_ACTIVE", 1 ); define( $name, 1 ); define( ...$args );', 'Other constant names and names not written out.' );
	$php86_clean( 'namespace Shop; const LET = 1; const IS = 2;', 'A namespaced constant in upper case.' );
	$php86_clean( 'namespace Shop; use Foo\Is_Thing; use function foo\is_ok; use const foo\LETTER;', 'Longer use aliases.' );
	$php86_clean( 'class_alias( "A", "Letter" ); class_alias( "A", $alias ); $o = new Is(); $x = is( 1 ); echo let;', 'Other aliases, and uses of a name.' );
	$php86_clean( 'const _X = 1; define( "__", 2 ); echo _( "text" );', 'Constants that only start with "_", and gettext.' );
	$php86_clean( 'namespace Shop; use function gettext as _;', 'A function alias "_" (not in the notes).' );
	$php86_clean( 'function define( $a, $b ) {} define( "let", 1 );', 'A define() the file declares itself.' );

	upgw_same( array( 'syntax.let_is_name', 'syntax.let_is_name' ), $php86_mine( 'namespace Shop; class LET {} function Is() {}' ), 'Class and function names in any case, in a namespace.' );
	upgw_same( array( 'syntax.let_is_name' ), $php86_mine( 'define( "IS", 1 );' ), 'A global constant is compared without regard to case.' );
	upgw_same( array( 'syntax.let_is_name' ), $php86_mine( 'define( "Shop\\\\is", 1 );' ), 'A namespaced constant through define().' );
	upgw_same( array( 'syntax.let_is_name', 'syntax.let_is_name' ), $php86_mine( 'namespace Shop; use Foo\{Let, Bar\Is};' ), 'Group use, with the alias taken from the last part.' );
	upgw_same( array( 'syntax.let_is_name' ), $php86_mine( 'class_alias( "A", "Shop\\\\Let", true );' ), 'class_alias() with a namespaced alias.' );
	upgw_same( array( 'syntax.underscore_constant_name' ), $php86_mine( 'namespace Shop; const _ = 1;' ), 'A namespaced constant "_".' );
};

$tests['8.6-dep: define() without a third argument, or with a literal true, is not reported'] = static function () use ( $php86_clean, $php86_mine ) {
	$php86_clean( 'define( "A", 1 ); define( "B", 2, true ); define( "C", 3, TRUE );', 'Two arguments, and true (an older deprecation).' );
	$php86_clean( '$f = define( ... );', 'A first-class callable.' );
	$php86_clean( 'define( "A", 1, false );', 'A define() declared in another file.', array( 'define' ) );
	$php86_clean( '$o->define( "A", 1, false ); Shop\define( "A", 1, false );', 'A method and a namespaced function.' );

	upgw_same( array( 'call.define_third_argument' ), $php86_mine( 'define( "A", 1, case_insensitive: false );' ), 'A named third argument.' );
};

$tests['8.6-dep: is_a() and is_subclass_of() without a written string are not reported'] = static function () use ( $php86_clean, $php86_mine ) {
	$php86_clean( 'var_dump( is_a( $object, "Base" ), is_a( new Child(), "Base" ), is_a( $this, Base::class ) );', 'An object or a variable as the first argument.' );
	$php86_clean( 'var_dump( is_a( "Child", "Base", true ), is_a( Child::class, "Base", $allow ) );', 'is_a() with $allow_string true or unknown.' );
	$php86_clean( 'var_dump( is_subclass_of( "Child", "Base" ), is_subclass_of( Child::class, "Base", true ) );', 'is_subclass_of() allows strings by default.' );
	$php86_clean( 'var_dump( is_a( $class, "Base" ), $o->is_a( "Child", "Base" ), Shop\is_a( "Child", "Base" ) );', 'A variable, a method and a namespaced function.' );

	upgw_same( array( 'call.is_a_string_without_allow_string' ), $php86_mine( 'var_dump( is_a( "Shop\\\\{$name}", "Base" ) );' ), 'An interpolated string.' );
	upgw_same( array( 'call.is_a_string_without_allow_string' ), $php86_mine( 'var_dump( is_a( "Shop\\\\" . $name, "Base" ) );' ), 'A concatenation.' );
	upgw_same( array( 'call.is_a_string_without_allow_string' ), $php86_mine( 'class C { function f() { return is_subclass_of( __CLASS__, "Base", false ); } }' ), '__CLASS__ with a literal false.' );
	upgw_same( array(), $php86_mine( 'var_dump( is_a( __CLASS__, "Base", true ), is_a( $prefix . "Child", "Base", true ) );' ), 'Concatenation and __CLASS__ with $allow_string true.' );
};

$tests['8.6-dep: methods on objects of unknown or other classes are not reported'] = static function () use ( $php86_clean, $php86_mine ) {
	$php86_clean( 'function f() { $o = new ArrayObject( array() ); $o->ksort(); $o->getFlags(); $o->setFlags( 0 ); $o->serialize(); }', 'ArrayObject keeps its methods.' );
	$php86_clean( 'function f( $it ) { $it->ksort(); $it->getFlags(); }', 'Receiver of unknown class.' );
	$php86_clean( 'ksort( $a ); asort( $a ); $flags = $this->getFlags();', 'Functions and $this.' );
	$php86_clean( 'function f( $file ) { return $file->fgetcsv(); } $r = fgetcsv( $h, 0, ",", "\"", "\\\\" );', 'fgetcsv() of an unknown object, and the function.' );
	$php86_clean( 'function f( $db ) { global $wpdb; $db->get_charset(); $wpdb->get_charset(); $db->stmt_init(); }', 'get_charset() and stmt_init() of unknown objects.' );
	$php86_clean( 'function f( mysqli $db ) { $s = new mysqli_stmt( $db, "SELECT 1" ); $q = new mysqli_stmt( $db, $sql ); }', 'mysqli_stmt with a query.' );
	$php86_clean( 'function f( ArrayIterator $it ) { $it->current(); $it->offsetGet( 0 ); }', 'Methods that are not deprecated.' );

	upgw_same( array( 'call.arrayiterator_method' ), $php86_mine( 'function f( RecursiveArrayIterator $it ) { return $it->getFlags(); }' ), 'A parameter declared as a subclass.' );
	upgw_same( array( 'call.splfileobject_csv_method' ), $php86_mine( 'function f() { $f = new SplTempFileObject(); return $f->getCsvControl(); }' ), 'SplTempFileObject.' );
	upgw_same( array( 'call.mysqli_stmt_without_query' ), $php86_mine( '$s = new mysqli_stmt( $db, null );' ), 'A literal null query.' );
};

$tests['8.6-dep: arrays and unknown values for array parameters are not reported'] = static function () use ( $php86_clean, $php86_mine ) {
	$php86_clean( 'echo http_build_query( array( "a" => 1 ) ), http_build_query( $args ), http_build_query( (array) $object );', 'Arrays and variables.' );
	$php86_clean( '$c = deflate_init( ZLIB_ENCODING_DEFLATE, array( "level" => 6 ) ); $d = inflate_init( ZLIB_ENCODING_RAW ); $e = deflate_init( ZLIB_ENCODING_DEFLATE, $options );', 'deflate_init() and inflate_init() with arrays or variables.' );
	$php86_clean( 'echo http_build_query( array( "filter" => new Filter() ) );', 'An object inside the array is known at run time only.' );

	upgw_same( array( 'argument.object_instead_of_array' ), $php86_mine( 'class A { public function q() { return http_build_query( $this ); } }' ), '$this.' );
	upgw_same( array( 'argument.object_instead_of_array' ), $php86_mine( '$c = deflate_init( options: new Options(), encoding: ZLIB_ENCODING_DEFLATE );' ), 'Named arguments.' );
};

$tests['8.6-dep: session handlers that have both methods, or may inherit them, are not reported'] = static function () use ( $php86_clean, $php86_mine ) {
	$methods = 'public function open( $p, $n ) {} public function close() {} public function read( $i ) {} public function write( $i, $d ) {} public function destroy( $i ) {} public function gc( $m ) {}';

	$php86_clean( 'class S extends SessionHandler {}', 'A subclass of SessionHandler.' );
	$php86_clean( 'class S extends Base implements SessionHandlerInterface { ' . $methods . ' }', 'A subclass of another class may inherit the methods.' );
	$php86_clean( 'class S implements SessionHandlerInterface { use Sid; ' . $methods . ' }', 'A trait may bring the methods.' );
	$php86_clean( 'class S implements SessionHandlerInterface { ' . $methods . ' public function create_sid() {} public function validateId( $i ) {} }', 'Both methods declared.' );
	$php86_clean( 'abstract class S implements SessionHandlerInterface, SessionIdInterface, SessionUpdateTimestampHandlerInterface { ' . $methods . ' }', 'Both interfaces implemented.' );
	$php86_clean( 'namespace Shop; class S implements SessionHandlerInterface { ' . $methods . ' }', 'Shop\SessionHandlerInterface is another interface.' );
	$php86_clean( 'class S implements Countable { public function count(): int { return 0; } }', 'Another interface.' );

	upgw_same( array( 'class.session_handler_missing_methods' ), $php86_mine( 'namespace Shop; class S implements \SessionHandlerInterface { ' . $methods . ' public function CREATE_SID() {} }' ), 'Fully qualified interface, validateId() missing.' );
	upgw_same( array( 'class.session_handler_missing_methods' ), $php86_mine( '$h = new class implements SessionHandlerInterface { ' . $methods . ' };' ), 'An anonymous class.' );
};

$tests['8.6-dep: every finding of this tranche says it comes from the notes only'] = static function () {
	$code = "<?php\nclass R { const NAMESPACE = 'x'; public function __construct() { return 1; } }\nfunction readonly() {}\nclass Let {}\nconst _ = 1;\ndefine( 'A', 1, false );\n"
		. "var_dump( is_a( 'A', 'B' ) );\nfunction f( mysqli \$db ) { \$db->stmt_init(); }\necho http_build_query( (object) array() );\n";

	$result = upgw_scanner()->scan( $code );

	upgw_true( count( $result->findings ) >= 9, 'Expected a finding for each line.' );

	foreach ( $result->findings as $finding ) {
		upgw_same( '8.6', $finding->deprecated, "{$finding->rule}: version." );
		upgw_same( 'documented', $finding->evidence, "{$finding->rule}: evidence." );
		upgw_same( null, $finding->level_for( '8.5' ), "{$finding->rule}: nothing on 8.5." );
	}
};

return $tests;
