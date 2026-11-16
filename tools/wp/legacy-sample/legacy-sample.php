<?php
/**
 * Plugin Name: Legacy Sample (Upgrade Warden test data)
 * Description: Written the way plugins were written in 2012. Used by tools/wp-check.mjs to check Upgrade Warden on a real WordPress. Never activate this on a real site.
 * Version: 1.0
 */

class Legacy_Sample_Widget {
	var $title = 'unset';

	function Legacy_Sample_Widget( $title = 'Sample' ) {
		$this->title = $title;
	}

	function initial() {
		return $this->title{0};
	}
}

function legacy_sample_describe( stdClass $item = null ) {
	return null === $item ? 'nothing' : 'something';
}

function legacy_sample_pairs( $items ) {
	$out = array();

	while ( list( $key, $value ) = each( $items ) ) {
		$out[] = "${key}=" . (integer) $value;
	}

	return $out;
}

function legacy_sample_quotes() {
	if ( function_exists( 'get_magic_quotes_gpc' ) ) {
		return get_magic_quotes_gpc();
	}

	return false;
}
