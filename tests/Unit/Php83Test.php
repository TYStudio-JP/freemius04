<?php
/**
 * PHP 8.3 (tranche 8.3): code that looks like what the 8.3 rules report and
 * is fine, and so must stay unreported.
 *
 * Only the rule ids of this tranche are looked at, so that a rule somebody
 * else adds for the same function does not make these tests fail.
 *
 * @package UpgradeWarden
 */

$tests = array();

$mine83 = static function ( $code, array $declared = array() ) {
	$ids = array(
		'call.get_class_without_argument',
		'argument.range_negative_step',
		'argument.range_digit_and_letter',
		'argument.range_float_step',
		'argument.file_flag_append',
		'argument.mb_strimwidth_negative_width',
		'constant.ziparchive_fl_recompress',
		'call.sqlite3_enable_exceptions_false',
		'class.interface_constant_visibility',
		'class.dom_member_conflict',
		'class.phar_constant_redeclared',
		'class.trait_static_property_redeclared',
	);

	$found = array();

	foreach ( upgw_scanner()->scan( "<?php\n" . $code, $declared )->findings as $finding ) {
		if ( in_array( $finding->rule, $ids, true ) ) {
			$found[] = $finding->rule;
		}
	}

	return $found;
};

$clean83 = static function ( $code, $message, array $declared = array() ) use ( $mine83 ) {
	upgw_same( array(), $mine83( $code, $declared ), $message . "\n      code: " . $code );
};

$tests['8.3: get_class() with an argument, outside a class, or as a method is not reported'] = static function () use ( $clean83, $mine83 ) {
	$clean83( 'class A { function f() { return get_class( $this ); } }', 'With $this.' );
	$clean83( 'class A { function f() { return get_parent_class( $this ); } }', 'get_parent_class( $this ).' );
	$clean83( 'class A { function f() { return self::class; } }', 'self::class.' );
	$clean83( 'function f() { return get_class(); }', 'Outside a class: already an error on 8.0.' );
	$clean83( 'class A { function f() { return $this->get_class(); } }', 'Method of the same name.' );
	$clean83( 'class A { function f() { return Util::get_class(); } }', 'Static method of the same name.' );
	$clean83( "namespace App;\nclass A { function f() { return Tools\\get_class(); } }", 'Function in a namespace.' );
	$clean83( 'class A { function f() { return get_class( ...$args ); } }', 'Spread arguments.' );
	$clean83( 'interface I { function f(); }', 'Interface.' );
	$clean83( 'class A { function f() { function inner() { return get_class(); } } }', 'Named function inside a method: no class scope, an Error on 8.0.' );

	upgw_same( array( 'call.get_class_without_argument' ), $mine83( 'trait T { function f() { return get_class(); } }' ), 'Inside a trait.' );
	upgw_same( array( 'call.get_class_without_argument' ), $mine83( "namespace App;\nclass A { function f() { return get_parent_class(); } }" ), 'Global fallback inside a namespace.' );
};

$tests['8.3: get_class() fix only directly in a method of a named class'] = static function () {
	$fixes = static function ( $code ) {
		$kinds = array();

		foreach ( upgw_scanner()->scan( "<?php\n" . $code )->findings as $finding ) {
			if ( 'call.get_class_without_argument' === $finding->rule ) {
				$kinds[] = empty( $finding->fix['kind'] ) ? 'none' : $finding->fix['kind'];
			}
		}

		return $kinds;
	};

	upgw_same( array( 'replace' ), $fixes( 'class A { function f() { return get_class(); } }' ), 'Method of a class.' );
	upgw_same( array( 'none' ), $fixes( 'class A { function f() { return function () { return get_class(); }; } }' ), 'Inside a closure.' );
	upgw_same( array( 'none' ), $fixes( 'trait T { function f() { return get_class(); } }' ), 'Inside a trait.' );
	upgw_same( array( 'none' ), $fixes( 'class A { function f() { return get_parent_class(); } }' ), 'get_parent_class().' );
};

$tests['8.3: range() calls that behave the same on every version are not reported'] = static function () use ( $clean83, $mine83 ) {
	$clean83( "range( 'a', 'z' );", 'Letters.' );
	$clean83( "range( 'A', 'Z' );", 'Capital letters.' );
	$clean83( "range( '0', '9' );", 'Digit strings.' );
	$clean83( 'range( 0, 9 );', 'Numbers.' );
	$clean83( 'range( 1, 100, 5 );', 'Integer step.' );
	$clean83( 'range( 5, 1, -1 );', 'Negative step on a decreasing range.' );
	$clean83( 'range( 0, 1, 0.25 );', 'Fractional step.' );
	$clean83( 'range( 0.0, 1.0, 1.0 );', 'Float boundaries.' );
	$clean83( 'range( $start, $end, -1 );', 'Boundaries from variables.' );
	$clean83( 'range( 1, 5, $step );', 'Step from a variable.' );
	$clean83( "range( '5', \$letter );", 'One boundary from a variable.' );
	$clean83( "range( '10', 'z' );", 'Two-byte numeric string: not the verified case.' );
	$clean83( 'range( ...$arguments );', 'Spread arguments.' );
	$clean83( '$this->range( 1, 5, -1 );', 'Method of the same name.' );
	$clean83( "Vendor\\range( '5', 'z' );", 'Function in a namespace.' );
	$clean83( 'range( 1, 5, -1 );', 'Own global range().', array( 'range' ) );

	upgw_same( array( 'argument.range_negative_step' ), $mine83( 'range( 1, 5, step: -2 );' ), 'Named step.' );
	upgw_same( array( 'argument.range_float_step' ), $mine83( 'range( 0, 10, 1.0 );' ), 'Step 1.0.' );
	upgw_same( array( 'argument.range_negative_step' ), $mine83( 'range( 1, 5, -1.0 );' ), 'Negative whole-number float step on an increasing range.' );
	upgw_same( array( 'argument.range_float_step' ), $mine83( 'range( 5, 1, -2.0 );' ), 'Negative whole-number float step on a decreasing range.' );
	upgw_same( array( 'argument.range_negative_step' ), $mine83( 'range( 1, 5, -0.5 );' ), 'Negative fractional step on an increasing range.' );
	upgw_same( array( 'argument.range_negative_step' ), $mine83( 'range( 0.0, 1.0, -0.25 );' ), 'Float boundaries.' );
	upgw_same( array( 'argument.range_negative_step' ), $mine83( "range( 'a', 'e', -1 );" ), 'Letters.' );
	upgw_same( array( 'argument.range_negative_step' ), $mine83( "range( '1', '5', -1 );" ), 'Digits written as strings.' );
	upgw_same( array( 'argument.range_digit_and_letter' ), $mine83( "range( '0', 'z', 1 );" ), 'Digit and letter with a step of 1.' );
	$clean83( "range( 'e', 'a', -1 );", 'Negative step on decreasing letters.' );
	$clean83( "range( 'a', 'e', -1.5 );", 'Float step on letters: [0] on every version, only a warning from 8.3.' );
	$clean83( "range( 'a', '5', -1 );", 'Digit and letter with a negative step.' );
	$clean83( "range( '1', 'a', 2 );", 'Digit and letter with a step of 2: a ValueError until 8.2, not a new break.' );
	$clean83( "range( 'aa', 'zz', -1 );", 'Strings of two bytes.' );
	$clean83( 'range( 5, 1, -0.5 );', 'Negative fractional step on a decreasing range.' );
	$clean83( 'range( 1, 5, 0.0 );', 'Zero float step: a ValueError on every version.' );
};

$tests['8.3: file() without FILE_APPEND, and FILE_APPEND elsewhere, are not reported'] = static function () use ( $clean83, $mine83 ) {
	$clean83( 'file( $path );', 'No flags.' );
	$clean83( 'file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );', 'Valid flags.' );
	$clean83( 'file( $path, $flags );', 'Flags from a variable.' );
	$clean83( 'file( $path, $flags & ~FILE_APPEND );', 'FILE_APPEND removed from the flags.' );
	$clean83( 'file_put_contents( $path, $data, FILE_APPEND );', 'file_put_contents(), where FILE_APPEND belongs.' );
	$clean83( 'file_put_contents( $path, $data, FILE_APPEND | LOCK_EX );', 'file_put_contents() with LOCK_EX.' );
	$clean83( '$fs->file( $path, FILE_APPEND );', 'Method of the same name.' );
	$clean83( "Vendor\\file( \$path, FILE_APPEND );", 'Function in a namespace.' );
	$clean83( 'file( $path, 0, $context );', 'Context as third argument.' );

	upgw_same( array( 'argument.file_flag_append' ), $mine83( 'file( $path, flags: FILE_APPEND );' ), 'Named argument.' );
};

$tests['8.3: mb_strimwidth() with a positive or unknown width is not reported'] = static function () use ( $clean83 ) {
	$clean83( "mb_strimwidth( \$text, 0, 40, '...' );", 'Positive width.' );
	$clean83( "mb_strimwidth( \$text, -10, 5, '...' );", 'Negative start, positive width.' );
	$clean83( 'mb_strimwidth( $text, 0, $width );', 'Width from a variable.' );
	$clean83( 'mb_strimwidth( $text, 0, -$width );', 'Negated variable.' );
	$clean83( 'mb_strimwidth( $text, 0, 0 );', 'Zero.' );
	$clean83( '$s->mb_strimwidth( $text, 0, -3 );', 'Method of the same name.' );
	$clean83( 'mb_strimwidth( $text, 0, -3 );', 'Polyfill declared in the plugin.', array( 'mb_strimwidth' ) );
};

$tests['8.3: other ZipArchive constants and FL_RECOMPRESS of other classes are not reported'] = static function () use ( $clean83, $mine83 ) {
	$clean83( 'ZipArchive::CREATE;', 'Another constant.' );
	$clean83( 'ZipArchive::FL_NOCASE;', 'Another flag.' );
	$clean83( 'My_Zip::FL_RECOMPRESS;', 'Another class.' );
	$clean83( "namespace App;\nZipArchive::FL_RECOMPRESS;", 'App\\ZipArchive, not the global class.' );
	$clean83( "defined( 'ZipArchive::FL_RECOMPRESS' );", 'Name in a string.' );
	$clean83( 'FL_RECOMPRESS;', 'Global constant of the same name.' );
	$clean83( 'class Z extends My_Zip { function f() { return self::FL_RECOMPRESS; } }', 'self:: in a class that does not extend ZipArchive.' );
	$clean83( 'class Z extends ZipArchive { const FL_RECOMPRESS = 0; function f() { return self::FL_RECOMPRESS; } }', 'self:: naming the subclass constant.' );
	$clean83( 'class Z { function f() { return static::FL_RECOMPRESS; } }', 'static:: without a parent.' );
	upgw_same( array( 'constant.ziparchive_fl_recompress' ), $mine83( 'class Z extends \\ZipArchive { function f() { return static::FL_RECOMPRESS; } }' ), 'static:: in a subclass.' );
	upgw_same( array( 'constant.ziparchive_fl_recompress' ), $mine83( 'class Z extends ZipArchive { const FL_RECOMPRESS = 0; function f() { return parent::FL_RECOMPRESS; } }' ), 'parent:: in a subclass.' );
};

$tests['8.3: enableExceptions() that keeps exceptions on, or on an unknown object, is not reported'] = static function () use ( $clean83, $mine83 ) {
	$clean83( "\$db = new SQLite3( ':memory:' );\n\$db->enableExceptions( true );", 'true.' );
	$clean83( "\$db = new SQLite3( ':memory:' );\n\$db->enableExceptions( \$strict );", 'Value from a variable.' );
	$clean83( '$db->enableExceptions( false );', 'Object of unknown class.' );
	$clean83( "\$db = new My_Store();\n\$db->enableExceptions( false );", 'Another class.' );
	$clean83( "function a() { \$db = new SQLite3( ':memory:' ); }\nfunction b() { \$db->enableExceptions( false ); }", 'Variable of another function.' );
	$clean83( "\$db = new SQLite3( ':memory:' );\n\$db = new My_Store();\n\$db->enableExceptions( false );", 'Variable given another object.' );
	$clean83( 'class A extends My_Store { function f() { $this->enableExceptions( false ); } }', '$this of another class.' );
	$clean83( "class A { function f() { \$this->db = new SQLite3( ':memory:' ); } }\nclass B { function g() { \$this->db->enableExceptions( false ); } }", 'Property of another class.' );
	$clean83( "namespace App;\n\$db = new SQLite3( ':memory:' );\n\$db->enableExceptions( false );", 'App\\SQLite3, not the global class.' );

	upgw_same( array( 'call.sqlite3_enable_exceptions_false' ), $mine83( "( new SQLite3( ':memory:' ) )->enableExceptions( false );" ), 'Called on new SQLite3.' );
	upgw_same( array( 'call.sqlite3_enable_exceptions_false' ), $mine83( 'function f( ?\\SQLite3 $db ) { $db->enableExceptions( false ); }' ), 'Parameter declared as ?SQLite3.' );
	upgw_same( array( 'call.sqlite3_enable_exceptions_false' ), $mine83( 'class S { function f( SQLite3 $db ) { $db->enableExceptions(); } }' ), 'Parameter of a method, no argument.' );
	$clean83( 'function f( SQLite3 $db ) { $db->enableExceptions( true ); }', 'Parameter, true.' );
	$clean83( 'function f( PDO $db ) { $db->enableExceptions( false ); }', 'Parameter of another class.' );
	$clean83( 'function f( $db ) { $db->enableExceptions( false ); }', 'Parameter without a type.' );
	$clean83( 'function f( SQLite3 $db ) { $db = new My_Store(); $db->enableExceptions( false ); }', 'Parameter given another object.' );
	$clean83( 'function f( SQLite3 $db ) { return function ( $db ) { $db->enableExceptions( false ); }; }', 'Closure parameter of the same name without a type.' );
	$clean83( 'class A extends SQLite3 { function enableExceptions( $enable = true ) { return parent::enableExceptions( $enable ); } function f() { $this->enableExceptions( false ); } }', 'Subclass with its own enableExceptions().' );
	upgw_same( array( 'call.sqlite3_enable_exceptions_false' ), $mine83( "\$db = new \\SQLite3( ':memory:' );\n\$db->EnableExceptions( enable: false );" ), 'Named argument, method name in another case.' );
};

$tests['8.3: public or unrelated constants next to interfaces are not reported'] = static function () use ( $clean83, $mine83 ) {
	$clean83( "interface I { const X = 1; }\nclass C implements I { public const X = 2; }", 'Public override.' );
	$clean83( "interface I { const X = 1; }\nclass C implements I { const X = 2; }", 'Override without a modifier is public.' );
	$clean83( "interface I { const X = 1; }\nclass C implements I { protected const Y = 2; }", 'Another constant.' );
	$clean83( "interface I { const X = 1; }\nclass C { protected const X = 2; }", 'Class does not implement the interface.' );
	$clean83( 'class C implements Elsewhere { protected const X = 2; }', 'Interface from another file.' );
	$clean83( "interface I { const X = 1; }\nclass P implements I {}\nclass C extends P { protected const X = 2; }", 'Through a parent class: checked on every version, not an 8.3 change.' );
	$clean83( "namespace A { interface I { const X = 1; } }\nnamespace B { class C implements I { protected const X = 2; } }", 'B\\I is not A\\I.' );

	upgw_same( array( 'class.interface_constant_visibility' ), $mine83( "class C implements I { private const X = 2; }\ninterface I { const X = 1; }" ), 'Interface declared below the class.' );
	upgw_same( array( 'class.interface_constant_visibility' ), $mine83( "interface I { const X = 1; }\nenum E: int implements I { case A = 1; protected const X = 2; }" ), 'Enum.' );
};

$tests['8.3: DOM subclasses whose members are compatible or unrelated are not reported'] = static function () use ( $clean83 ) {
	$clean83( 'class N extends DOMNode { public function getRootNode( array $options = array() ): DOMNode { return $this; } }', 'Method with a return type.' );
	$clean83( 'class N extends DOMElement { public function render() {} }', 'Another method.' );
	$clean83( 'class N extends DOMElement { public string $id = ""; }', 'Property with a type.' );
	$clean83( 'class N extends DOMElement { public $ID; }', 'Property names are case-sensitive.' );
	$clean83( 'class N extends My_Node { public function contains() {} }', 'Not a DOM class.' );
	$clean83( 'class N extends DOMDocument { public $id; }', 'id belongs to DOMElement only.' );
	$clean83( "namespace App;\nclass N extends DOMElement { public \$id; }", 'App\\DOMElement, not the DOM class.' );
};

$tests['8.3: Phar subclasses without redeclared constants are not reported'] = static function () use ( $clean83 ) {
	$clean83( 'class A extends Phar { const VERSION = 2; }', 'Constant Phar does not have.' );
	$clean83( 'class A extends PharData { const GZ = 4096; }', 'PharData: not affected on the runtimes.' );
	$clean83( 'class A extends My_Archive { const GZ = 4096; }', 'Another class.' );
	$clean83( 'class A extends Phar { const gz = 1; }', 'Constant names are case-sensitive.' );
};

$tests['8.3: static properties that were never shared are not reported'] = static function () use ( $clean83 ) {
	$clean83( "trait T { public static \$count = 0; }\nclass A { use T; }", 'No parent.' );
	$clean83( "trait T { public static \$count = 0; }\nclass P {}\nclass A extends P { use T; }", 'Parent without the property.' );
	$clean83( "trait T { public static \$count = 0; }\nclass P { private static \$count = 0; }\nclass A extends P { use T; }", 'Private in the parent: not inherited.' );
	$clean83( "trait T { public \$count = 0; }\nclass P { public \$count = 0; }\nclass A extends P { use T; }", 'Not static.' );
	$clean83( "trait T { public static \$count = 0; }\nclass P { public static \$count = 0; }\nclass A extends P { use T; public static \$count = 0; }", 'The class declares it itself.' );
	$clean83( 'class A extends Elsewhere { use T; }', 'Trait and parent from another file.' );
};

return $tests;
