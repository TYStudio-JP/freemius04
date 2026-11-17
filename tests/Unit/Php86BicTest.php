<?php
/**
 * PHP 8.6 backward incompatible changes (tranche 8.6-bic): code that looks
 * like an affected use and is fine, and so must stay unreported.
 *
 * Only the rule ids of this tranche are looked at, so that a rule somebody
 * else adds for the same code does not make these tests fail.
 *
 * @package UpgradeWarden
 */

$tests = array();

$bic86_mine = static function ( $code, array $declared = array() ) {
	$ids = array(
		'argument.unpack_endianness_modifier',
		'argument.setlocale_array_position',
		'argument.invalid_mode_value',
		'signature.session_validate_id_return_type',
		'argument.soap_classmap_integer_keys',
		'argument.zip_glob_option_type',
	);

	$found = array();

	foreach ( upgw_scanner()->scan( "<?php\n" . $code, $declared )->findings as $finding ) {
		if ( in_array( $finding->rule, $ids, true ) ) {
			$found[] = $finding->rule;

			upgw_same( 'documented', $finding->evidence, 'Every 8.6 finding is documented, not verified: ' . $finding->rule );
		}
	}

	return $found;
};

$bic86_clean = static function ( $code, $message, array $declared = array() ) use ( $bic86_mine ) {
	upgw_same( array(), $bic86_mine( $code, $declared ), $message . "\n      code: " . $code );
};

$tests['8.6-bic: unpack() formats without a modifier are not reported'] = static function () use ( $bic86_clean, $bic86_mine ) {
	$bic86_clean( 'unpack( "Nlen/a*data", $bin );', 'Ordinary format.' );
	$bic86_clean( 'unpack( "s1<value", $bin );', 'A repeater before the name, as the notes say.' );
	$bic86_clean( 'unpack( "s*<value", $bin );', 'A * repeater before the name.' );
	$bic86_clean( 'unpack( "a4<b", $bin );', 'The "<" comes after a repeater.' );
	$bic86_clean( 'unpack( $format, $bin );', 'Format from a variable.' );
	$bic86_clean( 'pack( "s<", 1 );', 'pack() is not in the notes.' );
	$bic86_clean( '$o->unpack( "s<value", $bin ); Util::unpack( "C>x", $bin );', 'Methods of the same name.' );
	$bic86_clean( 'unpack( "s<value", $bin );', 'A function the plugin declares itself.', array( 'unpack' ) );
	$bic86_clean( 'namespace Foo; function unpack( $a, $b ) {} \\Foo\\unpack( "s<value", $bin );', 'A namespaced function.' );

	upgw_same( array( 'argument.unpack_endianness_modifier' ), $bic86_mine( 'unpack( string: $bin, format: "Ctype/n>len" );' ), 'Named arguments, second element.' );
};

$tests['8.6-bic: setlocale() with arrays where 8.6 accepts them is not reported'] = static function () use ( $bic86_clean, $bic86_mine ) {
	$bic86_clean( 'setlocale( LC_ALL, array( "de_DE", "de" ) );', 'An array as $locales alone.' );
	$bic86_clean( 'setlocale( LC_ALL, "de_DE", "de", "C" );', 'Strings only.' );
	$bic86_clean( 'setlocale( LC_ALL, $locales, "C" );', 'A variable that may hold a string.' );
	$bic86_clean( 'setlocale( LC_ALL, "C", $more );', 'A later variable.' );
	$bic86_clean( 'setlocale( LC_ALL, ...$args );', 'Unpacked arguments.' );
	$bic86_clean( '$i18n->setlocale( LC_ALL, array( "C" ), "C" );', 'A method of the same name.' );

	upgw_same( array( 'argument.setlocale_array_position' ), $bic86_mine( 'namespace Foo; setlocale( LC_ALL, "C", [ "C" ] );' ), 'Short array syntax inside a namespace.' );
};

$tests['8.6-bic: valid or unknown mode arguments are not reported'] = static function () use ( $bic86_clean, $bic86_mine ) {
	$bic86_clean( 'array_filter( $a, $cb, ARRAY_FILTER_USE_KEY ); array_filter( $a, $cb, ARRAY_FILTER_USE_BOTH ); array_filter( $a, $cb, 0 );', 'Valid modes.' );
	$bic86_clean( 'array_filter( $a, $cb ); array_filter( $a );', 'No mode.' );
	$bic86_clean( 'array_filter( $a, $cb, $mode ); array_filter( $a, $cb, MY_MODE );', 'Mode from a variable or a user constant.' );
	$bic86_clean( 'array_change_key_case( $a, CASE_UPPER ); array_change_key_case( $a, 1 ); array_change_key_case( $a, true );', 'Valid cases.' );
	$bic86_clean( 'scandir( $d, SCANDIR_SORT_NONE ); scandir( $d, 1 ); scandir( $d );', 'Valid sorting orders.' );
	$bic86_clean( 'pathinfo( $p, PATHINFO_FILENAME | PATHINFO_EXTENSION );', 'pathinfo() is not reported.' );
	$bic86_clean( '$q->array_filter( $a, $cb, 9 ); Coll::scandir( $d, 7 );', 'Methods of the same name.' );
	$bic86_clean( 'array_filter( $a, $cb, 3 );', 'A function the plugin declares itself.', array( 'array_filter' ) );

	upgw_same( array( 'argument.invalid_mode_value' ), $bic86_mine( 'array_filter( $a, $cb, ARRAY_FILTER_USE_KEY | ARRAY_FILTER_USE_BOTH );' ), 'Both flags combined.' );
	upgw_same( array( 'argument.invalid_mode_value' ), $bic86_mine( 'scandir( directory: $d, sorting_order: -1 );' ), 'Named, negative.' );
};

$tests['8.6-bic: validateId() that is typed, silenced or elsewhere is not reported'] = static function () use ( $bic86_clean, $bic86_mine ) {
	$bic86_clean( 'class H extends SessionHandler { public function validateId( $id ): bool { return true; } }', 'Return type declared.' );
	$bic86_clean( "class H extends SessionHandler {\n#[\\ReturnTypeWillChange]\npublic function validateId( \$id ) { return true; } }", 'ReturnTypeWillChange.' );
	$bic86_clean( 'class H extends SessionHandler implements SessionUpdateTimestampHandlerInterface { public function validateId( $id ) { return true; } public function updateTimestamp( $id, $d ) { return true; } }', 'Also implements the interface: deprecated since 8.1, another rule.' );
	$bic86_clean( 'class H implements SessionHandlerInterface { public function validateId( $id ) { return true; } }', 'Not a SessionHandler subclass.' );
	$bic86_clean( 'class H extends My_Session_Handler { public function validateId( $id ) { return true; } }', 'Parent unknown.' );
	$bic86_clean( 'namespace App; class H extends SessionHandler { public function validateId( $id ) { return true; } }', 'App\\SessionHandler.' );
	$bic86_clean( 'class H extends SessionHandler { public function read( $id ) { return ""; } public function validate_id( $id ) { return true; } }', 'Other methods.' );

	upgw_same( array( 'signature.session_validate_id_return_type' ), $bic86_mine( '$h = new class extends \\SessionHandler { public function validateId( $id ) { return true; } };' ), 'Anonymous class.' );
};

$tests['8.6-bic: classmaps that 8.6 accepts or that already fail are not reported'] = static function () use ( $bic86_clean, $bic86_mine ) {
	$bic86_clean( 'new SoapClient( $wsdl, array( "classmap" => array( "Item" => "My_Item", "Order" => "My_Order" ) ) );', 'String keys only.' );
	$bic86_clean( 'new SoapClient( $wsdl, array( "classmap" => array( "My_Item", "My_Order" ) ) );', 'A list: already rejected since 8.2.' );
	$bic86_clean( 'new SoapClient( $wsdl, array( "classmap" => $map ) ); new SoapClient( $wsdl, $options );', 'Variables.' );
	$bic86_clean( 'new SoapClient( $wsdl, array( "classmap" => array( $k => "A", "B" => "B" ) ) );', 'A key that is not a literal.' );
	$bic86_clean( 'new SoapServer( $wsdl, array( "classmap" => "My_Item" ) );', 'SoapServer with a string: not in the notes.' );
	$bic86_clean( 'new My_Soap( $wsdl, array( "classmap" => array( "A" => "A", "B" ) ) );', 'Another class.' );
	$bic86_clean( 'namespace App; new SoapClient( $wsdl, array( "classmap" => array( "A" => "A", "B" ) ) );', 'App\\SoapClient.' );
	$bic86_clean( 'new SoapClient( $wsdl, array( "typemap" => array( "A" => "A", "B" ) ) );', 'Another option.' );

	upgw_same( array( 'argument.soap_classmap_integer_keys' ), $bic86_mine( 'new \\SoapClient( null, array( "classmap" => array( "A" => "A", "5" => "B" ) ) );' ), 'A numeric string key is an integer key.' );
};

$tests['8.6-bic: zip options of the right type are not reported'] = static function () use ( $bic86_clean, $bic86_mine ) {
	$bic86_clean( '$zip->addGlob( "*.txt", 0, array( "remove_all_path" => true, "add_path" => "x/" ) );', 'A bool.' );
	$bic86_clean( '$zip->addGlob( "*.txt", 0, array( "remove_all_path" => $flag ) );', 'A variable.' );
	$bic86_clean( '$zip->addPattern( "/x/", ".", array( "comp_method" => ZipArchive::CM_DEFLATE, "comp_flags" => 9 ) );', 'Ints.' );
	$bic86_clean( '$zip->addPattern( "/x/", ".", array( "comp_method" => "8" ) );', 'A numeric string is coerced.' );
	$bic86_clean( '$zip->addGlob( "*.txt", 0, array( "remove_path" => 1, "add_path" => 2 ) );', 'Other options.' );
	$bic86_clean( '$zip->addFile( "a.txt", "b.txt", 0, 0, array( "remove_all_path" => 1 ) );', 'Another method.' );
	$bic86_clean( '$zip->addGlob( "*.txt", array( "remove_all_path" => 1 ) );', 'The array is not in the options position.' );

	upgw_same( array( 'argument.zip_glob_option_type' ), $bic86_mine( '$zip?->addGlob( "*.txt", options: array( "remove_all_path" => "yes" ) );' ), 'Nullsafe call, named options.' );
};

$tests['8.6-bic: variants in closures, namespaces, aliases and other letter cases are reported'] = static function () use ( $bic86_mine ) {
	$code = 'namespace Acme\\Lib;
use SessionHandler as Base;
use SoapClient as SC;
class S1 extends Base { public function VALIDATEID( $id ) { return true; } }
$f = function ( $b ) { return UNPACK( "S<len", $b ); };
$g = fn( $b ) => \\unpack( "n>x", $b );
$x = Array_Filter( $l, null, 7 );
$c = new SC( null, [ "classmap" => [ "A" => "A", "B" ] ] );
$z = $this->zip->AddGlob( "*", 0, [ "remove_all_path" => "true" ] );
SetLocale( LC_ALL, [ "a" ], "b" );';

	$found = $bic86_mine( $code );
	sort( $found );

	upgw_same(
		array(
			'argument.invalid_mode_value',
			'argument.setlocale_array_position',
			'argument.soap_classmap_integer_keys',
			'argument.unpack_endianness_modifier',
			'argument.unpack_endianness_modifier',
			'argument.zip_glob_option_type',
			'signature.session_validate_id_return_type',
		),
		$found,
		'One finding per variant.'
	);
};

return $tests;
