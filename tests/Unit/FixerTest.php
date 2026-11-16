<?php
/**
 * How the fixer behaves beyond the single cases the fixtures cover.
 *
 * @package UpgradeWarden
 */

use TYStudio\UpgradeWarden\Engine\ScanResult;

$tests = array();

$tests['code with nothing to fix comes back untouched'] = static function () {
	$code  = "<?php\n// Comment.\nfunction f( ?Foo \$a = null ) {\n\treturn \$a;\n}\n";
	$fixed = upgw_fixer()->fix( $code );

	upgw_same( $code, $fixed['code'] );
	upgw_same( array(), $fixed['applied'] );
	upgw_same( '', $fixed['error'] );
};

$tests['only the offending characters change; layout and comments stay'] = static function () {
	$code = "<?php\n/* keep   this */\n\$a   =   \$text{0};   // and this\n\n\n\$b = (real)   \$x;\n";
	$want = "<?php\n/* keep   this */\n\$a   =   \$text[0];   // and this\n\n\n\$b = (float)   \$x;\n";

	upgw_same( $want, upgw_fixer()->fix( $code )['code'] );
};

$tests['fixes inside one another are all made'] = static function () {
	$code = <<<'PHP'
<?php
class Old_Thing {
	var $first;

	function Old_Thing( array $options = null, $text = 'ab' ) {
		$this->first = $text{0} . ( $options ? 'x' : $text ? 'y' : 'z' );
		echo "made ${text}\n";
	}
}
PHP;

	$fixed = upgw_fixer()->fix( $code );

	upgw_same( '', $fixed['error'] );
	upgw_same( array(), upgw_rules_in( $fixed['code'] ), 'Everything fixable is fixed.' );
	upgw_same( ScanResult::GRAMMAR_CURRENT, upgw_scanner()->scan( $fixed['code'] )->grammar, 'The result parses with the newest grammar.' );
	upgw_true( false !== strpos( $fixed['code'], 'function __construct( ?array $options = null, $text = \'ab\' )' ), 'The constructor is renamed and its parameter fixed.' );
	upgw_true( false !== strpos( $fixed['code'], 'function Old_Thing( ?array $options = null, $text = \'ab\' ) {' . "\n\t\tself::__construct( \$options, \$text );" ), 'A method of the old name passes the arguments on.' );
	upgw_true( false !== strpos( $fixed['code'], '$text[0]' ) && false !== strpos( $fixed['code'], '{$text}' ), 'The fixes inside the body are made too.' );
};

$tests['fixing twice changes nothing more'] = static function () {
	foreach ( upgw_fixtures() as $name => $fixture ) {
		$once  = upgw_fixer()->fix( $fixture['code'] )['code'];
		$twice = upgw_fixer()->fix( $once );

		upgw_same( $once, $twice['code'], $name );
		upgw_same( array(), $twice['applied'], $name );
	}
};

$tests['only the chosen rules are fixed'] = static function () {
	$code  = "<?php\n\$a = \$text{0};\n\$b = (real) \$x;\n";
	$fixed = upgw_fixer()->fix( $code, array( 'syntax.real_cast' ) );

	upgw_same( "<?php\n\$a = \$text{0};\n\$b = (float) \$x;\n", $fixed['code'] );
	upgw_same( 1, count( $fixed['applied'] ) );
};

$tests['a constructor that reads its arguments by position passes all of them on'] = static function () {
	$code  = "<?php\nclass Bag {\n\tfunction Bag() {\n\t\t\$this->items = func_get_args();\n\t}\n}\n";
	$fixed = upgw_fixer()->fix( $code )['code'];

	upgw_true( false !== strpos( $fixed, "function Bag() {\n\t\tself::__construct( ...func_get_args() );" ), $fixed );
};

$tests['references and variadic parameters are passed on as they were declared'] = static function () {
	$code  = "<?php\nclass Bag {\n\tfunction Bag( &\$store, ...\$rest ) {\n\t\t\$store = \$rest;\n\t}\n}\n";
	$fixed = upgw_fixer()->fix( $code )['code'];

	upgw_true( false !== strpos( $fixed, "function Bag( &\$store, ...\$rest ) {\n\t\tself::__construct( \$store, ...\$rest );" ), $fixed );
};

$tests['a fully qualified utf8_encode() stays fully qualified'] = static function () {
	$fixed = upgw_fixer()->fix( "<?php\nnamespace App;\n\$t = \\utf8_encode( \$t );\n" )['code'];

	upgw_same( "<?php\nnamespace App;\n\$t = \\mb_convert_encoding( \$t, 'UTF-8', 'ISO-8859-1' );\n", $fixed );
};

$tests['what has no verified fix is left alone'] = static function () {
	$code  = "<?php\n\$n = (unset) \$x;\n\$pair = each( \$items );\nfunction f( \$a = 1, \$b ) {}\necho \"\${\$name}\";\n";
	$fixed = upgw_fixer()->fix( $code );

	upgw_same( $code, $fixed['code'] );
	upgw_same( array(), $fixed['applied'] );
};

$tests['a file that cannot be parsed is not touched'] = static function () {
	$code  = "<?php\nfunction ( {\n\$a = \$text{0};\n";
	$fixed = upgw_fixer()->fix( $code );

	upgw_same( $code, $fixed['code'] );
	upgw_true( '' !== $fixed['error'] );
};

return $tests;
