<?php
/**
 * PHP 8.4 deprecations (tranche 8.4-dep): code that looks like a deprecated
 * use and is fine, and so must stay unreported.
 *
 * Only the rule ids of this tranche are looked at, so that a rule somebody
 * else adds for the same function does not make these tests fail.
 *
 * @package UpgradeWarden
 */

$tests = array();

$mine = static function ( $code, array $declared = array() ) {
	$ids = array(
		'call.trigger_error_e_user_error',
		'syntax.underscore_class_name',
		'call.dateperiod_iso_constructor',
		'call.reflectionmethod_one_argument',
		'call.mysqli_store_result_mode',
		'call.session_set_save_handler_callbacks',
		'call.stream_context_set_option_two_arguments',
		'call.csv_escape_default',
		'ini.session_deprecated_setting',
		'constant.session_sid',
		'call.xml_handler_method_name',
	);

	$found = array();

	foreach ( upgw_scanner()->scan( "<?php\n" . $code, $declared )->findings as $finding ) {
		if ( in_array( $finding->rule, $ids, true ) ) {
			$found[] = $finding->rule;
		}
	}

	return $found;
};

$clean = static function ( $code, $message, array $declared = array() ) use ( $mine ) {
	upgw_same( array(), $mine( $code, $declared ), $message . "\n      code: " . $code );
};

$tests['8.4-dep: trigger_error() with another level, or a level from a variable, is not reported'] = static function () use ( $clean, $mine ) {
	$clean( "trigger_error( 'Careful.' );", 'Default level.' );
	$clean( "trigger_error( 'Careful.', E_USER_WARNING );", 'Warning.' );
	$clean( "trigger_error( 'Old.', E_USER_DEPRECATED );", 'Deprecation.' );
	$clean( "trigger_error( 'Careful.', \$level );", 'Level from a variable.' );
	$clean( "trigger_error( 'Careful.', WP_DEBUG ? E_USER_ERROR : E_USER_NOTICE );", 'Level decided at run time.' );
	$clean( "trigger_error( E_USER_ERROR );", 'The constant as the message, not the level.' );
	$clean( "\$log->trigger_error( 'Stop.', E_USER_ERROR );", 'Method call.' );
	$clean( "Logger::trigger_error( 'Stop.', E_USER_ERROR );", 'Static method call.' );
	$clean( "Vendor\\trigger_error( 'Stop.', E_USER_ERROR );", 'Function in a namespace.' );
	$clean( "trigger_error( ...\$arguments );", 'Arguments spread from an array.' );
	$clean( "set_error_handler( 'handler', E_USER_ERROR );", 'Another function that takes the constant.' );
	$clean( "if ( E_USER_ERROR === \$errno ) { exit( 1 ); }", 'Comparing with the constant.' );

	upgw_same( array( 'call.trigger_error_e_user_error' ), $mine( "trigger_error( 'Stop.', \\E_USER_ERROR );" ), 'Fully qualified constant.' );
	upgw_same( array( 'call.trigger_error_e_user_error' ), $mine( "namespace App;\nuser_error( 'Stop.', E_USER_ERROR );" ), 'Alias, inside a namespace.' );
	upgw_same( array( 'call.trigger_error_e_user_error' ), $mine( "trigger_error( 'Stop.', 256 );" ), 'The number itself.' );
};

$tests['8.4-dep: trigger_error( ..., E_USER_ERROR ) behind a version check is marked as guarded'] = static function () {
	$findings = upgw_scanner()->scan( "<?php\nif ( PHP_VERSION_ID < 80400 ) {\n\ttrigger_error( 'Stop.', E_USER_ERROR );\n}" )->findings;
	$guarded  = array();

	foreach ( $findings as $finding ) {
		if ( 'call.trigger_error_e_user_error' === $finding->rule ) {
			$guarded[] = $finding->guarded;
		}
	}

	upgw_same( array( true ), $guarded );
};

$tests['8.4-dep: names that merely contain an underscore are not reported as the class name "_"'] = static function () use ( $clean ) {
	$clean( 'class __ {}', 'Two underscores.' );
	$clean( 'class _A {}', 'Underscore prefix.' );
	$clean( 'class A_ {}', 'Underscore suffix.' );
	$clean( 'interface _I {}', 'Interface.' );
	$clean( 'trait T_ {}', 'Trait.' );
	$clean( 'function _( $text ) { return $text; }', 'A function named _ is still allowed.' );
	$clean( 'echo _( "text" );', 'gettext.' );
	$clean( '$object = new class {};', 'Anonymous class.' );
	$clean( 'class A { const _ = 1; public function _() {} }', 'Constant and method named _.' );
};

$tests['8.4-dep: DatePeriod with a start, an interval and an end is not reported'] = static function () use ( $clean, $mine ) {
	$clean( '$p = new DatePeriod( $start, $interval, $end );', 'Three arguments.' );
	$clean( '$p = new DatePeriod( $start, $interval, 4, DatePeriod::EXCLUDE_START_DATE );', 'Four arguments.' );
	$clean( '$p = new DatePeriod( ...$arguments );', 'Spread arguments.' );
	$clean( '$p = DatePeriod::createFromISO8601String( "R2/2012-07-01T00:00:00Z/P7D" );', 'The replacement.' );
	$clean( "namespace App;\n\$p = new DatePeriod( 'R2/2012-07-01T00:00:00Z/P7D' );", 'A class of that name in a namespace.' );
	$clean( '$p = new Vendor\DatePeriod( $iso );', 'Qualified name of another class.' );
	$clean( '$p = new $class( $iso );', 'Class from a variable.' );
	$clean( '$i = new DateInterval( "P7D" );', 'Another date class with one argument.' );
	$clean( '$d = new DateTime( "now", $zone );', 'DateTime.' );

	upgw_same( array( 'call.dateperiod_iso_constructor' ), $mine( "namespace App;\n\$p = new \\DatePeriod( \$iso );" ), 'Fully qualified inside a namespace.' );
	upgw_same( array( 'call.dateperiod_iso_constructor' ), $mine( "namespace App;\nuse DatePeriod;\n\$p = new DatePeriod( \$iso, 1 );" ), 'Imported.' );
};

$tests['8.4-dep: ReflectionMethod with a class and a method is not reported'] = static function () use ( $clean, $mine ) {
	$clean( '$m = new ReflectionMethod( $object, "run" );', 'Two arguments.' );
	$clean( '$m = new ReflectionMethod( "Plugin", "run" );', 'Two strings.' );
	$clean( '$m = new ReflectionMethod( ...$callable );', 'Spread arguments.' );
	$clean( '$m = ReflectionMethod::createFromMethodName( "Plugin::run" );', 'The replacement.' );
	$clean( '$c = new ReflectionClass( "Plugin" );', 'ReflectionClass takes one argument.' );
	$clean( '$f = new ReflectionFunction( "plugin_run" );', 'ReflectionFunction takes one argument.' );
	$clean( '$p = new ReflectionProperty( "Plugin", "name" );', 'ReflectionProperty.' );
	$clean( "namespace App;\n\$m = new ReflectionMethod( 'Plugin::run' );", 'A class of that name in a namespace.' );

	upgw_same( array( 'call.reflectionmethod_one_argument' ), $mine( '$m = new \ReflectionMethod( $name );' ), 'One argument from a variable is still one argument.' );
};

$tests['8.4-dep: calls with the argument counts PHP 8.4 still accepts are not reported'] = static function () use ( $clean, $mine ) {
	$clean( '$result = mysqli_store_result( $link );', 'Without $mode.' );
	$clean( '$result = $mysqli->store_result( 0 );', 'Method: the class of the object is unknown.' );
	$clean( '$result = mysqli_use_result( $link );', 'Another function.' );
	$clean( '$result = mysqli_query( $link, $sql, MYSQLI_STORE_RESULT );', 'mysqli_query() keeps its mode.' );
	$clean( '$result = mysqli_store_result( ...$arguments );', 'Spread arguments.' );

	$clean( 'session_set_save_handler( $handler );', 'Handler object.' );
	$clean( 'session_set_save_handler( $handler, true );', 'Handler object and shutdown flag.' );
	$clean( 'session_set_save_handler( ...$callbacks );', 'Spread arguments.' );
	$clean( '$sessions->session_set_save_handler( $a, $b, $c, $d, $e, $f );', 'Method call.' );

	$clean( 'stream_context_set_option( $context, "http", "method", "POST" );', 'Four arguments.' );
	$clean( 'stream_context_set_options( $context, $options );', 'The replacement.' );
	$clean( 'stream_context_set_params( $context, $params );', 'Another function with two arguments.' );
	$clean( 'stream_context_create( $options, $params );', 'Another function with two arguments.' );
	$clean( 'stream_context_set_option( ...$arguments );', 'Spread arguments.' );
	$clean( "namespace App;\nuse function Vendor\\stream_context_set_option;\nstream_context_set_option( \$context, \$options );", 'Function imported from a namespace.' );

	upgw_same( array(), $mine( 'mysqli_store_result( $link, 0 );', array( 'mysqli_store_result' ) ), 'A function the plugin declares itself.' );
	upgw_same( array( 'call.session_set_save_handler_callbacks' ), $mine( 'session_set_save_handler( $a, $b, $c, $d, $e, $f, $g );' ), 'Seven callbacks.' );
};

$tests['8.4-dep: CSV calls that pass $escape are not reported'] = static function () use ( $clean, $mine ) {
	$clean( 'fputcsv( $handle, $row, ",", \'"\', "\\\\" );', 'fputcsv with $escape.' );
	$clean( 'fputcsv( $handle, $row, ",", \'"\', "", "\n" );', 'fputcsv with $escape and $eol.' );
	$clean( '$row = fgetcsv( $handle, 0, ",", \'"\', "\\\\" );', 'fgetcsv with $escape.' );
	$clean( '$row = fgetcsv( $handle, null, ",", \'"\', $escape );', 'fgetcsv with $escape from a variable.' );
	$clean( '$row = str_getcsv( $line, ",", \'"\', "\\\\" );', 'str_getcsv with $escape.' );
	$clean( '$row = str_getcsv( $line, escape: "\\\\" );', 'Named argument.' );
	$clean( 'fputcsv( $handle, $row, escape: "" );', 'Named argument.' );
	$clean( '$row = fgetcsv( ...$arguments );', 'Spread arguments.' );
	$clean( '$row = $file->fgetcsv();', 'Method: the class of the object is unknown.' );
	$clean( '$file->fputcsv( $row );', 'Method.' );
	$clean( 'Csv::str_getcsv( $line );', 'Static method.' );
	$clean( '$rows = array_map( "str_getcsv", $lines );', 'The name as a string: the call happens elsewhere.' );
	$clean( '$line = fgets( $handle );', 'Another function.' );

	upgw_same( array(), $mine( 'my_csv( $line ); str_getcsv( $line );', array( 'str_getcsv' ) ), 'A function the plugin declares itself.' );
	upgw_same( array( 'call.csv_escape_default' ), $mine( '$row = str_getcsv( $line, separator: ";" );' ), 'Named arguments without escape.' );
	upgw_same( array( 'call.csv_escape_default' ), $mine( '$row = fgetcsv( $handle, 0, ",", \'"\' );' ), 'One argument short.' );
};

$tests['8.4-dep: session settings left at their defaults, or set from a variable, are not reported'] = static function () use ( $clean, $mine ) {
	$clean( "ini_set( 'session.use_only_cookies', 1 );", 'Keeping cookies only.' );
	$clean( "ini_set( 'session.use_only_cookies', '1' );", 'Keeping cookies only, as a string.' );
	$clean( "ini_set( 'session.use_only_cookies', true );", 'Keeping cookies only, as a boolean.' );
	$clean( "ini_set( 'session.use_only_cookies', 'On' );", 'Keeping cookies only, as a word.' );
	$clean( "ini_set( 'session.use_trans_sid', 0 );", 'Keeping the transparent id off.' );
	$clean( "ini_set( 'session.use_trans_sid', false );", 'Keeping the transparent id off, as a boolean.' );
	$clean( "ini_set( 'session.use_trans_sid', 'Off' );", 'Keeping the transparent id off, as a word.' );
	$clean( "ini_set( 'session.sid_length', 32 );", 'Default length.' );
	$clean( "ini_set( 'session.sid_length', '032' );", 'Default length with a leading zero.' );
	$clean( "ini_set( 'session.sid_bits_per_character', 4 );", 'Default bits.' );
	$clean( "ini_set( 'session.trans_sid_tags', 'a=href,area=href,frame=src,form=' );", 'Default tags.' );
	$clean( "ini_set( 'session.trans_sid_hosts', '' );", 'No hosts.' );
	$clean( "ini_set( 'session.referer_check', '' );", 'No referer check.' );
	$clean( "ini_set( 'session.use_trans_sid', \$enabled );", 'Value from a variable.' );
	$clean( "ini_set( 'session.sid_length', SESSION_ID_LENGTH );", 'Value from a constant.' );
	$clean( "ini_set( \$setting, 0 );", 'Setting from a variable.' );
	$clean( "ini_set( 'session.use_cookies', 0 );", 'A setting with a similar name.' );
	$clean( "ini_set( 'session.use_strict_mode', 1 );", 'Another session setting.' );
	$clean( "ini_set( 'session.cookie_httponly', 1 ); ini_set( 'session.gc_maxlifetime', 3600 ); ini_set( 'session.save_path', \$path );", 'Common session settings.' );
	$clean( "ini_set( 'use_trans_sid', 1 );", 'Without the session. prefix this is no session setting.' );
	$clean( "\$old = ini_get( 'session.use_trans_sid' );", 'Reading the setting.' );
	$clean( "ini_restore( 'session.use_trans_sid' );", 'Restoring the setting.' );
	$clean( "\$config->ini_set( 'session.use_trans_sid', 1 );", 'Method call.' );
	$clean( "session_start();", 'No options.' );
	$clean( "session_start( \$options );", 'Options from a variable.' );
	$clean( "session_start( array( 'cookie_lifetime' => 0, 'read_and_close' => true ) );", 'Other options.' );
	$clean( "session_start( array( 'use_only_cookies' => 1, 'use_trans_sid' => 0 ) );", 'Options at their defaults.' );
	$clean( "\$options = array( 'use_trans_sid' => 1 );", 'An array that is not passed to session_start() here.' );

	upgw_same( array( 'ini.session_deprecated_setting' ), $mine( "ini_set( 'session.use_only_cookies', false );" ), 'Switched off with a boolean.' );
	upgw_same( array( 'ini.session_deprecated_setting' ), $mine( "@ini_set( 'session.use_trans_sid', 'on' );" ), 'Switched on with a word, errors silenced.' );
	upgw_same(
		array( 'ini.session_deprecated_setting', 'ini.session_deprecated_setting' ),
		$mine( "session_start( array( 'use_only_cookies' => 0, 'cookie_lifetime' => 0, 'sid_length' => 48 ) );" ),
		'Two deprecated options in one session_start().'
	);
};

$tests['8.4-dep: something else called SID is not reported'] = static function () use ( $clean, $mine ) {
	$clean( "define( 'SID', 'shop-1' );\necho SID;", 'A constant the file defines itself.' );
	$clean( "const SID = 'shop-1';\necho SID;", 'A constant declared with const.' );
	$clean( 'echo Shop::SID;', 'Class constant.' );
	$clean( 'echo $row->SID, $row["SID"], $SID;', 'Property, array key and variable.' );
	$clean( 'echo Vendor\SID;', 'Constant in a namespace.' );
	$clean( 'echo sid;', 'Constants are case-sensitive.' );
	$clean( 'echo SIDE, MY_SID, SID_LENGTH;', 'Longer names.' );
	$clean( "if ( defined( 'SID' ) ) { \$name = 'SID'; }", 'The name as a string.' );
	$clean( 'echo session_id();', 'The function that replaces it.' );

	$findings = upgw_scanner()->scan( "<?php\nif ( defined( 'SID' ) ) {\n\t\$url .= '?' . SID;\n}" )->findings;
	$guarded  = array();

	foreach ( $findings as $finding ) {
		if ( 'constant.session_sid' === $finding->rule ) {
			$guarded[] = $finding->guarded;
		}
	}

	upgw_same( array( true ), $guarded, 'Behind defined() the finding is kept, marked as guarded.' );
	upgw_same( array( 'constant.session_sid' ), $mine( "namespace App;\n\$url .= '?' . SID;" ), 'Inside a namespace the name falls back to the global constant.' );
};

$tests['8.4-dep: XML handlers that are functions or real callables are not reported'] = static function () use ( $clean, $mine ) {
	$object = "xml_set_object( \$parser, \$this );\n";

	$clean( "function feed_open( \$p, \$n, \$a ) {}\nfunction feed_close( \$p, \$n ) {}\n{$object}xml_set_element_handler( \$parser, 'feed_open', 'feed_close' );", 'Functions declared in the same file.' );
	$clean( "{$object}xml_set_element_handler( \$parser, 'feed_open', 'feed_close' );", 'Functions declared in another file of the plugin.', array( 'feed_open', 'feed_close' ) );
	$clean( "{$object}xml_set_element_handler( \$parser, array( \$this, 'open' ), array( \$this, 'close' ) );", 'Array callables.' );
	$clean( "{$object}xml_set_element_handler( \$parser, [ \$this, 'open' ], [ \$this, 'close' ] );", 'Short array callables.' );
	$clean( "{$object}xml_set_character_data_handler( \$parser, function ( \$p, \$data ) {} );", 'Closure.' );
	$clean( "{$object}xml_set_character_data_handler( \$parser, 'Feed::text' );", 'Static method as a string.' );
	$clean( "{$object}xml_set_character_data_handler( \$parser, 'Vendor\\\\feed_text' );", 'Function in a namespace as a string.' );
	$clean( "{$object}xml_set_character_data_handler( \$parser, \$handler );", 'Handler from a variable.' );
	$clean( "{$object}xml_set_character_data_handler( \$parser, 'trim' );", 'An internal function.' );
	$clean( "{$object}xml_set_element_handler( \$parser, null, null );", 'Removing the handlers.' );
	$clean( "xml_set_element_handler( \$parser, 'open', 'close' );", 'No xml_set_object in the file: the names can only be functions declared elsewhere.' );
	$clean( "{$object}\$reader->xml_set_element_handler( \$parser, 'open', 'close' );", 'Method call.' );
	$clean( "{$object}xml_parser_set_option( \$parser, XML_OPTION_CASE_FOLDING, 0 );", 'Another parser function.' );

	upgw_same( array( 'call.xml_handler_method_name' ), $mine( "{$object}xml_set_element_handler( \$parser, 'open', array( \$this, 'close' ) );" ), 'One of two handlers is a method name.' );
};

$tests['8.4-dep: stream_context_set_option() as the fallback for stream_context_set_options() is marked as guarded'] = static function () {
	$code     = "<?php\nif ( function_exists( 'stream_context_set_options' ) ) {\n\tstream_context_set_options( \$context, \$options );\n} else {\n\tstream_context_set_option( \$context, \$options );\n}";
	$findings = upgw_scanner()->scan( $code )->findings;
	$guarded  = array();

	foreach ( $findings as $finding ) {
		if ( 'call.stream_context_set_option_two_arguments' === $finding->rule ) {
			$guarded[] = $finding->guarded;
		}
	}

	upgw_same( array( true ), $guarded );
};

return $tests;
