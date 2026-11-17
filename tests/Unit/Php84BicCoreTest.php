<?php
/**
 * PHP 8.4 backward incompatible changes, Core to GMP (tranche 8.4-bic-core):
 * code that looks like a reported use and is fine, and so must stay
 * unreported.
 *
 * Only the rule ids of this tranche are looked at, so that a rule somebody
 * else adds for the same code does not make these tests fail.
 *
 * @package UpgradeWarden
 */

$tests = array();

$mine = static function ( $code ) {
	$ids = array(
		'constant.php_debug_zts_type',
		'call.exit_argument_type',
		'class.typed_constant_redeclared',
		'call.invalid_literal_argument',
		'clone.dom_xpath',
		'signature.dom_create_document_return_type',
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

$tests['8.4-bic-core: PHP_DEBUG and PHP_ZTS used in ways that keep working are not reported'] = static function () use ( $clean, $mine ) {
	$clean( 'if ( PHP_ZTS ) { echo 1; }', 'Truth test.' );
	$clean( 'if ( PHP_ZTS == 0 ) { echo 1; }', 'Loose comparison.' );
	$clean( 'if ( PHP_DEBUG != 0 ) { echo 1; }', 'Loose not-equal.' );
	$clean( '$a = (bool) PHP_DEBUG === false;', 'Cast first.' );
	$clean( 'if ( PHP_ZTS === 1 ) { echo 1; }', 'Comparison with 1: unchanged on the builds the runtimes have.' );
	$clean( 'if ( PHP_MAJOR_VERSION === 0 ) { echo 1; }', 'Another constant.' );
	$clean( 'if ( $php_zts === 0 ) { echo 1; }', 'A variable.' );
	$clean( 'if ( Env::PHP_ZTS === 0 ) { echo 1; }', 'A class constant of the same name.' );
	$clean( "namespace App;\nuse const Vendor\\PHP_ZTS;\nif ( PHP_ZTS === 0 ) { echo 1; }", 'Imported constant of another namespace.' );

	upgw_same( array( 'constant.php_debug_zts_type' ), $mine( 'if ( 0 === \\PHP_ZTS ) { echo 1; }' ), 'Fully qualified, reversed.' );
	$clean( 'if ( PHP_ZTS === true ) { echo 1; }', 'Comparison with true: unchanged on the builds the runtimes have.' );
	$clean( 'if ( $zts === false ) { echo 1; }', 'A variable compared with false.' );
	$clean( "namespace App;\nif ( PHP_ZTS === Lib\\false ) { echo 1; }", 'A namespaced constant called false.' );
	upgw_same( array( 'constant.php_debug_zts_type' ), $mine( 'if ( PHP_DEBUG !== FALSE ) { echo 1; }' ), 'Comparison with false, upper case.' );
};

$tests['8.4-bic-core: exit and die with an int, a string, false or a variable are not reported'] = static function () use ( $clean, $mine ) {
	$clean( 'exit;', 'No argument.' );
	$clean( 'exit();', 'Empty parentheses.' );
	$clean( 'exit( 0 );', 'Int.' );
	$clean( 'exit( -1 );', 'Negative int.' );
	$clean( "die( 'Stop.' );", 'String.' );
	$clean( 'die( false );', 'False without strict types prints nothing, as before.' );
	$clean( 'exit( $status );', 'Variable.' );
	$clean( 'exit( (int) $status );', 'Cast to int.' );
	$clean( 'wp_die( true );', 'A function that is not exit.' );
	$clean( '$logger->exit( true );', 'A method called exit.' );
	$clean( "declare( strict_types = 1 );\nexit( 1 );", 'Int with strict types.' );
	$clean( "declare( strict_types = 1 );\nexit( 'Stop.' );", 'String with strict types.' );
	$clean( "declare( strict_types = 0 );\ndie( false );", 'Strict types switched off.' );

	upgw_same( array( 'call.exit_argument_type' ), $mine( 'die( TRUE );' ), 'Upper-case TRUE.' );
	upgw_same( array( 'call.exit_argument_type' ), $mine( 'exit( -0.5 );' ), 'Negative float.' );
};

$tests['8.4-bic-core: exit( false ) is reported only in the file that declares strict types'] = static function () {
	$scanner = upgw_scanner();
	$strict  = array();
	$loose   = array();

	foreach ( $scanner->scan( "<?php\ndeclare( strict_types = 1 );\nexit( false );" )->findings as $finding ) {
		$strict[] = $finding->rule . ' ' . $finding->breaks;
	}

	foreach ( $scanner->scan( "<?php\nexit( false );" )->findings as $finding ) {
		$loose[] = $finding->rule;
	}

	upgw_same( array( 'call.exit_argument_type 8.4' ), $strict, 'Strict file.' );
	upgw_same( array(), array_values( array_intersect( $loose, array( 'call.exit_argument_type' ) ) ), 'The next file, without strict types.' );
};

$tests['8.4-bic-core: classes that extend internal classes without redeclaring a typed constant are not reported'] = static function () use ( $clean, $mine ) {
	$clean( 'class A extends PDO { const LOCAL_MODE = 2; }', 'A new constant name.' );
	$clean( 'class A extends DateTime { const MYSQL = "Y-m-d H:i:s"; }', 'A new date format.' );
	$clean( 'class A extends ArrayIterator { public function x() { return self::STD_PROP_LIST; } }', 'Reading the constant.' );
	$clean( 'class A extends ArrayIterator { const std_prop_list = 1; }', 'Constant names are case-sensitive.' );
	$clean( 'class A extends Local_Pdo { const FETCH_ASSOC = 2; }', 'A user class as parent.' );
	$clean( 'class A { const FETCH_ASSOC = 2; }', 'No parent.' );
	$clean( "namespace App;\nclass PDO {}\nclass A extends PDO { const FETCH_ASSOC = 2; }", 'A class named PDO in a namespace.' );
	$clean( 'class A extends PDO { const int FETCH_ASSOC = 2; }', 'A typed constant (8.3 syntax).' );
	$clean( 'class A extends Exception { const CODE = 2; }', 'A class whose constants were not typed in 8.4.' );
	$clean( 'interface I { const ATOM = "x"; }', 'An interface.' );

	upgw_same( array( 'class.typed_constant_redeclared' ), $mine( "namespace App;\nuse PDO;\nclass A extends PDO { const FETCH_ASSOC = 2; }" ), 'Imported PDO.' );
	upgw_same( array( 'class.typed_constant_redeclared', 'class.typed_constant_redeclared' ), $mine( 'class A extends \\SplQueue { const IT_MODE_LIFO = 2, IT_MODE_FIFO = 0; }' ), 'Two constants in one statement.' );
};

$tests['8.4-bic-core: valid or computed arguments are not reported as invalid literals'] = static function () use ( $clean ) {
	$clean( 'echo round( 2.5 );', 'No mode.' );
	$clean( 'echo round( 2.5, 0, PHP_ROUND_HALF_EVEN );', 'Mode constant.' );
	$clean( 'echo round( 2.5, 0, 4 );', 'Mode 4 (PHP_ROUND_HALF_ODD).' );
	$clean( 'echo round( 2.5, 0, $mode );', 'Mode from a variable.' );
	$clean( 'echo round( 2.5, 9 );', 'A large precision.' );
	$clean( "str_getcsv( \$line, ';', '\"', '\\\\' );", 'One-byte separator.' );
	$clean( "str_getcsv( \$line, \"\\t\", '\"', '' );", 'Tab and empty escape.' );
	$clean( 'str_getcsv( $line, $separator, $enclosure, $escape );', 'Variables.' );
	$clean( "echo php_uname( 's' ), php_uname( 'n' ), php_uname();", 'Valid modes.' );
	$clean( 'echo php_uname( $mode );', 'Mode from a variable.' );
	$clean( 'imagejpeg( $image, null, 90 );', 'JPEG quality 90.' );
	$clean( 'imagejpeg( $image, null, -1 );', 'JPEG default quality.' );
	$clean( 'imagepng( $image, null, 9 );', 'PNG level 9.' );
	$clean( 'imagepng( $image );', 'PNG without level.' );
	$clean( 'imagepng( $image, null, $quality );', 'PNG level from a variable.' );
	$clean( 'imagewebp( $image, null, 150 );', 'WebP still accepts a quality above 100.' );
	$clean( 'curl_multi_select( $mh, 1.0 );', 'Positive timeout.' );
	$clean( 'curl_multi_select( $mh, -0.0 );', 'Minus zero.' );
	$clean( 'curl_multi_select( $mh );', 'Default timeout.' );
	$clean( "unserialize( \$data, array( 'allowed_classes' => array( 'WP_Post' ) ) );", 'Class names.' );
	$clean( "unserialize( \$data, array( 'allowed_classes' => false ) );", 'false.' );
	$clean( "unserialize( \$data, array( 'max_depth' => array( 1 ) ) );", 'Another option.' );
	$clean( "\$csv->str_getcsv( \$line, '||' );", 'A method.' );
	$clean( "Vendor\\round( 2.5, 0, 0 );", 'A function in a namespace.' );
	$clean( 'round( ...$args );', 'Spread arguments.' );
	$clean( "function php_uname( \$m ) { return \$m; }\necho php_uname( 'os' );", 'A function declared in the same file.' );
};

$tests['8.4-bic-core: clones that need not be a DOMXPath are not reported'] = static function () use ( $clean, $mine ) {
	$clean( 'function f( $xpath ) { return clone $xpath; }', 'A parameter.' );
	$clean( 'function f( $d ) { $xpath = new DOMXPath( $d ); $xpath = $other; return clone $xpath; }', 'Reassigned.' );
	$clean( 'function f( $d ) { $xpath = new DOMXPath( $d ); g( $xpath ); return clone $xpath; }', 'Passed to a function, which may take it by reference.' );
	$clean( 'function f( $d ) { $xpath = new DOMXPath( $d ); extract( $d ); return clone $xpath; }', 'extract() can overwrite it.' );
	$clean( 'function f( $d ) { $document = new DOMDocument(); return clone $document; }', 'Cloning a DOMDocument.' );
	$clean( 'function f() { return clone $xpath; }', 'Never assigned.' );
	$clean( '$xpath = new DOMXPath( $d ); $copy = clone $xpath;', 'Code outside a function.' );
	$clean( "namespace App;\nfunction f() { \$x = new DOMXPath(); return clone \$x; }", 'A DOMXPath class of a namespace.' );
	$clean( 'function f( $d ) { $xpath = new DOMXPath( $d ); return function () use ( &$xpath ) { return clone $xpath; }; }', 'A closure that captures the variable.' );

	upgw_same( array( 'clone.dom_xpath' ), $mine( 'function f( $d ) { return clone new \\DOMXPath( $d ); }' ), 'Clone of new DOMXPath.' );
	upgw_same( array( 'clone.dom_xpath' ), $mine( 'class A { public function f( $d ) { $x = new DOMXPath( $d ); $n = $x->query( "//a" ); $c = clone $x; } }' ), 'In a method, after a query.' );
};

$tests['8.4-bic-core: createDocument() overrides that keep working are not reported'] = static function () use ( $clean, $mine ) {
	$clean( 'class A extends DOMImplementation { public function createDocument( $n = null, $q = "", $d = null ): DOMDocument { return parent::createDocument( $n, $q ); } }', 'Return type DOMDocument.' );
	$clean( 'class A extends DOMImplementation { #[\\ReturnTypeWillChange] public function createDocument( $n = null, $q = "", $d = null ) { return parent::createDocument( $n, $q ); } }', 'ReturnTypeWillChange.' );
	$clean( 'class A extends DOMImplementation { public function createDocumentType( $q, $p = "", $s = "" ) { return parent::createDocumentType( $q, $p, $s ); } }', 'Another method.' );
	$clean( 'class A extends Local_Factory { public function createDocument() { return null; } }', 'A user class as parent.' );
	$clean( 'class A { public function createDocument() { return null; } }', 'No parent.' );

	upgw_same( array( 'signature.dom_create_document_return_type' ), $mine( "namespace App;\nuse DOMImplementation as Impl;\nclass A extends Impl { public function CreateDocument() { return null; } }" ), 'Imported under an alias, other case.' );
};

$tests['8.4-bic-core: round() modes 5 to 8 are reported as changed, not as breaking'] = static function () {
	foreach ( array( 5, 6, 7, 8 ) as $mode ) {
		$found = array();

		foreach ( upgw_scanner()->scan( "<?php\necho round( 2.5, 0, {$mode} );" )->findings as $finding ) {
			if ( 'call.invalid_literal_argument' === $finding->rule ) {
				$found[] = array( $finding->breaks, $finding->changed );
			}
		}

		upgw_same( array( array( null, '8.4' ) ), $found, "Mode {$mode} is accepted by 8.4." );
	}

	foreach ( array( '0', '9', '-1' ) as $mode ) {
		$found = array();

		foreach ( upgw_scanner()->scan( "<?php\necho round( 2.5, 0, {$mode} );" )->findings as $finding ) {
			if ( 'call.invalid_literal_argument' === $finding->rule ) {
				$found[] = array( $finding->breaks, $finding->changed );
			}
		}

		upgw_same( array( array( '8.4', null ) ), $found, "Mode {$mode} is a ValueError from 8.4." );
	}
};

return $tests;
