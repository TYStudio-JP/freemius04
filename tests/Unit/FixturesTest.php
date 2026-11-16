<?php
/**
 * Ties the scanner and the fixer to what real PHP runtimes showed.
 *
 * A fixture states a claim, and tools/verify-fixtures.mjs has run its code on
 * PHP 7.4 to 8.5 and recorded the outcome in tools/fixtures/observed.json.
 * These tests make sure the engine says exactly what was verified there, and
 * that the fixer writes exactly the fixed program that was run there.
 *
 * @package UpgradeWarden
 */

$tests    = array();
$observed = upgw_observed();

$tests['there are fixtures, and each has a verified record'] = static function () use ( $observed ) {
	$fixtures = upgw_fixtures();

	upgw_true( count( $fixtures ) > 0, 'No fixtures found.' );

	foreach ( $fixtures as $name => $fixture ) {
		upgw_true( isset( $observed['fixtures'][ $name ] ), "{$name} has no record. Run: cd tools && node verify-fixtures.mjs" );
		upgw_same( 'verified', $observed['fixtures'][ $name ]['verdict'], "{$name}: the claim did not hold on the real runtimes." );
		upgw_same( $fixture['claim'], $observed['fixtures'][ $name ]['claim'], "{$name}: the claim changed after it was verified. Run verify-fixtures again." );

		if ( null !== $fixture['fixed'] ) {
			upgw_same( 'same output on every version', $observed['fixtures'][ $name ]['fix'], "{$name}: the fixed program was not verified." );
		}
	}
};

foreach ( upgw_fixtures() as $name => $fixture ) {
	// A "runtime." fixture records behaviour that depends on values and cannot
	// be seen in the source. It is evidence for the run-time monitor; the
	// scanner has no rule for it.
	if ( 0 === strpos( (string) $fixture['rule'], 'runtime.' ) ) {
		continue;
	}

	$tests[ "{$name}: the scanner reports the verified claim" ] = static function () use ( $fixture ) {
		$result = upgw_scanner()->scan( $fixture['code'] );
		$claim  = $fixture['claim'] + array(
			'deprecated_in' => null,
			'changed_in'    => null,
			'breaks_in'     => null,
		);

		upgw_true( count( $result->findings ) > 0, 'The scanner found nothing in the fixture.' );

		// A guard fixture: the runtimes showed that nothing happens, and the
		// scanner has to mark what it finds as guarded.
		if ( ! empty( $fixture['scan']['guarded'] ) ) {
			foreach ( $result->findings as $finding ) {
				upgw_same( $fixture['rule'], $finding->rule, "Unexpected finding on line {$finding->line}." );
				upgw_true( $finding->guarded, "The finding on line {$finding->line} is not marked as guarded." );
			}

			return;
		}

		foreach ( $result->findings as $finding ) {
			upgw_same( $fixture['rule'], $finding->rule, "Unexpected finding on line {$finding->line}." );
			upgw_true( ! $finding->guarded, "The finding on line {$finding->line} is marked as guarded." );
			upgw_same( $claim['deprecated_in'], $finding->deprecated, 'Version of the deprecation.' );
			upgw_same( $claim['changed_in'], $finding->changed, 'Version of the behaviour change.' );
			upgw_same( $claim['breaks_in'], $finding->breaks, 'Version from which the code no longer runs.' );
		}
	};

	if ( null === $fixture['fixed'] ) {
		continue;
	}

	$tests[ "{$name}: the fixer writes the verified fixed program" ] = static function () use ( $fixture ) {
		$fixed = upgw_fixer()->fix( $fixture['code'] );

		upgw_same( '', $fixed['error'], 'The fixer reported an error.' );
		upgw_same( $fixture['fixed'], $fixed['code'], 'The fixer output differs from the program that was run on the real runtimes.' );
		upgw_same( array(), upgw_rules_in( $fixed['code'] ), 'Findings remain after the fix.' );
	};
}

return $tests;
