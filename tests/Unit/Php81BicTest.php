<?php
/**
 * PHP 8.1 backward incompatible changes (tranche 8.1-bic): code that looks
 * like an affected use and is fine, and so must stay unreported.
 *
 * What the rules do report is checked by FixturesTest against the fixtures
 * named *~8.1-bic*. Only the rule ids of this tranche are looked at, so that
 * a rule somebody else adds for the same code does not make these tests fail.
 *
 * @package UpgradeWarden
 */

$tests = array();

$bic81_mine = static function ( $code ) {
	$ids = array(
		'syntax.globals_whole_write',
		'argument.null_to_internal_scalar',
		'signature.tentative_return_type_missing',
		'class.mysqli_stmt_execute_signature',
		'call.is_resource_finfo',
		'argument.version_compare_operator',
		'argument.html_default_flags',
		'argument.openssl_pkcs7_default_cipher',
	);

	$found = array();

	foreach ( upgw_scanner()->scan( "<?php\n" . $code )->findings as $finding ) {
		if ( in_array( $finding->rule, $ids, true ) ) {
			$found[] = $finding->rule;
		}
	}

	return $found;
};

$bic81_clean = static function ( $code, $message ) use ( $bic81_mine ) {
	upgw_same( array(), $bic81_mine( $code ), $message . "\n      code: " . $code );
};

$tests['8.1-bic: element access to $GLOBALS, reading it whole and iterating over it are not reported'] = static function () use ( $bic81_clean, $bic81_mine ) {
	$bic81_clean( '$GLOBALS["a"] = 1; $GLOBALS["a"]["b"][] = 2; $GLOBALS["a"] .= "x";', 'Element writes.' );
	$bic81_clean( 'unset( $GLOBALS["a"], $GLOBALS["a"]["b"] );', 'Element unset.' );
	$bic81_clean( '$copy = $GLOBALS; $n = count( $GLOBALS ); $keys = array_keys( $GLOBALS ); extract( $GLOBALS );', 'Reads, and extract() (prefer-ref).' );
	$bic81_clean( 'foreach ( $GLOBALS as $k => &$v ) {} foreach ( $GLOBALS as $v ) {}', 'foreach, even by reference: 8.1 runs it.' );
	$bic81_clean( 'echo key( $GLOBALS ), current( $GLOBALS ); var_dump( isset( $GLOBALS ) );', 'Functions that take the array by value.' );
	$bic81_clean( '$x = &$GLOBALS["a"]; $GLOBALS["b"] = &$y;', 'References to elements.' );
	$bic81_clean( '$globals = array(); $globals = $GLOBALS; $this->GLOBALS = 1; $Globals = 2; unset( $globals );', 'Other variables and properties.' );
	$bic81_clean( 'function end( &$a ) {} end( $GLOBALS );', 'A function of the same name declared in the file.' );
	$bic81_clean( 'my_helper( $GLOBALS ); $object->sort( $GLOBALS ); preg_match( "/x/", "x", $GLOBALS["m"] );', 'User functions, methods, and an element passed by reference.' );

	upgw_same( array( 'syntax.globals_whole_write' ), $bic81_mine( 'namespace App; sort( $GLOBALS );' ), 'A namespaced call falls back to the global sort().' );
	upgw_same( array( 'syntax.globals_whole_write' ), $bic81_mine( 'preg_match( "/x/", "x", $GLOBALS );' ), 'By-reference parameter that is not the first.' );
};

$tests['8.1-bic: $GLOBALS by reference to an internal function is guarded by a version check'] = static function () {
	$findings = upgw_scanner()->scan( "<?php\nif ( PHP_VERSION_ID < 80100 ) {\n\tarray_pop( \$GLOBALS );\n}" )->findings;
	upgw_same( 1, count( $findings ) );
	upgw_true( $findings[0]->guarded, 'Runs only on old PHP.' );

	// A compile error is raised whatever the "if" says.
	$findings = upgw_scanner()->scan( "<?php\nif ( PHP_VERSION_ID < 80100 ) {\n\t\$GLOBALS = array();\n}" )->findings;
	upgw_same( 1, count( $findings ) );
	upgw_true( ! $findings[0]->guarded, 'Compile errors are never guarded.' );
};

$tests['8.1-bic: null that is accepted, or not literal, is not reported'] = static function () use ( $bic81_clean ) {
	$bic81_clean( '$v = null; echo strlen( $v ), strlen( NULL_VALUE ), strlen( "null" ), strlen( Foo::null );', 'Not a literal null.' );
	$bic81_clean( 'echo mb_strlen( "x", null ), mb_substr( "abc", 0, null ), array_slice( array( 1 ), 0, null ), json_encode( null );', 'Nullable or mixed parameters.' );
	$bic81_clean( 'var_dump( is_null( null ), isset( $a ), array_key_exists( null, array() ), ctype_digit( null ), mb_check_encoding( null ) );', 'Parameters typed mixed, or rules of other tranches.' );
	$bic81_clean( 'echo implode( ",", null ), array_sum( null ), count( null );', 'Array parameters: null was never accepted there.' );
	$bic81_clean( "declare( strict_types = 1 );\necho strlen( null );", 'strict_types: a TypeError on every version (runtime.null_to_internal_strict_types).' );
	$bic81_clean( 'function strlen( $s ) { return 0; } echo strlen( null );', 'A function of the same name in the file.' );
	$bic81_clean( 'echo App\strlen( null ), $object->strlen( null ), Text::trim( null ), my_trim( null );', 'Namespaced functions, methods, user functions.' );
	$bic81_clean( '$args = array( null ); echo trim( ...$args ), trim( ...$args, characters: null );', 'Unpacked arguments.' );
	$bic81_clean( '$f = strlen( ... );', 'First-class callable.' );
};

$tests['8.1-bic: overrides that declare a return type or carry the attribute are not reported'] = static function () use ( $bic81_clean, $bic81_mine ) {
	$bic81_clean( 'class A implements Countable { public function count(): int { return 0; } }', 'Return type declared.' );
	$bic81_clean( 'class A implements Countable { #[\ReturnTypeWillChange] public function count() { return 0; } }', 'Attribute, fully qualified.' );
	$bic81_clean( "namespace App;\nuse ReturnTypeWillChange;\nclass A implements \\Countable { #[ReturnTypeWillChange] public function count() { return 0; } }", 'Attribute, imported.' );
	$bic81_clean( 'class A implements Countable { public function count(): string { return ""; } }', 'An incompatible return type is not judged.' );
	$bic81_clean( 'class A extends Some_Collection { public function count() { return 0; } public function getIterator() {} }', 'A user parent class: its methods are not known.' );
	$bic81_clean( "namespace App;\nclass A implements Countable { public function count() { return 0; } }", 'App\\Countable is not the internal interface.' );
	$bic81_clean( 'class A implements Countable { use Counts; public function size() { return 0; } }', 'The method comes from a trait.' );
	$bic81_clean( 'class A implements Countable { public function __construct() {} public static function create() {} private function helper() {} }', 'Methods that are not on Countable.' );
	$bic81_clean( 'class A { public function count() { return 0; } public function offsetGet( $o ) {} }', 'No internal parent or interface.' );
	$bic81_clean( 'class A implements Serializable { public function serialize() {} public function unserialize( $d ) {} public function __serialize(): array { return array(); } public function __unserialize( array $d ): void {} }', 'Serializable has no tentative return types.' );
	$bic81_clean( 'class E extends Exception { public function __toString() { return ""; } public function report() {} }', 'Exception methods without a tentative return type.' );
	upgw_same( array( 'class.mysqli_stmt_execute_signature' ), $bic81_mine( 'class S extends mysqli_stmt { public function execute() { return true; } }' ), 'mysqli_stmt::execute() without parameters: reported by the signature rule only, not twice.' );
};

$tests['8.1-bic: the tentative return type fix keeps a doc comment and indentation'] = static function () {
	$code  = "<?php\nclass A implements Countable {\n\t/** Doc. */\n\tpublic function count() { return 0; }\n}\n";
	$fixed = upgw_fixer()->fix( $code );

	upgw_same( "<?php\nclass A implements Countable {\n\t/** Doc. */\n\t#[\\ReturnTypeWillChange]\n\tpublic function count() { return 0; }\n}\n", $fixed['code'] );
};

$tests['8.1-bic: compatible mysqli_stmt::execute() overrides are not reported'] = static function () use ( $bic81_clean ) {
	$bic81_clean( 'class S extends mysqli_stmt { #[\ReturnTypeWillChange] public function execute( $params = null ) { return true; } }', 'Optional parameter.' );
	$bic81_clean( 'class S extends mysqli_stmt { public function execute( ?array $params = null ): bool { return true; } }', 'The 8.1 signature.' );
	$bic81_clean( 'class S extends My_Statement { public function execute(): bool { return true; } }', 'A user parent class.' );
	$bic81_clean( 'class S extends mysqli { public function query( $q, $m = MYSQLI_STORE_RESULT ): bool { return true; } public function execute(): bool { return true; } }', 'mysqli, not mysqli_stmt.' );
	$bic81_clean( 'class S extends PDOStatement { public function execute( $params = null ): bool { return true; } }', 'PDOStatement.' );
};

$tests['8.1-bic: is_resource() on other values is not reported'] = static function () use ( $bic81_clean ) {
	$bic81_clean( 'function f() { $h = fopen( "php://memory", "r" ); return is_resource( $h ); }', 'A real resource.' );
	$bic81_clean( 'function f() { $f = finfo_open(); return false !== $f; }', 'The recommended check.' );
	$bic81_clean( 'function f( $f ) { $f = finfo_open(); return is_resource( $f ); }', 'A parameter of that name.' );
	$bic81_clean( 'function f() { $f = finfo_open(); if ( ! $f ) { $f = fopen( "x", "r" ); } return is_resource( $f ); }', 'Assigned something else as well.' );
	$bic81_clean( 'function f() { if ( is_resource( $f ) ) {} $f = finfo_open(); }', 'Checked before it is assigned.' );
	$bic81_clean( 'function f() { $f = finfo_open(); helper( $f ); return is_resource( $f ); }', 'Passed to a function that may take it by reference.' );
	$bic81_clean( 'function f() { $f = finfo_open(); $g = function () use ( &$f ) { $f = 1; }; return is_resource( $f ); }', 'A closure takes it by reference.' );
	$bic81_clean( 'function f() { $f = finfo_open(); foreach ( $list as $f ) {} return is_resource( $f ); }', 'foreach writes it.' );
	$bic81_clean( 'function f() { $f = finfo_open(); extract( $_GET ); return is_resource( $f ); }', 'extract() may write it.' );
	$bic81_clean( 'function f() { $f = finfo_open(); return function () { return is_resource( $f ); }; }', 'Another function: $f is not the same variable.' );
	$bic81_clean( '$f = finfo_open(); var_dump( is_resource( $f ) );', 'Top of the file: not followed (runtime.is_resource_finfo_elsewhere).' );
	$bic81_clean( 'function f() { $this->f = finfo_open(); return is_resource( $this->f ); }', 'A property: not followed.' );
	$bic81_clean( 'function finfo_open() { return fopen( "x", "r" ); } var_dump( is_resource( finfo_open() ) );', 'A finfo_open() declared in the file.' );
	$bic81_clean( 'var_dump( is_resource( App\finfo_open() ), is_resource( $x->finfo_open() ) );', 'Other functions and methods of that name.' );
};

$tests['8.1-bic: version_compare() with valid operators is not reported'] = static function () use ( $bic81_clean ) {
	$bic81_clean( 'version_compare( PHP_VERSION, "8.0", "lt" ); version_compare( PHP_VERSION, "8.0", "<" ); version_compare( PHP_VERSION, "8.0", "ge" );', 'Valid operators.' );
	$bic81_clean( 'version_compare( PHP_VERSION, "8.0" ); version_compare( PHP_VERSION, "l", ">=" ); version_compare( $op, "1", $l );', 'No operator, l as a version, variable operator.' );
	$bic81_clean( 'version_compare( PHP_VERSION, "8.0", "LT" ); version_compare( PHP_VERSION, "8.0", "L" );', 'Upper case was refused before 8.1 too (8.0 ValueError, 7.4 null).' );
	$bic81_clean( '$x->version_compare( 1, 2, "l" ); App\version_compare( 1, 2, "l" );', 'Methods and namespaced functions.' );
};

$tests['8.1-bic: HTML functions with flags, or text that does not change, are not reported'] = static function () use ( $bic81_clean ) {
	$bic81_clean( 'echo htmlspecialchars( "it\'s", ENT_QUOTES ), htmlentities( "it\'s", ENT_COMPAT, "UTF-8" ), htmlspecialchars( "it\'s", flags: ENT_QUOTES );', 'Explicit flags.' );
	$bic81_clean( 'echo htmlspecialchars( "plain <b>" ), htmlentities( "caf\u{e9}" ), htmlspecialchars( $text ), htmlspecialchars( "It" . $s );', 'No quote in a literal, or no literal.' );
	$bic81_clean( 'echo htmlspecialchars_decode( "&quot;&amp;" ), html_entity_decode( "&eacute;&#34;" ), html_entity_decode( $s );', 'Decoding text without &#039;.' );
	$bic81_clean( 'print_r( get_html_translation_table( HTML_SPECIALCHARS, ENT_QUOTES ) );', 'Translation table with flags.' );
};

$tests['8.1-bic: openssl_pkcs7_encrypt() with a cipher, and other functions, are not reported'] = static function () use ( $bic81_clean ) {
	$bic81_clean( 'openssl_pkcs7_encrypt( $in, $out, $cert, array(), 0, OPENSSL_CIPHER_AES_256_CBC );', 'Cipher given.' );
	$bic81_clean( 'openssl_pkcs7_encrypt( $in, $out, $cert, array(), cipher_algo: OPENSSL_CIPHER_AES_256_CBC );', 'Cipher given by name.' );
	$bic81_clean( 'openssl_pkcs7_encrypt( ...$args ); openssl_pkcs7_sign( $in, $out, $cert, $key, array() );', 'Unpacked arguments; signing.' );
};

$tests['8.1-bic: $GLOBALS read inside an array, destructured from, or with an element incremented is not reported'] = static function () use ( $bic81_clean, $bic81_mine ) {
	$bic81_clean( '$all = array( $GLOBALS ); $two = [ $GLOBALS, 1 ]; [ $a, $b ] = [ $GLOBALS, 1 ]; list( $c ) = $GLOBALS;', 'Arrays that only read $GLOBALS.' );
	$bic81_clean( '$GLOBALS["n"]++; --$GLOBALS["n"]; foreach ( $GLOBALS as $GLOBALS_key => $value ) {} [ $GLOBALS["a"] ] = array( 1 );', 'Element increments, element destructuring.' );
	$bic81_clean( 'foreach ( $list as list( $GLOBALS["a"], $b ) ) {}', 'Element as a foreach destructuring target.' );

	upgw_same( array( 'syntax.globals_whole_write' ), $bic81_mine( 'function f( $v ) { [ [ $GLOBALS ] ] = $v; }' ), 'Nested destructuring target.' );
	upgw_same( array( 'syntax.globals_whole_write' ), $bic81_mine( 'function f( $v ) { foreach ( $v as $GLOBALS => $x ) {} }' ), 'foreach key target.' );
	upgw_same( array( 'syntax.globals_whole_write' ), $bic81_mine( 'function f( $v ) { foreach ( $v as [ $GLOBALS ] ) {} }' ), 'foreach destructuring target.' );
	upgw_same( array( 'syntax.globals_whole_write' ), $bic81_mine( 'function f() { --$GLOBALS; }' ), 'Decrement.' );
};

$tests['8.1-bic: is_resource() of a handle that is not only finfo_open() is not reported'] = static function () use ( $bic81_clean ) {
	$bic81_clean( 'function f( $p ) { $h = @fopen( $p, "r" ); return is_resource( $h ); }', 'A suppressed fopen().' );
	$bic81_clean( 'function f( $p ) { $h = @finfo_open(); $h = @fopen( $p, "r" ); return is_resource( $h ); }', 'Reassigned to something else.' );
};

return $tests;
