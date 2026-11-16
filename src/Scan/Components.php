<?php
/**
 * The plugins and themes of this site, as things to scan.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Lists components and their PHP files.
 */
final class Components {

	/**
	 * Files larger than this are not scanned. Generated files of this size are
	 * usually data, and parsing them would not fit a shared host's memory.
	 */
	const MAX_FILE_BYTES = 1572864;

	/**
	 * Every plugin, must-use plugin and theme installed on the site.
	 *
	 * @return array<string,array{id:string,type:string,name:string,version:string,path:string,active:bool}>
	 */
	public static function all() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$components = array();
		$own        = plugin_basename( UPGW_FILE );

		foreach ( get_plugins() as $file => $data ) {
			if ( $file === $own ) {
				continue;
			}

			$folder = dirname( $file );
			$single = '.' === $folder;
			$id     = 'plugin:' . ( $single ? $file : $folder );

			$components[ $id ] = array(
				'id'      => $id,
				'type'    => 'plugin',
				'name'    => '' !== $data['Name'] ? $data['Name'] : $file,
				'version' => (string) $data['Version'],
				'path'    => WP_PLUGIN_DIR . '/' . ( $single ? $file : $folder ),
				'active'  => is_plugin_active( $file ),
			);
		}

		if ( is_dir( WPMU_PLUGIN_DIR ) ) {
			$components['mu:all'] = array(
				'id'      => 'mu:all',
				'type'    => 'mu',
				'name'    => __( 'Must-use plugins', 'upgrade-warden' ),
				'version' => '',
				'path'    => WPMU_PLUGIN_DIR,
				'active'  => true,
			);
		}

		$active = array( get_stylesheet(), get_template() );

		foreach ( wp_get_themes() as $slug => $theme ) {
			$id = 'theme:' . $slug;

			$components[ $id ] = array(
				'id'      => $id,
				'type'    => 'theme',
				'name'    => (string) $theme->get( 'Name' ),
				'version' => (string) $theme->get( 'Version' ),
				'path'    => $theme->get_stylesheet_directory(),
				'active'  => in_array( $slug, $active, true ),
			);
		}

		return $components;
	}

	/**
	 * PHP files of a component, relative to its path, sorted.
	 *
	 * @param string $path Folder, or a single PHP file.
	 * @return string[] For a single file, one empty string.
	 */
	public static function files( $path ) {
		if ( is_file( $path ) ) {
			return array( '' );
		}

		if ( ! is_dir( $path ) ) {
			return array();
		}

		$files    = array();
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
				static function ( $current ) {
					return ! $current->isDir() || ! in_array( $current->getFilename(), array( '.git', 'node_modules' ), true );
				}
			)
		);

		foreach ( $iterator as $file ) {
			if ( 'php' === strtolower( $file->getExtension() ) && $file->getSize() <= self::MAX_FILE_BYTES ) {
				$files[] = ltrim( str_replace( '\\', '/', substr( $file->getPathname(), strlen( $path ) ) ), '/' );
			}
		}

		sort( $files );

		return $files;
	}

	/**
	 * Absolute path of a file of a component.
	 *
	 * @param string $path     Component path.
	 * @param string $relative Relative file path; empty for a single-file plugin.
	 * @return string
	 */
	public static function absolute( $path, $relative ) {
		return '' === $relative ? $path : $path . '/' . $relative;
	}
}
