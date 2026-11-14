<?php
/**
 * WhatsApp clicks: local REST route for the beacon, and a button shortcode.
 *
 * @package Wonderflow
 */

defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-json/wonderflow/v1/click { ref, first_touch, occurred_at }.
 * Anonymous: it never creates a contact. The server key stays here; the click
 * is queued and forwarded to POST /v1/events.
 */
class Wonderflow_Whatsapp {

	const REF_PATTERN = '/^[2-9A-HJKMNP-Z]{5}$/';
	const IP_LIMIT    = 20;

	/**
	 * Hooks.
	 */
	public static function init() {
		if ( ! Wonderflow_Settings::get( 'whatsapp_enabled' ) ) {
			return;
		}
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_shortcode( 'wonderflow_whatsapp', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * Registers the route.
	 */
	public static function routes() {
		register_rest_route(
			'wonderflow/v1',
			'/click',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'click' ),
				'permission_callback' => '__return_true', // Public on purpose: anonymous visitors, limited per IP.
			)
		);
	}

	/**
	 * Queues one click.
	 *
	 * @param WP_REST_Request $request Request (JSON or text/plain body from sendBeacon).
	 * @return WP_REST_Response
	 */
	public static function click( $request ) {
		$data = json_decode( (string) $request->get_body(), true );
		$ref  = is_array( $data ) && isset( $data['ref'] ) && is_string( $data['ref'] ) ? strtoupper( trim( $data['ref'] ) ) : '';
		if ( ! preg_match( self::REF_PATTERN, $ref ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_ref' ), 400 );
		}
		if ( self::over_limit() ) {
			return new WP_REST_Response( array( 'error' => 'rate_limited' ), 429 );
		}

		$body = array(
			'type' => 'whatsapp_click',
			'ref'  => $ref,
		);
		$first = Wonderflow_Attribution::sanitize( isset( $data['first_touch'] ) ? $data['first_touch'] : null );
		if ( ! $first ) {
			$first = Wonderflow_Attribution::current()['first'];
		}
		if ( $first ) {
			$body['first_touch'] = $first;
		}
		$body['occurred_at'] = gmdate( 'Y-m-d\TH:i:s\Z' );

		Wonderflow_Queue::push( 'event', 'WhatsApp', $body );
		return new WP_REST_Response( array( 'ok' => true ), 202 );
	}

	/**
	 * Clicks per visitor IP and minute (hashed, only in a transient).
	 *
	 * @return bool
	 */
	private static function over_limit() {
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key   = 'wonderflow_rl_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 20 ) . '_' . gmdate( 'YmdHi' );
		$count = (int) get_transient( $key );
		if ( $count >= self::IP_LIMIT ) {
			return true;
		}
		set_transient( $key, $count + 1, 2 * MINUTE_IN_SECONDS );
		return false;
	}

	/**
	 * [wonderflow_whatsapp label="Marcar pelo WhatsApp" class="button"]: a link to
	 * the clinic number; the script adds the Ref on click.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts   = shortcode_atts(
			array(
				'label' => __( 'Book on WhatsApp', 'wonderflow' ),
				'class' => 'wonderflow-whatsapp',
			),
			$atts,
			'wonderflow_whatsapp'
		);
		$number = Wonderflow_Settings::get( 'whatsapp_number' );
		if ( '' === $number ) {
			return '';
		}
		return sprintf(
			'<a class="%s" href="%s" target="_blank" rel="noopener">%s</a>',
			esc_attr( $atts['class'] ),
			esc_url( 'https://wa.me/' . $number ),
			esc_html( $atts['label'] )
		);
	}
}
