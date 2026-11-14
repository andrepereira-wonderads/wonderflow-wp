<?php
/**
 * Plugin settings (one option array).
 *
 * @package Wonderflow
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and sanitises the `wonderflow_settings` option.
 */
class Wonderflow_Settings {

	const OPTION = 'wonderflow_settings';

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'endpoint'         => '',
			'server_key'       => '',
			'country'          => 'PT',
			'consent_version'  => 'site-v1',
			'forms_enabled'    => false,
			'whatsapp_enabled' => false,
			'whatsapp_number'  => '',
			'whatsapp_message' => 'Olá! Ref {ref}',
			'field_map'        => '',
		);
	}

	/**
	 * All settings, merged with the defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Whether the endpoint and the key are set.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		return '' !== self::get( 'endpoint' ) && '' !== self::get( 'server_key' );
	}

	/**
	 * Sanitises the settings form. An empty key field keeps the saved key, so the
	 * key never has to be printed back in the page.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$current = self::all();
		$input   = is_array( $input ) ? $input : array();
		$out     = self::defaults();

		$endpoint        = isset( $input['endpoint'] ) ? esc_url_raw( trim( $input['endpoint'] ), array( 'https' ) ) : '';
		$out['endpoint'] = untrailingslashit( $endpoint );

		$key = isset( $input['server_key'] ) ? trim( sanitize_text_field( $input['server_key'] ) ) : '';
		if ( '' === $key ) {
			$out['server_key'] = $current['server_key'];
		} elseif ( preg_match( '/^whk_[A-Za-z0-9_-]{10,200}$/', $key ) ) {
			$out['server_key'] = $key;
		} else {
			$out['server_key'] = $current['server_key'];
			add_settings_error( self::OPTION, 'bad_key', __( 'The server key must start with whk_. The previous key was kept.', 'wonderflow' ) );
		}
		if ( ! empty( $input['forget_key'] ) ) {
			$out['server_key'] = '';
		}

		$country        = isset( $input['country'] ) ? strtoupper( sanitize_text_field( $input['country'] ) ) : 'PT';
		$out['country'] = preg_match( '/^[A-Z]{2}$/', $country ) ? $country : 'PT';

		$version                = isset( $input['consent_version'] ) ? sanitize_text_field( $input['consent_version'] ) : '';
		$out['consent_version'] = '' !== $version ? substr( $version, 0, 60 ) : 'site-v1';

		$out['forms_enabled']    = ! empty( $input['forms_enabled'] );
		$out['whatsapp_enabled'] = ! empty( $input['whatsapp_enabled'] );

		$number                 = isset( $input['whatsapp_number'] ) ? preg_replace( '/\D+/', '', $input['whatsapp_number'] ) : '';
		$out['whatsapp_number'] = substr( $number, 0, 15 );

		$message = isset( $input['whatsapp_message'] ) ? sanitize_text_field( $input['whatsapp_message'] ) : '';
		if ( false === strpos( $message, '{ref}' ) ) {
			$message = trim( $message . ' Ref {ref}' );
		}
		$out['whatsapp_message'] = substr( $message, 0, 300 );

		$map = isset( $input['field_map'] ) ? trim( (string) $input['field_map'] ) : '';
		if ( '' !== $map && ! is_array( json_decode( $map, true ) ) ) {
			add_settings_error( self::OPTION, 'bad_map', __( 'The field mapping is not valid JSON. The previous mapping was kept.', 'wonderflow' ) );
			$map = $current['field_map'];
		}
		$out['field_map'] = $map;

		return $out;
	}
}
