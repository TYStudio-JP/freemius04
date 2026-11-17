<?php
/**
 * PHP 8.0 deprecations (tranche 8.0-dep): code that looks like a deprecated
 * use and is fine, or is not certain, and so must stay unreported.
 *
 * Only the rule ids of this tranche are looked at, so that a rule somebody
 * else adds for the same code does not make these tests fail.
 *
 * @package UpgradeWarden
 */

$tests = array();

$mine = static function ( $code ) {
	$ids = array(
		'signature.null_default_before_required',
		'call.reflection_function_is_disabled',
		'call.reflection_parameter_type_method',
	);

	$found = array();

	foreach ( upgw_scanner()->scan( "<?php\n" . $code )->findings as $finding ) {
		if ( in_array( $finding->rule, $ids, true ) ) {
			$found[] = $finding->rule;
		}
	}

	return $found;
};

$clean = static function ( $code, $message ) use ( $mine ) {
	upgw_same( array(), $mine( $code ), $message . "\n      code: " . $code );
};

$tests['null defaults that PHP accepts are not reported'] = static function () use ( $clean ) {
	$clean( 'function f( stdClass $a = null, $b ) {}', 'the "Type $param = null" exception of the 8.0 notes (signature.implicit_nullable handles it from 8.4)' );
	$clean( 'function f( int $a = null, $b ) {}', 'a scalar type with a null default before a required parameter' );
	$clean( 'function f( $a, $b = null ) {}', 'a null default after the required parameters' );
	$clean( 'function f( $a = null, $b = null ) {}', 'only optional parameters' );
	$clean( 'function f( $a = null, ...$rest ) {}', 'a variadic parameter is not required' );
	$clean( 'function f( $a = null, $b = 1, ...$rest ) {}', 'optional parameters before a variadic one' );
	$clean( 'function f( int|string $a = null, $b ) {}', 'a union without null behaves like a plain type' );
	$clean( 'function f( int|null $a = null, $b ) {}', 'a union naming null: not verifiable on 7.4, so not reported' );
	$clean( 'function f( $a = NULLX, $b ) {}', 'a constant that is not null' );
};

$tests['other defaults before a required parameter are left to signature.required_after_optional'] = static function () use ( $clean ) {
	$clean( "function f( \$a = 'x', \$b ) {}", 'a string default' );
	$clean( 'function f( $a = array(), $b ) {}', 'an array default' );
};

$tests['the deprecation version follows the type'] = static function () {
	$versions = array();

	foreach ( upgw_scanner()->scan( "<?php\nfunction f( \$a = null, ?Foo \$b = null, mixed \$c = null, \$d ) {}" )->findings as $finding ) {
		if ( 'signature.null_default_before_required' === $finding->rule ) {
			$versions[] = $finding->deprecated;
		}
	}

	upgw_same( array( '8.0', '8.1', '8.3' ), $versions, 'untyped 8.0, nullable 8.1, mixed 8.3' );
};

$tests['method names that are not certainly reflection are not reported'] = static function () use ( $clean ) {
	$clean( 'function f( $field ) { return $field->isDisabled(); }', 'isDisabled() on an unknown object' );
	$clean( 'class A { function g() { return $this->getClass(); } }', 'getClass() on $this' );
	$clean( 'function f( $route ) { foreach ( $route->getParameters() as $p ) { $p->getClass(); } }', 'getParameters() of an unknown object' );
	$clean( 'function f( Request $p ) { return $p->isArray(); }', 'a parameter of another class' );
	$clean( 'function f() { $p = new My\ReflectionParameter( "f", 0 ); return $p->getClass(); }', 'a namespaced class of the same name' );
	$clean( '$p = new ReflectionParameter( "f", 0 ); $p->getClass();', 'top-level code is not followed' );
	$clean( 'function f() { $m = new ReflectionMethod( "A", "b" ); return $m->isDisabled(); }', 'isDisabled() is only deprecated on ReflectionFunction' );
	$clean( 'function f() { $p = new ReflectionFunction( "f" ); return $p->getClass(); }', 'getClass() on something that is not a parameter' );
	$clean( 'function f() { $p = new ReflectionParameter( "f", 0 ); return $p->getType(); }', 'getType() is the replacement' );
	$clean( 'function f() { $p = new ReflectionParameter( "f", 0 ); return $p->getClass( 1 ); }', 'a call with arguments is not the reflection method' );
};

$tests['a variable that may hold something else is not followed'] = static function () use ( $clean ) {
	$clean( 'function f( $x ) { $p = new ReflectionParameter( "f", 0 ); $p = $x; return $p->getClass(); }', 'reassigned from an unknown value' );
	$clean( 'function f( $ref, $o ) { foreach ( $ref->getParameters() as $p ) { $p = $o; $p->getClass(); } }', 'reassigned in the loop' );
	$clean( 'function f( $x ) { $p = new ReflectionParameter( "f", 0 ); list( $p ) = $x; return $p->getClass(); }', 'destructured' );
	$clean( 'function f( $x ) { $p = new ReflectionParameter( "f", 0 ); [ $p ] = $x; return $p->getClass(); }', 'destructured with brackets' );
	$clean( 'function f( $x ) { $p = new ReflectionParameter( "f", 0 ); $g = function () use ( &$p ) { $p = 1; }; return $p->getClass(); }', 'captured by reference' );
	$clean( 'function f( $x ) { $p = new ReflectionParameter( "f", 0 ); extract( $x ); return $p->getClass(); }', 'extract()' );
	$clean( 'function f( $x ) { $p = new ReflectionParameter( "f", 0 ); return array_map( fn( $p ) => $p->getClass(), $x ); }', 'shadowed by an arrow function parameter' );
	$clean( 'function f( $x ) { global $p; return $p->getClass(); }', 'a global' );
	$clean( 'function f() { static $p; return $p->isArray(); }', 'a static variable' );
	$clean( 'function f( &$p ) { $p = new ReflectionParameter( "f", 0 ); return $p->isArray(); }', 'a by-reference parameter' );
	$clean( 'function f() { $p = new ReflectionParameter( "f", 0 ); return function () use ( $p ) { return $p->isArray(); }; }', 'a closure is not followed into' );
	$clean( 'function f( $k ) { foreach ( ( new ReflectionFunction( "f" ) )->getParameters() as $p => $v ) { $p->isArray(); } }', 'the key of the loop' );
};

$tests['reflection calls are reported along the usual chains'] = static function () use ( $mine ) {
	$code = 'function f( $c ) {
		$r = new ReflectionClass( $c );
		foreach ( $r->getMethods() as $m ) {
			foreach ( $m->getParameters() as $p ) {
				$p->getClass();
				$p->isCallable();
			}
		}
		$r->getMethod( "x" )->getParameters()[0]->isArray();
		$r->getConstructor()?->getParameters()[1]->getClass();
	}';

	upgw_same( array_fill( 0, 4, 'call.reflection_parameter_type_method' ), $mine( $code ), 'getMethods(), getMethod(), getConstructor() chains' );
};

$tests['a typed arrow function parameter is followed'] = static function () use ( $mine, $clean ) {
	upgw_same( array( 'call.reflection_parameter_type_method' ), $mine( '$f = fn( \\ReflectionParameter $p ) => $p->isArray();' ), 'arrow function with a ReflectionParameter parameter' );
	upgw_same( array( 'call.reflection_parameter_type_method' ), $mine( 'namespace N; use ReflectionParameter; function f( ReflectionParameter $p ) { return $p->GETCLASS(); }' ), 'namespaced code, imported class, upper-case method name' );
	$clean( '$f = fn( \\ReflectionParameter $p ) => array( $p = new Foo(), $p->isArray() );', 'an arrow function that reassigns its parameter' );
	$clean( '$f = fn( $p ) => $p->isArray();', 'an untyped arrow function parameter' );
	$clean( 'function f( ReflectionParameter $p ) { return fn( Foo $p ) => $p->isArray(); }', 'an arrow parameter shadowing a reflection parameter' );
};

$tests['a reflection call behind a PHP version check is marked as guarded'] = static function () {
	$code  = "<?php\nfunction f( ReflectionParameter \$p ) {\n\tif ( PHP_VERSION_ID < 80000 ) {\n\t\treturn \$p->getClass();\n\t}\n\treturn \$p->getType();\n}";
	$flags = array();

	foreach ( upgw_scanner()->scan( $code )->findings as $finding ) {
		if ( 'call.reflection_parameter_type_method' === $finding->rule ) {
			$flags[] = $finding->guarded;
		}
	}

	upgw_same( array( true ), $flags, 'one guarded finding' );
};

return $tests;
