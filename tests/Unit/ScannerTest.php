<?php
/**
 * What the scanner must not report, and how it grades what it does report.
 *
 * The fixtures prove that real problems are found. These tests are about the
 * other half: code that looks similar and is fine. Wrong reports are what
 * made earlier checkers hard to trust.
 *
 * @package UpgradeWarden
 */

use TYStudio\UpgradeWarden\Engine\Finding;
use TYStudio\UpgradeWarden\Engine\ScanResult;

$tests = array();

$clean = static function ( $code, $message ) {
	upgw_same( array(), upgw_rules_in( "<?php\n" . $code ), $message );
};

$tests['a method or a namespaced function named like a removed function is not reported'] = static function () use ( $clean ) {
	$clean( '$list->each( $callback );', 'Method call.' );
	$clean( 'Collection::each( $callback );', 'Static method call.' );
	$clean( 'Vendor\Tools\each( $items );', 'Qualified call into a namespace.' );
	$clean( '$name = "each"; $name( $items );', 'Call through a variable.' );
	$clean( "namespace App;\nuse function Vendor\\Tools\\each;\neach( \$items );", 'Function imported from a namespace.' );
};

$tests['a bare call inside a namespace falls back to the internal function and is reported'] = static function () {
	upgw_same( array( 'function.removed' ), upgw_rules_in( "<?php\nnamespace App;\neach( \$items );" ) );
	upgw_same( array( 'function.removed' ), upgw_rules_in( "<?php\nnamespace App;\n\\each( \$items );" ) );
};

$tests['a function the code declares itself is not reported'] = static function () use ( $clean ) {
	$clean( "if ( ! function_exists( 'each' ) ) {\n\tfunction each( &\$array ) { return false; }\n}\neach( \$items );", 'Declared in the same file.' );

	$result = upgw_scanner()->scan( "<?php\neach( \$items );", array( 'each' ) );
	upgw_same( array(), $result->findings, 'Declared in another file of the same plugin.' );

	$declared = upgw_scanner()->scan( "<?php\nfunction My_Helper() {}\nnamespace_less();" )->declared_functions;
	upgw_same( array( 'my_helper' ), $declared, 'Declared functions are listed in lower case.' );
};

$tests['a call behind function_exists() is marked as guarded'] = static function () {
	$codes = array(
		"if ( function_exists( 'get_magic_quotes_gpc' ) && get_magic_quotes_gpc() ) { \$x = 1; }",
		"if ( function_exists( 'get_magic_quotes_gpc' ) ) {\n\t\$on = get_magic_quotes_gpc();\n}",
		"\$on = function_exists( 'get_magic_quotes_gpc' ) ? get_magic_quotes_gpc() : false;",
		"function quotes() {\n\tif ( ! function_exists( 'get_magic_quotes_gpc' ) ) {\n\t\treturn false;\n\t}\n\treturn get_magic_quotes_gpc();\n}",
	);

	foreach ( $codes as $code ) {
		$findings = upgw_scanner()->scan( "<?php\n" . $code )->findings;

		upgw_same( 1, count( $findings ), $code );
		upgw_true( $findings[0]->guarded, 'Not marked as guarded: ' . $code );
	}
};

$tests['a call behind a PHP version check is marked as guarded'] = static function () {
	$codes = array(
		"if ( PHP_VERSION_ID < 80000 ) {\n\tlibxml_disable_entity_loader( true );\n}",
		"if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {\n\tlibxml_disable_entity_loader( true );\n}",
		"if ( \\PHP_VERSION_ID < 80000 ) {\n\tlibxml_disable_entity_loader( true );\n}",
	);

	foreach ( $codes as $code ) {
		$findings = upgw_scanner()->scan( "<?php\n" . $code )->findings;

		upgw_same( 1, count( $findings ), $code );
		upgw_true( $findings[0]->guarded, 'Not marked as guarded: ' . $code );
	}
};

$tests['a check for something else does not guard'] = static function () {
	$findings = upgw_scanner()->scan( "<?php\nif ( function_exists( 'mb_strlen' ) ) {\n\t\$on = get_magic_quotes_gpc();\n}" )->findings;

	upgw_same( 1, count( $findings ) );
	upgw_true( ! $findings[0]->guarded, 'A check for another function must not guard this call.' );

	$findings = upgw_scanner()->scan( "<?php\nif ( is_resource( \$handle ) ) {\n\tutf8_encode( \$text );\n}" )->findings;

	upgw_true( ! $findings[0]->guarded, 'is_resource() only guards functions that release a former resource.' );
};

$tests['a compile-time problem is never guarded'] = static function () {
	$findings = upgw_scanner()->scan( "<?php\nif ( PHP_VERSION_ID < 80000 ) {\n\t\$first = \$text{0};\n}" )->findings;

	upgw_same( 'syntax.curly_offset', $findings[0]->rule );
	upgw_true( ! $findings[0]->guarded, 'The file fails to compile on PHP 8 whatever the condition says.' );
};

$tests['ternaries that PHP still accepts are not reported'] = static function () use ( $clean ) {
	$clean( '$r = ( $a ? 1 : $b ) ? 2 : 3;', 'Parentheses on the left.' );
	$clean( '$r = $a ? 1 : ( $b ? 2 : 3 );', 'Parentheses on the right.' );
	$clean( '$r = $a ?: $b ?: $c;', 'Chain of short ternaries.' );
	$clean( '$r = $a ? ( $b ? 1 : 2 ) : 3;', 'Nested in the middle with parentheses.' );
	$clean( '$r = $a ? $b ? 1 : 2 : 3;', 'Nested in the middle without parentheses.' );
};

$tests['constructors that are fine are not reported'] = static function () use ( $clean ) {
	$clean( "class Widget {\n\tfunction __construct() {}\n\tfunction Widget() {}\n}", 'A class that also has __construct().' );
	$clean( "namespace App;\nclass Widget {\n\tfunction Widget() {}\n}", 'A class in a namespace: such a method never was a constructor.' );
	$clean( "class Widget {\n\tfunction other() {}\n}", 'No method named after the class.' );
	$clean( "\$object = new class {\n\tfunction run() {}\n};", 'Anonymous class.' );
};

$tests['parameters that are fine are not reported'] = static function () use ( $clean ) {
	$clean( 'function f( ?Foo $a = null ) {}', 'Explicitly nullable.' );
	$clean( 'function f( Foo|null $a = null ) {}', 'Union with null.' );
	$clean( 'function f( mixed $a = null ) {}', 'mixed already includes null.' );
	$clean( 'function f( $a = null ) {}', 'No type.' );
	$clean( 'function f( Foo $a = FOO ) {}', 'A default that is not null.' );
	$clean( 'function f( $a, $b = 1 ) {}', 'Optional after required.' );
	$clean( 'function f( $a = 1, ...$rest ) {}', 'A variadic parameter is not a required one.' );
};

$tests['implicit nullable is found on methods, closures and union types'] = static function () {
	upgw_same( array( 'signature.implicit_nullable' ), upgw_rules_in( "<?php\nclass A { function f( array \$a = null ) {} }" ) );
	upgw_same( array( 'signature.implicit_nullable' ), upgw_rules_in( "<?php\n\$f = function ( Foo \$a = NULL ) {};" ) );
	upgw_same( array( 'signature.implicit_nullable' ), upgw_rules_in( "<?php\n\$f = fn( Foo \$a = null ) => 1;" ) );
	upgw_same( array( 'signature.implicit_nullable' ), upgw_rules_in( "<?php\nfunction f( Foo|Bar \$a = null ) {}" ) );
};

$tests['strings and casts that are fine are not reported'] = static function () use ( $clean ) {
	$clean( 'echo "plain {$name} and $other and {$list[\'key\']}";', 'Braces around the whole variable.' );
	$clean( 'echo \'${name}\';', 'Single quotes do not interpolate.' );
	$clean( '$i = (int) $a; $b = (bool) $a; $f = (float) $a; $s = (string) $a; $x = (array) $a; $o = (object) $a;', 'Canonical casts.' );
	$clean( '$first = $text[0]; $item = $list[\'key\'];', 'Square brackets.' );
	$clean( "switch ( \$a ) {\n\tcase 1:\n\t\tbreak;\n\tdefault:\n\t\tbreak;\n}", 'Colons after case.' );
};

$tests['the level depends on the version the site moves to'] = static function () {
	$each = upgw_scanner()->scan( "<?php\neach( \$items );" )->findings[0];

	upgw_same( Finding::LEVEL_ERROR, $each->level_for( '8.0' ) );
	upgw_same( Finding::LEVEL_ERROR, $each->level_for( '8.6' ) );

	$nullable = upgw_scanner()->scan( "<?php\nfunction f( Foo \$a = null ) {}" )->findings[0];

	upgw_same( null, $nullable->level_for( '8.3' ), 'Nothing happens before 8.4.' );
	upgw_same( Finding::LEVEL_DEPRECATED, $nullable->level_for( '8.4' ) );

	$constructor = upgw_scanner()->scan( "<?php\nclass Widget {\n\tfunction Widget() {}\n}" )->findings[0];

	upgw_same( Finding::LEVEL_CHANGED, $constructor->level_for( '8.0' ), 'Runs, but no longer as a constructor.' );
};

$tests['what comes from the upgrade notes only is told apart from what a runtime showed'] = static function () {
	$hash = upgw_scanner()->scan( "<?php\n\$id = spl_object_hash( \$object );" )->findings[0];

	upgw_same( 'function.deprecated', $hash->rule );
	upgw_same( '8.6', $hash->deprecated );
	upgw_same( 'documented', $hash->evidence );
	upgw_same( null, $hash->level_for( '8.5' ) );
	upgw_same( Finding::LEVEL_DEPRECATED, $hash->level_for( '8.6' ) );
	upgw_true( ! $hash->runtime_checked_for( '8.6' ), 'No PHP 8.6 runtime was available, and the finding must say so.' );

	$encode = upgw_scanner()->scan( "<?php\n\$text = utf8_encode( \$text );" )->findings[0];

	upgw_same( 'verified', $encode->evidence );
	upgw_true( $encode->runtime_checked_for( '8.5' ) );
};

$tests['the worst level of a file ignores guarded code'] = static function () {
	$result = upgw_scanner()->scan( "<?php\nif ( PHP_VERSION_ID < 80000 ) {\n\teach( \$items );\n}\n\$text = utf8_encode( \$text );" );

	upgw_same( 2, count( $result->findings ) );
	upgw_same( Finding::LEVEL_DEPRECATED, $result->worst_level_for( '8.5' ) );
	upgw_same( null, $result->worst_level_for( '8.1' ) );
};

$tests['syntax removed in PHP 8 is read with the older grammar'] = static function () {
	upgw_same( ScanResult::GRAMMAR_CURRENT, upgw_scanner()->scan( "<?php\necho \$text[0];" )->grammar );

	$legacy = upgw_scanner()->scan( "<?php\necho \$text{0};" );

	upgw_same( ScanResult::GRAMMAR_LEGACY, $legacy->grammar );
	upgw_true( '' !== $legacy->parse_error, 'The reason the newest grammar rejects the file is kept.' );
};

$tests['syntax newer than PHP 7.4 is read with the newest grammar'] = static function () {
	$code = "<?php\nenum Suit: string { case Hearts = 'H'; }\nfinal class P { public function __construct( public readonly ?Suit \$suit = null ) {} }\n\$r = \$a?->b |> strtoupper( ... );\n\$m = match ( \$x ) { 1 => 'a', default => 'b' };";

	$result = upgw_scanner()->scan( $code );

	upgw_same( ScanResult::GRAMMAR_CURRENT, $result->grammar );
	upgw_same( array(), $result->findings );
};

$tests['a file that does not parse at all is reported as such, not as clean'] = static function () {
	$result = upgw_scanner()->scan( "<?php\nfunction ( {" );

	upgw_same( ScanResult::GRAMMAR_NONE, $result->grammar );
	upgw_true( '' !== $result->parse_error );
};

$tests['a file without PHP in it is clean'] = static function () {
	$result = upgw_scanner()->scan( "<html>\n<body>each( \$x ) and \$text{0}</body>\n</html>\n" );

	upgw_same( ScanResult::GRAMMAR_CURRENT, $result->grammar );
	upgw_same( array(), $result->findings );
};

$tests['every rule that states a version has a fixture'] = static function () {
	$covered = array();

	foreach ( upgw_fixtures() as $fixture ) {
		$covered[ $fixture['rule'] ] = true;
	}

	$stated = array();

	foreach ( glob( UPGW_ROOT . '/src/Engine/Rules/*.php' ) as $file ) {
		if ( preg_match_all( "/report\(\s*'([a-z_.0-9]+)'/", file_get_contents( $file ), $matches ) ) {
			foreach ( $matches[1] as $rule ) {
				$stated[ $rule ] = basename( $file );
			}
		}
	}

	upgw_true( count( $stated ) >= 9, 'The rule ids could not be read from the rule files.' );

	foreach ( $stated as $rule => $file ) {
		upgw_true( isset( $covered[ $rule ] ), "{$rule} ({$file}) has no fixture, so its versions were never checked on a real runtime." );
	}
};

return $tests;
