<?php
/**
 * Applies fixes to a plugin or theme, with a backup and a way back.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Pro\Fix;

use TYStudio\UpgradeWarden\Engine\Catalog;
use TYStudio\UpgradeWarden\Engine\Scanner;
use TYStudio\UpgradeWarden\Scan\Components;
use TYStudio\UpgradeWarden\Scan\Job;

defined( 'ABSPATH' ) || exit;

/**
 * Nothing is written before every file to be changed has been copied to the
 * backup folder. Undoing restores a file only if it still is what the fix left
 * behind; a file changed since (by an update or by hand) is left alone and
 * reported, because overwriting it would destroy newer work.
 */
final class Service {

	/**
	 * Folder that holds the backups.
	 *
	 * @return string Path without a trailing slash.
	 */
	public static function backup_root() {
		$uploads = wp_upload_dir();

		return $uploads['basedir'] . '/upgrade-warden/backups';
	}

	/**
	 * What fixing a component would change. Nothing is written.
	 *
	 * @param array $component Component from Components::all().
	 * @return array<string,array{before:string,after:string,applied:array[]}> By relative path.
	 */
	public static function preview( array $component ) {
		$fixer   = new Fixer( new Scanner( Catalog::load( UPGW_DIR . 'data/catalog' ) ) );
		$changes = array();

		foreach ( Components::files( $component['path'] ) as $relative ) {
			$path = Components::absolute( $component['path'], $relative );
			$code = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.

			if ( false === $code ) {
				continue;
			}

			$result = $fixer->fix( $code );

			if ( '' === $result['error'] && $result['code'] !== $code ) {
				$changes[ $relative ] = array(
					'before'  => $code,
					'after'   => $result['code'],
					'applied' => $result['applied'],
				);
			}
		}

		return $changes;
	}

	/**
	 * Fixes a component.
	 *
	 * @param array $component Component from Components::all().
	 * @return array{backup:string,files:int,fixes:int,error:string}
	 */
	public static function apply( array $component ) {
		$changes = self::preview( $component );
		$outcome = array(
			'backup' => '',
			'files'  => 0,
			'fixes'  => 0,
			'error'  => '',
		);

		if ( ! $changes ) {
			return $outcome;
		}

		foreach ( array_keys( $changes ) as $relative ) {
			if ( ! wp_is_writable( Components::absolute( $component['path'], $relative ) ) ) {
				/* translators: %s: file path. */
				$outcome['error'] = sprintf( __( 'The file %s is not writable. Nothing was changed.', 'upgrade-warden' ), $relative );

				return $outcome;
			}
		}

		$id  = gmdate( 'Ymd-His' ) . '-' . substr( md5( $component['id'] . wp_rand() ), 0, 6 );
		$dir = self::backup_root() . '/' . $id;

		if ( ! wp_mkdir_p( $dir ) ) {
			$outcome['error'] = __( 'The backup folder could not be created. Nothing was changed.', 'upgrade-warden' );

			return $outcome;
		}

		self::protect( self::backup_root() );

		$manifest = array(
			'component' => $component['id'],
			'name'      => $component['name'],
			'version'   => $component['version'],
			'created'   => time(),
			'files'     => array(),
		);
		$index    = 0;

		// Every original is saved first. Copies get a neutral name, so that the
		// backup folder never contains anything a web server would run.
		foreach ( $changes as $relative => $change ) {
			$stored = sprintf( '%04d.bak', $index++ );

			if ( false === file_put_contents( $dir . '/' . $stored, $change['before'] ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Backup of a local file.
				$outcome['error'] = __( 'A backup copy could not be written. Nothing was changed.', 'upgrade-warden' );

				return $outcome;
			}

			$manifest['files'][] = array(
				'path'   => $relative,
				'stored' => $stored,
				'before' => md5( $change['before'] ),
				'after'  => md5( $change['after'] ),
				'fixes'  => count( $change['applied'] ),
			);
		}

		file_put_contents( $dir . '/manifest.json', wp_json_encode( $manifest ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Own data file.

		foreach ( $changes as $relative => $change ) {
			file_put_contents( Components::absolute( $component['path'], $relative ), $change['after'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The fix itself.

			++$outcome['files'];
			$outcome['fixes'] += count( $change['applied'] );
		}

		self::forget_compiled( $component, array_keys( $changes ) );
		Job::scan_component( $component['id'] );

		$outcome['backup'] = $id;

		return $outcome;
	}

	/**
	 * Backups of a component, newest first.
	 *
	 * @param string $component_id Component id.
	 * @return array<string,array> Manifests by backup id.
	 */
	public static function backups( $component_id ) {
		$found = array();

		foreach ( (array) glob( self::backup_root() . '/*/manifest.json' ) as $file ) {
			$manifest = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Own data file.

			if ( is_array( $manifest ) && $manifest['component'] === $component_id && empty( $manifest['undone'] ) ) {
				$found[ basename( dirname( $file ) ) ] = $manifest;
			}
		}

		krsort( $found );

		return $found;
	}

	/**
	 * Puts the files of a backup back.
	 *
	 * @param array  $component Component from Components::all().
	 * @param string $id        Backup id.
	 * @return array{restored:int,kept:string[],error:string} kept lists files
	 *         changed since the fix, which were left as they are.
	 */
	public static function undo( array $component, $id ) {
		$outcome = array(
			'restored' => 0,
			'kept'     => array(),
			'error'    => '',
		);
		$dir     = self::backup_root() . '/' . $id;
		$backups = self::backups( $component['id'] );

		if ( ! preg_match( '/^[0-9]{8}-[0-9]{6}-[a-f0-9]{6}$/', $id ) || ! isset( $backups[ $id ] ) ) {
			$outcome['error'] = __( 'That backup does not exist.', 'upgrade-warden' );

			return $outcome;
		}

		$restored = array();

		foreach ( $backups[ $id ]['files'] as $file ) {
			$path    = Components::absolute( $component['path'], $file['path'] );
			$current = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
			$saved   = file_get_contents( $dir . '/' . $file['stored'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Own backup.

			if ( false === $current || false === $saved || md5( $current ) !== $file['after'] || md5( $saved ) !== $file['before'] ) {
				$outcome['kept'][] = $file['path'];
				continue;
			}

			file_put_contents( $path, $saved ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Restoring the original.

			$restored[] = $file['path'];
			++$outcome['restored'];
		}

		$manifest           = $backups[ $id ];
		$manifest['undone'] = time();

		file_put_contents( $dir . '/manifest.json', wp_json_encode( $manifest ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Own data file.

		self::forget_compiled( $component, $restored );
		Job::scan_component( $component['id'] );

		return $outcome;
	}

	/**
	 * Keeps the web server from listing or serving the backup folder.
	 *
	 * @param string $root Backup folder.
	 */
	private static function protect( $root ) {
		if ( ! file_exists( $root . '/index.php' ) ) {
			file_put_contents( $root . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Own folder.
		}

		if ( ! file_exists( $root . '/.htaccess' ) ) {
			file_put_contents( $root . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Own folder.
		}
	}

	/**
	 * Makes PHP read the changed files again instead of a cached compilation.
	 *
	 * @param array    $component Component.
	 * @param string[] $relatives Relative paths.
	 */
	private static function forget_compiled( array $component, array $relatives ) {
		if ( ! function_exists( 'opcache_invalidate' ) ) {
			return;
		}

		foreach ( $relatives as $relative ) {
			@opcache_invalidate( Components::absolute( $component['path'], $relative ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Fails harmlessly when the cache is restricted.
		}
	}
}
