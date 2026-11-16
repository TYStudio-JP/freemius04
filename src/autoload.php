<?php
/**
 * Class autoloader.
 *
 * Maps TYStudio\UpgradeWarden\Foo\Bar to src/Foo/Bar.php,
 * TYStudio\UpgradeWarden\Pro\Foo to premium/Foo.php and
 * TYStudio\UpgradeWarden\Vendor\PhpParser\Foo to lib/php-parser/PhpParser/Foo.php.
 *
 * @package UpgradeWarden
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'TYStudio\\UpgradeWarden\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$root     = dirname( __DIR__ );

		if ( 0 === strpos( $relative, 'Vendor\\PhpParser\\' ) ) {
			$base     = $root . '/lib/php-parser/PhpParser/';
			$relative = substr( $relative, 17 );
		} elseif ( 0 === strpos( $relative, 'Pro\\' ) ) {
			$base     = $root . '/premium/';
			$relative = substr( $relative, 4 );
		} else {
			$base = $root . '/src/';
		}

		$file = $base . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);
