<?php
/**
 * PHP 8.5 backward incompatible changes (tranche 8.5-bic): code that looks
 * like an affected use and is fine, and so must stay unreported.
 *
 * Only the rule ids of this tranche are looked at, so that a rule somebody
 * else adds for the same code does not make these tests fail.
 *
 * @package UpgradeWarden
 */

$tests = array();

$bic85_mine = static function ( $code, array $declared = array() ) {
	$ids = array(
		'call.class_alias_reserved_name',
		'class.attribute_on_non_instantiable',
		'class.soapclient_dorequest_signature',
		'class.splfileobject_fwrite_signature',
		'constant.pdo_props_late_without_class',
		'argument.pcre_k_in_lookaround',
		'call.simplexml_xpath_non_node_set',
		'argument.printf_empty_precision',
	);

	$found = array();

	foreach ( upgw_scanner()->scan( "<?php\n" . $code, $declared )->findings as $finding ) {
		if ( in_array( $finding->rule, $ids, true ) ) {
			$found[] = $finding->rule;
		}
	}

	return $found;
};

$bic85_clean = static function ( $code, $message, array $declared = array() ) use ( $bic85_mine ) {
	upgw_same( array(), $bic85_mine( $code, $declared ), $message . "\n      code: " . $code );
};

$tests['8.5-bic: class_alias() look-alikes are not reported'] = static function () use ( $bic85_clean, $bic85_mine ) {
	$bic85_clean( 'class_alias( "Foo", "Bar" );', 'An ordinary alias.' );
	$bic85_clean( 'class_alias( "array", "Foo" );', '"array" as the original, not the alias.' );
	$bic85_clean( 'class_alias( "Foo", $name );', 'Alias from a variable.' );
	$bic85_clean( 'class_alias( "Foo", "My\\\\Array" );', 'A namespaced alias that ends in Array.' );
	$bic85_clean( 'class_alias( "Foo", "arrays" ); class_alias( "Foo", "callables" );', 'Names that only start with a reserved word.' );
	$bic85_clean( '$o->class_alias( "Foo", "array" ); Util::class_alias( "Foo", "callable" );', 'Methods of the same name.' );
	$bic85_clean( 'function class_alias( $a, $b ) {} class_alias( "Foo", "array" );', 'A function the file declares itself.' );

	upgw_same( array( 'call.class_alias_reserved_name' ), $bic85_mine( 'class_alias( original: "Foo", alias: "callable" );' ), 'Named arguments.' );
};

$tests['8.5-bic: #[Attribute] look-alikes are not reported'] = static function () use ( $bic85_clean, $bic85_mine ) {
	$bic85_clean( '#[Attribute] class Marker {}', 'A concrete class.' );
	$bic85_clean( '#[Attribute] final class Marker {}', 'A final class.' );
	$bic85_clean( '#[Attribute] #[\\DelayedTargetValidation] abstract class Marker {}', 'DelayedTargetValidation delays the check.' );
	$bic85_clean( 'namespace Foo; #[Attribute] abstract class Marker {}', 'Foo\\Attribute, not the global one.' );
	$bic85_clean( '#[\\Other\\Attribute] interface Marker {}', 'Another class named Attribute.' );
	$bic85_clean( '#[AllowDynamicProperties] abstract class Base {}', 'Another attribute on an abstract class.' );
	$bic85_clean( 'abstract class Base { #[Attribute] public function m() {} }', 'An attribute on a method.' );
	$bic85_clean( '/* #[Attribute] */ abstract class Base {}', 'In a comment.' );

	upgw_same( array( 'class.attribute_on_non_instantiable' ), $bic85_mine( 'namespace Foo; use Attribute; #[Attribute] abstract class Marker {}' ), 'Imported with use.' );
	upgw_same( array( 'class.attribute_on_non_instantiable' ), $bic85_mine( '#[Attribute] enum Suit { case Hearts; }' ), 'An enum.' );
};

$tests['8.5-bic: SoapClient and SplFileObject overrides that stay compatible are not reported'] = static function () use ( $bic85_clean ) {
	$bic85_clean( 'class C extends SoapClient { public function __doRequest( $r, $l, $a, $v, $o = 0, $u = null ) {} }', 'Six parameters.' );
	$bic85_clean( 'class C extends SoapClient { public function __doRequest( $r, $l, $a, $v, $o = 0, ...$rest ) {} }', 'Variadic sixth parameter.' );
	$bic85_clean( 'class C extends SoapClient { public function __doRequest( $r, $l, $a, $v, ...$rest ) {} }', 'Variadic fifth parameter.' );
	$bic85_clean( 'class C extends SoapClient { public function __call( $n, $a ) {} public function __soapCall( $f, $a, $o = null, $h = null, &$out = null ) {} }', 'Other methods with five parameters.' );
	$bic85_clean( 'class C extends My_Base { public function __doRequest( $r, $l, $a, $v, $o = 0 ) {} }', 'Parent is not SoapClient (unknown).' );
	$bic85_clean( 'namespace App; class C extends SoapClient { public function __doRequest( $r, $l, $a, $v, $o = 0 ) {} }', 'App\\SoapClient, not the internal class.' );
	$bic85_clean( 'class C { public function __doRequest( $r, $l, $a, $v, $o = 0 ) {} }', 'No parent.' );

	$bic85_clean( 'class F extends SplFileObject { public function fwrite( $data, $length = 0 ) {} }', 'Untyped $length.' );
	$bic85_clean( 'class F extends SplFileObject { public function fwrite( string $data, ?int $length = null ) {} }', 'Nullable $length.' );
	$bic85_clean( 'class F extends SplFileObject { public function fwrite( string $data, int $length = null ) {} }', 'Implicitly nullable $length.' );
	$bic85_clean( 'class F extends SplFileObject { public function fwrite( string $data, int|null $length = 0 ) {} }', 'Union with null.' );
	$bic85_clean( 'class F extends SplFileObject { public function fwrite( string $data, mixed $length = 0 ) {} }', 'mixed.' );
	$bic85_clean( 'class F extends SplFileObject { public function fputs( string $data, int $length = 0 ) {} }', 'Another method.' );
	$bic85_clean( 'class F extends Other_File { public function fwrite( string $data, int $length = 0 ) {} }', 'Unknown parent.' );
};

$tests['8.5-bic: PDO fetch modes that 8.5 accepts are not reported'] = static function () use ( $bic85_clean, $bic85_mine ) {
	$bic85_clean( '$s->fetchAll( PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE, "Row" );', 'With FETCH_CLASS.' );
	$bic85_clean( '$s->fetchAll( PDO::FETCH_PROPS_LATE | PDO::FETCH_CLASS | PDO::FETCH_GROUP, "Row" );', 'FETCH_CLASS in any position.' );
	$bic85_clean( '$s->fetchAll( PDO::FETCH_PROPS_LATE );', 'The flag alone, without a mode.' );
	$bic85_clean( '$s->fetchAll( PDO::FETCH_ASSOC | PDO::FETCH_GROUP );', 'Another flag.' );
	$bic85_clean( '$s->fetchAll( $mode | PDO::FETCH_PROPS_LATE );', 'Mode from a variable.' );
	$bic85_clean( '$x = MyDb::FETCH_ASSOC | MyDb::FETCH_PROPS_LATE;', 'Constants of another class.' );
	$bic85_clean( 'namespace App; $x = PDO::FETCH_ASSOC | PDO::FETCH_PROPS_LATE;', 'App\\PDO, not the internal class.' );

	upgw_same( array( 'constant.pdo_props_late_without_class' ), $bic85_mine( '$s->fetchAll( PDO::FETCH_NUM | PDO::FETCH_GROUP | PDO::FETCH_PROPS_LATE );' ), 'One finding for a chain of three.' );
	upgw_same( array( 'constant.pdo_props_late_without_class' ), $bic85_mine( 'namespace App; use PDO; $x = \\PDO::FETCH_ASSOC | PDO::FETCH_PROPS_LATE;' ), 'Imported and fully qualified.' );
};

$tests['8.5-bic: regular expressions without \\K in a lookaround are not reported'] = static function () use ( $bic85_clean, $bic85_mine ) {
	$bic85_clean( 'preg_replace( \'/foo\\Kbar/\', "", $s );', '\\K outside a lookaround.' );
	$bic85_clean( 'preg_match( \'/(?<=foo)\\Kbar/\', $s );', '\\K after the lookbehind.' );
	$bic85_clean( 'preg_match( \'/(?=[\\K])/\', $s );', '\\K inside a character class.' );
	$bic85_clean( 'preg_match( \'/(?=a\\\\\\\\K)/\', $s );', 'An escaped backslash followed by K.' );
	$bic85_clean( 'preg_match( \'/(?=a\\QK\\E)(?:\\K)/\', $s );', '\\K in a non-capturing group.' );
	$bic85_clean( 'preg_match( \'/(?=a)(?#\\K)b/\', $s );', 'In a (?#) comment.' );
	$bic85_clean( 'preg_match( \'/(?=a  # \\K\\n)b/x\', $s );', 'x modifier: not judged.' );
	$bic85_clean( 'preg_match( \'/(?ix)(?=a  # \\K\n)b/\', $s );', 'Inline (?ix): not judged.' );
	$bic85_clean( 'preg_match( \'/(?=a\\k<n>)/\', $s );', 'Lower-case \\k back reference.' );
	$bic85_clean( 'preg_match( $pattern, $s ); preg_quote( \'(?=\\K)\' ); str_replace( \'(?=\\K)\', "", $s );', 'Not a pattern argument.' );
	$bic85_clean( 'preg_match( \'/(\\(?=)\\K/\', $s );', 'An escaped parenthesis is not a group.' );

	upgw_same( array( 'argument.pcre_k_in_lookaround' ), $bic85_mine( 'preg_replace( array( \'/a/\', \'/(?!x\\K)/\' ), "", $s );' ), 'In an array of patterns.' );
	upgw_same( array( 'argument.pcre_k_in_lookaround' ), $bic85_mine( 'preg_replace_callback_array( array( \'{(?<=a\\K)b}\' => "f" ), $s );' ), 'Keys of preg_replace_callback_array().' );
	upgw_same( array( 'argument.pcre_k_in_lookaround' ), $bic85_mine( 'preg_match( "/(?<=a\\\\Kb)c/", $s );' ), 'Double quotes with an escaped backslash.' );
};

$tests['8.5-bic: xpath() calls that return nodes are not reported'] = static function () use ( $bic85_clean ) {
	$bic85_clean( '$x->xpath( "//item" );', 'A node path.' );
	$bic85_clean( '$x->xpath( "//item[count(child) > 1]" );', 'count() inside a predicate.' );
	$bic85_clean( '$x->xpath( "count/item" );', 'An element named count.' );
	$bic85_clean( '$x->xpath( "text()" ); $x->xpath( "id(\'a\')" ); $x->xpath( "node()" );', 'Node-set functions.' );
	$bic85_clean( '$x->xpath( $query );', 'Expression from a variable.' );
	$bic85_clean( '$xp->evaluate( "count(//item)" ); $xp->query( "count(//item)" );', 'DOMXPath methods.' );
	$bic85_clean( '$x->xpath( "COUNT(//item)" );', 'Upper case: not an XPath function on any version.' );
	$bic85_clean( 'xpath( "count(//item)" );', 'A function, not a method.' );
	$bic85_clean( '$x->xpath( "count(//item)", $ns );', 'Two arguments: not SimpleXMLElement::xpath().' );
};

$tests['8.5-bic: printf formats with a precision are not reported'] = static function () use ( $bic85_clean, $bic85_mine ) {
	$bic85_clean( 'printf( "%.2f", $n );', 'Explicit precision.' );
	$bic85_clean( 'printf( "%.0f", $n );', 'Explicit zero.' );
	$bic85_clean( 'printf( "%f %5f %-8F", $a, $b, $c );', 'No dot.' );
	$bic85_clean( 'printf( "%.s %.d %5.x", $a, $b, $c );', 'Conversions that did not change.' );
	$bic85_clean( 'printf( "%%.f", $n );', 'An escaped percent sign.' );
	$bic85_clean( 'printf( "%\'.10d", $n );', 'A dot as the padding character.' );
	$bic85_clean( 'printf( "%.*f", 2, $n );', 'Precision from an argument.' );
	$bic85_clean( 'printf( $format, $n ); number_format( $n ); $o->sprintf( "%.f", $n );', 'Not a literal format of a printf function.' );
	$bic85_clean( 'fprintf( "%.f", $handle );', 'fprintf() reads its format from the second argument.' );
	$bic85_clean( 'function sprintf( $f ) {} sprintf( "%.f" );', 'A function the file declares itself.' );

	upgw_same( array( 'argument.printf_empty_precision' ), $bic85_mine( 'echo sprintf( "%\'.10.f", $n );' ), 'A dot as padding and an empty precision.' );
	upgw_same( array( 'argument.printf_empty_precision' ), $bic85_mine( 'vsprintf( "%.lf", array( 1.5 ) );' ), 'With the l modifier.' );
};

$tests['8.5-bic: helpers read patterns and formats as PHP does'] = static function () {
	upgw_true( \TYStudio\UpgradeWarden\Engine\Rules\Php85BicPcreLookaroundK::has_k_in_lookaround( '/(?<=\d\K)px/' ), 'Lookbehind.' );
	upgw_true( \TYStudio\UpgradeWarden\Engine\Rules\Php85BicPcreLookaroundK::has_k_in_lookaround( '#(?=(a\K))#i' ), 'Nested group inside a lookahead.' );
	upgw_true( ! \TYStudio\UpgradeWarden\Engine\Rules\Php85BicPcreLookaroundK::has_k_in_lookaround( '/(?=a)b\K/' ), 'After the lookahead closed.' );
	upgw_true( ! \TYStudio\UpgradeWarden\Engine\Rules\Php85BicPcreLookaroundK::has_k_in_lookaround( '/(?=[]\K])/' ), '"]" first in a class.' );
	upgw_same( '%.f', \TYStudio\UpgradeWarden\Engine\Rules\Php85BicPrintfPrecision::empty_precision( 'total: %.f' ), 'Spec returned.' );
	upgw_same( '%1$-8.G', \TYStudio\UpgradeWarden\Engine\Rules\Php85BicPrintfPrecision::empty_precision( '%s %1$-8.G' ), 'Argument number, flag and width.' );
	upgw_same( null, \TYStudio\UpgradeWarden\Engine\Rules\Php85BicPrintfPrecision::empty_precision( '100%.' ), 'A dot at the end.' );
};

return $tests;
