<?php
/**
 * Plugin Name: Runtime Sample (Upgrade Warden test data)
 * Description: Does things that depend on values and that PHP 8 treats differently from PHP 7. Used by tools/wp-check.mjs to check the run-time log on a real WordPress. Never activate this on a real site.
 * Version: 1.0
 */

add_action(
	'init',
	static function () {
		if ( empty( $_GET['upgw_sample'] ) ) {
			return;
		}

		$nothing = null;

		// PHP 8.1 and later: a deprecation notice, here even silenced with @.
		$length = @strlen( $nothing );

		// PHP 7: a warning, and the page goes on. PHP 8: a TypeError; the page stops here.
		$total = count( $nothing );

		echo 'runtime sample finished: ', (int) $length + (int) $total;
	}
);
