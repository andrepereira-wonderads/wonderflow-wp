<?php
/**
 * Form builders -> CRM leads (server side).
 *
 * Each adapter turns a submission into a list of fields
 * { key, label, type, value } and hands it to submit(), which maps the fields,
 * adds the origins and queues the lead. The free-text message is never sent:
 * it may hold health data, and the CRM never stores it.
 *
 * @package Wonderflow
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adapters for Elementor Pro, Contact Form 7, WPForms, Gravity Forms and Fluent Forms.
 */
class Wonderflow_Forms {

	/**
	 * Hooks (each one only fires when its builder is active).
	 */
	public static function init() {
		if ( ! Wonderflow_Settings::get( 'forms_enabled' ) ) {
			return;
		}
		add_action( 'wpcf7_mail_sent', array( __CLASS__, 'from_cf7' ) );
		add_action( 'elementor_pro/forms/new_record', array( __CLASS__, 'from_elementor' ), 10, 1 );
		add_action( 'wpforms_process_complete', array( __CLASS__, 'from_wpforms' ), 10, 3 );
		add_action( 'gform_after_submission', array( __CLASS__, 'from_gravity' ), 10, 2 );
		add_action( 'fluentform/submission_inserted', array( __CLASS__, 'from_fluent' ), 10, 3 );
	}

	/**
	 * Contact Form 7.
	 *
	 * @param object $form WPCF7_ContactForm.
	 */
	public static function from_cf7( $form ) {
		if ( ! class_exists( 'WPCF7_Submission' ) ) {
			return;
		}
		$submission = WPCF7_Submission::get_instance();
		if ( ! $submission ) {
			return;
		}
		$types = array();
		foreach ( $form->scan_form_tags() as $tag ) {
			if ( '' !== $tag->name ) {
				$types[ $tag->name ] = $tag->basetype;
			}
		}
		$fields = array();
		foreach ( (array) $submission->get_posted_data() as $name => $value ) {
			$fields[] = self::field( $name, $name, isset( $types[ $name ] ) ? $types[ $name ] : 'text', $value );
		}
		self::submit( 'cf7:' . $form->id(), $form->title(), $fields );
	}

	/**
	 * Elementor Pro form widget.
	 *
	 * @param object $record ElementorPro\Modules\Forms\Classes\Form_Record.
	 */
	public static function from_elementor( $record ) {
		$name   = (string) $record->get_form_settings( 'form_name' );
		$fields = array();
		foreach ( (array) $record->get( 'fields' ) as $id => $field ) {
			$fields[] = self::field( $id, isset( $field['title'] ) ? $field['title'] : $id, isset( $field['type'] ) ? $field['type'] : 'text', isset( $field['raw_value'] ) ? $field['raw_value'] : ( isset( $field['value'] ) ? $field['value'] : '' ) );
		}
		self::submit( 'elementor:' . $name, $name, $fields );
	}

	/**
	 * WPForms.
	 *
	 * @param array $form_fields Processed fields.
	 * @param array $entry       Raw entry.
	 * @param array $form_data   Form settings.
	 */
	public static function from_wpforms( $form_fields, $entry, $form_data ) {
		$fields = array();
		foreach ( (array) $form_fields as $id => $field ) {
			$type  = isset( $field['type'] ) ? $field['type'] : 'text';
			$value = isset( $field['value'] ) ? $field['value'] : '';
			if ( 'name' === $type && ( ! empty( $field['first'] ) || ! empty( $field['last'] ) ) ) {
				$value = trim( ( isset( $field['first'] ) ? $field['first'] : '' ) . ' ' . ( isset( $field['last'] ) ? $field['last'] : '' ) );
			}
			$fields[] = self::field( (string) $id, isset( $field['name'] ) ? $field['name'] : (string) $id, $type, $value );
		}
		$id    = isset( $form_data['id'] ) ? $form_data['id'] : '';
		$title = isset( $form_data['settings']['form_title'] ) ? $form_data['settings']['form_title'] : (string) $id;
		self::submit( 'wpforms:' . $id, $title, $fields );
	}

	/**
	 * Gravity Forms.
	 *
	 * @param array $entry Entry.
	 * @param array $form  Form.
	 */
	public static function from_gravity( $entry, $form ) {
		$fields = array();
		foreach ( (array) $form['fields'] as $field ) {
			$id    = (string) $field->id;
			$type  = (string) $field->type;
			$value = isset( $entry[ $id ] ) ? $entry[ $id ] : '';
			if ( 'name' === $type ) {
				$value = trim( ( isset( $entry[ $id . '.3' ] ) ? $entry[ $id . '.3' ] : '' ) . ' ' . ( isset( $entry[ $id . '.6' ] ) ? $entry[ $id . '.6' ] : '' ) );
			} elseif ( in_array( $type, array( 'checkbox', 'consent' ), true ) ) {
				$value = isset( $entry[ $id . '.1' ] ) ? $entry[ $id . '.1' ] : $value;
			}
			$fields[] = self::field( $id, (string) $field->label, $type, $value );
		}
		self::submit( 'gravity:' . $form['id'], isset( $form['title'] ) ? $form['title'] : (string) $form['id'], $fields );
	}

	/**
	 * Fluent Forms.
	 *
	 * @param int    $entry_id  Entry id.
	 * @param array  $form_data Submitted values by input name.
	 * @param object $form      Form.
	 */
	public static function from_fluent( $entry_id, $form_data, $form ) {
		$fields = array();
		foreach ( (array) $form_data as $name => $value ) {
			if ( 0 === strpos( (string) $name, '_' ) ) {
				continue; // Nonces and internal fields.
			}
			if ( is_array( $value ) && ( isset( $value['first_name'] ) || isset( $value['last_name'] ) ) ) {
				$value = trim( ( isset( $value['first_name'] ) ? $value['first_name'] : '' ) . ' ' . ( isset( $value['last_name'] ) ? $value['last_name'] : '' ) );
			}
			$fields[] = self::field( $name, $name, 'text', $value );
		}
		self::submit( 'fluent:' . $form->id, isset( $form->title ) ? $form->title : (string) $form->id, $fields );
	}

	/**
	 * One normalised field.
	 *
	 * @param string $key   Field key / name.
	 * @param string $label Label.
	 * @param string $type  Builder field type.
	 * @param mixed  $value Value (string or list).
	 * @return array
	 */
	private static function field( $key, $label, $type, $value ) {
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_filter( array_map( 'strval', $value ), 'strlen' ) );
		}
		return array(
			'key'   => (string) $key,
			'label' => (string) $label,
			'type'  => strtolower( (string) $type ),
			'value' => trim( (string) $value ),
		);
	}

	/**
	 * Maps a submission and queues it.
	 *
	 * @param string $form_key   `<builder>:<id>`.
	 * @param string $form_label Form name (log and CRM `form_name`).
	 * @param array  $fields     Normalised fields.
	 */
	public static function submit( $form_key, $form_label, array $fields ) {
		$map = self::manual_map( $form_key );
		if ( false === $map ) {
			return; // Form skipped by the clinic.
		}
		$lead = self::map( $fields, $map );
		if ( empty( $lead['name'] ) && empty( $lead['phone'] ) && empty( $lead['email'] ) ) {
			return; // Not a contact form (newsletter without fields, search...).
		}

		$posted = array();
		foreach ( $fields as $field ) {
			if ( in_array( $field['key'], array( 'wf_first_touch', 'wf_last_touch' ), true ) ) {
				$posted[ $field['key'] ] = $field['value'];
			}
		}
		// Builders that drop unknown inputs: read the raw POST too.
		foreach ( array( 'wf_first_touch', 'wf_last_touch' ) as $hidden ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the builder already verified the submission.
			if ( empty( $posted[ $hidden ] ) && isset( $_POST[ $hidden ] ) && is_string( $_POST[ $hidden ] ) ) {
				$posted[ $hidden ] = wp_unslash( $_POST[ $hidden ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by Wonderflow_Attribution.
			}
		}
		$touches = Wonderflow_Attribution::current( $posted );

		$body = array(
			'form_name' => substr( $form_label, 0, 120 ),
			'country'   => Wonderflow_Settings::get( 'country' ),
			'website'   => '',
		);
		foreach ( array( 'name', 'phone', 'email' ) as $key ) {
			if ( ! empty( $lead[ $key ] ) ) {
				$body[ $key ] = $lead[ $key ];
			}
		}
		if ( isset( $body['email'] ) && ! is_email( $body['email'] ) ) {
			unset( $body['email'] );
		}
		$consents = array();
		foreach ( array( 'data_processing', 'utility', 'marketing' ) as $key ) {
			if ( isset( $lead[ $key ] ) ) {
				$consents[ $key ] = $lead[ $key ];
			}
		}
		if ( $consents ) {
			$consents['text_version'] = Wonderflow_Settings::get( 'consent_version' );
			$body['consents']         = $consents;
		}
		if ( $touches['first'] ) {
			$body['first_touch'] = $touches['first'];
		}
		if ( $touches['last'] ) {
			$body['last_touch'] = $touches['last'];
		}

		/**
		 * Filters a lead before it is queued. Return false to skip it.
		 *
		 * @param array|false $body     API body.
		 * @param string      $form_key `<builder>:<id>`.
		 */
		$body = apply_filters( 'wonderflow_lead', $body, $form_key );
		if ( is_array( $body ) ) {
			Wonderflow_Queue::push( 'lead', $form_label, $body );
		}
	}

	/**
	 * Manual mapping of one form, from the settings JSON.
	 *
	 * @param string $form_key `<builder>:<id>`.
	 * @return array|false|null Mapping, false to skip, null for automatic.
	 */
	private static function manual_map( $form_key ) {
		$all = json_decode( (string) Wonderflow_Settings::get( 'field_map' ), true );
		if ( ! is_array( $all ) || ! array_key_exists( $form_key, $all ) ) {
			return null;
		}
		return false === $all[ $form_key ] ? false : ( is_array( $all[ $form_key ] ) ? $all[ $form_key ] : null );
	}

	/**
	 * Picks name, phone, email and consents from the fields.
	 *
	 * @param array      $fields Normalised fields.
	 * @param array|null $map    Manual mapping (target => field key).
	 * @return array
	 */
	public static function map( array $fields, $map ) {
		$out = array();
		if ( is_array( $map ) ) {
			$by_key = array();
			foreach ( $fields as $field ) {
				$by_key[ $field['key'] ] = $field;
			}
			foreach ( array( 'name', 'phone', 'email', 'data_processing', 'utility', 'marketing' ) as $target ) {
				if ( isset( $map[ $target ] ) && isset( $by_key[ $map[ $target ] ] ) ) {
					$value          = $by_key[ $map[ $target ] ]['value'];
					$out[ $target ] = in_array( $target, array( 'name', 'phone', 'email' ), true ) ? substr( $value, 0, 120 ) : self::is_checked( $value );
				}
			}
			return $out;
		}

		$first = '';
		$last  = '';
		foreach ( $fields as $field ) {
			$target = self::guess( $field );
			if ( null === $target ) {
				continue;
			}
			if ( 'first_name' === $target ) {
				$first = $field['value'];
			} elseif ( 'last_name' === $target ) {
				$last = $field['value'];
			} elseif ( in_array( $target, array( 'name', 'phone', 'email' ), true ) ) {
				if ( empty( $out[ $target ] ) && '' !== $field['value'] ) {
					$out[ $target ] = substr( $field['value'], 0, 120 );
				}
			} elseif ( ! isset( $out[ $target ] ) || ! $out[ $target ] ) {
				$out[ $target ] = self::is_checked( $field['value'] );
			}
		}
		if ( empty( $out['name'] ) && ( '' !== $first || '' !== $last ) ) {
			$out['name'] = substr( trim( $first . ' ' . $last ), 0, 120 );
		}
		return $out;
	}

	/**
	 * Guesses what a field holds, from its type, key and label (pt and en).
	 *
	 * @param array $field Normalised field.
	 * @return string|null Target, or null to ignore (message fields included).
	 */
	private static function guess( array $field ) {
		$text = strtolower( remove_accents( $field['key'] . ' ' . $field['label'] ) );
		$type = $field['type'];

		if ( preg_match( '/mensag|message|coment|observa|assunto|subject|descri|textarea/', $text . ' ' . $type ) ) {
			return null;
		}
		if ( in_array( $type, array( 'checkbox', 'acceptance', 'consent', 'gdpr', 'gdpr-checkbox', 'terms' ), true ) || preg_match( '/consent|rgpd|gdpr|aceit|accept|autoriz|privacidade|privacy/', $text ) ) {
			if ( preg_match( '/marketing|newsletter|novidade|promo|campanha|ofertas/', $text ) ) {
				return 'marketing';
			}
			if ( preg_match( '/lembret|reminder|whatsapp|utility|marcac|consulta/', $text ) ) {
				return 'utility';
			}
			if ( preg_match( '/consent|rgpd|gdpr|aceit|accept|autoriz|privacidade|privacy|tratamento|termos|terms/', $text ) ) {
				return 'data_processing';
			}
			return null;
		}
		if ( 'email' === $type || preg_match( '/e-?mail/', $text ) ) {
			return 'email';
		}
		if ( in_array( $type, array( 'tel', 'phone', 'phone_number' ), true ) || preg_match( '/telem|telef|phone|movel|mobile|contacto tel|whatsapp/', $text ) ) {
			return 'phone';
		}
		if ( preg_match( '/apelido|sobrenome|last.?name|surname/', $text ) ) {
			return 'last_name';
		}
		if ( preg_match( '/primeiro.?nome|first.?name/', $text ) ) {
			return 'first_name';
		}
		if ( 'name' === $type || preg_match( '/\bnome\b|\bname\b|your-name|nome.?completo|full.?name/', $text ) ) {
			return 'name';
		}
		return null;
	}

	/**
	 * Whether a checkbox value means "yes".
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private static function is_checked( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return '' !== $value && ! in_array( $value, array( '0', 'no', 'nao', 'não', 'false', 'off' ), true );
	}
}
