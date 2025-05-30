<?php

class Appointments_Admin {
	/**
	 * @var Appointments_Admin_User_Profile
	 */
	public $user_profile;

	public $pages = array();

	public function __construct() {
		$this->includes();
		/**
		 * Allow to save selected options
		 *
		 */
		add_filter( 'set-screen-option', array( $this, 'save_screen_options' ), 10, 3 );
		// Add a column in users list to show if it's a service provider
		add_filter( 'manage_users_custom_column', array( $this, 'render_provider_user_column' ), 10, 3 );
		add_filter( 'manage_users_columns', array( $this, 'add_users_columns' ) );

		add_action( 'admin_menu', array( $this, 'admin_init' ) ); 						// Creates admin settings window
		add_action( 'admin_notices', array( $this, 'admin_notices' ) ); 				// Warns admin
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );			// Load scripts
		add_action( 'admin_enqueue_scripts', array( $this, 'edit_posts_scripts' ) );
		add_action( 'admin_print_styles', array( $this, 'admin_css' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices_new' ) );
		add_action( 'wp_ajax_appointments_dismiss_notice', array( $this, 'dismiss_notice' ) );
		// Add quick link to plugin settings from plugins list page.
		add_filter( 'plugin_action_links_' . plugin_basename( APP_PLUGIN_FILE ), array( $this, 'add_settings_link' ) );
		/**
		 * Get service data
		 *
		 * @since 2.3.0
		 */
		add_action( 'wp_ajax_appointment_get_service', array( $this, 'get_service' ) );
		/**
		 * Get worker data
		 *
		 * @since 2.3.0
		 */
		add_action( 'wp_ajax_appointment_get_worker', array( $this, 'get_worker' ) );

		new Appointments_Admin_Dashboard_Widget();
		$this->user_profile = new Appointments_Admin_User_Profile();

		include( APP_PLUGIN_DIR . '/admin/admin-helpers.php' );
		/**
		 * Add page with shortcode.
		 */
		add_action( 'wp_ajax_make_an_appointment_page', array( $this, 'make_an_appointment_page' ) );
		/**
		 * save and read proper screen columns settings
		 *
		 * @since 2.4.0
		 */
		add_action( 'update_user_meta', array( $this, 'update_meta' ), 10, 4 );
		add_filter( 'get_user_metadata', array( $this, 'get_meta' ), 10, 4 );
	}

	private function includes() {
		include_once( appointments_plugin_dir() . 'admin/class-app-list-table.php' );
		include_once( appointments_plugin_dir() . 'admin/widgets/class-app-dashboard-widget.php' );
		include_once( appointments_plugin_dir() . 'admin/class-app-admin-user-profile.php' );
	}

	/**
	 * Allow to save selected options
	 *
	 */
	public function save_screen_options( $status, $option, $value ) {
		switch ( $option ) {
			case 'app_services_per_page':
			case 'appointments_records_per_page':
			case 'app_workers_per_page':
			return $value;
		}
		return $status;
	}

	public function add_users_columns( $columns ) {
		$columns['provider'] = __( 'Appointments Provider', 'appointments' );
		return $columns;
	}

	public function render_provider_user_column( $content, $column, $user_id ) {
		if ( 'provider' === $column && appointments_is_worker( $user_id ) && App_Roles::get_capability( 'manage_options', App_Roles::CTX_PAGE_SHORTCODES ) ) {
			$link = admin_url( 'admin.php?page=app_settings&tab=workers' );
			$content = '<a href="' . esc_url( $link ) . '"><span class="dashicons dashicons-businessman"></span> ' . __( 'Edit', 'appointments' ) . '</a>';
		}

		return $content;

	}

	public function admin_notices_new() {
		$notices = _appointments_get_admin_notices();
		$noticed = false;
		foreach ( $notices as $notice_slug => $notice ) {
			if ( ! current_user_can( $notice['cap'] ) ) {
				continue;
			}

			$user_dismissed_notices = _appointments_get_user_dismissed_notices( get_current_user_id() );

			if ( $notice_text = _appointments_get_admin_notice( $notice_slug ) ) {
				if ( in_array( $notice_slug, $user_dismissed_notices ) ) {
					continue;
				}

				$noticed = true;
				?>
				<div class="error app-notice">
					<p>
						<strong>Terminmanager:</strong> <?php echo $notice_text; ?>
						<a class="app-dismiss" data-dismiss="<?php echo $notice_slug; ?>" href="#" title="<?php esc_attr_e( 'Hinweis abweisen', 'appointments' ); ?>"> <?php esc_html_e( 'Verwerfen', 'appointments' ); ?><span class="dashicons dashicons-dismiss"></span></a>
					</p>
				</div>
				<?php
			}
		}
		if ( $noticed ) {
			?>
			<script>
				jQuery( document).ready( function( $ ) {
					function app_dismiss_notice( slug ) {
						$.ajax({
							url: ajaxurl,
							data: {
								notice: slug,
								_wpnonce: '<?php echo wp_create_nonce( 'app-dismiss-notice' ); ?>',
								action: 'appointments_dismiss_notice'
							}
						})
							.always( function( data ) {
								console.log(data);
							});

					}

					//$('.app-dismiss').on("click", function( e ) {
					$('.app-dismiss').on("click", function( e ) {
						e.preventDefault();
						$(this).parent().parent().hide();
						app_dismiss_notice( $(this).data( 'dismiss' ) );
					});
				}( jQuery ) );
			</script>
			<?php
		}
	}

	public function dismiss_notice() {
		check_ajax_referer( 'app-dismiss-notice' );

		$user_id = get_current_user_id();
		if ( $user_id ) {
			$dismissed_notices = _appointments_get_user_dismissed_notices( $user_id );

			$notice_slug = $_REQUEST['notice'];
			if ( _appointments_get_admin_notice( $notice_slug ) && ! in_array( $notice_slug, $dismissed_notices ) ) {
				$dismissed_notices[] = $notice_slug;
				update_user_meta( $user_id, 'app_dismissed_notices', $dismissed_notices );
			}
		}
		die();
	}

	function admin_css() {
		global $appointments;
		wp_enqueue_style( 'appointments-admin', $appointments->plugin_url . '/css/admin.css', false, $appointments->version );

		$screen = get_current_screen();
		$title = sanitize_title( __( 'Terminmanager', 'appointments' ) );

		$allow_profile = ! empty( $appointments->options['allow_worker_wh'] ) && 'yes' == $appointments->options['allow_worker_wh'];

		if ( empty( $screen->base ) || (
				! preg_match( '/(^|\b|_)appointments($|\b|_)/', $screen->base )
				&&
				! preg_match( '/(^|\b|_)' . preg_quote( $title, '/' ) . '($|\b|_)/', $screen->base ) // Super-weird admin screen base being translatable!!!
				&&
				( ! $allow_profile || ! preg_match( '/profile/', $screen->base ) || ! (defined( 'IS_PROFILE_PAGE' ) && IS_PROFILE_PAGE))
			) ) { return; }

		wp_enqueue_style( 'jquery-colorpicker-css', $appointments->plugin_url . '/css/colorpicker.css', false, $appointments->version );
		wp_enqueue_style( 'jquery-datepick', $appointments->plugin_url . '/css/jquery.datepick.css', false, $appointments->version );
		wp_enqueue_style( 'jquery-multiselect', $appointments->plugin_url . '/css/jquery.multiselect.css', false, $appointments->version );

		wp_enqueue_style( 'custom-ligin-screen-jquery-switch-button', $appointments->plugin_url . '/assets/css/vendor/jquery.switch_button.css', array(), '1.12.1' );
		do_action( 'app-admin-admin_styles' );
	}

	function edit_posts_scripts() {
		$screen = get_current_screen();
		if ( $screen->base === 'post' || $screen->base === 'edit' ) {
			_appointments_enqueue_jquery_ui_datepicker();
		}
	}

	// Enqeue js on admin pages
	function admin_scripts() {
		global $appointments;
		$screen = get_current_screen();
		$title = sanitize_title( __( 'Terminmanager', 'appointments' ) );

		$allow_profile = ! empty( $appointments->options['allow_worker_wh'] ) && 'yes' == $appointments->options['allow_worker_wh'];

		if ( empty( $screen->base ) ) {
			return false;
		}
		if (
			! preg_match( '/(^|\b|_)appointments($|\b|_)/', $screen->base )
			&& ! preg_match( '/(^|\b|_)' . preg_quote( $title, '/' ) . '($|\b|_)/', $screen->base ) // Super-weird admin screen base being translatable!!!
			&& ( ! $allow_profile || ! preg_match( '/profile/', $screen->base ) || ! ( defined( 'IS_PROFILE_PAGE' ) && IS_PROFILE_PAGE ) )
			&& 'user-edit' !== $screen->base
		) {
			return false;
		}

		_appointments_enqueue_jquery_ui_datepicker();
		wp_enqueue_script( 'jquery-colorpicker', $appointments->plugin_url . '/js/colorpicker.js', array( 'jquery' ), $appointments->version );
		wp_enqueue_script( 'app-multi-datepicker', appointments_plugin_url() . 'admin/js/admin-multidatepicker.js', array( 'jquery-ui-datepicker' ), appointments_get_db_version(), true );
		wp_enqueue_script( 'app-switch-button', appointments_plugin_url() . 'admin/js/switch-button.js', array(), appointments_get_db_version(), true );
		wp_enqueue_script( 'jquery-multiselect', $appointments->plugin_url . '/includes/external/jquery-ui-multiselect-widget/src/jquery.multiselect.min.js', array( 'jquery-ui-core', 'jquery-ui-widget', 'jquery-ui-position' ), '2.0.1' );
		// Make a locale check to update locale_error flag

		if ( empty( $appointments->options['disable_js_check_admin'] ) ) {
			wp_enqueue_script( 'app-js-check', $appointments->plugin_url . '/js/js-check.js', array( 'jquery' ), $appointments->version );
		}

		/**
		 * Switch button
		 */
		wp_enqueue_script( 'custom-ligin-screen-jquery-switch-button', $appointments->plugin_url.'/assets/js/vendor/jquery.switch_button.js', array( 'jquery', 'jquery-effects-core' ), '1.12.1', true );
		$i18n = array(
			'labels' => array(
				'label_on' => __( 'an', 'appointments' ),
				'label_off' => __( 'aus', 'appointments' ),
			),
		);
		wp_localize_script( 'custom-ligin-screen-jquery-switch-button', 'switch_button', $i18n );

		wp_enqueue_script( 'appointments-admin', $appointments->plugin_url . '/admin/js/admin.js', array( 'jquery' ), $appointments->version );
		wp_localize_script('appointments-admin', '_app_admin_data', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'strings' => array(
				'preparing_export' => __( 'Bereite Export vor, bitte warte...', 'appointments' ),
			),
		));
		do_action( 'app-admin-admin_scripts' );
	}

	/**
	 *	Dismiss warning messages for the current user for the session
	 *	@since 1.1.7
	 */
	function dismiss() {
		global $current_user;
		$keys = array( 'app_dismiss', 'app_dismiss_google', 'app_dismiss_confirmation_lacking', 'app_dismiss_app_paypal_lacking' );
		foreach ( $keys as $key ) {
			if ( isset( $_REQUEST[ $key ] ) ) {
				update_user_meta( $current_user->ID, $key, true );
				?><div class="updated fade"><p><?php _e( 'Hinweis abgewiesen.', 'appointments' ); ?></p></div><?php
			}
		}
	}

	/**
	 *	Warn admin if no services defined or duration is wrong
	 */
	function admin_notices() {
		global $appointments;
		$this->dismiss();

		global $current_user;
		$r = false;
		$results = appointments_get_services();
		if ( ! $results ) {
			echo '<div class="error"><p>' .
			     __( '<b>[Terminmanager]</b> Du musst mindestens einmal einen Service definieren.', 'appointments' ) .
			     '</p></div>';
			$r = true;
		} else {
			foreach ( $results as $result ) {
				if ( $result->duration < $appointments->get_min_time() ) {
					echo '<div class="error"><p>' .
					     __( '<b>[Terminmanager]</b> Einer Deiner Dienste hat eine Dauer, die kleiner als die Zeitbasis ist. Bitte besuche die Registerkarte Dienste und speicher nach Deinen Korrekturen die neuen Einstellungen.', 'appointments' ) .
					     '</p></div>';
					$r = true;
					break;
				}
				if ( $result->duration % $appointments->get_min_time() != 0 ) {
					echo '<div class="error"><p>' .
					     __( '<b>[Terminmanager]</b> Einer Deiner Dienste ist nicht durch die Zeitbasis teilbar. Bitte besuche die Registerkarte Dienste und speicher nach Deinen Korrekturen die neuen Einstellungen.', 'appointments' ) .
					     '</p></div>';
					$r = true;
					break;
				}
				if ( $result->duration > 1440 ) {
					echo '<div class="error"><p>' .
					     __( '<b>[Terminmanager]</b> Einer Deiner Dienste hat eine Dauer von mehr als 24 Stunden. Terminmanager unterstützt keine Dienste, die länger als 1440 Minuten (24 Stunden) dauern.', 'appointments' ) .
					     '</p></div>';
					$r = true;
					break;
				}
				$dismissed = false;
				$dismiss_id = get_user_meta( $current_user->ID, 'app_dismiss', true );
				if ( $dismiss_id ) {
					$dismissed = true; }
				if ( appointments_get_workers() && ! appointments_get_workers_by_service( $result->ID ) && ! $dismissed ) {
					echo '<div class="error"><p>' .
					     __( '<b>[Terminmanager]</b> Einem Deiner Dienste ist kein Dienstanbieter zugewiesen. Lösche nicht verwendete Dienste.', 'appointments' ) .
					     '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a title="'.__( 'Verwerfe diesen Hinweis für diese Sitzung', 'appointments' ).'" href="' . esc_url( add_query_arg( 'app_dismiss', '1' ) ) . '"><small>'.__( 'Verwerfen', 'appointments' ).'</small></a>'.
					     '</p></div>';
					$r = true;
					break;
				}
			}
		}
		if ( ! $appointments->db_version || version_compare( $appointments->db_version, '1.2.2', '<' ) ) {
			echo '<div class="error"><p>' .
			     __( '<b>[Terminmanager]</b> Terminmanager Datenbanktabellen müssen aktualisiert werden. Bitte deaktiviere und reaktiviere das Plugin (LÖSCHE das Plugin NICHT). Du verlierst keine gespeicherten Informationen.', 'appointments' ) .
			     '</p></div>';
			$r = true;
		}

		// Check for duplicate shortcodes for a visited page
		if ( isset( $_GET['post'] ) && $_GET['post'] && $this->has_duplicate_shortcode( $_GET['post'] ) ) {
			echo '<div class="error"><p>' .
			     __( '<b>[Terminmanager]</b> Mehrere Instanzen von Diensten, Dienstanbietern, Bestätigungs-, Paypal- oder Login-Shortcodes auf derselben Seite können Probleme verursachen.</p>', 'appointments' ).
			     '</div>';
		}

		// Check for missing confirmation shortcode
		$dismissed_c = false;
		$dismiss_id_c = get_user_meta( $current_user->ID, 'app_dismiss_confirmation_lacking', true );
		if ( $dismiss_id_c ) {
			$dismissed_c = true; }
		if ( ! $dismissed_c && isset( $_GET['post'] ) && $_GET['post'] && $this->confirmation_shortcode_missing( $_GET['post'] ) ) {
			echo '<div class="error"><p>' .
			     __( '<b>[Terminmanager]</b> Der Bestätigungs-Shortcode [app_confirmation] ist immer erforderlich, um einen Termin abzuschließen.', 'appointments' ) .
			     '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a title="'.__( 'Verwirf diesen Hinweis für diese Sitzung', 'appointments' ).'" href="' . $_SERVER['REQUEST_URI'] . '&app_dismiss_confirmation_lacking=1"><small>'.__( 'Dismiss', 'appointments' ).'</small></a>'.
			     '</p></div>';
			$r = true;
		}

		// Check for missing app_paypal shortcode
		$dismissed_p = false;
		$dismiss_id_p = get_user_meta( $current_user->ID, 'app_dismiss_app_paypal_lacking', true );
		if ( $dismiss_id_p ) {
			$dismiss_id_p = true; }
		if ( ! $dismiss_id_p && isset( $_GET['post'] ) && $_GET['post'] && $this->app_paypal_shortcode_missing( $_GET['post'] ) ) {
			echo '<div class="error"><p>' .
			     __( '<b>[Terminmanager]</b> Der Paypal-Shortcode [app_paypal] ist immer erforderlich, um einen Termin mit dem Status <b>ausstehend</b> abzuschließen.', 'appointments' ) .
			     '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a title="'.__( 'Verwirf diesen Hinweis für diese Sitzung', 'appointments' ).'" href="' . $_SERVER['REQUEST_URI'] . '&app_dismiss_app_paypal_lacking=1"><small>'.__( 'Dismiss', 'appointments' ).'</small></a>'.
			     '</p></div>';
			$r = true;
		}
		return $r;
	}


	function faq_page() {
		global $appointments;
		?>
		<div class="wrap">
			<div class="icon32" style="margin:10px 0 0 0"><img src="<?php echo $appointments->plugin_url . '/images/general.png'; ?>" /></div>
			<h2><?php echo __( 'PS Terminmanager FAQ','appointments' ); ?></h2>
			<?php if ( file_exists( APP_ADMIN_PLUGIN_DIR . '/app-faq.php' ) ) { include( APP_ADMIN_PLUGIN_DIR . '/app-faq.php' ); } ?>
		</div>
		<?php
	}


	/**
	 *	Admin pages init stuff, save settings
	 *
	 */
	function admin_init() {
		global $appointments;
		if ( ! session_id() ) {
			@session_start(); }

		include_once( APP_PLUGIN_DIR . '/admin/pages/class-admin-appointments-page.php' );
		include_once( APP_PLUGIN_DIR . '/admin/pages/class-admin-settings-page.php' );
		include_once( APP_PLUGIN_DIR . '/admin/pages/class-admin-transactions-page.php' );
		include_once( APP_PLUGIN_DIR . '/admin/pages/class-admin-export-import-settings.php' );
		$appointments_pages['appointments'] = new Appointments_Admin_Appointments_Page();
		$appointments_pages['settings'] = new Appointments_Admin_Settings_Page();
		$appointments_pages['transactions'] = new Appointments_Admin_Transactions_Page();

		add_submenu_page( 'appointments', __( 'FAQ','appointments' ), __( 'FAQ','appointments' ), App_Roles::get_capability( 'manage_options', App_Roles::CTX_PAGE_FAQ ), 'app_faq', array( &$this, 'faq_page' ) );
		// Add datepicker to appointments page

		do_action( 'app-admin-admin_pages_added', $appointments_pages['appointments']->page_id );

		new Appointments_Admin_Import_Export_Settings_Page();

		$this->pages = $appointments_pages;

		// Read Location, Service, Worker
		$appointments->get_lsw();
	}

	/**
	 * Check if there are more than one shortcodes for certain shortcode types
	 * @since 1.0.5
	 * @return bool
	 */
	function has_duplicate_shortcode( $post_id ) {
		$post = get_post( $post_id );
		if ( is_object( $post ) && $post && strpos( $post->post_content, '[app_' ) !== false ) {
			if ( substr_count( $post->post_content, '[app_services' ) > 1 || substr_count( $post->post_content, '[app_service_providers' ) > 1
			     || substr_count( $post->post_content, '[app_confirmation' ) > 1 || substr_count( $post->post_content, '[app_paypal' ) > 1
			     || substr_count( $post->post_content, '[app_login' ) > 1 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check if confirmation shortcode missing
	 * @since 1.2.5
	 * @return bool
	 */
	function confirmation_shortcode_missing( $post_id ) {
		$post = get_post( $post_id );
		if ( is_object( $post ) && $post && strpos( $post->post_content, '[app_' ) !== false ) {
			if ( ! substr_count( $post->post_content, '[app_confirmation' )
			     && ( substr_count( $post->post_content, '[app_monthly' ) || substr_count( $post->post_content, '[app_schedule' ) ) ) {
				return true; }
		}
		return false;
	}

	/**
	 * Check if app_paypal shortcode missing
	 * @since 1.2.5
	 * @return bool
	 */
	function app_paypal_shortcode_missing( $post_id ) {
		$post = get_post( $post_id );
		if ( is_object( $post ) && $post && strpos( $post->post_content, '[app_' ) !== false ) {
			if ( ! substr_count( $post->post_content, '[app_paypal' )
			     && preg_match( '/\[app_my_appointments[^\]]+status[^\]]+pending/', $post->post_content ) ) {
				return true; }
		}
		return false;
	}

	/**
	 * Add quick link to plugin settings page.
	 *
	 * @param $links Links array.
	 *
	 * @return array
	 */
	public function add_settings_link( $links ) {

		$plugin_links = array(
			'<a href="' . admin_url( 'admin.php?page=app_settings' ) . '">' . __( 'Einstellungen', 'appointments' ) . '</a>',
		);

		return array_merge( $plugin_links, $links );
	}


	/**
	 * make an Appointment page
	 *
	 * @since 2.2.4
	 */
	public function make_an_appointment_page() {
		$data = array(
			'message' => __( 'Etwas ist schief gelaufen!', 'appointments' ),
		);
		if (
			! isset( $_POST['_wpnonce'] )
			|| ! isset( $_POST['app_page_type'] )
			|| ! wp_verify_nonce( $_POST['_wpnonce'], 'appointment-create-page' )
		) {
			wp_send_json_error( $data );
		}
		$tpl = ! empty( $_POST['app_page_type'] ) ? $_POST['app_page_type'] : false;
		$page_id = wp_insert_post(
			array(
				'post_title'	=> _x( 'Einen Termin buchen', 'Standard-Seitentitel für Terminkalender.', 'appointments' ),
				'post_status'	=> 'publish',
				'post_type'		=> 'page',
				'post_content'	=> App_Template::get_default_page_template( $tpl ),
			)
		);
		if ( $page_id ) {
			$data = array(
				'message' => sprintf(
					__( 'Seite wurde erfolgreich erstellt: %s', 'appointments' ),
					sprintf(
						'<a href="%s">%s</a>',
						esc_url( get_page_link( $page_id ) ),
						esc_html__( 'Seite anzeigen', 'appointments' )
					)
				),
			);
			wp_send_json_success( $data );
		}
		wp_send_json_error( $data );
	}

	/**
	 * get service
	 *
	 * @since 2.3.0
	 */
public function get_service() {
    $data = array(
        'message' => __( 'Etwas ist schief gelaufen!', 'appointments' ),
    );
    if (
        isset( $_POST['_wpnonce'] )
        && isset( $_POST['id'] )
        && wp_verify_nonce( $_POST['_wpnonce'], 'service-'.$_POST['id'] )
    ) {
        $service = appointments_get_service( $_POST['id'] );
        // --- Anpassung: auch null abfangen ---
        if (
            !isset($service->service_padding)
            || is_null($service->service_padding)
            || (!is_array($service->service_padding) && !is_object($service->service_padding))
        ) {
            $service->service_padding = (object)[
                'before' => '',
                'after' => ''
            ];
        }
        wp_send_json_success( $service );
    }
    wp_send_json_error( $data );
}

	/**
	 * get worker
	 *
	 * @since 2.3.0
	 */
	public function get_worker() {
		$data = array(
			'message' => __( 'Etwas ist schief gelaufen!', 'appointments' ),
		);
		if (
			isset( $_POST['_wpnonce'] )
			&& isset( $_POST['id'] )
			&& wp_verify_nonce( $_POST['_wpnonce'], 'worker-'.$_POST['id'] )
		) {
			$worker = appointments_get_worker( $_POST['id'] );
			$worker->display_name = $worker->get_name();
			wp_send_json_success( $worker );
		}
		wp_send_json_error( $data );
	}

	/**
	 * read proper screen columns settings
	 *
	 * @since 2.4.0
	 */
	public function get_meta( $value, $object_id, $meta_key, $single ) {
		if ( 'manageappointments_page_app_settingscolumnshidden' === $meta_key ) {
			$tab = filter_input( INPUT_GET, 'tab', FILTER_SANITIZE_STRING );
			switch ( $tab ) {
				case 'services':
				case 'workers':
				return get_user_meta( $object_id, 'appointments_columns_hidden_'.$tab );
			}
		}
		return $value;
	}

	/**
	 * save proper screen columns settings
	 *
	 * @since 2.4.0
	 */
	public function update_meta( $meta_id, $object_id, $meta_key, $_meta_value ) {
		if ( 'manageappointments_page_app_settingscolumnshidden' === $meta_key ) {
			if ( isset( $_POST['tab'] ) ) {
				$tab = filter_input( INPUT_POST, 'tab', FILTER_SANITIZE_STRING );
				switch ( $tab ) {
					case 'services':
					case 'workers':
						update_user_meta( $object_id, 'appointments_columns_hidden_'.$tab, $_meta_value );
				}
			}
		}
	}
}
