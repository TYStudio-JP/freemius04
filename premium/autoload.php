<?php
/**
 * Class autoloader of the Pro edition.
 *
 * Maps TYStudio\UpgradeWarden\Pro\Foo\Bar to premium/Foo/Bar.php.
 *
 * @package UpgradeWarden
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'TYStudio\\UpgradeWarden\\Pro\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$file = __DIR__ . '/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);
