<?php
/**
 * PHP 8.5 deprecations (tranche 8.5-dep): code that looks like a deprecated
 * use and is fine, and so must stay unreported.
 *
 * Only the rule ids of this tranche are looked at, so that a rule somebody
 * else adds for the same function does not make these tests fail.
 *
 * @package UpgradeWarden
 */

$tests = array();

$mine = static function ( $code, array $declared = array() ) {
	$ids = array(
		'call.get_defined_functions_argument',
		'call.finfo_buffer_context',
		'call.openssl_pkey_derive_key_length',
		'call.spl_autoload_unregister_call',
		'argument.dir_handle_missing',
		'argument.chr_out_of_range',
		'argument.ord_not_one_byte',
		'argument.array_key_exists_null',
		'syntax.null_array_offset',
		'method.debuginfo_return_null',
		'constant.class_deprecated',
		'call.reflection_set_accessible',
		'call.pdo_driver_method',
		'call.pdo_uri_dsn',
		'call.splobjectstorage_method',
		'argument.arrayobject_object',
		'variable.http_response_header',
	);

	$found = array();

	foreach ( upgw_scanner()->scan( "<?php\n" . $code, $declared )->findings as $finding ) {
		if ( in_array( $finding->rule, $ids, true ) ) {
			$found[] = $finding->rule;
		}
	}

	return $found;
};

$guarded = static function ( $code, $rule ) {
	$flags = array();

	foreach ( upgw_scanner()->scan( "<?php\n" . $code )->findings as $finding ) {
		if ( $rule === $finding->rule ) {
			$flags[] = $finding->guarded;
		}
	}

	return $flags;
};

$clean = static function ( $code, $message, array $declared = array() ) use ( $mine ) {
	upgw_same( array(), $mine( $code, $declared ), $message . "\n      code: " . $code );
};

$tests['8.5-dep: calls without the deprecated argument are not reported'] = static function () use ( $clean, $mine ) {
	$clean( '$f = get_defined_functions();', 'get_defined_functions() without an argument.' );
	$clean( '$m = finfo_buffer( $f, $s, FILEINFO_MIME_TYPE );', 'finfo_buffer() with three arguments.' );
	$clean( '$k = openssl_pkey_derive( $public, $private );', 'openssl_pkey_derive() with two arguments.' );
	$clean( 'spl_autoload_unregister( "my_loader" );', 'Unregistering one loader.' );
	$clean( 'spl_autoload_unregister( $loader );', 'Loader from a variable.' );
	$clean( '$e = readdir( $handle ); rewinddir( $handle ); closedir( $handle );', 'Directory functions with a handle.' );
	$clean( '$c = chr( 0 ) . chr( 255 ) . chr( 65 ) . chr( $code );', 'chr() inside 0..255 or from a variable.' );
	$clean( '$o = ord( "a" ) + ord( "\n" ) + ord( $text );', 'ord() with one byte or a variable.' );
	$clean( '$o = ord( $text[0] );', 'ord() of the first byte.' );
	$clean( 'var_dump( array_key_exists( "", $a ), array_key_exists( $key, $a ), key_exists( 0, $a ) );', 'array_key_exists() without a literal null.' );
	$clean( 'var_dump( array_key_exists( $a, null ) );', 'null as the array, not the key.' );
	$clean( '$x = $obj->readdir(); $y = Dir::closedir(); $z = $o->chr( 300 ); $w = My\\ord( "ab" );', 'Methods and namespaced functions.' );

	upgw_same( array(), $mine( 'function readdir() { return 1; } $x = readdir();' ), 'A function the file declares itself.' );
	upgw_same( array(), $mine( '$x = readdir();', array( 'readdir' ) ), 'A function declared in another file.' );
	upgw_same( array( 'call.get_defined_functions_argument' ), $mine( 'namespace App; $f = get_defined_functions( false );' ), 'Fallback to the global function inside a namespace.' );
	upgw_same( array( 'argument.chr_out_of_range', 'argument.chr_out_of_range' ), $mine( '$a = chr( 256 ); $b = chr( -1 );' ), 'Both ends of the range.' );
};

$tests['8.5-dep: null array offsets that PHP still accepts are not reported'] = static function () use ( $clean, $mine ) {
	$clean( '$a = array( "" => 1, 0 => 2, "null" => 3 ); $b = [ $k => 1 ];', 'Other keys.' );
	$clean( '$a = array( null, null ); $b = [ null ];', 'null as a value, not a key.' );
	$clean( '$v = $a[""] ?? $a[0] ?? $a["null"];', 'Other offsets.' );
	$clean( 'unset( $a[null] );', 'unset() raised nothing on 8.5.' );
	$clean( '$v = $this[null];', 'ArrayAccess on $this.' );
	$clean( '$v = $a[NULL_KEY];', 'A constant, not the literal null.' );
	$clean( '$a[] = 1;', 'Append.' );

	upgw_same( array( 'syntax.null_array_offset' ), $mine( 'list( null => $x ) = $a;' ), 'list() with a null key.' );
	upgw_same( array( 'syntax.null_array_offset' ), $mine( '$v = $a[NULL];' ), 'Upper case NULL.' );
	$clean( '$c = "abc"[null];', 'A string literal: a warning, not this notice.' );
};

$tests['8.5-dep: __debugInfo() that returns an array, and other methods returning null, are not reported'] = static function () use ( $clean ) {
	$clean( 'class A { public function __debugInfo() { return array(); } }', 'Returns an array.' );
	$clean( 'class A { public function __debugInfo() { return $this->data ?? []; } }', 'Returns an expression.' );
	$clean( 'class A { public function __debugInfo() { $f = function () { return null; }; return array(); } }', 'null returned by a nested closure.' );
	$clean( 'class A { public function __debugInfo() { return array_map( fn() => null, [] ); } }', 'null returned by a nested arrow function.' );
	$clean( 'class A { public function __debugInfo() { $o = new class { function f() { return null; } }; return []; } }', 'null returned by a nested class.' );
	$clean( 'class A { public function debugInfo() { return null; } public function __toString() { return ""; } }', 'Other methods.' );
	$clean( 'interface I { public function __debugInfo(); }', 'Declaration without a body.' );
	$clean( 'class A { public function __debugInfo(): array { return null; } }', 'With an array return type null is a TypeError, a different problem.' );
};

$tests['8.5-dep: class constants that are not deprecated are not reported'] = static function () use ( $clean, $guarded ) {
	$clean( 'echo DateTimeInterface::RFC2822, DateTime::ATOM, DateTimeImmutable::RFC3339;', 'Other date formats.' );
	$clean( 'echo My\\DateTime::RFC7231, $date::RFC7231, static::RFC7231;', 'Other classes, objects, static::.' );
	$clean( '$o = array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false );', 'Generic PDO constants.' );
	$clean( '$o = array( Pdo\\Mysql::ATTR_INIT_COMMAND => "SET NAMES utf8mb4" );', 'The new driver class.' );
	$clean( 'namespace App; $o = PDO::MYSQL_ATTR_INIT_COMMAND;', 'A class PDO in a namespace.' );
	$clean( '$o = PDO::MYSQL_ATTR_MAX_BUFFER_SIZE;', 'Not shown on the runtimes here, not reported.' );
	$clean( 'if ( defined( "PDO::MYSQL_ATTR_SSL_CA" ) ) {}', 'The name as a string.' );

	upgw_same(
		array( true ),
		$guarded( "if ( defined( 'Pdo\\Sqlite::ATTR_OPEN_FLAGS' ) ) {\n\t\$o = Pdo\\Sqlite::ATTR_OPEN_FLAGS;\n} elseif ( defined( 'PDO::SQLITE_ATTR_OPEN_FLAGS' ) ) {\n\t\$o = PDO::SQLITE_ATTR_OPEN_FLAGS;\n}", 'constant.class_deprecated' ),
		'The else branch of a check for the new class is guarded.'
	);
	upgw_same(
		array( true ),
		$guarded( "\$o = class_exists( '\\Pdo\\Mysql' ) ? Pdo\\Mysql::ATTR_INIT_COMMAND : PDO::MYSQL_ATTR_INIT_COMMAND;", 'constant.class_deprecated' ),
		'The else side of a ternary on the new class is guarded.'
	);
	upgw_same(
		array( false ),
		$guarded( "if ( defined( 'PDO::MYSQL_ATTR_INIT_COMMAND' ) ) {\n\t\$o = PDO::MYSQL_ATTR_INIT_COMMAND;\n}", 'constant.class_deprecated' ),
		'defined() on the old constant keeps nothing away.'
	);
	upgw_same(
		array( false ),
		$guarded( "if ( class_exists( 'Pdo\\Mysql' ) ) {\n\t\$o = PDO::MYSQL_ATTR_INIT_COMMAND;\n}", 'constant.class_deprecated' ),
		'The then branch of the check is not guarded.'
	);
};

$tests['8.5-dep: methods that only share a name with the deprecated ones are not reported'] = static function () use ( $clean, $mine ) {
	$clean( '$this->setAccessible( true );', 'setAccessible() on $this.' );
	$clean( 'class Field { function setAccessible( $on ) {} } $field->setAccessible( true );', 'A file that declares setAccessible().' );
	$clean( '$field->setAccessible( "admin", true );', 'Two arguments: not the Reflection method.' );
	$clean( '$field->setAccessible();', 'No argument: not the Reflection method.' );
	$clean( '$this->sqliteCreateFunction( "f", "g" );', 'sqliteCreateFunction() on $this.' );
	$clean( '$pdo->createFunction( "f", "g" ); $pdo->sqliteCreate( "x" );', 'The new method, and other names.' );
	$clean( '$subject->attach( $observer ); $subject->detach( $observer ); $list->contains( $x );', 'attach() on an unknown receiver (SplSubject, collections).' );
	$clean( 'function f( $s ) { $s->attach( $o ); }', 'Untyped parameter.' );
	$clean( 'function f( SplSubject $s ) { $s->attach( $o ); }', 'Parameter of another type.' );
	$clean( 'function f() { $s = new SplObjectStorage(); $s = get_storage(); $s->attach( $o ); }', 'Variable reassigned from a call.' );
	$clean( 'function f() { $s = new SplObjectStorage(); foreach ( $list as $s ) {} $s->attach( $o ); }', 'Variable reassigned by foreach.' );
	$clean( 'function f() { $s = new SplObjectStorage(); $g = function () use ( &$s ) { $s = 1; }; $s->attach( $o ); }', 'Variable shared with a closure by reference.' );
	$clean( '$s = new SplObjectStorage(); $s->attach( $o );', 'Code outside functions is not looked at.' );
	$clean( 'function f() { $s = new SplObjectStorage(); $s[ $o ] = 1; return isset( $s[ $o ] ); }', 'Array access, the replacement.' );
	$clean( 'class R { public $items; function __construct() { $this->items = new SplObjectStorage(); } function add( $o ) { $this->items->attach( $o ); } }', 'Public untyped property.' );
	$clean( 'class R { private $items; function __construct() { $this->items = new SplObjectStorage(); } function set( $i ) { $this->items = $i; } function add( $o ) { $this->items->attach( $o ); } }', 'Property assigned something else too.' );
	$clean( 'class S extends SplObjectStorage { public function attach( $o, $i = null ): void {} function f( $o ) { $this->attach( $o ); } }', 'A subclass that overrides attach().' );
	$clean( 'class S extends Foo { function f( $o ) { $this->attach( $o ); parent::detach( $o ); } }', 'A class extending something else.' );
	$clean( 'function f( $text ) { $i = new finfo(); return $i->buffer( $text, FILEINFO_MIME ); }', 'finfo::buffer() without a context.' );
	$clean( '$cache->buffer( $a, $b, $c );', 'buffer() on an unknown receiver.' );

	upgw_same( array( 'call.splobjectstorage_method' ), $mine( 'function f( ?SplObjectStorage $s ) { $s->contains( $o ); }' ), 'Nullable typed parameter.' );
	upgw_same( array( 'call.splobjectstorage_method' ), $mine( 'class S extends \\SplObjectStorage { function f( $o ) { parent::detach( $o ); } }' ), 'parent:: in a subclass.' );
	upgw_same( array( 'call.splobjectstorage_method' ), $mine( 'class R { private SplObjectStorage $items; function add( $o ) { $this->items->attach( $o ); } }' ), 'Typed property.' );
	upgw_same( array( 'call.reflection_set_accessible' ), $mine( '$p = $class->getProperty( "x" ); $p->setAccessible( true );' ), 'setAccessible() by name.' );
};

$tests['8.5-dep: setAccessible() behind a PHP version check is marked as guarded'] = static function () use ( $guarded ) {
	upgw_same( array( true ), $guarded( "if ( PHP_VERSION_ID < 80100 ) {\n\t\$p->setAccessible( true );\n}", 'call.reflection_set_accessible' ) );
	upgw_same( array( false ), $guarded( "if ( method_exists( \$p, 'setAccessible' ) ) {\n\t\$p->setAccessible( true );\n}", 'call.reflection_set_accessible' ) );
};

$tests['8.5-dep: PDO::sqliteCreateFunction() in the else branch of a Pdo\\Sqlite check is marked as guarded'] = static function () use ( $guarded ) {
	upgw_same( array( true ), $guarded( "if ( \$pdo instanceof Pdo\\Sqlite ) {\n\t\$pdo->createFunction( 'f', 'g' );\n} else {\n\t\$pdo->sqliteCreateFunction( 'f', 'g' );\n}", 'call.pdo_driver_method' ) );
	upgw_same( array( true ), $guarded( "if ( method_exists( \$pdo, 'createFunction' ) || class_exists( 'Pdo\\\\Sqlite' ) ) {\n} else {\n\t\$pdo->sqliteCreateCollation( 'c', 'g' );\n}", 'call.pdo_driver_method' ) );
	upgw_same( array( false ), $guarded( "if ( \$pdo instanceof PDO ) {\n\t\$pdo->sqliteCreateFunction( 'f', 'g' );\n}", 'call.pdo_driver_method' ) );
};

$tests['8.5-dep: PDO and ArrayObject constructions that are fine are not reported'] = static function () use ( $clean, $mine ) {
	$clean( '$p = new PDO( "mysql:host=localhost", $u, $w ); $q = new PDO( $dsn ); $r = new PDO( "sqlite:" . $file );', 'Ordinary DSNs.' );
	$clean( '$p = new My\\PDO( "uri:file:///x" );', 'Another class named PDO.' );
	$clean( '$p = new PDO( $prefix . "uri:x" );', 'uri: not at the start of what is written.' );
	$clean( '$p = new PDO( "mysql:host=$host;uri:x" ); $q = new PDO( username: "u", dsn: $dsn );', 'Interpolated or named DSN that does not start with uri:.' );
	upgw_same( array( 'call.pdo_uri_dsn', 'call.pdo_uri_dsn' ), $mine( '$p = new PDO( "uri:file://$path" ); $q = new PDO( dsn: "uri:x" );' ), 'Interpolated and named uri: DSN.' );
	upgw_same( array( 'argument.arrayobject_object' ), $mine( '$a = new ArrayObject( flags: 0, array: new stdClass() );' ), 'Named array argument.' );
	$clean( '$a = new ArrayObject( array( 1 ) ); $b = new ArrayIterator( $this->items ); $c = new ArrayObject( [] ); $d = new ArrayIterator( $list );', 'Arrays and variables.' );
	$clean( '$a = new ArrayObject(); $b = new ArrayIterator( get_object_vars( $this ) );', 'No argument, or an array of the properties.' );
	$clean( '$a = new My\\ArrayObject( new Foo() ); $b = new Collection( $this );', 'Other classes.' );
};

$tests['8.5-dep: $http_response_header uses that PHP accepts are not reported'] = static function () use ( $clean, $mine ) {
	$clean( 'if ( isset( $http_response_header ) ) {}', 'isset() at the top level.' );
	$clean( 'function f() { return isset( $http_response_header[0] ) || empty( $http_response_header ); }', 'isset() and empty(), also on an offset.' );
	$clean( 'function f() { return $http_response_header ?? array(); }', 'Left side of ??.' );
	$clean( 'function f() { $http_response_header = array(); return $http_response_header; }', 'A scope that assigns it is an ordinary variable.' );
	$clean( 'function f() { $headers = http_get_last_response_headers(); return $headers; }', 'The replacement.' );
	$clean( '$f = function () use ( $http_response_header ) { return 1; };', 'Closure use by value raised nothing.' );
	$clean( 'class A { public $http_response_header; function f() { return $this->http_response_header; } }', 'A property of that name.' );
	$clean( 'function f() { return compact( "http_response_header" ); }', 'The name as a string.' );
	$clean( '$http_response_header = array(); echo count( $http_response_header );', 'Top-level code that assigns it.' );
	$clean( 'function my_parse( &$h ) {} function f() { return my_parse( $http_response_header ); }', 'Argument of a function of this file that takes a reference.' );
	$clean( 'function f() { return my_parse( $http_response_header ); }', 'Argument of a function declared in another file.', array( 'my_parse' ) );
	$clean( 'function f() { $http_response_header[] = 1; return $http_response_header; }', 'A scope that writes an offset first.' );
	$clean( 'function f() { list( $http_response_header ) = array( 1 ); return $http_response_header; }', 'list() into it first.' );
	$clean( 'function f( $http_response_header ) { return $http_response_header; } $g = fn( $http_response_header ) => $http_response_header;', 'A parameter of that name.' );
	$clean( 'function f() { $http_response_header++; unset( $http_response_header ); }', '++ and unset() raised nothing.' );
	$clean( 'function f() { preg_match( "/x/", $s, $http_response_header ); return $http_response_header; }', 'Passed by reference to an internal function.' );

	upgw_same( array( 'variable.http_response_header', 'variable.http_response_header' ), $mine( 'function f() { return is_array( $http_response_header ) ? count( $http_response_header ) : 0; }' ), 'Arguments of internal functions that take them by value.' );
	upgw_same( array( 'variable.http_response_header' ), $mine( 'function f() { $h = $http_response_header; return $h; }' ), 'Plain read.' );
	upgw_same( array( 'variable.http_response_header' ), $mine( 'function f() { foreach ( $http_response_header as $line ) {} }' ), 'foreach over it.' );
	upgw_same( array( 'variable.http_response_header' ), $mine( 'class A { function f() { file_get_contents( "x" ); return $this->parse( $http_response_header ); } }' ), 'Argument of a method.' );
	upgw_same( array( 'variable.http_response_header' ), $mine( 'function f() { return my_parse( $http_response_header ); }' ), 'Argument of a function PHP does not know when it compiles the call.' );
	upgw_same( array( 'variable.http_response_header' ), $mine( 'function f() { $h = $http_response_header; $http_response_header = null; return $h; }' ), 'A read before the scope assigns it.' );
	upgw_same( array( 'variable.http_response_header' ), $mine( 'function f() { $http_response_header = 1; return fn() => $http_response_header; }' ), 'An arrow function has a scope of its own.' );
	upgw_same( array( 'variable.http_response_header', 'variable.http_response_header' ), $mine( 'function f() { foreach ( array( 1 ) as $http_response_header ) {} return $http_response_header; }' ), 'foreach into it raises and does not count as an assignment.' );
};

return $tests;
