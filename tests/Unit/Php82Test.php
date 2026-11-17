<?php
/**
 * PHP 8.2 (tranche 8.2): code that looks like what the Php82* rules report
 * and is fine, or cannot be judged from the file, and so must stay unreported.
 *
 * What the rules do report is checked by FixturesTest against the fixtures
 * named *~8.2*. Where a comment names a runtime fixture, that fixture shows on
 * real PHP what the code below does.
 *
 * Only the rule ids of this tranche are looked at, so that a rule somebody
 * else adds for the same code does not make these tests fail.
 *
 * @package UpgradeWarden
 */

$tests = array();

$mine82 = static function ( $code ) {
	$ids = array(
		'property.dynamic_on_this',
		'callable.partially_supported',
		'argument.mb_non_text_encoding',
		'signature.tentative_return_type_changed',
	);

	$found = array();

	foreach ( upgw_scanner()->scan( "<?php\n" . $code )->findings as $finding ) {
		if ( in_array( $finding->rule, $ids, true ) ) {
			$found[] = $finding->rule;
		}
	}

	return $found;
};

$clean82 = static function ( $code, $message ) use ( $mine82 ) {
	upgw_same( array(), $mine82( $code ), $message );
};

$tests['8.2: declared, allowed or undecidable properties on $this are not reported'] = static function () use ( $clean82, $mine82 ) {
	$clean82( 'class A { public $count; public function f() { $this->count = 1; } }', 'Declared property.' );
	$clean82( 'class A { var $count; private $other, $third = 2; public function f() { $this->count = 1; $this->third = 3; } }', 'var and grouped declarations.' );
	$clean82( 'class A { public function __construct( private $count = 0 ) {} public function f() { $this->count = 1; } }', 'Promoted constructor parameter.' );
	// runtime.dynamic_property_allowed.
	$clean82( "#[\\AllowDynamicProperties]\nclass A { public function f() { \$this->count = 1; } }", 'The attribute.' );
	$clean82( "#[AllowDynamicProperties]\nclass A { public function f() { \$this->count = 1; } }", 'The attribute, not fully qualified.' );
	// runtime.dynamic_property_magic_set.
	$clean82( 'class A { public function __set( $n, $v ) {} public function f() { $this->count = 1; } }', '__set() takes the assignment.' );
	$clean82( 'class A { public function __SET( $n, $v ) {} public function f() { $this->count = 1; } }', '__set() in another case.' );
	$clean82( 'class A extends Base { public function f() { $this->count = 1; } }', 'The parent may declare it, allow it or have __set().' );
	$clean82( 'class A extends stdClass { public function f() { $this->count = 1; } }', 'stdClass allows dynamic properties.' );
	// runtime.dynamic_property_from_trait.
	$clean82( 'class A { use Has_Count; public function f() { $this->count = 1; } }', 'A trait may declare it.' );
	// runtime.dynamic_property_declared_by_child.
	$clean82( 'abstract class A { public function f() { $this->count = 1; } }', 'Abstract: a child may declare it.' );
	$clean82( 'readonly class A { public function f() { $this->count = 1; } }', 'Readonly class: an Error, not a deprecation.' );
	$clean82( 'trait T { public function f() { $this->count = 1; } }', 'A trait: the class that uses it decides.' );
	$clean82( 'class A { public function f() { $fn = function () { $this->count = 1; }; $g = fn() => $this->other = 2; } }', 'A closure may be bound to another object.' );
	$clean82( 'class A { public static function f() { $o = new self(); $o->count = 1; } }', 'Not $this.' );
	// runtime.dynamic_property_other_object.
	$clean82( '$post = new WP_Post(); $post->extra = 1; $this->count = 1;', 'Other objects, and $this outside a class.' );
	$clean82( 'class A { public function f() { $this->$name = 1; $this->{"x"} = 2; } }', 'Dynamic names.' );
	$clean82( 'class A { public $items = array(); public function f() { $this->items[] = 1; $this->items["k"] = 2; } }', 'Declared array property.' );
	$clean82( 'class A { public $cache; public function f() { $this->cache ??= 1; echo $this->other; } }', 'Declared, and an undeclared one only read.' );
	$clean82( 'class A { public function f() { list( $this->a, $this->b ) = array( 1, 2 ); } }', 'list() is not looked at.' );

	$clean82( 'class A { public $count = 0; private $log = ""; public function f() { $this->count += 1; $this->count++; --$this->count; $this->log .= "x"; $this->count[0]++; } }', 'Compound assignments and ++/-- on declared properties.' );
	$clean82( 'class A extends B { public function f() { $this->count += 1; $this->hits++; } }', 'Compound assignment and ++ where the parent may declare it.' );
	$clean82( 'class A { public function __set( $n, $v ) {} public function f() { $this->count .= "x"; } }', 'Compound assignment with __set().' );

	upgw_same( array( 'property.dynamic_on_this' ), $mine82( 'class A { public function f() { $this->count = 1; $this->count = 2; } public function g() { $this->count = 3; } }' ), 'One finding per class and property.' );
	upgw_same( array( 'property.dynamic_on_this', 'property.dynamic_on_this' ), $mine82( 'class A { public function f() { $this->total += 1; $this->hits++; $this->total .= "x"; } }' ), 'Compound assignment and ++, one finding per property.' );
	upgw_same( array( 'property.dynamic_on_this', 'property.dynamic_on_this' ), $mine82( 'class A { public function f() { $this->count = 1; } } class B { public function f() { $this->count = 1; } }' ), 'One per class.' );
};

$tests['8.2: callables that $callable() accepts, or that are not literals, are not reported'] = static function () use ( $clean82, $mine82 ) {
	$sorter = "class Base_Sorter { public static function pick() { return 1; } }\nclass Sorter extends Base_Sorter {\n\tpublic function run() {\n\t\t%s;\n\t}\n}";
	$in     = static function ( $call ) use ( $sorter ) {
		return sprintf( $sorter, $call );
	};

	// runtime.partially_supported_callable_plain.
	$clean82( $in( 'return call_user_func( "Sorter::pick" ) + call_user_func( array( "Sorter", "pick" ) ) + call_user_func( array( $this, "pick" ) )' ), 'Class name, array with class name, object and method.' );
	$clean82( $in( 'return call_user_func( array( self::class, "pick" ) ) + call_user_func( array( static::class, "pick" ) ) + call_user_func( array( parent::class, "pick" ) )' ), '::class constants.' );
	$clean82( $in( 'return call_user_func( array( new static(), "pick" ) ) + call_user_func( "pick" )' ), 'An object, and a plain function name.' );
	$clean82( $in( 'return array_filter( "self::pick", $a ) + usort( "parent::pick", $a ) + array_map( $a, "static::pick" )' ), 'The callable in the wrong position.' );
	// runtime.partially_supported_callable_variable.
	$clean82( $in( '$callback = "self::pick"; return call_user_func( $callback )' ), 'A callable in a variable.' );
	$clean82( $in( 'return add_action( "init", "self::pick" ) + add_filter( "x", array( "self", "pick" ) )' ), 'Functions of WordPress or the plugin.' );
	$clean82( $in( 'spl_autoload_register( array( self::class, "pick" ) ); register_shutdown_function( array( $this, "pick" ) ); set_error_handler( static::class . "::pick" ); return array_walk_recursive( $a, "Sorter::pick" )' ), 'Supported callables for the other callback functions.' );
	$clean82( $in( 'return call_user_func( callback: "self::pick" )' ), 'Named argument.' );
	$clean82( $in( 'return call_user_func( ...$args )' ), 'Spread argument.' );
	$clean82( $in( 'return call_user_func( "self::pick::more" ) + call_user_func( "selfish::pick" ) + call_user_func( "self::" . $name )' ), 'Not one of the forms.' );
	$clean82( $in( 'return call_user_func( array( "self", "pick", 3 ) ) + call_user_func( array( "a" => "self", "b" => "pick" ) )' ), 'Arrays of another shape.' );
	$clean82( $in( 'return Other::fromCallable( "self::pick" )' ), 'fromCallable() of another class.' );
	$clean82( "function run() { return call_user_func( 'self::pick' ) + call_user_func( array( 'parent', 'pick' ) ); }", 'Outside a class: an Error on every version.' );
	$clean82( "function call_user_func_alt() {}\nclass A { public function f() { \\App\\call_user_func( 'self::f' ); } }", 'A qualified call to a namespaced function.' );

	upgw_same( array( 'callable.partially_supported' ), $mine82( "namespace App;\nclass A extends B { public function f() { return call_user_func( 'parent::f' ); } }" ), 'Unqualified in a namespace falls back to the global function.' );
	upgw_same( array( 'callable.partially_supported' ), $mine82( "class A { public function f() { return function () { return call_user_func( 'self::f' ); }; } }" ), 'A closure in a class keeps the class scope.' );
};

$tests['8.2: encodings that are text, the source encoding and variables are not reported'] = static function () use ( $clean82, $mine82 ) {
	$clean82( 'echo mb_convert_encoding( $s, "UTF-8", "ISO-8859-1" ), mb_strlen( $s, "UTF-8" ), mb_substr( $s, 0, 1, "ASCII" );', 'Text encodings.' );
	// runtime.mb_convert_encoding_from_non_text.
	$clean82( 'echo mb_convert_encoding( $s, "UTF-8", "HTML-ENTITIES" ), mb_convert_encoding( $s, "UTF-8", array( "BASE64" ) );', 'As the source encoding.' );
	// runtime.mb_non_text_encoding_not_raised.
	$clean82( 'echo mb_detect_encoding( $s, "HTML-ENTITIES" ), mb_internal_encoding( "BASE64" ), mb_http_output( "QPrint" ), mb_detect_order( "UUENCODE" ), mb_preferred_mime_name( "HTML-ENTITIES" );', 'Functions that raise nothing.' );
	// runtime.mb_non_text_encoding_variable.
	$clean82( '$e = "HTML-ENTITIES"; echo mb_convert_encoding( $s, $e, "UTF-8" ), mb_convert_encoding( $s, HTML_ENC, "UTF-8" );', 'Encoding in a variable or a constant.' );
	$clean82( 'echo mb_strlen( "HTML-ENTITIES" ), mb_substr( "BASE64", 0, 1 ), mb_strpos( $s, "QPrint" );', 'The name as the text, not the encoding.' );
	$clean82( 'echo mb_convert_encoding( string: $s, to_encoding: "HTML-ENTITIES" ), mb_strlen( ...$args );', 'Named and spread arguments.' );
	$clean82( 'echo base64_encode( $s ), htmlentities( $s, ENT_QUOTES, "UTF-8" ), iconv( "UTF-8", "BASE64", $s );', 'Other functions.' );
	$clean82( "namespace App;\nfunction mb_strlen( \$s, \$e ) {}\necho \\App\\mb_strlen( \$s, 'BASE64' );", 'A namespaced function.' );
	$clean82( 'echo mb_convert_encoding( $s, "HTML-ENTITIES-X", "UTF-8" ), mb_strlen( $s, "BASE64URL" );', 'Other names.' );

	upgw_same( array( 'argument.mb_non_text_encoding' ), $mine82( "namespace App;\necho mb_strlen( \$s, 'base64' );" ), 'Unqualified in a namespace, lower case.' );
};

$tests['8.2: overrides that fit the new tentative return type, or other methods, are not reported'] = static function () use ( $clean82, $mine82 ) {
	// runtime.tentative_return_type_static.
	$clean82( 'class D extends DateTime { public static function createFromImmutable( $o ): static { return new static(); } }', 'Already static.' );
	$clean82( 'class D extends DateTime { public static function createFromImmutable( $o ): never { throw new Exception(); } }', 'never fits every type.' );
	// runtime.tentative_return_type_missing: an 8.1 matter.
	$clean82( 'class D extends DateTime { public static function createFromImmutable( $o ) { return new static(); } }', 'No return type.' );
	// runtime.tentative_return_type_will_change.
	$clean82( "class D extends DateTime {\n#[\\ReturnTypeWillChange]\npublic static function createFromImmutable( \$o ): DateTime { return new static(); } }", 'The attribute.' );
	$clean82( 'class D extends DateTime { public static function createFromMutable( $o ): DateTime { return new static(); } public function format( $f ): string { return ""; } }', 'Other methods of DateTime.' );
	$clean82( 'class D extends DateTimeImmutable { public static function createFromImmutable( $o ): DateTimeImmutable { return new static(); } }', 'The method of the other class.' );
	$clean82( 'class F extends SplFileObject { public function hasChildren(): false { return false; } public function getChildren(): null { return null; } }', 'false and null.' );
	$clean82( 'class F extends Local_File { public function hasChildren(): bool { return false; } }', 'Not known to extend SplFileObject.' );
	$clean82( 'class F extends RecursiveArrayIterator { public function hasChildren(): bool { return false; } public function getChildren(): ?RecursiveIterator { return null; } }', 'Other iterators keep the old type.' );
	$clean82( "namespace App;\nclass D extends DateTime { public static function createFromImmutable( \$o ): DateTime { return new static(); } }", 'App\\DateTime is another class.' );
	$clean82( 'interface I { public static function createFromImmutable( $o ): DateTime; }', 'An interface.' );

	upgw_same( array( 'signature.tentative_return_type_changed' ), $mine82( "namespace App;\nclass D extends \\DateTime { public static function createFromImmutable( \$o ): \\DateTime { return new static(); } }" ), 'Fully qualified in a namespace.' );
	upgw_same( array( 'signature.tentative_return_type_changed' ), $mine82( 'class D extends DateTime { public static function CREATEFROMIMMUTABLE( $o ): ?DateTime { return null; } }' ), 'Method name in another case, nullable type.' );
};

return $tests;
