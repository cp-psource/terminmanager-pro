<?php

class Appointments_AJAX {

	public $options = array();
	public $_google_user_cache = null;

	public function __construct() {
		add_action( 'wp_ajax_nopriv_app_paypal_ipn', array(&$this, 'handle_paypal_return')); // Send Paypal to IPN function

		add_action( 'wp_ajax_inline_edit', array( &$this, 'inline_edit' ) ); 			// Add/edit appointments
		add_action( 'wp_ajax_inline_edit_save', array( &$this, 'inline_edit_save' ) ); 	// Save edits
		add_action( 'wp_ajax_inline_fetch_worker_slots', array( &$this, 'inline_fetch_worker_slots' ) ); 	// Fetch working hours for worker
		add_action( 'wp_ajax_js_error', array( &$this, 'js_error' ) ); 					// Track js errors
		add_action( 'wp_ajax_app_export', array( &$this, 'export' ) ); 					// Export apps

		// Front end ajax hooks
		add_action( 'wp_ajax_pre_confirmation', array( &$this, 'pre_confirmation' ) ); 			// Get pre_confirmation results
		add_action( 'wp_ajax_nopriv_pre_confirmation', array( &$this, 'pre_confirmation' ) ); 	// Get pre_confirmation results
		add_action( 'wp_ajax_post_confirmation', array( &$this, 'post_confirmation' ) ); 		// Do after final confirmation
		add_action( 'wp_ajax_nopriv_post_confirmation', array( &$this, 'post_confirmation' ) ); // Do after final confirmation

		add_action( 'wp_ajax_cancel_user_app', array( $this, 'cancel_user_app' ) );
		add_action( 'wp_ajax_nopriv_cancel_user_app', array( $this, 'cancel_user_app' ) );

		add_action( 'wp_ajax_services_load_thumbnail', array( $this, 'load_service_thumbnail' ) );
		add_action( 'wp_ajax_nopriv_services_load_thumbnail', array( $this, 'load_service_thumbnail' ) );

		add_filter( 'app-export-columns', array( $this, 'app_export_columns' ) );
		add_action( 'wp_ajax_delete_worker', array( $this, 'delete_worker' ) );
		add_action( 'wp_ajax_delete_service', array( $this, 'delete_service' ) );
	}

	public function load_service_thumbnail() {
		wp_send_json_success( array( 'hello' ) );
	}

	public function cancel_user_app() {
		$user_id = get_current_user_id();
		$options = appointments_get_options();
		$app_id = absint( $_POST['app_id'] );
		check_ajax_referer( 'cancel-appointment-' . $user_id, 'cancel_nonce' );

		if ( 'yes' != $options['allow_cancel'] ) {
			wp_send_json_error( esc_js(__('Die Stornierung von Terminen ist deaktiviert. Bitte wende Dich an den Website-Administrator.','appointments') ) );
		}

		$app = appointments_get_appointment( $app_id );
		if ( ! $app ) {
			die();
		}

		// Check if user is the real owner of this appointment to prevent malicious attempts
		$owner = false;

		// First try to find from database
		if ( $user_id ) {
			// User is logged in
			if ( $app->user && $app->user == $user_id ) {
				$owner = true;
			}
		}

		// Then check cookie. Check is not so strict here, as he couldn't be seeing that cancel checkbox in the first place
		if ( ! $owner ) {
			$apps = Appointments_Sessions::get_current_visitor_appointments();
			if ( in_array( $app_id, $apps ) ) {
				$owner = true;
			}
		}

		// Addons may want to do something here
		$owner = apply_filters( 'app_cancellation_owner', $owner, $app_id );

		if ( !$owner ) {
			wp_send_json_error( esc_js(__('Es gibt ein Problem mit diesem Termin. Bitte aktualisiere die Seite und versuche es erneut. Wenn das Problem weiterhin besteht, wende Dich bitte an den Website-Administrator.','appointments') ) );
		}


		$result = appointments_cancel_appointment( $app_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( esc_js(__('Termin konnte nicht abgesagt werden. Bitte aktualisiere die Seite und versuche es erneut.','appointments') ) );
		}
		wp_send_json_success();
	}

	/**
	 * Track javascript errors
	 * @since 1.0.3
	 */
	function js_error() {

		// @TODO: Activate this again in future releases
		//		if  ( false && isset( $_POST['url'] ) ) {
		//			$this->error_url = $_POST['url'];
		//			$appointments->log( __('Javascript error on : ', 'appointments') . $this->error_url );
		//			die( json_encode( array( 'message'	=> '<div class="error"><p>' .
		//			                                         sprintf( __('<b>[Terminmanager]</b> You have at least one javascript error on %s.<br />Error message: %s<br />File: %s<br />Line: %s', 'appointments'), $this->error_url, @$_POST['errorMessage'], @$_POST['file'], @$_POST['lineNumber']) .
		//			                                         '</p></div>')
		//			)
		//			);
		//		}
		die();
	}

	function inline_edit_save() {
		global $appointments, $wpdb, $current_user;

		check_ajax_referer( 'app-edit-appointment', 'nonce' );

		$app_id = absint( $_POST["app_id"] );
		$app = appointments_get_appointment( $app_id );
		$app_orig_status = isset( $app->status ) ? $app->status : '';
		$data = array();

		$data['user'] = absint( $_POST['user'] );
		$data['email'] = !empty($_POST['email']) && is_email($_POST['email']) ? $_POST['email'] : '';
		$data['name'] = sanitize_text_field( $_POST['name'] );
		$data['phone'] = sanitize_text_field( $_POST['phone'] );
		$data['address'] = sanitize_text_field( $_POST['address'] );
		$data['city'] = sanitize_text_field( $_POST['city'] );
		$data['service'] = absint( $_POST['service'] );
		$data['worker'] = absint( $_POST['worker'] );
		$data['price'] = $_POST['price'];
		$data['note'] = $_POST['note'];
		$data['status'] = $_POST['status'];
		$data['date'] = $_POST['date'];
		$data['time'] = $_POST['time'];
		$resend = (bool)$_POST["resend"];

		$data = apply_filters('app-appointment-inline_edit-save_data', $data);

		$error = apply_filters( 'appointments_inline_edit_error', false, $data, $_REQUEST );
		if ( is_wp_error( $error ) ) {
			$result = array(
				'app_id' => $app_id,
				'message' => '<strong style="color:red;">' . _x( 'Fehler', 'Fehler beim Bearbeiten eines Termins', 'appointments' ) . ': ' . $error->get_error_message() . '</strong>'
			);
			wp_send_json( $result );
		}
		elseif ( true === $error ) {
			// Unknown error
			$result = array(
				'app_id' => $app_id,
				'message' => '<strong style="color:red;">' . _x( 'Fehler', 'Fehler beim Bearbeiten eines Termins', 'appointments' ) . ': ' . __( 'Datensatz konnte nicht gespeichert werden ODER Du hast keine Änderungen vorgenommen!', 'appointments' ) . '</strong>'
			);
			wp_send_json( $result );
		}

		do_action( 'appointments_inline_edit', $app_id, $data );


		$update_result = $insert_result = false;
		if ( $app ) {
			// Update

			if ( ! $resend ) {
				// The appointments_update_appointment() will try send confirmation email. 
				// Avoid sending if not requested
				add_filter( 'appointments_send_confirmation', '__return_false', 40 );
			}

			$data['datetime'] = strtotime( $data['date'] . ' ' . $data['time'] );
			$update_result = appointments_update_appointment( $app_id, $data );

			// Send confirmation email if requested in update (only for confirmed or similar statuses)
			$unconfirmable_statuses = array( 'removed', 'pending', 'completed' );

			if( $app_orig_status != $data['status'] || in_array( $data['status'], $unconfirmable_statuses ) ){
				$resend = false;
			}

			if ( $resend ) {
				appointments_send_confirmation( $app_id );
			}

		} else {
            // Insert
			$data['date'] = strtotime( $data['date'] . ' ' . $data['time'] );
			if ( ! $resend ) {
				add_filter( 'appointments_send_confirmation', '__return_false', 50 );
            }
			$app_id = appointments_insert_appointment( $data );
			$insert_result = true;
		}

		do_action('app-appointment-inline_edit-after_save', $app_id, $data);
		do_action('app-appointment-inline_edit-before_response', $app_id, $data);

		$app = appointments_get_appointment( $app_id );
		if ( ! $app ) {
			$insert_result = false;
			$update_result = false;
		}


		if ( $update_result ) {
			$app_orig_status = $app->status;
			$result = array(
				'app_id' => $app->ID,
				'message' => __('<span style="color:green;font-weight:bold">Änderungen gespeichert.</span>', 'appointments'),
			);
			// Log change of status
			if ( $data['status'] != $app_orig_status ) {
				$appointments->log( sprintf( __('Status geändert von %s zu %s von %s für Termin ID:%d','appointments'), $app_orig_status, $data["status"], $current_user->user_login, $app->ID ) );
				// Result includes reload of page to show updated statuses.
				$result[ 'reload' ] = true;
			}

		} else if ( $insert_result ) {
			$result = array(
				'app_id' => $app->ID,
				'message' => __('<span style="color:green;font-weight:bold">Änderungen gespeichert.</span>', 'appointments'),
			);
		} else {
			$message = $resend && !empty($data['status']) && 'removed' != $data['status']
				? sprintf('<span style="color:green;font-weight:bold">%s</span>', __('Bestätigungsnachricht(erneut) gesendet', 'appointments'))
				: sprintf('<span style="color:red;font-weight:bold">%s</span>', __('Der Datensatz konnte nicht gespeichert werden oder Du hast keine Änderungen vorgenommen!', 'appointments'))
				;
			$result = array(
				'app_id' => $app_id,
				'message' => $message,
			);
		}

		$result = apply_filters('app-appointment-inline_edit-result', $result, $app_id, $data);
		die(json_encode($result));
	}

	// Edit or create appointments
	function inline_edit() {
		$appointments = appointments();

		check_ajax_referer( 'app-add-new', 'nonce' );

		$app_id = absint( $_POST["app_id"] );
		$app = false;
		$workers = appointments_get_workers();
		if ( $app_id ) {
			$app = appointments_get_appointment( $app_id );
		}

		if ( $app ) {
			$start_date_timestamp = date( "Y-m-d", $app->get_start_timestamp() );
			$end_datetime = date_i18n( $appointments->datetime_format, strtotime( $app->end ) );

			// Is this a registered user?
			if ( $app->user ) {
				$fields = array( 'name', 'email', 'phone', 'address', 'city' );

				// Fill up the fields
				foreach ( $fields as $field ) {
					$value = get_user_meta( $app->user, 'app_' . $field, true );
					if ( $value ) {
						$app->$field = $value;
					}
				}
			}
		} else {
			$worker_id = 0;
			if ( ! empty( $workers ) && $workers[0] instanceof Appointments_Worker ) {
				$worker_id =  $workers[0]->ID;
			}
			
			$app = array(
				'ID' => 0,
				'user' => 0,
				'worker' => $worker_id,
				'location' => 0,
				'service' => appointments_get_services_min_id(),
				'price' => $appointments->get_price()
			);

			// @TODO Remove this shit
			// Get first service and its price
			$_REQUEST['app_service_id'] = $app['service'];
			$_REQUEST['app_provider_id'] = 0;

			$app = new Appointments_Appointment( $app );

			// Set start date as now + 60 minutes.
			$current_time = current_time( 'timestamp' );
			$start_date_timestamp = date( "Y-m-d", $current_time + 60 * $appointments->get_min_time() );
			$end_datetime = '';
		}

		$column_length = isset( $_POST['col_len'] ) && is_numeric( $_POST['col_len'] ) ? absint( $_POST['col_len'] ) : 6;
		$columns = isset( $_POST['columns'] ) ? absint( $_POST['columns'] ) : $column_length;
		$dropdown_users = wp_dropdown_users( array(
			'show_option_all' => __( 'Nicht registrierter Benutzer', 'appointments' ),
			'show'            => 'user_login',
			'echo'            => 0,
			'selected'        => $app->user,
			'name'            => 'user'
		) );

		$user_fields = array( 'name', 'email', 'phone', 'address', 'city' );

		$options = appointments_get_options();

		// Check if an admin min time (time base) is set. @since 1.0.2
		$min_time = $options["admin_min_time"] ? $options["admin_min_time"] : $appointments->get_min_time();
		$min_secs = 60 * apply_filters( 'app_admin_min_time', $min_time );

		$services = appointments_get_services();
		$notifications_sent = appointments_get_appointment_meta( $app_id, '_notifications_sent' );
		$confirmation_sent = ( is_array( $notifications_sent ) && isset( $notifications_sent['confirmation'] ) && $notifications_sent['confirmation'] );

		ob_start();
		include( appointments_plugin_dir() . 'admin/views/inline-edit.php' );
		wp_send_json( array( 'result' => ob_get_clean() ) );

	}
	
	/**
	 * Fetch the working hours of a worker for inline edit time slots
	 */
    public function inline_fetch_worker_slots() {
        global $appointments, $wpdb, $current_user;
        check_ajax_referer( 'app-edit-appointment', 'nonce' );
        $worker_id 			= absint( $_POST["worker_id"] );
        $app_id 			= absint( $_POST["app_id"] );
        $location_id 		= isset( $_POST['location_id'] ) ? absint( $_POST['location_id'] ) : 0;
        $selected_slot 		= sanitize_text_field( $_POST['selected_slot'] );
        $format             = get_option( 'time_format' );
        // @TODO: If date is set from js, we could check the day number to get time slots for that day
        $slots = appointments_get_worker_weekly_start_hours( $app_id, $worker_id, $location_id, true, 'admin_min_time' );
        if ( ! $slots ) {
            $result = array(
                'error' => '<strong style="color:red;">' . __( 'Fehler beim Abrufen der Arbeitszeit.', 'appointments' ) . '</strong>'
            );
            wp_send_json( $worker_slots );
        }
        $worker_slots = array();
        if ( is_array( $slots ) ) {
            foreach ( $slots as $slot ) {
                $h = strtotime( $slot );
                $worker_slots[] = sprintf(
                    '<option value="%s" %s>%s</option>',
                    esc_attr( $slot ),
                    selected( $selected_slot, $slot, false ),
                    esc_html( date( $format, strtotime( $slot ) ) )
                );
            }
        }
        $result = array(
            'message' => json_encode( $worker_slots )
        );
        wp_send_json( $result );
    }

	/**
	 * Make checks on submitted fields and save appointment
	 */
	function post_confirmation() {
		global $appointments;
		$check_spam = $appointments->check_spam();
		if (! $check_spam) {
			die(json_encode(array(
				"error" => apply_filters(
					'app_spam_message',
					__( 'Du hast Dich bereits für einen Termin beworben. Bitte warte, bis Du von uns hörst.', 'appointments')
				),
			)));
		}
		/**
		 * Check nonce
		 */
		$this->security_check_die( 'AppShortcodeConfirmation' );

		global $current_user;

		$values = explode( ":", $_POST["value"] );
		$location = $values[0];
		$service = $values[1];
		$worker = $values[2];
		$start = $values[3];
		$end = $values[4];
		$post_id = $values[5];

		if (is_user_logged_in()) {
			$user_id = $current_user->ID;
			$userdata = get_userdata( $current_user->ID );
			$user_email = $userdata->email;

			$user_name = $userdata->display_name;
			if (!$user_name) {
				$first_name = get_user_meta($worker, 'first_name', true);
				$last_name = get_user_meta($worker, 'last_name', true);
				$user_name = $first_name . " " . $last_name;
			}
			if ("" == trim($user_name)) $user_name = $userdata->user_login;
		} else {
			$user_id = 0;
			$user_email = '';
			$user_name = '';
		}

		// A little trick to pass correct lsw variables to the get_price, is_busy and get_capacity functions
		$_REQUEST["app_location_id"] = $location;
		$_REQUEST["app_service_id"] = $service;
		$_REQUEST["app_provider_id"] = $worker;
		$appointments->get_lsw();

		// Default status
        $status = 'pending';


		if ('yes' != $appointments->options["payment_required"] && isset($appointments->options["auto_confirm"]) && 'yes' == $appointments->options["auto_confirm"]) {
			$status = 'confirmed';
		}

		// We may have 2 prices now: 1) Service full price, 2) Amount that will be paid to Paypal
		$price = $appointments->get_price();
		$price = apply_filters('app_post_confirmation_price', $price, $service, $worker, $start, $end);
		$paypal_price = $appointments->get_price(true);
		$paypal_price = apply_filters('app_post_confirmation_paypal_price', number_format( str_replace( ',', '', $paypal_price ), 2, ".", "" ), $service, $worker, $start, $end);

		// Break here - is the appointment free and, if so, shall we auto-confirm?
        if (
            0 == 100 * $price
            && 'pending' === $status
            && 'yes' === $appointments->options['payment_required']
			&& isset($appointments->options['auto_confirm']) && 'yes' === $appointments->options['auto_confirm']
			&& isset($appointments->options['allow_free_autoconfirm'])
            && $appointments->options['allow_free_autoconfirm']
        ) {
            $status = 'confirmed';
        }
		$name = !empty($_POST['app_name'])
			? sanitize_text_field($_POST["app_name"])
			: $user_name
			;
		$name_check = apply_filters( "app_name_check", true, $name );
		if ( ! $name_check ) {
			$appointments->json_die( 'name' );
		}

		$email = $user_email;
		if ( ! empty( $_POST['app_email'] ) ) {
			$_email = sanitize_email( $_POST['app_email'] );
			if ( is_email( $_email ) ) {
				$email = $_email;
			}
		}

		if ( $appointments->options["ask_email"] && ! is_email( $email ) ) {
			$appointments->json_die( 'email' );
		}

		$phone = !empty($_POST['app_phone'])
			? sanitize_text_field($_POST["app_phone"])
			: ''
			;
		$phone_check = apply_filters("app_phone_check", true, $phone);
		if ( ! $phone_check ) {
			$appointments->json_die( 'phone' );
		}

		$address = !empty($_POST['app_address'])
			? sanitize_text_field($_POST["app_address"])
			: ''
			;
		$address_check = apply_filters("app_address_check", true, $address);
		if ( ! $address_check ) {
			$appointments->json_die( 'address' );
		}

		$city = !empty($_POST['app_city'])
			? sanitize_text_field($_POST["app_city"])
			: ''
			;
		$city_check = apply_filters("app_city_check", true, $city);
		if ( ! $city_check ) {
			$appointments->json_die( 'city' );
		}

		$note = !empty($_POST['app_note'])
			? sanitize_text_field($_POST["app_note"])
			: ''
			;

		$gcal = !empty($_POST['app_gcal'])
			? $_POST['app_gcal']
			: ''
			;

		do_action('app-additional_fields-validate');

		// It may be required to add additional data here
		$note = apply_filters('app_note_field', $note);

		$service_result = appointments_get_service( $service );

		$duration = false;
		if ( $service_result !== null ) {
			$duration = $service_result->duration;
		}
		if ( ! $duration ) {
			// In minutes
			$duration = $appointments->get_min_time();
		}
        $duration = apply_filters( 'app_post_confirmation_duration', $duration, $service, $worker, $user_id );
        $end = $start + $duration * MINUTE_IN_SECONDS;
		$args = array(
			'worker_id' => $worker,
			'service_id' => $service,
			'location_id' => $location
		);
		$is_busy = apppointments_is_range_busy( $start, $end, $args );
		if ( $is_busy ) {
			die( json_encode( array(
				"error" => apply_filters(
					'app_booked_message',
					__( 'Es tut uns leid, aber dieses Zeitfenster ist nicht mehr verfügbar. Bitte aktualisiere die Seite und versuche es mit einem anderen Zeitfenster. Vielen Dank.', 'appointments' )
				),
			) ) );
		}

		if ( ! $worker ) {
			// Try to assign to first worker available
			if( $service ){
				$workers = appointments_get_workers_by_service( $service );
			}
			else{
				$workers = appointments_get_all_workers();
            }
			foreach ( $workers as $worker ) {
				$args['worker_id'] = $worker->ID;
                $is_busy = apppointments_is_range_busy( $start, $end, $args );
				if ( ! $is_busy ) {
					$worker = $worker->ID;
					break;
				}
			}
		}
		unset( $args );
		$status = apply_filters('app_post_confirmation_status', $status, $price, $service, $worker, $user_id );
		$args = array(
			'user'     => $user_id,
			'name'     => $name,
			'email'    => $email,
			'phone'    => $phone,
			'address'  => $address,
			'city'     => $city,
			'location' => $location,
			'service'  => $service,
			'worker'   => $worker,
			'price'    => $price,
			'status'   => $status,
			'date'    => $start,
			'note'     => $note,
			'duration' => $duration
		);

		/**
		 * Allow to filter arguments that will be passed to appointment insertion
		 *
		 * Return WP_Error or true to avoid appointment insert
		 *
		 * @param bool
		 * @param array $args List of arguments that will be used to insert the appointment
		 * @param array $_REQUEST Request list coming from form screen
		 */
		$args = apply_filters( 'appointments_post_confirmation_args', $args );

		/**
		 * Allow to filter errors in confirmation form right before the appointment is added
		 *
		 * Return WP_Error or true to avoid appointment insert
		 *
		 * @param bool
		 * @param array $args List of arguments that will be used to insert the appointment
		 * @param array $_REQUEST Request list coming from form screen
		 */
		$error = apply_filters( 'appointments_post_confirmation_error', false, $args, $_REQUEST );
		if ( is_wp_error( $error ) ) {
			wp_send_json( array( 'error' => $error->get_error_message() ) );
		}
		elseif ( true === $error ) {
			// Unknown error
			wp_send_json( array( 'error' => __( 'Termin konnte nicht gespeichert werden. Bitte wende Dich an den Website-Administrator.', 'appointments') ) );
		}
        $insert_id = appointments_insert_appointment( $args );
        /**
         * somthing went wrong!
         */
		if ( ! $insert_id ) {
			die(json_encode(array(
				"error" => __( 'Termin konnte nicht gespeichert werden. Bitte wende Dich an den Website-Administrator.', 'appointments'),
			)));
		}
        /**
         * GDPR
         */
        if ( isset( $_REQUEST['app_gdpr'] ) && $_REQUEST['app_gdpr'] )  {
            appointments_update_appointment_meta( $insert_id, 'gdpr_agree', time() );
        }
        /**
         * A new appointment is accepted, so clear cache.
         */
		appointments_clear_appointment_cache( $insert_id, $args );
		appointments_clear_cache();
		$apps = Appointments_Sessions::get_current_visitor_appointments();
		$apps[] = $insert_id;
		Appointments_Sessions::set_visitor_appointments( $apps );
		Appointments_Sessions::set_visitor_personal_data( array(
			"n" => $name,
			"e" => $email,
			"p" => $phone,
			"a" => $address,
			"c" => $city,
			"g" => $gcal
		) );

		// May be required to clean up or modify userdata cookie
		do_action( 'app_save_cookie', $insert_id, $apps );

		// Save user data too
		if ( is_user_logged_in() && defined('APP_USE_LEGACY_USERDATA_OVERWRITING') && APP_USE_LEGACY_USERDATA_OVERWRITING ) {
			global $current_user;
			if ( $name )
				update_user_meta( $current_user->ID, 'app_name', $name );
			if ( $email )
				update_user_meta( $current_user->ID, 'app_email', $email );
			if ( $phone )
				update_user_meta( $current_user->ID, 'app_phone', $phone );
			if ( $address )
				update_user_meta( $current_user->ID, 'app_address', $address );
			if ( $city )
				update_user_meta( $current_user->ID, 'app_city', $city );

			do_action( 'app_save_user_meta', $current_user->ID, array( 'name'=>$name, 'email'=>$email, 'phone'=>$phone, 'address'=>$address, 'city'=>$city ) );
		}

		// Send confirmation for pending, payment not required cases, if selected so
		if (
			'yes' != $appointments->options["payment_required"] &&
			isset($appointments->options["send_notification"]) &&
			'yes' == $appointments->options["send_notification"] &&
			'pending' == $status
		) {
			appointments_send_notification( $insert_id );
		}

		// GCal button
		if (isset($appointments->options["gcal"]) && 'yes' == $appointments->options["gcal"] && $gcal) {
			$gcal_url = $appointments->gcal( $service, $start, $start + ($duration * 60 ), false, $address, $city );
		} else {
			$gcal_url = '';
		}

		$additional = array(
			'mp' => 0,
			'variation' => null,
		);
		$additional = apply_filters('app-appointment-appointment_created', $additional, $insert_id, $post_id, $service, $worker, $start, $end);
		$mp = isset( $additional['mp'] ) ? $additional['mp'] : 0;
		$variation = isset( $additional['variation'] ) ? $additional['variation'] : 0;

		$gcal_same_window = ! empty( $appointments->options["gcal_same_window"] ) ? 1 : 0;

		if (isset( $appointments->options["payment_required"] ) && 'yes' == $appointments->options["payment_required"]) {
			die(json_encode(array(
				"cell" => $_POST["value"],
				"app_id" => $insert_id,
				"refresh" => 0,
				"price" => $paypal_price,
				"service_name" => stripslashes( $service_result->name ),
				'gcal_url' => $gcal_url,
				'gcal_same_window' => $gcal_same_window,
				'mp' => $mp,
				'variation' => $variation
			)));
		} else {
			$result = array(
				"cell" => $_POST["value"],
				"app_id" => $insert_id,
				"refresh" => 1,
				'gcal_url' => $gcal_url,
				'gcal_same_window' => $gcal_same_window,
			);
			wp_send_json( $result );
		}
	}

	/**
	 *	IPN handling for Paypal
	 */
	function handle_paypal_return() {

		global $appointments;

		// PayPal IPN handling code
		$appointments->options = get_option( 'appointments_options' );

		if ((isset($_POST['payment_status']) || isset($_POST['txn_type'])) && isset($_POST['custom'])) {

			if ($appointments->options['mode'] == 'live') {
				$domain = 'https://www.paypal.com';
			} else {
				$domain = 'https://www.sandbox.paypal.com';
			}

			$req = 'cmd=_notify-validate';
			foreach ($_POST as $k => $v) {
				$req .= '&' . $k . '=' . $v;
			}

			$header = 'POST /cgi-bin/webscr HTTP/1.0' . "\r\n"
				. 'Content-Type: application/x-www-form-urlencoded' . "\r\n"
				. 'Content-Length: ' . strlen($req) . "\r\n"
				. "\r\n";

			@set_time_limit(60);
			if ($conn = @fsockopen($domain, 80, $errno, $errstr, 30)) {
				fputs($conn, $header . $req);
				socket_set_timeout($conn, 30);

				$response = '';
				$close_connection = false;
				while (true) {
					if (feof($conn) || $close_connection) {
						fclose($conn);
						break;
					}

					$st = @fgets($conn, 4096);
					if ($st === false) {
						$close_connection = true;
						continue;
					}

					$response .= $st;
				}

				$error = '';
				$lines = explode("\n", str_replace("\r\n", "\n", $response));
				// looking for: HTTP/1.1 200 OK
				if (count($lines) == 0) $error = 'Response Error: Header not found';
				else if (substr($lines[0], -7) != ' 200 OK') $error = 'Response Error: Unexpected HTTP response';
				else {
					// remove HTTP header
					while (count($lines) > 0 && trim($lines[0]) != '') array_shift($lines);

					// first line will be empty, second line will have the result
					if (count($lines) < 2) $error = 'Response Error: No content found in transaction response';
					else if (strtoupper(trim($lines[1])) != 'VERIFIED') $error = 'Response Error: Unexpected transaction response';
				}

				if ($error != '') {
					$appointments->log( $error );
					exit;
				}
			}

			// We are using server time. Not Paypal time.
			$timestamp = $appointments->local_time;

			// process PayPal response
			switch ($_POST['payment_status']) {
			case 'Partially-Refunded':
				break;

			case 'In-Progress':
				break;

			case 'Completed':
			case 'Processed':
				// case: successful payment
				$amount = $_POST['mc_gross'];
				$currency = $_POST['mc_currency'];


				$args = array(
					'app_ID' => $_POST['custom'],
					'paypal_ID' => $_POST['txn_id'],
					'stamp' => $timestamp,
					'total_amount' => $amount,
					'currency' => $currency,
					'status' => $_POST['payment_status'],
					'note' => '',
				);

				$transaction = appointments_get_transaction( $_POST['custom'] );
				if ( $transaction ) {
					appointments_update_transaction( $_POST['custom'], $args );
				}
				else {
					appointments_insert_transaction( $args );
				}

				if ( ! appointments_update_appointment_status( $_POST['custom'], 'paid' ) ) {
					// Something wrong. Warn admin
					$message = sprintf( __('Die Paypal-Bestätigung ist eingetroffen, der Status konnte jedoch aus irgendeinem Grund nicht geändert werden. Bitte überprüfe den Termin mit der ID %s', 'appointments'), $_POST['custom'] );

					wp_mail( $appointments->get_admin_email( ), __('Der Terminstatus konnte nicht geändert werden','appointments'), $message, $appointments->message_headers() );
				}
				break;

			case 'Reversed':
				// case: charge back
				$note = __('Die letzte Transaktion wurde rückgängig gemacht. Grund: Zahlung wurde storniert (Rückbelastung)', 'appointments');
				$amount = $_POST['mc_gross'];
				$currency = $_POST['mc_currency'];

				$args = array(
					'app_ID' => $_POST['custom'],
					'paypal_ID' => $_POST['txn_id'],
					'stamp' => $timestamp,
					'total_amount' => $amount,
					'currency' => $currency,
					'status' => $_POST['payment_status'],
					'note' => $note,
				);

				$transaction = appointments_get_transaction( $_POST['custom'] );
				if ( $transaction ) {
					appointments_update_transaction( $_POST['custom'], $args );
				}
				else {
					appointments_insert_transaction( $args );
				}
				break;

			case 'Refunded':
				// case: refund
				$note = __('Die letzte Transaktion wurde rückgängig gemacht. Grund: Die Zahlung wurde zurückerstattet', 'appointments');
				$amount = $_POST['mc_gross'];
				$currency = $_POST['mc_currency'];

				$args = array(
					'app_ID' => $_POST['custom'],
					'paypal_ID' => $_POST['txn_id'],
					'stamp' => $timestamp,
					'total_amount' => $amount,
					'currency' => $currency,
					'status' => $_POST['payment_status'],
					'note' => $note,
				);

				$transaction = appointments_get_transaction( $_POST['custom'] );
				if ( $transaction ) {
					appointments_update_transaction( $_POST['custom'], $args );
				}
				else {
					appointments_insert_transaction( $args );
				}
				break;

			case 'Denied':
				// case: denied
				$note = __('Die letzte Transaktion wurde rückgängig gemacht. Grund: Zahlung verweigert', 'appointments');
				$amount = $_POST['mc_gross'];
				$currency = $_POST['mc_currency'];

				$args = array(
					'app_ID' => $_POST['custom'],
					'paypal_ID' => $_POST['txn_id'],
					'stamp' => $timestamp,
					'total_amount' => $amount,
					'currency' => $currency,
					'status' => $_POST['payment_status'],
					'note' => $note,
				);

				$transaction = appointments_get_transaction( $_POST['custom'] );
				if ( $transaction ) {
					appointments_update_transaction( $_POST['custom'], $args );
				}
				else {
					appointments_insert_transaction( $args );
				}

				break;

			case 'Pending':
				// case: payment is pending
				$pending_str = array(
					'address' => __('Der Kunde hat keine bestätigte Lieferadresse angegeben', 'appointments'),
					'authorization' => __('Überweisung noch nicht erfasst', 'appointments'),
					'echeck' => __('eCheck, das noch nicht gelöscht wurde', 'appointments'),
					'intl' => __('Zahlung wartet auf Genehmigung durch Dienstleister', 'appointments'),
					'multi-currency' => __('Zahlung wartet darauf, dass der Dienstanbieter den Prozess mit mehreren Währungen abwickelt', 'appointments'),
					'unilateral' => __('Der Kunde hat seine E-Mail noch nicht registriert oder bestätigt', 'appointments'),
					'upgrade' => __('Warten auf das Upgrade des PayPal-Kontos durch den Dienstanbieter', 'appointments'),
					'verify' => __('Warten, bis der Dienstanbieter sein PayPal-Konto überprüft hat', 'appointments'),
					'*' => ''
				);
				$reason = @$_POST['pending_reason'];
				$note = __('Letzte Transaktion steht noch aus. Grund: ', 'appointments') . (isset($pending_str[$reason]) ? $pending_str[$reason] : $pending_str['*']);
				$amount = $_POST['mc_gross'];
				$currency = $_POST['mc_currency'];

				$args = array(
					'app_ID' => $_POST['custom'],
					'paypal_ID' => $_POST['txn_id'],
					'stamp' => $timestamp,
					'total_amount' => $amount,
					'currency' => $currency,
					'status' => $_POST['payment_status'],
					'note' => $note,
				);

				$transaction = appointments_get_transaction( $_POST['custom'] );
				if ( ! $transaction ) {
					appointments_update_transaction( $_POST['custom'], $args );
				}
				else {
					appointments_insert_transaction( $args );
				}

				break;

			default:
				// case: various error cases
			}
		} else {
			// Did not find expected POST variables. Possible access attempt from a non PayPal site.
			// This is IPN response, so echoing will not help. Let's log it.
			$appointments->log( 'Error: Missing POST variables. Identification is not possible.' );
			exit;
		}
		exit;
	}
	/**
	 * Check and return necessary fields to the front end
	 */
	function pre_confirmation () {
		global $appointments;

		/**
		 * Check nonce
		 */
		$this->security_check_die( 'AppShortcodeConfirmation' );


		$values = explode( ":", $_POST["value"] );
		$location = $values[0];
		$service_id = $service = $values[1];
		$worker_id = $worker = $values[2];
		$start = $values[3];
		$end = $values[4];

		// A little trick to pass correct lsw variables to the get_price, is_busy and get_capacity functions
		$_REQUEST["app_location_id"] = $location;
		$_REQUEST["app_service_id"] = $service;
		$_REQUEST["app_provider_id"] = $worker;
		$appointments->get_lsw();

		// Alright, so before we go further, let's check if we can
		if (!is_user_logged_in() && (!empty($appointments->options['login_required']) && 'yes' == $appointments->options['login_required'])) {
			die(json_encode(array(
				'error' => __('Du musst Dich anmelden, um einen Termin zu vereinbaren.', 'appointments'),
			)));
		}

		$price = $appointments->get_price();

		// It is possible to apply special discounts
		$price = apply_filters('app_display_amount', $price, $service, $worker);
		$price = apply_filters('app_pre_confirmation_price', $price, $service, $worker, $start, $end);

		$display_currency = !empty($appointments->options["currency"])
			? App_Template::get_currency_symbol($appointments->options["currency"])
			: App_Template::get_currency_symbol('USD')
			;

		$is_busy = $appointments->is_busy($start,  $end, $appointments->get_capacity());
		if ( $is_busy ) {
			die(json_encode(array(
				"error" => apply_filters(
					'app_booked_message',
					__( 'Es tut uns leid, aber dieses Zeitfenster ist nicht mehr verfügbar. Bitte aktualisiere die Seite und versuche es mit einem anderen Zeitfenster. Vielen Dank.', 'appointments')
				)
			)));
		}

        $service_obj = appointments_get_service($service);
		if ( ! $service_obj ) {
			die(json_encode(array('error' => __('Dienst nicht gefunden.', 'appointments'))));
		}
		$service = '<label><span>' . __('Dienstname: ', 'appointments') .  '</span>'. apply_filters('app_confirmation_service', stripslashes($service_obj->name), $service_obj->name) . '</label>';
		$start = '<label><span>' . __('Datum und Uhrzeit: ', 'appointments') . '</span>'. apply_filters('app_confirmation_start', date_i18n($appointments->datetime_format, $start), $start) . '</label>';
		$end = '<label><span>' . __('Dauert (ungefähr): ', 'appointments') . '</span>'. apply_filters('app_confirmation_lasts', $service_obj->duration . " " . __('Minuten', 'appointments'), $service_obj->duration) . '</label>';

		$price = $price > 0
			? '<label><span>' . __('Preis: ', 'appointments') .  '</span>'. apply_filters('app_confirmation_price', $price . " " . $display_currency, $price) . '</label>'
			: 0
			;
		$worker = !empty($worker)
			? '<label><span>' . __('Dienstleister: ', 'appointments' ) . '</span>'. apply_filters('app_confirmation_worker', stripslashes(appointments_get_worker_name($worker)), $worker) . '</label>'
			: ''
            ;
		$ask_name = !empty($appointments->options['ask_name'])
			? 'ask'
			: ''
			;

		$ask_email = !empty($appointments->options['ask_email'])
			? 'ask'
			: ''
			;

		$ask_phone = !empty($appointments->options['ask_phone'])
			? 'ask'
			: ''
			;

		$ask_address = !empty($appointments->options['ask_address'])
			? 'ask'
			: ''
			;

		$ask_city = !empty($appointments->options['ask_city'])
			? 'ask'
			: ''
			;

		$ask_note = !empty($appointments->options['ask_note'])
			? 'ask'
			: ''
			;

		$ask_gcal = isset( $appointments->options["gcal"] ) && 'yes' == $appointments->options["gcal"]
			? 'ask'
			: ''
			;

		$reply_array = array(
			'service_id'=> intval( $service_id ),
			'service'	=> $service,
			'worker'	=> $worker,
			'worker_id' => intval( $worker_id ),
			'start'		=> $start,
			'end'		=> $end,
			'price'		=> $price,
			'name'		=> $ask_name,
			'email'		=> $ask_email,
			'phone'		=> $ask_phone,
			'address'	=> $ask_address,
			'city'		=> $ask_city,
			'note'		=> $ask_note,
			'gcal'		=> $ask_gcal
		);

		$reply_array = apply_filters('app_pre_confirmation_reply', $reply_array);

		die(json_encode($reply_array));
	}


	/**
	 * Save a CSV file of all appointments
	 * @since 1.0.9
	 */
	function export(){
		global $appointments;
		$type = ! empty( $_POST['export_type'] ) ? $_POST['export_type'] : 'all';
		$apps = array();
		$args = array();
		if ( 'selected' == $type && ! empty( $_POST['app'] ) ) {
			// selected appointments
			if ( $_POST['app'] ) {
				$args = array( 'app_id' => array_map( 'absint', $_POST['app'] ) );
			}
		} else if ( 'type' == $type ) {
			$status = ! empty( $_POST['status'] ) ? $_POST['status'] : false;
			if ( 'active' === $status ) {
				$args = array( 'status' => array( 'confirmed', 'paid' ) );
			} else if ( $status ) {
				$args = array( 'status' => $status );
			}
		}
		$args = apply_filters( 'app-export-appointment-args', $args );
		$apps = appointments_get_appointments( $args );

		if ( empty( $apps ) || ! is_array( $apps ) ) {
			die( __( 'Nichts zum Download!', 'appointments' ) );
		}
		$file = fopen('php://temp/maxmemory:'. (12*1024*1024), 'r+');
		// Add field names to the file
		$columns = array_map( 'strtolower', apply_filters( 'app-export-columns', $appointments->db->get_col_info() ) );
		fputcsv( $file,  $columns );
		foreach ( $apps as $app ) {
			$raw = $app;
			array_walk( $app, array( &$this, 'export_helper' ) );
			$app = $this->sort_app_columns( $app, $columns );
			$app = apply_filters( 'app-export-appointment', $app, $raw );
			if ( ! empty( $app ) ) {
				fputcsv( $file, (array)$app );
			}
		}
		$filename = "appointments_".date('F')."_".date('d')."_".date('Y').".csv";
		//serve the file
		rewind($file);
		/**
		 * Check buffers
		 */
		$list = ob_list_handlers();
		if ( ! empty( $list ) ) {
			ob_end_clean(); //kills any buffers set by other plugins
		}
		header('Content-Description: File Transfer');
		header('Content-Type: text/csv');
		header('Content-Disposition: attachment; filename="'.$filename.'"');
		header('Content-Transfer-Encoding: binary');
		header('Expires: 0');
		header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
		header('Pragma: public');
		$output = stream_get_contents($file);
		//$output = $output . "\xEF\xBB\xBF"; // UTF-8 BOM
		header('Content-Length: ' . strlen($output));
		fclose($file);
		die($output);
	}

	/**
	 * Sorting the appointment columns so they match the csv columns. Usefull when locations addon enabled
	 * @since 2.2.1
	 */
	function sort_app_columns( $app, $columns ){

		$sorted_app = array();
		$app_array = array_change_key_case( (array)$app, CASE_LOWER );

		foreach( $columns as $column ){

			if( isset( $app_array[ $column ] ) ){
				$sorted_app[ $column ] = $app_array[ $column ];
			}
			else{
				$sorted_app[ $column ] = '';
			}

		}

		return $sorted_app;

	}


	/**
	 * Helper function for export
	 * @since 1.0.9
	 */
	function export_helper( &$value, $key ) {
		global $appointments;
		if ( 'created' == $key || 'start' == $key || 'end' == $key )
			$value = mysql2date( $appointments->datetime_format, $value );
		else if ( 'user' == $key && $value ) {
			$userdata = get_userdata( $value );
			if ( $userdata )
				$value = $userdata->user_login;
		}
		else if ( 'service' == $key )
			$value = $appointments->get_service_name( $value );
		else if ( 'worker' == $key )
			$value = appointments_get_worker_name( $value );
	}

	/**
	 * Control csv columns
	 * @since 1.9.4.1
	 */
	function app_export_columns( $cols ){
		// Do not include the Location column in the export CSV if the add-on is not active
		if( !class_exists( 'App_Locations_LocationsWorker' ) ){
			foreach( $cols as $key => $col ) {
				if( $col == 'location' ) unset( $cols[ $key ] );
			}
		}
		return $cols;
	}

	/**
	 * return security message, when checks fails
	 * @since 2.2.2.1
	 */
	private function security_check_die( $action, $query_arg = 'nonce' ) {
		$check = check_ajax_referer( $action, $query_arg, false );
		if ( false !== $check ) {
			return;
		}
		$data = array(
			"error" => apply_filters(
				'app_spam_message',
				__( 'Entschuldigung, es gab ein Problem mit Deiner Anfrage!', 'appointments')
			),
		);
		$data = json_encode( $data );
		die( $data );
	}

	/**
	 * Delete service
	 *
	 * @since 2.2.6
	 */
	public function delete_service() {
		$data = array( 'message' => __( 'Entschuldigung, es gab ein Problem mit Deiner Anfrage!', 'appointments') );
		if ( ! isset( $_POST['id'] ) || ! isset( $_POST['nonce'] ) ) {
			wp_send_json_error( $data );
		}
		$service_id = filter_input( INPUT_POST, 'id', FILTER_VALIDATE_INT );
		$nonce = sprintf( 'service-%d', $service_id );
		if ( ! wp_verify_nonce( $_POST['nonce'], $nonce ) ) {
			wp_send_json_error( $data );
		}
		$result = appointments_delete_service( $service_id );
		if ( $result ) {
			$data = array(
				'message' => __( 'Dienstanbieter wurde erfolgreich gelöscht.', 'appointments' ),
				'id' => $service_id,
			);
			wp_send_json_success( $data );
		}
		wp_send_json_error();
	}

	/**
	 * Delete worker
	 *
	 * @since 2.2.6
	 */
	public function delete_worker() {
		$data = array( 'message' => __( 'Entschuldigung, es gab ein Problem mit Deiner Anfrage!', 'appointments') );
		if ( ! isset( $_POST['id'] ) || ! isset( $_POST['nonce'] ) ) {
			wp_send_json_error( $data );
		}
		$worker_id = filter_input( INPUT_POST, 'id', FILTER_VALIDATE_INT );
		$nonce = sprintf( 'worker-%d', $worker_id );
		if ( ! wp_verify_nonce( $_POST['nonce'], $nonce ) ) {
			wp_send_json_error( $data );
		}
		$result = appointments_delete_worker( $worker_id );
		if ( $result ) {
			$data = array(
				'message' => __( 'Dienstanbieter wurde erfolgreich gelöscht.', 'appointments' ),
				'id' => $worker_id,
			);
			wp_send_json_success( $data );
		}
		wp_send_json_error();
	}

}
