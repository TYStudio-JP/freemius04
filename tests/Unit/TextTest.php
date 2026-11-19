<?php
/**
 * Every finding can be said in plain words, in English and in Japanese.
 *
 * @package UpgradeWarden
 */

use TYStudio\UpgradeWarden\Admin\RuleText;

if ( ! function_exists( '__' ) ) {
	/**
	 * Stand-in for the WordPress function: no translation here.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function __( $text ) { // phpcs:ignore
		return $text;
	}
}

$tests = array();

/**
 * Rule ids with a sentence of their own in Messages::describe().
 *
 * @return string[]
 */
$in_messages = static function () {
	preg_match_all( "/case '([a-z0-9_.]+)':/", file_get_contents( UPGW_ROOT . '/src/Admin/Messages.php' ), $found );

	return $found[1];
};

$tests['every rule id the engine reports has a sentence'] = static function () use ( $in_messages ) {
	$ids = array();

	foreach ( glob( UPGW_ROOT . '/src/Engine/Rules/*.php' ) as $file ) {
		preg_match_all( "/report\(\s*'([a-z0-9_.]+)'/", file_get_contents( $file ), $found );

		foreach ( $found[1] as $id ) {
			$ids[ $id ] = true;
		}
	}

	foreach ( upgw_fixtures() as $fixture ) {
		if ( 0 !== strpos( (string) $fixture['rule'], 'runtime.' ) ) {
			$ids[ $fixture['rule'] ] = true;
		}
	}

	upgw_true( count( $ids ) > 150, 'Found only ' . count( $ids ) . ' rule ids.' );

	$known   = array_flip( $in_messages() );
	$missing = array();

	foreach ( array_keys( $ids ) as $id ) {
		if ( ! isset( $known[ $id ] ) && '' === RuleText::sentence( $id ) ) {
			$missing[] = $id;
		}
	}

	upgw_same( array(), $missing, 'Add them to tools/catalog-src/rule-text.json and run: node tools/build-ruletext.mjs' );
};

$tests['no sentence is left over from a rule that is gone'] = static function () {
	$source = '';

	foreach ( glob( UPGW_ROOT . '/src/Engine/Rules/*.php' ) as $file ) {
		$source .= file_get_contents( $file );
	}

	foreach ( array_keys( RuleText::all() ) as $id ) {
		upgw_true( false !== strpos( $source, "'" . $id . "'" ), "{$id} has a sentence but no rule reports it." );
	}
};

$tests['a sentence names no PHP version and has no placeholder'] = static function () {
	foreach ( RuleText::all() as $id => $sentence ) {
		upgw_same( 0, preg_match( '/PHP \d\.\d|%s|%d|%\d\$/', $sentence ), "{$id}: {$sentence}" );
		upgw_true( strlen( $sentence ) <= 200, "{$id}: the sentence is " . strlen( $sentence ) . ' bytes long.' );
	}
};

$tests['RuleText.php and its Japanese are what the script writes from the JSON'] = static function () {
	$json = json_decode( file_get_contents( UPGW_ROOT . '/tools/catalog-src/rule-text.json' ), true );
	$all  = RuleText::all();
	$ja   = require UPGW_ROOT . '/languages/src/ja-rules.php';

	upgw_same( count( $json ), count( $all ) );

	foreach ( $json as $id => $text ) {
		upgw_same( $text['en'], $all[ $id ], $id );
		upgw_same( $text['ja'], $ja[ $text['en'] ], $id );
	}
};

return $tests;
