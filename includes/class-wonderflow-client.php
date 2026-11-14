<?php
/**
 * HTTP client of the CRM capture API (`ingest` v1).
 *
 * @package Wonderflow
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends requests with the server key. Never logs bodies: they hold personal data.
 */
class Wonderflow_Client {

	/**
	 * POSTs a JSON body to a route of the API.
	 *
	 * @param string $route           `/v1/leads` or `/v1/events`.
	 * @param array  $body            Request body.
	 * @param string $idempotency_key Idempotency-Key header.
	 * @return array { status: int (0 on network error), error: string }
	 */
	public static function post( $route, array $body, $idempotency_key ) {
		if ( ! Wonderflow_Settings::is_connected() ) {
			return array(
				'status' => 0,
				'error'  => 'not_configured',
			);
		}
		$response = wp_remote_post(
			Wonderflow_Settings::get( 'endpoint' ) . $route,
			array(
				'timeout'     => 8,
				'redirection' => 0,
				'headers'     => array(
					'Authorization'   => 'Bearer ' . Wonderflow_Settings::get( 'server_key' ),
					'Content-Type'    => 'application/json',
					'Idempotency-Key' => $idempotency_key,
				),
				'body'        => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 0,
				'error'  => 'network_error',
			);
		}
		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		$error   = is_array( $decoded ) && isset( $decoded['error'] ) ? sanitize_key( $decoded['error'] ) : '';
		return array(
			'status' => $status,
			'error'  => $error,
		);
	}

	/**
	 * Checks the endpoint and the key without creating anything: a lead with an
	 * invalid email passes the key check and fails validation (400).
	 *
	 * @return array { ok: bool, code: string }
	 */
	public static function test_connection() {
		$result = self::post( '/v1/leads', array( 'email' => 'not-an-email' ), 'wonderflow-test-' . wp_generate_uuid4() );
		if ( 400 === $result['status'] && 'invalid_body' === $result['error'] ) {
			return array(
				'ok'   => true,
				'code' => 'ok',
			);
		}
		if ( 403 === $result['status'] && 'refused' === $result['error'] ) {
			return array(
				'ok'   => false,
				'code' => 'plan_without_form_capture',
			);
		}
		return array(
			'ok'   => false,
			'code' => '' !== $result['error'] ? $result['error'] : 'http_' . $result['status'],
		);
	}

	/**
	 * Whether a failed send is worth retrying (network, rate limit, server error).
	 *
	 * @param int $status HTTP status, 0 on network error.
	 * @return bool
	 */
	public static function is_retryable( $status ) {
		return 0 === $status || 429 === $status || $status >= 500;
	}
}
