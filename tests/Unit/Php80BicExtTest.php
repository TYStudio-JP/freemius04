<?php
/**
 * PHP 8.0, Backward Incompatible Changes, the extensions (tranche
 * 8.0-bic-ext): code that looks like what the rules of this tranche report
 * and is fine, so must stay unreported.
 *
 * Only the rule ids of this tranche are looked at, so that a rule somebody
 * else adds for the same code does not make these tests fail.
 *
 * @package UpgradeWarden
 */

$tests = array();

$mine80ext = static function ( $code ) {
	$ids = array(
		'call.is_resource_on_object',
		'call.mktime_without_argument',
		'call.parse_str_without_result',
		'argument.imagecropauto_minus_one',
		'argument.needle_not_string',
		'argument.empty_needle',
		'call.mb_decode_numericentity_is_hex',
		'argument.mb_strrpos_encoding',
		'argument.mb_iso8859_alias',
		'call.openssl_seal_without_method',
		'call.assert_string',
		'argument.strip_tags_filter',
		'argument.length_null',
		'argument.vsprintf_non_array',
		'argument.password_hash_salt',
		'call.implode_reversed',
		'call.crypt_without_salt',
		'call.reflection_export',
		'call.splfileobject_fgetss',
		'call.splfixedarray_iterator_method',
		'class.internal_variadic_signature',
		'class.splheap_compare_signature',
		'class.xmlreader_static_method',
		'class.dom_removed_class',
		'constant.ziparchive_opsys_z_cpm',
		'argument.pcre_invalid_escape',
		'callable.sort_bool_return',
	);

	$found = array();

	foreach ( upgw_scanner()->scan( "<?php\n" . $code )->findings as $finding ) {
		if ( in_array( $finding->rule, $ids, true ) ) {
			$found[] = $finding->rule;
		}
	}

	return $found;
};

$clean80ext = static function ( $code, $message ) use ( $mine80ext ) {
	upgw_same( array(), $mine80ext( $code ), $message . "\n      code: " . $code );
};

$tests['8.0-bic-ext: is_resource() on values that are not followed is not reported'] = static function () use ( $clean80ext, $mine80ext ) {
	$clean80ext( '$im = imagecreatetruecolor( 1, 1 ); echo is_resource( $im );', 'Outside a function.' );
	$clean80ext( 'function f( $im ) { return is_resource( $im ); }', 'A parameter.' );
	$clean80ext( 'function f() { $f = fopen( "php://memory", "r" ); return is_resource( $f ); }', 'fopen() still returns a resource.' );
	$clean80ext( 'function f() { return is_resource( curl_init() ); }', 'Not a function of this tranche.' );
	$clean80ext( 'function f( $x ) { $im = imagecreatetruecolor( 1, 1 ); if ( $x ) { $im = $x; } return is_resource( $im ); }', 'Assigned something else too.' );
	$clean80ext( 'function f() { if ( is_resource( $im ) ) {} $im = imagecreatetruecolor( 1, 1 ); }', 'Checked before it is assigned.' );
	$clean80ext( 'function f() { $im = imagecreatetruecolor( 1, 1 ); my_filter( $im ); return is_resource( $im ); }', 'Passed to an unknown function, which may take it by reference.' );
	$clean80ext( 'function f() { $p = xml_parser_create(); xml_parse_into_struct( $x, "<a/>", $p ); return is_resource( $p ); }', 'Written by xml_parse_into_struct().' );
	$clean80ext( 'function f() { $k = openssl_pkey_new(); openssl_seal( "d", $k, $e, array(), "AES-128-CBC", $iv ); return is_resource( $k ); }', 'Written by openssl_seal().' );
	$clean80ext( 'function f() { $c = openssl_csr_new( array(), $k ); $c = $c ?: imagecreate( 1, 1 ); return is_resource( $c ); }', 'Assigned a mixed expression.' );
	$clean80ext( 'function f() { $im = imagecreatetruecolor( 1, 1 ); return function () use ( &$im ) { return is_resource( $im ); }; }', 'A closure by reference.' );
	$clean80ext( 'function is_resource( $x ) { return true; } function f() { return is_resource( imagecreate( 1, 1 ) ); }', 'is_resource() declared by the code.' );
	$clean80ext( 'function f() { return is_resource( $this->image ); }', 'A property.' );
	upgw_same( array( 'call.is_resource_on_object' ), $mine80ext( 'function f() { $im = @imagecreatefrompng( "a.png" ); imagecopy( $dst, $im, 0, 0, 0, 0, 1, 1 ); return is_resource( $im ); }' ), 'Given to a GD function: still the image.' );
};

$tests['8.0-bic-ext: calls with arguments that are fine are not reported'] = static function () use ( $clean80ext ) {
	$clean80ext( 'echo mktime( 0, 0, 0, 1, 1, 2020 ), gmmktime( 1 );', 'mktime() with arguments.' );
	$clean80ext( '$f = mktime( ... );', 'A first-class callable.' );
	$clean80ext( 'parse_str( $q, $out ); mb_parse_str( $q, $out ); parse_str( ...$args );', 'With a result, or unpacked.' );
	$clean80ext( 'imagecropauto( $im, IMG_CROP_DEFAULT ); imagecropauto( $im, -2 ); imagecropauto( $im, $mode );', 'Other crop modes.' );
	$clean80ext( 'strpos( $s, "7" ); strpos( $s, $n ); strpos( 7, "a" ); substr_count( $s, 7 ); strpos( $s, "a", 7 );', 'String needles, int elsewhere.' );
	$clean80ext( 'strrchr( $s, "" ); strpos( "", "a" ); explode( "", $s ); str_replace( "", "x", $s );', 'Empty strings that did not change.' );
	$clean80ext( 'mb_decode_numericentity( $s, $map, "UTF-8" ); mb_encode_numericentity( $s, $map, "UTF-8", true );', 'Three arguments; the encode function keeps $hex.' );
	$clean80ext( 'mb_strrpos( $s, "b", 0, "UTF-8" ); mb_strrpos( $s, "b", "2" ); mb_strrpos( $s, "b", $enc ); strrpos( $s, "b", "x" );', 'Offset, numeric string, variable, other function.' );
	$clean80ext( 'mb_convert_encoding( $s, "ISO-8859-1", "UTF-8" ); mb_strlen( $s, "ISO8859-15" ); iconv( "ISO_8859-1", "UTF-8", $s ); mb_regex_encoding( "ISO8859_1" );', 'Names that still work, other functions, mb_regex_encoding() left to the catalog.' );
	$clean80ext( 'openssl_seal( $d, $s, $e, $k, "AES-128-CBC", $iv ); openssl_open( $d, $o, $e, $k, "AES-128-CBC", $iv );', 'With a method.' );
	$clean80ext( 'assert( $a == $b ); assert( is_string( $s ), "must be a string" ); assert( "$a" );', 'Expressions, a description string, an interpolated string.' );
	$clean80ext( 'stream_filter_append( $f, "string.rot13" ); stream_filter_append( $f, "convert.base64-encode" ); fopen( "php://filter/read=string.toupper/resource=x", "r" ); echo "string.strip_tags";', 'Other filters, and the name outside a call.' );
	$clean80ext( 'substr( $s, 1 ); substr( $s, 1, $len ); substr_compare( $a, $b, 1, null ); array_slice( $a, 1, null ); mb_substr( $s, 1, null );', 'Length left out, a variable, or functions where null was already fine.' );
	$clean80ext( 'vsprintf( "%s", array( "x" ) ); vsprintf( "%s", $args ); vprintf( "%s", $args ); sprintf( "%s", "x" );', 'Arrays, variables, sprintf().' );
	$clean80ext( 'password_hash( $p, PASSWORD_DEFAULT, array( "cost" => 12 ) ); password_hash( $p, PASSWORD_DEFAULT, $options ); hash_hmac( "sha256", $d, array( "salt" => 1 ) );', 'Other options.' );
	$clean80ext( 'implode( ", ", $parts ); implode( $glue, $parts ); implode( $parts ); join( "|", array( 1 ) ); implode( "a", "b" ); implode( array( 1 ), array( 2 ) );', 'Glue first, or not decidable.' );
	$clean80ext( 'crypt( $p, $salt ); hash( "sha256", $p );', 'crypt() with a salt.' );
};

$tests['8.0-bic-ext: methods that still exist are not reported'] = static function () use ( $clean80ext ) {
	$clean80ext( 'echo new ReflectionClass( "A" ); Plugin_Exporter::export( "x" ); $r->export(); ReflectionClass::getName();', 'Casting, another class, an instance call.' );
	$clean80ext( 'namespace App; class ReflectionClass { static function export() {} } ReflectionClass::export();', 'A class of the same name in a namespace.' );
	$clean80ext( 'class Reader { function fgetss() { return 1; } function read() { return $this->fgetss(); } }', 'A class with its own fgetss().' );
	$clean80ext( '$file->fgets(); fgetss_wrapper( $f );', 'Other names.' );
	$clean80ext( 'function f() { $a = new SplFixedArray( 3 ); foreach ( $a as $v ) {} $i = $a->getIterator(); $i->rewind(); return $a->count(); }', 'getIterator() and its iterator.' );
	$clean80ext( 'function f( $a ) { $a->rewind(); $b = new ArrayIterator( array() ); $b->rewind(); }', 'Unknown objects and other iterators.' );
	$clean80ext( 'function f( $x ) { $a = new SplFixedArray( 3 ); $a = $x; $a->rewind(); }', 'Reassigned.' );
	$clean80ext( '$a = new SplFixedArray( 3 ); $a->rewind();', 'Outside a function: not followed.' );
};

$tests['8.0-bic-ext: overrides that are compatible are not reported'] = static function () use ( $clean80ext ) {
	$clean80ext( 'class P extends PDO { public function query( $q, $m = null, ...$args ) {} public function prepare( $q, $o = array() ) {} }', 'Variadic query(), other methods.' );
	$clean80ext( 'class S extends PDOStatement { public function setFetchMode( $mode, ...$args ) {} }', 'Variadic setFetchMode().' );
	$clean80ext( 'class R extends ReflectionMethod { public function invoke( $o = null, ...$args ) {} public function invokeArgs( $o = null, array $a = array() ) {} }', 'Variadic invoke(), invokeArgs().' );
	$clean80ext( 'class Db { public function query( $sql ) {} } class Q extends Base_Queue { public function compare( int $a, int $b ) {} }', 'Not the internal parents.' );
	$clean80ext( 'namespace App; class PDO {} class P extends PDO { public function query( $sql ) {} }', 'A PDO class of the namespace.' );
	$clean80ext( 'class H extends SplHeap { protected function compare( $a, $b ) { return 0; } } class Q extends SplPriorityQueue { public function compare( mixed $a, mixed $b ): int { return 0; } }', 'Untyped or mixed compare().' );
	$clean80ext( 'class H extends SplMinHeap { protected function compare( int $a, int $b ) { return 0; } } class H2 extends SplHeap { protected function compare( $a, $b, $c = 1 ) { return 0; } }', 'SplMinHeap (already an error on 7.4), an optional third parameter.' );
	$clean80ext( 'class X extends XMLReader { public static function open( $u, $e = null, $o = 0 ) {} public function read() {} }', 'Static open(), other methods.' );
};

$tests['8.0-bic-ext: DOM and Zip names that still exist are not reported'] = static function () use ( $clean80ext ) {
	$clean80ext( '$d = new DOMDocument(); $i = new DOMImplementation(); class E extends DOMException {}', 'Classes that exist.' );
	$clean80ext( 'if ( $x instanceof DOMConfiguration ) {} function f( DOMLocator $l ) {} try {} catch ( DOMNameList $e ) {}', 'instanceof, types and catch do not fail.' );
	$clean80ext( 'namespace App; $c = new DOMConfiguration();', 'A class of the namespace.' );
	$clean80ext( 'new DomError();', 'DomError, which 7.4 did not have either.' );
	$clean80ext( 'echo ZipArchive::OPSYS_CPM, ZipArchive::OPSYS_UNIX, Other::OPSYS_Z_CPM;', 'Other constants and classes.' );
};

$tests['8.0-bic-ext: patterns PCRE still accepts are not reported'] = static function () use ( $clean80ext, $mine80ext ) {
	$clean80ext( 'preg_match( "/\\\\d+\\\\s\\\\w\\\\b\\\\-\\\\.\\\\//", $s );', 'Known escapes and escaped punctuation.' );
	$clean80ext( 'preg_match( "/\\\\\\\\i/", $s );', 'An escaped backslash before i.' );
	$clean80ext( 'preg_match( "/\\\\cI\\\\p{L}\\\\k<n>\\\\g1\\\\x{41}/u", $s );', 'Escapes followed by more.' );
	$clean80ext( 'preg_match( "/a # \\\\i comment/x", $s ); preg_match( "/(?x) \\\\i/", $s ); preg_match( "/(?# \\\\i )a/", $s );', 'Extended mode and comments.' );
	$clean80ext( 'preg_match( "/\\\\Q\\\\i\\\\E/", $s );', 'Quoted span.' );
	$clean80ext( 'preg_match( $pattern, $s ); str_replace( "\\\\i", "", $s ); preg_quote( "\\\\i" );', 'Variables and other functions.' );
	$clean80ext( 'preg_replace( "~[\\\\w\\\\-]+~", "", $s ); preg_match( "{\\\\d}", $s );', 'Other delimiters.' );
	upgw_same( array( 'argument.pcre_invalid_escape' ), $mine80ext( 'preg_replace_callback_array( array( "/\\\\y/" => "f", "/a/" => "g" ), $s );' ), 'Keys of preg_replace_callback_array().' );
};

$tests['8.0-bic-ext: sort callbacks that return numbers are not reported'] = static function () use ( $clean80ext ) {
	$clean80ext( 'usort( $a, function ( $x, $y ) { return $x <=> $y; } ); usort( $a, fn( $x, $y ) => $x - $y );', 'Spaceship and subtraction.' );
	$clean80ext( 'usort( $a, function ( $x, $y ) { if ( $x == $y ) { return 0; } return $x > $y ? 1 : -1; } );', 'Comparisons inside, numbers returned.' );
	$clean80ext( 'usort( $a, "strcmp" ); usort( $a, array( $this, "cmp" ) ); array_filter( $a, fn( $x ) => $x > 1 );', 'Named callbacks and other functions.' );
	$clean80ext( 'usort( $a, function ( $x, $y ) { $f = function () { return 1 > 2; }; return $x - $y; } );', 'A comparison returned by an inner closure.' );
	$clean80ext( 'usort( $a, function ( $x, $y ) { $x > $y; } );', 'No return at all.' );
};

$tests['8.0-bic-ext: is_resource() next to a check for the PHP 8 object is not reported'] = static function () use ( $clean80ext, $mine80ext ) {
	$clean80ext( 'function a( $w ) { $im = imagecreatetruecolor( $w, $w ); if ( ! is_resource( $im ) && ! $im instanceof \\GdImage ) { return false; } return $im; }', 'instanceof GdImage in the same condition.' );
	$clean80ext( 'function a( $w ) { $im = imagecreatetruecolor( $w, $w ); if ( is_resource( $im ) || is_object( $im ) ) { imagedestroy( $im ); } }', 'is_object() in the same condition.' );
	$clean80ext( 'function a( $w ) { $im = imagecreatetruecolor( $w, $w ); if ( is_resource( $im ) ) { return 1; } elseif ( $im instanceof GdImage ) { return 2; } }', 'instanceof in an elseif of the same if.' );
	$clean80ext( 'function a() { $p = xml_parser_create(); return is_resource( $p ) || false !== $p; }', 'Comparison with false in the same expression.' );
	upgw_same( array( 'call.is_resource_on_object' ), $mine80ext( 'function a( $w ) { $im = imagecreatetruecolor( $w, $w ); if ( ! is_resource( $im ) || $w > 2 ) { return; } }' ), 'Another condition on something else is still reported.' );
};

$tests['8.0-bic-ext: a compare() that accepts two values through a variadic is not reported'] = static function () use ( $clean80ext ) {
	$clean80ext( 'class H extends SplHeap { protected function compare( ...$values ): int { return $values[0] <=> $values[1]; } }', 'Only a variadic.' );
	$clean80ext( 'class H extends SplHeap { protected function compare( $a, ...$rest ): int { return $a <=> $rest[0]; } }', 'One parameter and a variadic.' );
};

return $tests;
