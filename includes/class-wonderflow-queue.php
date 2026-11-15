<?php
/**
 * Send queue and send log.
 *
 * Every form or click is queued first and sent by WP-Cron, so the visitor never
 * waits for the CRM. The payload (personal data) is deleted as soon as the row
 * is settled; the log keeps only kind, form label, status, code and dates.
 *
 * @package Wonderflow
 */

defined( 'ABSPATH' ) || exit;

/**
 * Queue table `{prefix}wonderflow_queue`.
 */
class Wonderflow_Queue {

	const CRON_HOOK    = 'wonderflow_process_queue';
	const CLEANUP_HOOK = 'wonderflow_cleanup_log';
	const DB_VERSION   = '1';
	const MAX_ATTEMPTS = 8;
	const BATCH        = 20;
	const LOG_DAYS     = 30;

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wonderflow_queue';
	}

	/**
	 * Creates the table and schedules the jobs.
	 */
	public static function activate() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				kind varchar(10) NOT NULL,
				form_label varchar(120) NOT NULL DEFAULT '',
				idempotency_key varchar(100) NOT NULL,
				payload longtext NULL,
				status varchar(10) NOT NULL DEFAULT 'pending',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				last_code varchar(40) NOT NULL DEFAULT '',
				next_attempt_at datetime NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY status_next (status, next_attempt_at),
				KEY created_at (created_at)
			) {$charset};"
		);
		update_option( 'wonderflow_db_version', self::DB_VERSION, false );
		self::schedule();
	}

	/**
	 * Removes the scheduled jobs (the table and the log stay until uninstall).
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::CLEANUP_HOOK );
	}

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- retries need minutes.
		add_action( self::CRON_HOOK, array( __CLASS__, 'process' ) );
		add_action( self::CRON_HOOK . '_now', array( __CLASS__, 'process' ) );
		add_action( self::CLEANUP_HOOK, array( __CLASS__, 'cleanup' ) );
		if ( get_option( 'wonderflow_db_version' ) !== self::DB_VERSION ) {
			self::activate();
		}
		self::schedule();
	}

	/**
	 * Adds a five-minute schedule.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function cron_schedules( $schedules ) {
		$schedules['wonderflow_5min'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => 'Wonderflow: every 5 minutes',
		);
		return $schedules;
	}

	/**
	 * Schedules the recurring jobs once.
	 */
	private static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'wonderflow_5min', self::CRON_HOOK );
		}
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
	}

	/**
	 * Queues a send and asks WP-Cron to run it now (in a background request).
	 *
	 * @param string $kind       `lead` or `event`.
	 * @param string $form_label Form name for the log (no personal data).
	 * @param array  $payload    API body.
	 * @return int Row id.
	 */
	public static function push( $kind, $form_label, array $payload ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			self::table(),
			array(
				'kind'            => 'event' === $kind ? 'event' : 'lead',
				'form_label'      => substr( sanitize_text_field( $form_label ), 0, 120 ),
				'idempotency_key' => 'wp-' . $kind . '-' . wp_generate_uuid4(),
				'payload'         => wp_json_encode( $payload ),
				'status'          => 'pending',
				'next_attempt_at' => $now,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
		if ( ! wp_next_scheduled( self::CRON_HOOK . '_now' ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK . '_now' );
		}
		// Send in this request, after the response: WP-Cron only runs on visits
		// and is often late or disabled on hosts with page caching.
		if ( ! has_action( 'shutdown', array( __CLASS__, 'process_after_response' ) ) ) {
			add_action( 'shutdown', array( __CLASS__, 'process_after_response' ), 100 );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Closes the connection with the visitor first (PHP-FPM, LiteSpeed), then
	 * sends. Without that, the send is left to WP-Cron so nobody waits.
	 */
	public static function process_after_response() {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		} else {
			return;
		}
		self::process();
	}

	/**
	 * Number of rows waiting to be sent.
	 *
	 * @return int
	 */
	public static function pending_count() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending'" );
	}

	/**
	 * Sends the due rows.
	 *
	 * @param bool $force Also send rows waiting for a retry ("Send now").
	 */
	public static function process( $force = false ) {
		global $wpdb;
		if ( ! Wonderflow_Settings::is_connected() ) {
			return;
		}
		if ( get_transient( 'wonderflow_queue_lock' ) ) {
			return;
		}
		set_transient( 'wonderflow_queue_lock', 1, 2 * MINUTE_IN_SECONDS );

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, kind, idempotency_key, payload, attempts FROM {$table} WHERE status = 'pending' AND next_attempt_at <= %s ORDER BY id LIMIT %d", $force ? '9999-12-31 00:00:00' : current_time( 'mysql', true ), self::BATCH ) );

		foreach ( (array) $rows as $row ) {
			$payload = json_decode( (string) $row->payload, true );
			if ( ! is_array( $payload ) ) {
				self::settle( (int) $row->id, 'failed', 'bad_payload', (int) $row->attempts );
				continue;
			}
			$route    = 'event' === $row->kind ? '/v1/events' : '/v1/leads';
			$result   = Wonderflow_Client::post( $route, $payload, $row->idempotency_key );
			$attempts = (int) $row->attempts + 1;
			$code     = '' !== $result['error'] ? $result['error'] : 'http_' . $result['status'];

			if ( $result['status'] >= 200 && $result['status'] < 300 ) {
				self::settle( (int) $row->id, 'sent', 'http_' . $result['status'], $attempts );
			} elseif ( Wonderflow_Client::is_retryable( $result['status'] ) && $attempts < self::MAX_ATTEMPTS ) {
				self::retry( (int) $row->id, $code, $attempts );
			} else {
				self::settle( (int) $row->id, 'failed', $code, $attempts );
			}
		}
		delete_transient( 'wonderflow_queue_lock' );
	}

	/**
	 * Final state: drops the payload.
	 *
	 * @param int    $id       Row id.
	 * @param string $status   `sent` or `failed`.
	 * @param string $code     Result code.
	 * @param int    $attempts Attempts so far.
	 */
	private static function settle( $id, $status, $code, $attempts ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			self::table(),
			array(
				'status'     => $status,
				'last_code'  => substr( $code, 0, 40 ),
				'attempts'   => $attempts,
				'payload'    => null,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Schedules a retry with an increasing delay (1, 2, 4... minutes, max 6 hours).
	 *
	 * @param int    $id       Row id.
	 * @param string $code     Result code.
	 * @param int    $attempts Attempts so far.
	 */
	private static function retry( $id, $code, $attempts ) {
		global $wpdb;
		$delay = min( 6 * HOUR_IN_SECONDS, MINUTE_IN_SECONDS * ( 2 ** ( $attempts - 1 ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			self::table(),
			array(
				'last_code'       => substr( $code, 0, 40 ),
				'attempts'        => $attempts,
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ),
				'updated_at'      => current_time( 'mysql', true ),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Deletes log rows older than LOG_DAYS, and any payload left on them.
	 */
	public static function cleanup() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - self::LOG_DAYS * DAY_IN_SECONDS ) ) );
	}

	/**
	 * Latest rows for the log page (no payload).
	 *
	 * @param int $limit Rows.
	 * @return array
	 */
	public static function latest( $limit = 50 ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT kind, form_label, status, attempts, last_code, created_at, updated_at FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) );
	}
}
