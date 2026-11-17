<?php
/**
 * Tranche 8.1-dep (PHP 8.1, "Deprecated Functionality"): code that looks like
 * what the Php81Dep* rules report and must stay unreported.
 *
 * What the rules do report is checked by FixturesTest against the fixtures
 * named *~8.1-dep*. Where a comment says "runtime", a fixture of that name
 * shows on real PHP that the code below raises nothing.
 *
 * @package UpgradeWarden
 */

$tests = array();

$clean = static function ( $code, $message ) {
	upgw_same( array(), upgw_rules_in( "<?php\n" . $code ), $message );
};

$tests['8.1-dep: a Serializable class with the new methods, or one that may inherit them, is not reported'] = static function () use ( $clean ) {
	$old = 'public function serialize() { return ""; } public function unserialize( $data ) {}';
	$new = 'public function __serialize(): array { return array(); } public function __unserialize( array $data ): void {}';

	$clean( "class A implements Serializable { {$old} {$new} }", 'Both new methods.' );
	$clean( "class A implements Serializable { {$old} public function __SERIALIZE() { return array(); } public function __Unserialize( \$data ) {} }", 'Method names in another case.' );
	// runtime.serializable_methods_from_elsewhere.
	$clean( "abstract class A implements Serializable { {$old} }", 'Abstract class.' );
	$clean( "class A extends Base implements Serializable { {$old} }", 'The parent may have the new methods.' );
	$clean( "class A implements Serializable { use New_Serialization; {$old} }", 'A trait may have the new methods.' );
	$clean( "interface Storable extends Serializable {}", 'An interface raises nothing.' );
	$clean( "class A implements JsonSerializable { public function jsonSerialize() { return 1; } }", 'JsonSerializable.' );
	$clean( "namespace App;\nclass A implements Serializable { {$old} }", 'App\\Serializable is another interface.' );
	$clean( "namespace App;\nuse Vendor\\Serializable;\nclass A implements Serializable { {$old} }", 'Imported interface of the same name.' );

	upgw_same( array( 'class.serializable_only' ), upgw_rules_in( "<?php\nnamespace App;\nclass A implements \\Serializable { {$old} }" ), 'Fully qualified in a namespace.' );
	upgw_same( array( 'class.serializable_only' ), upgw_rules_in( "<?php\nnamespace App;\nuse Serializable;\nclass A implements Serializable { {$old} }" ), 'Imported from the global namespace.' );
};

$tests['8.1-dep: a Serializable class declared only on old PHP is marked as guarded'] = static function () {
	$class = 'class A implements Serializable { public function serialize() { return ""; } public function unserialize( $data ) {} }';

	$guarded = upgw_scanner()->scan( "<?php\nif ( PHP_VERSION_ID < 80100 ) {\n\t{$class}\n}" )->findings;
	upgw_same( 1, count( $guarded ) );
	upgw_true( $guarded[0]->guarded, 'Declared inside a version check.' );

	// Without the "if" the class is declared when the file is compiled.
	$plain = upgw_scanner()->scan( "<?php\n{$class}" )->findings;
	upgw_true( ! $plain[0]->guarded, 'Declared at the top of the file.' );
};

$tests['8.1-dep: floats that keep their value as an integer, and floats in other places, are not reported'] = static function () use ( $clean ) {
	$clean( '$a = array( 2.0 => "x", 1e3 => "y" );', 'Whole floats as keys.' );
	$clean( '$a = array( "1.5" => "x", 1 => 1.5, 3 => 2.5 );', 'A string key, and floats as values.' );
	$clean( '$n = 7; echo $n % 2.0, $n | 4.0, $n << 1.0, ~3.0;', 'Whole floats as operands.' );
	$clean( '$n = 7; echo $n * 1.5, $n / 2.5, $n + 0.5, $n - 0.5, $n ** 0.5, -1.5;', 'Arithmetic that works on floats.' );
	$clean( '$n = 7; $n *= 1.5; $n /= 2.5; $n += 0.5; $n .= 1.5;', 'Assignment operators that work on floats.' );
	$clean( '$n = 7; echo fmod( $n, 2.5 ), intdiv( 7, 2 ), (int) 1.5 % 2, (int) ( $n * 1.5 ) | 1;', 'fmod() and explicit casts.' );
	$clean( '$n = 7; echo $n && 1.5, $n || 2.5, $n and 1.5, $n or 0.5, $n xor 1.5;', 'Boolean operators.' );
	$clean( 'echo $text[1.5], $list[ 0.5 ];', 'An offset: the variable may be a string or an ArrayAccess object.' );
	$clean( '$a = array( 1e30 => "x" ); echo 7 % 1e30;', 'Too large for an integer: PHP 8.5 treats that differently.' );
	$clean( '$a = array( $half => "x", HALF => "y", 1.5 + 1 => "z" );', 'Keys that are not literals.' );
};

$tests['8.1-dep: static members of classes, and of traits from other files, are not reported'] = static function () use ( $clean ) {
	$trait = "trait Greets { public static \$count = 0; public static function greet() { return self::helper() . static::helper() . self::\$count; } public static function helper() { return 'x'; } }\n";

	$clean( $trait . "class Visitor { use Greets; }\necho Visitor::greet(), Visitor::\$count;", 'Through the class that uses the trait.' );
	$clean( $trait, 'self:: and static:: inside the trait.' );
	$clean( 'echo Greets::greet(), Greets::$count;', 'Not known to be a trait: declared in another file.' );
	$clean( "class Greets { public static function greet() {} }\necho Greets::greet();", 'A class.' );
	$clean( $trait . 'echo Greets::class;', '::class on a trait.' );
	// runtime.trait_static_callable.
	$clean( $trait . 'echo call_user_func( array( "Greets", "greet" ) ), call_user_func( "Greets::greet" );', 'Callables that name the trait.' );
	$clean( "namespace App;\n" . $trait . 'echo \Greets::greet(), \Other\Greets::greet();', 'A trait of the same short name in another namespace.' );
	$clean( "namespace App;\nuse Vendor\\Greets;\ntrait Local {}\necho Greets::greet();", 'An imported name.' );
	$clean( '$trait = "x"; echo $object->trait, Options::trait(), $class::greet();', 'The word "trait" elsewhere, and a dynamic class.' );

	upgw_same(
		array( 'call.static_on_trait' ),
		upgw_rules_in( "<?php\nnamespace App { trait Greets { public static function greet() {} } }\nnamespace { echo \\App\\Greets::greet(), Greets::greet(); }" ),
		'Bracketed namespaces: only the trait in App is known.'
	);
	upgw_same( array( 'call.static_on_trait' ), upgw_rules_in( "<?php\necho Greets::greet();\n" . $trait ), 'Used above its declaration.' );
};

$tests['8.1-dep: functions that return by reference or return void, but not both, are not reported'] = static function () use ( $clean ) {
	$clean( 'function &items(): array { static $items = array(); return $items; }', 'By reference with another type.' );
	$clean( 'function &items() { static $items = array(); return $items; }', 'By reference without a type.' );
	$clean( 'function clear(): void {}', 'void without a reference.' );
	$clean( 'function clear( &$items ): void { $items = array(); }', 'A reference parameter.' );
	$clean( 'class A { public function &get(): ?array { return $this->items; } public function set(): void {} }', 'Methods.' );
	$clean( '$f = function &() use ( &$items ) { return $items; }; $g = function (): void {};', 'Closures.' );
};

$tests['8.1-dep: ctype calls whose argument may be a string are not reported'] = static function () use ( $clean ) {
	$clean( 'ctype_digit( "1234" ); ctype_digit( $value ); ctype_alpha( $row["name"] ); ctype_digit( $this->id );', 'Strings and values of unknown type.' );
	$clean( 'ctype_digit( (string) $value ); ctype_digit( strval( $value ) ); ctype_digit( trim( $value ) ); ctype_digit( "$value" );', 'Made into a string.' );
	$clean( 'ctype_digit( substr( $value, 1 ) ); ctype_digit( str_replace( "-", "", $value ) ); ctype_digit( absint( $value ) );', 'Other function calls.' );
	$clean( 'ctype_digit( $a ?? 5 ); ctype_digit( $ok ? 5 : "5" ); ctype_digit( PHP_INT_MAX ); ctype_digit( 1 . 2 );', 'Expressions that are not a plain literal.' );
	$clean( '$text->ctype_digit( 5 ); Text::ctype_digit( 5 ); Vendor\ctype_digit( 5 ); $f = "ctype_digit"; $f( 5 );', 'Not the internal function.' );
	$clean( "namespace App;\nuse function Vendor\\ctype_digit;\nctype_digit( 5 );", 'Imported from a namespace.' );
	$clean( 'ctype_digit( ...$args ); ctype_digit( text: 5 ); $f = ctype_digit( ... );', 'Spread, named argument, first-class callable.' );
	$clean( "function intval( \$v ) { return (string) \$v; }\nctype_digit( intval( \$x ) );", 'A polyfill of the inner function.' );

	$finding = upgw_scanner()->scan( "<?php\nif ( PHP_VERSION_ID < 80100 ) { ctype_digit( 5 ); }" )->findings;
	upgw_true( $finding[0]->guarded, 'Behind a version check.' );
};

$tests['8.1-dep: polygon, mb_check_encoding and mysqli_get_client_info calls in their current form are not reported'] = static function () use ( $clean ) {
	$clean( 'imagepolygon( $image, $points, $color ); imagefilledpolygon( $image, $points, $color ); imageopenpolygon( $image, $points, $color );', 'Three arguments.' );
	$clean( 'imagepolygon( ...$args ); $gd->imagepolygon( $image, $points, 3, $color ); imageline( $image, 1, 2, 3, 4, $color );', 'Spread, a method, another function.' );
	$clean( 'mb_check_encoding( $text ); mb_check_encoding( $text, "UTF-8" ); mb_check_encoding( ...$args ); $mb->mb_check_encoding();', 'With a value.' );
	$clean( 'mb_detect_encoding( $text ); mb_internal_encoding();', 'Other mbstring functions without arguments.' );
	$clean( 'mysqli_get_client_info(); mysqli_get_client_version( $link ); mysqli_get_server_info( $link ); mysqli_get_host_info( $link );', 'No argument, and its neighbours.' );
	// runtime.mysqli_get_client_info_method: deprecated, but $link could be anything.
	$clean( '$link->get_client_info(); $link->client_info; $driver->driver_version; $link->init();', 'Members of an object of unknown class.' );
};

$tests['8.1-dep: array pointer functions on what may be an array are not reported'] = static function () use ( $clean ) {
	$clean( 'reset( $items ); end( $items ); key( $items ); current( $items ); next( $items ); prev( $items );', 'A variable.' );
	$clean( 'class A { function f() { return current( $this->items ) . key( $this->items ) . reset( $this->items ) . end( self::$all ); } }', 'A property of $this.' );
	$clean( 'current( (array) $object ); key( get_object_vars( $this ) ); current( array( 1 ) ); current( $object->all() );', 'Arrays made from an object.' );
	$clean( 'class A { function current() { return $this->current( $this ); } function f() { return Cursor::key( $this ) . $c->end( $this ); } }', 'Methods of the same name.' );
	$clean( 'class A { function f() { return count( $this ) . iterator_to_array( $this ) . is_array( $this ); } }', 'Other functions given $this.' );
	$clean( "function current( \$x ) { return \$x; }\nclass A { function f() { return current( \$this ); } }", 'A function of the same name declared by the code.' );
	$clean( 'end( (object) $a ); reset( new ArrayObject() );', 'Not valid for a reference parameter; left to the engine.' );
};

$tests['8.1-dep: other INI settings, and switching auto_detect_line_endings off or reading it, are not reported'] = static function () use ( $clean ) {
	// runtime.ini_auto_detect_line_endings_off.
	$clean( 'ini_set( "auto_detect_line_endings", false ); ini_set( "auto_detect_line_endings", "0" ); ini_set( "auto_detect_line_endings", 0 );', 'Switched off.' );
	$clean( 'ini_set( "auto_detect_line_endings", "off" ); ini_set( "auto_detect_line_endings", "" ); ini_set( "auto_detect_line_endings", null );', 'Switched off in other words.' );
	$clean( 'ini_set( "auto_detect_line_endings", $previous ); ini_set( $name, true );', 'Not written out.' );
	$clean( 'ini_get( "auto_detect_line_endings" ); ini_restore( "auto_detect_line_endings" );', 'Read or restored.' );
	$clean( 'ini_set( "Auto_Detect_Line_Endings", true ); ini_set( "memory_limit", "1" ); ini_set( "display_errors", true );', 'Another name; INI names are case-sensitive.' );
	// runtime.ini_filter_default: ini_set() cannot change it and raises nothing.
	$clean( 'ini_set( "filter.default", "special_chars" );', 'filter.default.' );
	$clean( '$config->ini_set( "auto_detect_line_endings", true );', 'A method.' );

	$finding = upgw_scanner()->scan( "<?php\nif ( version_compare( PHP_VERSION, '8.1', '<' ) ) { @ini_set( 'auto_detect_line_endings', true ); }" )->findings;
	upgw_same( 'ini.auto_detect_line_endings', $finding[0]->rule );
	upgw_true( $finding[0]->guarded, 'Behind a version check.' );
};

$tests['8.1-dep: init(), FETCH_SERIALIZE and ssl_method are reported only where the class is certain'] = static function () use ( $clean ) {
	$clean( 'class DB extends wpdb { function __construct() { parent::init(); } }', 'parent is not mysqli.' );
	$clean( 'class DB extends mysqli { function __construct() { parent::__construct(); self::init(); $this->init(); static::init(); } function init() {} }', 'Not parent::init().' );
	$clean( 'class DB extends mysqli { function __construct() { parent::real_connect(); mysqli_init(); } }', 'Other methods, and mysqli_init().' );
	$clean( "namespace App;\nclass DB extends mysqli { function __construct() { parent::init(); } }", 'App\\mysqli is another class.' );
	$clean( 'class DB { function __construct() { parent::init(); } }', 'No parent in sight.' );

	$clean( '$statement->fetchAll( PDO::FETCH_CLASS, "Row" ); $statement->setFetchMode( PDO::FETCH_ASSOC ); $pdo->query( $sql, PDO::FETCH_NUM );', 'Other fetch modes.' );
	$clean( 'echo PDO::FETCH_SERIALIZE; $modes = array( PDO::FETCH_SERIALIZE => "serialize" ); if ( $mode & PDO::FETCH_SERIALIZE ) {}', 'The constant read, not used to fetch.' );
	$clean( '$statement->fetchAll( My\PDO::FETCH_SERIALIZE ); $statement->fetchAll( Wrapper::FETCH_SERIALIZE ); $statement->fetchAll( $pdo::FETCH_SERIALIZE );', 'A constant of another class.' );
	$clean( "namespace App;\n\$statement->fetchAll( PDO::FETCH_SERIALIZE );", 'App\\PDO is another class.' );

	$clean( 'new SoapClient( $wsdl ); new SoapClient( $wsdl, array( "trace" => true, "ssl" => array() ) ); new SoapClient( $wsdl, $options );', 'No ssl_method written out.' );
	$clean( 'new SoapClient( $wsdl, array( "stream_context" => stream_context_create( array( "ssl" => array( "crypto_method" => 1 ) ) ) ) );', 'The replacement.' );
	$clean( 'new My_Client( $wsdl, array( "ssl_method" => 1 ) ); $options = array( "ssl_method" => 1 ); new SoapServer( $wsdl, array( "ssl_method" => 1 ) );', 'Another class, or only an array.' );
	$clean( 'new SoapClient( $wsdl, array( "SSL_METHOD" => 1, "ssl_method_name" => 1, 1 => "ssl_method" ) );', 'Similar keys and values.' );
	$clean( "namespace App;\nnew SoapClient( \$wsdl, array( 'ssl_method' => 1 ) );", 'App\\SoapClient is another class.' );
};

$tests['8.1-dep (review): function_exists() around a call does not mark it as guarded, a version check does'] = static function () {
	$cases = array(
		"if ( function_exists( 'ctype_digit' ) ) { ctype_digit( 5 ); }",
		"if ( function_exists( 'imagepolygon' ) ) { imagepolygon( \$image, \$points, 3, \$color ); }",
		"if ( function_exists( 'ini_set' ) ) { @ini_set( 'auto_detect_line_endings', true ); }",
		"if ( ! function_exists( 'mb_check_encoding' ) ) { return; }\nmb_check_encoding();",
		"if ( is_callable( 'mysqli_get_client_info' ) ) { mysqli_get_client_info( \$link ); }",
		"class A { function f() { if ( function_exists( 'current' ) ) { return current( \$this ); } } }",
	);

	foreach ( $cases as $code ) {
		$findings = upgw_scanner()->scan( "<?php\n" . $code )->findings;
		upgw_same( 1, count( $findings ), $code );
		upgw_true( ! $findings[0]->guarded, 'Not guarded: ' . $code );

		$findings = upgw_scanner()->scan( "<?php\nif ( PHP_VERSION_ID < 80100 ) {\n" . $code . "\n}" )->findings;
		upgw_same( 1, count( $findings ), $code );
		upgw_true( $findings[0]->guarded, 'Guarded by the version check: ' . $code );
	}
};

$tests['8.1-dep (review): PDO::FETCH_SERIALIZE without PDO::FETCH_CLASS beside it is not reported'] = static function () use ( $clean ) {
	// runtime.pdo_fetch_serialize_alone.
	$clean( '$statement->fetchAll( PDO::FETCH_SERIALIZE ); $statement->setFetchMode( PDO::FETCH_SERIALIZE ); $pdo->query( $sql, PDO::FETCH_SERIALIZE ); $statement->fetch( PDO::FETCH_SERIALIZE );', 'The flag alone.' );
	$clean( '$statement->fetchAll( PDO::FETCH_SERIALIZE | PDO::FETCH_ASSOC ); $statement->fetchAll( PDO::FETCH_SERIALIZE | Other::FETCH_CLASS );', 'Combined with something else.' );
	$clean( '$statement->fetchColumn( PDO::FETCH_CLASS | PDO::FETCH_SERIALIZE ); $mode = PDO::FETCH_CLASS | PDO::FETCH_SERIALIZE;', 'Not a method that takes a fetch mode.' );

	upgw_same( array( 'constant.pdo_fetch_serialize' ), upgw_rules_in( '<?php $statement->fetch( \PDO::FETCH_SERIALIZE | \pdo::FETCH_CLASS );' ), 'fetch(), either order.' );
	upgw_same( array( 'constant.pdo_fetch_serialize' ), upgw_rules_in( '<?php $statement?->fetchAll( PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE | PDO::FETCH_SERIALIZE, "Row" );' ), 'Three flags.' );
};

$tests['8.1-dep (review): mb_check_encoding( null, $encoding ) and the other ways to name mysqli::init()'] = static function () use ( $clean ) {
	upgw_same( array( 'argument.mb_check_encoding_none' ), upgw_rules_in( '<?php mb_check_encoding( NULL, "UTF-8" );' ), 'null with an encoding.' );
	$clean( 'mb_check_encoding( $text, null ); mb_check_encoding( "", "UTF-8" ); mb_check_encoding( null, "UTF-8", $extra );', 'A value, or a call PHP rejects.' );

	upgw_same( array( 'call.mysqli_parent_init', 'call.mysqli_parent_init' ), upgw_rules_in( '<?php class DB extends \mysqli { function __construct() { self::init(); MySQLi::Init(); } }' ), 'self:: and the class name.' );
	$clean( 'class DB extends mysqli { use Boots; function __construct() { self::init(); static::init(); $this->init(); } }', 'A trait may supply init().' );
	$clean( 'class DB extends Base { function __construct() { mysqli::init(); self::init(); } } mysqli::init();', 'Not in a class whose parent is mysqli.' );
	$clean( "namespace App;\nclass DB extends \\mysqli { function __construct() { mysqli::init(); } }", 'App\\mysqli::init().' );
};

$tests['8.1-dep (review): auto_detect_line_endings given a float'] = static function () use ( $clean ) {
	upgw_same( array( 'ini.auto_detect_line_endings' ), upgw_rules_in( '<?php ini_set( "auto_detect_line_endings", 1.5 );' ) );
	$clean( 'ini_set( "auto_detect_line_endings", 0.0 ); ini_set( "auto_detect_line_endings", 0.5 );', 'Read as 0.' );
};

return $tests;
