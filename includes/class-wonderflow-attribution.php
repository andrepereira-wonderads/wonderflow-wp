<?php
/**
 * Visit attribution: loads the front-end script and reads the stored origins.
 *
 * @package Wonderflow
 */

defined( 'ABSPATH' ) || exit;

/**
 * First and last origin of the visit, from the `wf_first` / `wf_last` cookies
 * written by assets/wonderflow.js (or the hidden form fields it adds).
 */
class Wonderflow_Attribution {

	const KEYS = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'referrer', 'landing_page', 'occurred_at' );

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Loads the script on every public page when a feature is on.
	 */
	public static function enqueue() {
		$settings = Wonderflow_Settings::all();
		if ( ! $settings['forms_enabled'] && ! $settings['whatsapp_enabled'] ) {
			return;
		}
		wp_enqueue_script( 'wonderflow', WONDERFLOW_URL . 'assets/wonderflow.js', array(), WONDERFLOW_VERSION, array( 'strategy' => 'defer' ) );
		wp_localize_script(
			'wonderflow',
			'wonderflowConfig',
			array(
				'clickUrl' => $settings['whatsapp_enabled'] ? esc_url_raw( rest_url( 'wonderflow/v1/click' ) ) : '',
				'whatsapp' => array(
					'enabled' => (bool) $settings['whatsapp_enabled'],
					'number'  => $settings['whatsapp_number'],
					'message' => $settings['whatsapp_message'],
				),
			)
		);
	}

	/**
	 * Origins of the current request. Hidden fields win over the cookies.
	 *
	 * @param array $fields Optional posted values (wf_first_touch / wf_last_touch).
	 * @return array { first: array, last: array }
	 */
	public static function current( array $fields = array() ) {
		return array(
			'first' => self::read( isset( $fields['wf_first_touch'] ) ? $fields['wf_first_touch'] : null, 'wf_first' ),
			'last'  => self::read( isset( $fields['wf_last_touch'] ) ? $fields['wf_last_touch'] : null, 'wf_last' ),
		);
	}

	/**
	 * Decodes one origin and keeps the known keys only.
	 *
	 * @param string|null $posted Posted JSON.
	 * @param string      $cookie Cookie name.
	 * @return array
	 */
	private static function read( $posted, $cookie ) {
		$raw = is_string( $posted ) && '' !== $posted ? $posted : null;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded and sanitised below.
		if ( null === $raw && isset( $_COOKIE[ $cookie ] ) ) {
			$raw = wp_unslash( $_COOKIE[ $cookie ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}
		return self::sanitize( is_string( $raw ) ? json_decode( $raw, true ) : null );
	}

	/**
	 * Keeps the known keys, as trimmed strings within the API limits.
	 *
	 * @param mixed $data Decoded origin.
	 * @return array
	 */
	public static function sanitize( $data ) {
		if ( ! is_array( $data ) ) {
			return array();
		}
		$out = array();
		foreach ( self::KEYS as $key ) {
			if ( ! isset( $data[ $key ] ) || ! is_scalar( $data[ $key ] ) ) {
				continue;
			}
			$value = trim( sanitize_text_field( (string) $data[ $key ] ) );
			if ( '' === $value ) {
				continue;
			}
			if ( 'occurred_at' === $key ) {
				$time = strtotime( $value );
				if ( false === $time ) {
					continue;
				}
				$value = gmdate( 'Y-m-d\TH:i:s\Z', $time );
			}
			$max         = in_array( $key, array( 'referrer', 'landing_page' ), true ) ? 500 : 200;
			$out[ $key ] = substr( $value, 0, $max );
		}
		return $out;
	}
}
