<?php
/**
 * Class autoloader.
 *
 * Maps TYStudio\UpgradeWarden\Foo\Bar to src/Foo/Bar.php and
 * TYStudio\UpgradeWarden\Vendor\PhpParser\Foo to vendor/php-parser/PhpParser/Foo.php.
 * The classes of the Pro edition have a loader of their own, which only the
 * Pro edition contains.
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
			$base     = $root . '/vendor/php-parser/PhpParser/';
			$relative = substr( $relative, 17 );
		} else {
			$base = $root . '/src/';
		}

		$file = $base . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);
