<?php
/**
 * PHP 8.0, Backward Incompatible Changes, Core (tranche 8.0-bic-core): code
 * that looks like what the rules of this tranche report and is fine, so must
 * stay unreported.
 *
 * Only the rule ids of this tranche are looked at, so that a rule somebody
 * else adds for the same code does not make these tests fail.
 *
 * @package UpgradeWarden
 */

$tests = array();

$mine80core = static function ( $code ) {
	$ids = array(
		'syntax.match_class_name',
		'call.non_static_method_statically',
		'callable.non_static_method_statically',
		'call.is_callable_non_static_method',
		'variable.php_errormsg',
		'call.define_case_insensitive',
		'function.autoload_declared',
		'callable.error_handler_errcontext',
		'array.negative_key_then_implicit',
		'class.parent_without_parent',
		'call.error_reporting_zero_check',
		'class.incompatible_method_signature',
		'syntax.concat_precedence',
		'class.ambiguous_trait_alias',
		'class.abstract_trait_method_mismatch',
		'class.magic_method_signature',
		'call.call_user_func_array_named_error',
		'call.call_user_func_array_named_order',
	);

	$found = array();

	foreach ( upgw_scanner()->scan( "<?php\n" . $code )->findings as $finding ) {
		if ( in_array( $finding->rule, $ids, true ) ) {
			$found[] = $finding->rule;
		}
	}

	return $found;
};

$clean80core = static function ( $code, $message ) use ( $mine80core ) {
	upgw_same( array(), $mine80core( $code ), $message . "\n      code: " . $code );
};

$tests['8.0-bic-core: match as a method, constant or part of a name is not reported'] = static function () use ( $clean80core ) {
	$clean80core( 'class Game { const MATCH = 1; public function match() { return 2; } }', 'Method and constant called match.' );
	$clean80core( 'class Matcher {} interface Matchable {} trait Match_Helpers {}', 'Names that only start with match.' );
	$clean80core( '$r = match ( $x ) { 1 => "a", default => "b" };', 'A match expression.' );
};

$tests['8.0-bic-core: static calls that are fine are not reported'] = static function () use ( $clean80core, $mine80core ) {
	$greeter = 'class Greeter { public function hello() { return 1; } public static function make() { return new self(); } }' . "\n";

	$clean80core( $greeter . 'echo Greeter::make();', 'Static method.' );
	$clean80core( $greeter . 'class Polite extends Greeter { public function hello() { return parent::hello(); } }', 'parent:: in a subclass.' );
	$clean80core( $greeter . 'class Polite extends Greeter { public function hi() { return Greeter::hello(); } }', 'Class name from an instance method of a subclass.' );
	$clean80core( 'class Greeter { public function hello() { return 1; } public function hi() { return Greeter::hello() . self::hello(); } }', 'Own class from an instance method.' );
	$clean80core( 'echo Not_In_This_File::hello();', 'Class declared elsewhere.' );
	$clean80core( $greeter . 'class Page extends Unknown_Base { public function render() { return Greeter::hello(); } }', 'Calling class whose parent is elsewhere: it may descend from Greeter.' );
	$clean80core( $greeter . '$f = function () { return Greeter::hello(); };', 'In a closure, which may be bound to a Greeter.' );
	$clean80core( $greeter . 'echo Greeter::missing();', 'Method not declared: __callStatic or a trait may answer.' );
	$clean80core( 'trait Says { public function hello() { return 1; } } class Greeter { use Says; } echo Greeter::hello();', 'Method from a trait: not looked into.' );
	$clean80core( $greeter . '$g = new Greeter(); echo call_user_func( array( $g, "hello" ) ), call_user_func( "Greeter::make" ), is_callable( array( "Greeter", "make" ) );', 'Callables with an object, or a static method.' );
	$clean80core( $greeter . 'echo call_user_func( array( "Greeter", "hello", "extra" ) ), call_user_func( "Greeter::hello::x" );', 'Not a callable shape.' );
	upgw_same( array( 'callable.non_static_method_statically' ), $mine80core( $greeter . 'class Shop { public function run() { return call_user_func( array( "Greeter", "hello" ) ); } }' ), 'Unrelated class: no $this of Greeter.' );
	upgw_same( array( 'call.non_static_method_statically' ), $mine80core( "namespace App;\n" . $greeter . 'echo \App\Greeter::hello();' ), 'Fully qualified name in a namespace.' );
	upgw_same( array( 'callable.non_static_method_statically' ), $mine80core( "namespace App;\n" . $greeter . 'echo call_user_func( "App\\\\Greeter::hello" );' ), 'Callable string with the namespace.' );
};

$tests['8.0-bic-core: $php_errormsg that the code sets itself is not reported'] = static function () use ( $clean80core ) {
	$clean80core( 'function f() { $php_errormsg = ""; return $php_errormsg; }', 'Assigned in the same function.' );
	$clean80core( 'function f( $php_errormsg ) { return $php_errormsg; }', 'A parameter.' );
	$clean80core( '$php_errormsg = "x";', 'Only written.' );
	$clean80core( 'if ( $ok ) { $php_errormsg = "x"; echo $php_errormsg; }', 'Assigned in the same top-level statement.' );
	$clean80core( 'function f() { global $php_errormsg; unset( $php_errormsg ); }', 'global and unset.' );
	$clean80core( 'echo $php_errormsg_copy, $errormsg;', 'Other names.' );
	$clean80core( 'class Feed { function error( $msg ) { if ( isset( $php_errormsg ) ) { $msg .= $php_errormsg; } return $msg; } }', 'No error silenced before the read in that scope (MagpieRSS in WordPress 4.0): never set on 7.4 either.' );
	$clean80core( 'echo isset( $php_errormsg ) ? $php_errormsg : ""; $x = @file( "a" );', 'The @ comes after the read.' );
	$clean80core( '$x = @file( "a" ); function f() { return $php_errormsg; }', 'The @ is in another scope.' );
};

$tests['8.0-bic-core: define() without a use in another case is not reported'] = static function () use ( $clean80core ) {
	$clean80core( 'define( "PLUGIN_SLUG", "x", true ); echo PLUGIN_SLUG;', 'Every use in the defined case: only a warning on 8.0.' );
	$clean80core( 'define( "PLUGIN_SLUG", "x" ); echo plugin_slug;', 'Case-sensitive constant: an error on 7.4 as well.' );
	$clean80core( 'define( "PLUGIN_SLUG", "x", false ); echo plugin_slug;', 'Third argument false.' );
	$clean80core( 'define( $name, "x", true ); echo plugin_slug;', 'Name not a literal.' );
	$clean80core( 'class A { function f() { return $this->define( "PLUGIN_SLUG", "x", true ) . plugin_slug; } }', 'A method called define.' );
};

$tests['8.0-bic-core: __autoload that is not the global function is not reported'] = static function () use ( $clean80core ) {
	$clean80core( "namespace App;\nfunction __autoload( \$c ) {}", 'Function in a namespace.' );
	$clean80core( 'class Loader { public static function __autoload( $c ) {} }', 'Method.' );
	$clean80core( 'spl_autoload_register( function ( $c ) {} );', 'spl_autoload_register().' );
};

$tests['8.0-bic-core: error handlers without a required fifth parameter are not reported'] = static function () use ( $clean80core ) {
	$clean80core( 'set_error_handler( function ( $n, $m, $f, $l ) { return true; } );', 'Four parameters.' );
	$clean80core( 'set_error_handler( function ( $n, $m, $f = "", $l = 0, $c = array() ) { return true; } );', 'Fifth parameter with a default.' );
	$clean80core( 'set_error_handler( function ( $n, $m, $f, $l, ...$rest ) { return true; } );', 'Variadic fifth parameter.' );
	$clean80core( 'set_error_handler( "handler_from_elsewhere" );', 'Function declared elsewhere.' );
	$clean80core( 'set_error_handler( array( $this, "handle" ) );', 'Method callable.' );
	$clean80core( 'set_exception_handler( function ( $a, $b, $c, $d, $e ) {} );', 'Another function.' );
};

$tests['8.0-bic-core: arrays whose keys are the same on 7.4 and 8.0 are not reported'] = static function () use ( $clean80core, $mine80core ) {
	$clean80core( '$a = array( -1 => "a", "b" );', 'First key -1: the next key is 0 either way.' );
	$clean80core( '$a = array( 0 => "z", -3 => "a", "b" );', 'First integer key not negative.' );
	$clean80core( '$a = array( -3 => "a", 5 => "b", "c" );', 'A larger key before the implicit one.' );
	$clean80core( '$a = array( -3 => "a", -2 => "b" );', 'Every key written.' );
	$clean80core( '$a = array( "a", -3 => "b", "c" );', 'Implicit key first.' );
	$clean80core( '$a = array( LOW => "a", "b" );', 'Key from a constant.' );
	$clean80core( '$a = array( -3 => "a", ...$rest );', 'Spread.' );
	$clean80core( '$a = array( "x" => 1, "-03" => "a", "b" );', 'A string key that stays a string.' );
	$clean80core( '[ -1 => $a, -2 => $b ] = $pair;', 'Destructuring.' );

	upgw_same( array( 'array.negative_key_then_implicit' ), $mine80core( '$a = array( -3 => "a", -7 => "b", "c" );' ), 'Negative keys only.' );
};

$tests['8.0-bic-core: parent in a class that has one, a trait or a closure is not reported'] = static function () use ( $clean80core ) {
	$clean80core( 'class A {} class B extends A { function f() { return parent::f(); } }', 'Class with a parent.' );
	$clean80core( 'trait T { function f() { return parent::f(); } }', 'Trait.' );
	$clean80core( 'class A { function f() { return function () { return parent::class; }; } }', 'Closure: compiles on 8.0.' );
	$clean80core( 'class A { function f() { return fn () => parent::g(); } }', 'Arrow function.' );
	$clean80core( '$o = new class extends ArrayObject { function count(): int { return parent::count(); } };', 'Anonymous class with a parent.' );
	$clean80core( 'class A { function parent() { return $this->parent; } }', 'A method and a property called parent.' );
};

$tests['8.0-bic-core: other uses of error_reporting() are not reported'] = static function () use ( $clean80core ) {
	$clean80core( 'function h( $no ) { if ( ! ( error_reporting() & $no ) ) { return; } }', 'The mask check the notes recommend.' );
	$clean80core( 'if ( error_reporting() == 0 ) { echo 1; }', 'Outside a function.' );
	$clean80core( 'function h() { return error_reporting() === E_ALL || error_reporting( 0 ) == 0; }', 'Other comparisons, and a call that sets the level.' );
	$clean80core( 'function h() { return ! $error_reporting; }', 'A variable.' );
};

$tests['8.0-bic-core: compatible overrides are not reported'] = static function () use ( $clean80core ) {
	$clean80core( 'class A { function f( $a ) {} } class B extends A { function f( $a, $b = 1 ) {} }', 'Extra optional parameter.' );
	$clean80core( 'class A { function f( $a, $b ) {} } class B extends A { function f( $a = 0, $b = 0, ...$c ) {} }', 'Fewer required parameters, variadic.' );
	$clean80core( 'class A { private function f( $a ) {} } class B extends A { function f( $a, $b ) {} }', 'Private parent method.' );
	$clean80core( 'class A { function __construct( $a ) {} } class B extends A { function __construct( $a, $b ) {} }', 'Constructor.' );
	$clean80core( 'abstract class A { abstract function f( $a ); } class B extends A { function f( $a, $b ) {} }', 'Abstract parent: already fatal on 7.4.' );
	$clean80core( 'class B extends WP_Widget { function widget( $a, $b, $c ) {} }', 'Parent in another file.' );
	$clean80core( 'interface I {} class A implements I { function f( $a ) {} } class B extends A { function f( $a, $b ) {} }', 'Parent implements an interface.' );
	$clean80core( 'class A { function f( array $a ) {} } class B extends A { function f( $a ) {} }', 'Types are not compared.' );
};

$tests['8.0-bic-core: concatenation that means the same on every version is not reported'] = static function () use ( $clean80core, $mine80core ) {
	$clean80core( 'echo $a + $b . $c;', 'Arithmetic first.' );
	$clean80core( 'echo $a . ( $b + $c );', 'Parenthesized.' );
	$clean80core( 'echo ( $a . $b ) + $c;', 'Concatenation parenthesized.' );
	$clean80core( 'echo $a . -1 . $b * $c;', 'Unary minus and multiplication.' );
	$clean80core( 'echo "a" . ( $b ) . $c;', 'Parentheses around a plain operand.' );

	upgw_same( array( 'syntax.concat_precedence' ), $mine80core( 'echo "a" . ( $b ) + 1;' ), 'Parentheses around the operand only.' );
	upgw_same( array( 'syntax.concat_precedence' ), $mine80core( 'echo $x . $a . $b - 1;' ), 'A longer chain is reported once.' );
};

$tests['8.0-bic-core: unambiguous trait aliases are not reported'] = static function () use ( $clean80core ) {
	$traits = 'trait T1 { function func() {} } trait T2 { function func() {} } trait T3 { function other() {} }' . "\n";

	$clean80core( $traits . 'class X { use T1, T2 { T1::func insteadof T2; T1::func as f1; } }', 'Alias with the trait name.' );
	$clean80core( $traits . 'class X { use T1, T3 { func as f1; } }', 'Only one trait has the method.' );
	$clean80core( 'class X { use Elsewhere1, Elsewhere2 { func as f1; } }', 'Traits from another file.' );
	$clean80core( $traits . 'class X { use T1 { func as f1; } }', 'One trait.' );
};

$tests['8.0-bic-core: classes that match the abstract methods of their traits are not reported'] = static function () use ( $clean80core ) {
	$clean80core( 'trait T { abstract function f( $a ): string; } class C { use T; function f( $a, $b = 1 ): string { return ""; } }', 'Compatible.' );
	$clean80core( 'trait T { abstract private function f(); } class C { use T; private function f( $a ) {} }', 'Private abstract: a compile error on 7.4 already.' );
	$clean80core( 'trait T { abstract function f(): Foo; } class C { use T; function f(): Bar {} }', 'Class types are not compared.' );
	$clean80core( 'trait T { abstract function f(): ?int; } class C { use T; function f(): int {} }', 'Nullable types are not compared.' );
	$clean80core( 'trait T { abstract function f( $a ); } class C extends P { use T; }', 'The class does not declare the method.' );
	$clean80core( 'trait T { function f( $a ) {} } class C { use T; function f( $a, $b ) {} }', 'Trait method not abstract.' );
};

$tests['8.0-bic-core: magic methods with accepted types are not reported'] = static function () use ( $clean80core ) {
	$clean80core(
		'class A {
			function __get( string $n ) { return 1; }
			function __set( string $n, int $v ): void {}
			function __isset( $n ): bool { return true; }
			function __unset( string $n ): void {}
			function __call( string $n, array $a ): string { return ""; }
			static function __callStatic( $n, $a ): ?string { return null; }
			function __sleep(): array { return array(); }
			function __wakeup(): void {}
			function __toString(): string { return ""; }
			function __debugInfo(): array { return array(); }
			static function __set_state( array $p ): self { return new self(); }
			function __invoke( $x ): int { return 1; }
		}',
		'Accepted signatures.'
	);
	$clean80core( 'class A { function __get( ?string $n ) {} function __unset( string|int $n ) {} }', 'Nullable and union name parameters are left alone.' );
	$clean80core( 'class A { function __toString(): never { throw new Exception(); } }', 'never, accepted from 8.1.' );
	$clean80core( 'class A { function get( int $n ): int { return 1; } function isset_( $n ): int { return 1; } }', 'Ordinary methods.' );
	$clean80core( 'function __get( int $n ): int { return 1; }', 'A function, not a method.' );
};

$tests['8.0-bic-core: call_user_func_array() calls that bind the same way are not reported'] = static function () use ( $clean80core ) {
	$greet = 'function greet( $greeting, $name ) { return 1; }' . "\n";

	$clean80core( $greet . 'call_user_func_array( "greet", array( "Hello", "World" ) );', 'Positional values.' );
	$clean80core( $greet . 'call_user_func_array( "greet", array( "greeting" => "Hello", "name" => "World" ) );', 'Names in the order of the parameters.' );
	$clean80core( $greet . 'call_user_func_array( "greet", array( "0" => "Hello", "1" => "World" ) );', 'Numeric string keys.' );
	$clean80core( $greet . 'call_user_func_array( "greet", $args );', 'Not a literal array.' );
	$clean80core( 'call_user_func_array( "sprintf", array( "format" => "%s", "x" => "y" ) );', 'Internal function: not judged.' );
	$clean80core( 'function all( ...$v ) {} call_user_func_array( "all", array( "a" => 1 ) );', 'Variadic function.' );
	$clean80core( $greet . 'call_user_func_array( "greet", array( "name" => "World" ) );', 'Too few values: fails on 7.4 already.' );
	$clean80core( 'call_user_func_array( array( $this, "greet" ), array( "a" => 1, "b" => 2 ) );', 'Method callable.' );
};

$tests['8.0-bic-core: review fixes stay unreported'] = static function () use ( $clean80core, $mine80core ) {
	$clean80core( 'function is_display_off() { return 0 == error_reporting(); } class Debug { static function on( $a, $b ) { return ! error_reporting(); } }', 'error_reporting() == 0 outside an error handler: means the same on 8.0.' );
	upgw_same( array( 'call.error_reporting_zero_check' ), $mine80core( 'class H { function handle( $no, $str ) { if ( 0 === error_reporting() ) { return true; } } } set_error_handler( array( new H(), "handle" ) );' ), 'Handler method in a file that installs it.' );
	$clean80core( 'define( "DEBUG", 1, true ); echo \\Other\\Debug, DEBUG;', 'A namespaced constant of another name is not a use in another case.' );
	upgw_same( array( 'call.define_case_insensitive' ), $mine80core( 'define( "DEBUG", 1, true ); echo \\Debug;' ), 'Fully qualified global name in another case.' );
	$clean80core( 'function greet( $greeting, $name ) { return 1; } call_user_func_array( "greet", array( "name" => "A", "name" => "B", "C" ) );', 'A key written twice is one value: left alone.' );
	$clean80core( 'class A { function __isset( string $n ): true { return true; } }', 'true is part of bool.' );
};

return $tests;
