<?php
/**
 * Japanese for the Freemius screens.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Pro;

defined( 'ABSPATH' ) || exit;

/**
 * Fills in Japanese for the Freemius screens (license activation, account,
 * deactivation) where the SDK's own translation leaves English. Registered
 * before a licence is active, since the licence screen is the first one shown.
 */
final class FreemiusJapanese {

	/**
	 * English to Japanese, loaded on first use.
	 *
	 * @var array<string, string>|null
	 */
	private static $map = null;

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'override_texts' ), 20 );
		add_filter( 'gettext_freemius', array( self::class, 'fill' ), 10, 2 );
		add_filter( 'gettext_with_context_freemius', array( self::class, 'fill' ), 10, 2 );
	}

	/**
	 * Returns the Japanese for an SDK text the SDK left untranslated.
	 * Texts the SDK already translates are left alone.
	 *
	 * @param string $translation Text after the SDK's translation.
	 * @param string $text        Original English text.
	 * @return string
	 */
	public static function fill( $translation, $text ) {
		if ( $translation !== $text || 0 !== strpos( determine_locale(), 'ja' ) ) {
			return $translation;
		}
		if ( null === self::$map ) {
			self::$map = require UPGW_DIR . 'premium/data/freemius-ja.php';
		}
		return isset( self::$map[ $text ] ) ? self::$map[ $text ] : $translation;
	}

	/**
	 * Replaces SDK strings whose bundled translation is broken
	 * (the Japanese "Thanks %s!" has "$s" instead of "%s").
	 * Runs after Pro has loaded its translations (init, priority 10).
	 *
	 * @return void
	 */
	public static function override_texts(): void {
		if ( function_exists( 'fs_override_i18n' ) ) {
			fs_override_i18n(
				array(
					/* translators: %s: user's first name. */
					'thanks-x' => __( 'Thanks %s!', 'upgrade-warden' ),
				),
				'upgrade-warden'
			);
		}
	}
}
