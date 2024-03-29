<?php
/*
Plugin Name: Provider Standorte
Description: Ermöglicht das Binden von Standorten an Deine Dienstanbieter.
Plugin URI: https://cp-psource.github.io/terminmanager-pro/
Version: 1.1
AddonType: Locations
Requires: Standorte
Author: PSOURCE
*/

class App_Locations_WorkerLocations {

	const STORAGE_PREFIX = 'app-worker_location-';

	private $_data;
	private $_locations;

	private function __construct() {}

	public static function serve() {
		$me = new App_Locations_WorkerLocations;
		$me->_add_hooks();
	}

	private function _add_hooks() {
		// Init and dispatch post-init actions
		add_action( 'app-locations-initialized', array( $this, 'initialize' ) );

		// Augment worker settings pages
		add_filter( 'app-settings-workers-worker-name', array( $this, 'add_worker_selection' ), 10, 2 );
		add_action( 'appointments_add_new_worker_form', array( $this, 'add_new_worker_selection' ) );
		add_filter( 'appointments_get_worker', array( $this, 'add_worker_location' ) );

		// Save settings page
		add_action( 'appointments_insert_worker', array( $this, 'save_worker_location' ) );
		add_action( 'psource_appointments_update_worker', array( $this, 'save_worker_location' ) );
		add_action( 'appointments_delete_worker', array( $this, 'delete_worker_location' ) );

		// Add settings
		add_action( 'appointments_locations_settings_section_settings', array( $this, 'show_settings' ) );
		add_filter( 'app-locations-before_save', array( $this, 'save_settings' ) );

		add_action( 'admin_notices', array( $this, 'show_nags' ) );

		// Record appointment location
		add_action( 'psource_appointments_insert_appointment', array( $this, 'record_appointment_location' ), 40 );

		add_filter( 'app-shortcodes-register', array( $this, 'register_shortcodes' ) );
		/**
		 * Add service location
		 *
		 * @since 2.4.0
		 */
		add_filter( 'app_pre_confirmation_reply', array( $this, 'add_location_to_reply_array' ) );
		/**
		 * Add location to columns
		 *
		 * @since 2.4.0
		 */
		add_filter( 'manage_appointments_service_provider_columns', array( $this, 'add_columns' ) );
		add_filter( 'default_hidden_columns', array( $this, 'add_default_hidden_columns' ), 10, 2 );
		add_filter( 'appointments_list_column_location', array( $this, 'get_column_location' ), 10, 2 );
	}

	function show_nags() {
		if ( ! class_exists( 'App_Locations_Location' ) || ! $this->_locations ) {
			echo '<div class="error"><p>' .
				__( "Du benötigst die Erweiterung Standorte, damit die Integrations-Erweiterung Provider Standorte funktioniert", 'appointments' ) .
			'</p></div>';
		}
	}

	public function initialize() {
		if ( ! class_exists( 'App_Locations_Model' ) ) { return false; }
		global $appointments;
		$this->_data = $appointments->options;
		if ( empty( $this->_data['worker_locations'] ) ) { $this->_data['worker_locations'] = array(); }
		$this->_locations = App_Locations_Model::get_instance();

		if ( empty( $this->_data['worker_locations']['insert'] ) || 'manual' == $this->_data['worker_locations']['insert'] ) {
			add_shortcode( 'app_worker_location', array( $this, 'process_shortcode' ) );
		} else {
			add_shortcode( 'app_worker_location', '__return_false' );
			add_filter( 'app-workers-worker_description', array( $this, 'inject_location_markup' ), 10, 3 );
		}

		if ( empty( $this->_data['worker_locations']['insert'] ) ) {
			$this->_data['worker_locations']['insert'] = '';
		}
	}

	public function register_shortcodes( $shortcodes ) {
		include_once( 'lib/app_worker_locations_shortcode.php' );
		$shortcodes['app_provider_locations'] = 'App_Shortcode_WorkerLocationsShortcode';
		$shortcodes['app_required_provider_locations'] = 'App_Shortcode_RequiredWorkerLocationsShortcode';
		return $shortcodes;
	}

	public function record_appointment_location( $appointment_id ) {
		global $wpdb, $appointments;
		$appointment = appointments_get_appointment( $appointment_id );
		if ( empty( $appointment->worker ) ) { return false; }

		$location_id = self::worker_to_location_id( $appointment->worker );
		if ( ! $location_id ) {
			return false;
		}
		appointments_update_appointment( $appointment_id, array( 'location' => $location_id ) );
	}

	public function show_settings() {
		?>
				<h3><?php _e( 'Provider Standorte Einstellungen', 'appointments' ) ?></h3>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="worker_locations-insert"><?php _e( 'Providerstandort anzeigen', 'appointments' )?></label></th>
						<td>
							<select id="worker_locations-insert" name="worker_locations[insert]">
								<option value="manual" <?php selected( $this->_data['worker_locations']['insert'], 'manual' ); ?> ><?php _e( 'Ich werde Standortinformationen manuell mithilfe des Shortcodes hinzufügen', 'appointments' ); ?></option>
								<option value="before" <?php selected( $this->_data['worker_locations']['insert'], 'before' ); ?> ><?php _e( 'Automatisch vor der Beschreibung des Providers', 'appointments' ); ?></option>
								<option value="after" <?php selected( $this->_data['worker_locations']['insert'], 'after' ); ?> ><?php _e( 'Automatisch nach Beschreibung des Providers', 'appointments' ); ?></option>
							</select>
							<p class="description"><?php _e( 'Du kannst den Shortcode folgendermaßen verwenden: <code>[app_provider_locations]</code>', 'appointments' ); ?></p>
						</td>
					</tr>
				</table>
		<?php
	}

	public function save_settings( $options ) {
		if ( empty( $_POST['worker_locations'] ) ) { return $options; }

		$data = stripslashes_deep( $_POST['worker_locations'] );
		$options['worker_locations']['insert'] = ! empty( $data['insert'] ) ? $data['insert'] : false;

		return $options;
	}

	public function process_shortcode( $args = array(), $content = '' ) {
		$worker_id = ! empty( $args['worker_id'] ) ? $args['worker_id'] : false;
		if ( ! $worker_id ) {
			$post_id = get_queried_object_id();
			$worker_id = $this->_map_description_post_to_worker_id( $post_id );
		}

		if ( ! $worker_id ) { return $content; }
		return $this->_get_worker_location_markup( $worker_id, $content );
	}

	public function inject_location_markup( $markup, $worker, $description ) {
		if ( ! $worker || empty( $worker->ID ) ) { return $markup; }
		$out = $this->_get_worker_location_markup( $worker->ID, '', ('content' == $description) );
		return ('before' == $this->_data['worker_locations']['insert'])
			? $out . $markup
			: $markup . $out
		;
	}

	public function add_worker_selection( $out, $worker_id ) {
		if ( ! class_exists( 'App_Locations_Model' ) || ! $this->_locations ) {
			return $out;
		}

		$locations = $this->_locations->get_all();
		$markup    = '';

		$markup .= '<label>' . __( 'Standort:', 'appointments' ) . '</label>&nbsp;';
		$markup .= '<select name="worker_location[' . $worker_id . ']"><option value=""></option>';
		foreach ( $locations as $location ) {
			$checked = $location->get_id() == self::worker_to_location_id( $worker_id ) ? 'selected="selected"' : '';
			$markup .= '<option value="' . $location->get_id() . '" ' . $checked . '>' . esc_html( $location->get_admin_label() ) . '</option>';
		}
		$markup .= '</select>';

		return $out . $markup;
	}

	public function add_new_worker_selection() {
		if ( ! class_exists( 'App_Locations_Model' ) || ! $this->_locations ) {
			return;
		}

		$locations = $this->_locations->get_all();

		?>
		<tr>
			<th scope="row">
				<label for="worker_location"><?php _e( 'Standort', 'appointments' ); ?></label>
			</th>
			<td>
<?php if ( empty( $locations ) ) {
	_e( 'Es gibt keine Standorte zur Auswahl. Bitte füge zuerst einige hinzu.', 'appointments' );
} else {
?>
		<select name="worker_location" id="worker_location">
			<option value=""></option>
			<?php foreach ( $locations as $location ) :  ?>
						<option value="<?php echo $location->get_id(); ?>"><?php echo esc_html( $location->get_admin_label() ); ?></option>
					<?php endforeach; ?>
		</select>
<?php } ?>
			</td>
		</tr>
		<?php
	}

	public function save_worker_location( $worker_id ) {
		if ( isset( $_POST['worker_location'] ) && is_array( $_POST['worker_location'] ) && isset( $_POST['worker_location'][ $worker_id ] ) ) {
			// Saving existing worker
			$location_id = $_POST['worker_location'][ $worker_id ];
		} elseif ( isset( $_POST['worker_location'] ) ) {
			$location_id = $_POST['worker_location'];
		} else {
			return false;
		}

		$key = self::STORAGE_PREFIX . $worker_id;
		$old_location_id = self::worker_to_location_id( $worker_id );

		if ( $old_location_id != $location_id ) {
			$this->_update_appointment_locations( $worker_id, $old_location_id, $location_id );
		}

		if ( empty( $location_id ) ) {
			return delete_option( $key );
		}

		return update_option( $key, $location_id );
	}

	public function delete_worker_location( $worker_id ) {
		$key = self::STORAGE_PREFIX . $worker_id;
		delete_option( $key );
	}

	public static function worker_to_location_id( $worker_id ) {
		if ( ! $worker_id ) { return false; }
		$key = self::STORAGE_PREFIX . $worker_id;
		return get_option( $key, false );
	}

	private function _worker_to_location( $worker_id ) {
		if ( ! $this->_locations ) { return false; }
		$location_id = self::worker_to_location_id( $worker_id );
		return $this->_locations->find_by( 'id', $location_id );
	}

	private function _update_appointment_locations( $worker_id, $old_location_id, $location_id ) {
		if ( $old_location_id == $location_id ) {
			return;
		}

		$apps = appointments_get_appointments( array( 'location' => $old_location_id, 'worker' => $worker_id ) );
		foreach ( $apps as $app ) {
			appointments_update_appointment( $app->ID, array( 'location' => $location_id ) );
		}
	}

	private function _get_worker_location_markup( $worker_id, $fallback = '', $rich_content = true ) {
		$location = $this->_worker_to_location( $worker_id );
		if ( ! $location ) { return $fallback; }
		return $this->_get_location_markup( $location, $rich_content );
	}

	private function _get_location_markup( $location, $rich_content = true ) {
		$lid = $location->get_id();
		return '<div class="app-worker_description-location" id="app-worker_description-location-' . esc_attr( $lid ) . '">' .
			apply_filters( 'app-locations-location_output', $location->get_display_markup( $rich_content ), $lid, $location ) .
		'</div>';
	}

	private function _map_description_post_to_worker_id( $post_id ) {
		global $appointments, $wpdb;
		$workers = appointments_get_workers( array( 'page' => $post_id ) );
		if ( ! empty( $workers ) ) {
			return $workers[0]->ID;
		}
		return false;
	}

	/**
	 * Add service_location to $service Object
	 *
	 * @since 2.3.0
	 */
	public function add_worker_location( $worker ) {
		$worker->worker_location = false;
		if ( is_object( $worker ) && isset( $worker->ID ) ) {
			$worker->worker_location = self::worker_to_location_id( $worker->ID );
		}
		return $worker;
	}

	/**
	 * Add worker Location to reply array
	 *
	 * @since 2.4.0
	 */
	public function add_location_to_reply_array( $reply_array ) {
		$worker_id = isset( $reply_array['worker_id'] )? $reply_array['worker_id'] : 0;
		$location = $this->_worker_to_location( $worker_id );
		if ( false === $location ) {
			return $reply_array;
		}
		$content = $location->get_display_markup();
		if ( ! empty( $content ) ) {
			$reply_array['worker_location'] = sprintf(
				'<label class="app-worker-location"><span>%s: </span>%s</label>',
				esc_html__( 'Standort', 'appointments' ),
				$content
			);
		}
		return $reply_array;
	}

	/**
	 * Add column "Location" to Service Providers list
	 *
	 * @since 2.4.0
	 */
	public function add_columns( $columns ) {
		$columns['location'] = __( 'Standort', 'appointments' );
		return $columns;
	}

	/**
	 * Hide by default column "Location" to Service Providers list
	 *
	 * @since 2.4.0
	 */
	public function add_default_hidden_columns( $hidden, $screen ) {
		$hidden[] = 'location';
		return $hidden;
	}

	/**
	 * Add column "Location" content to Service Providers list
	 *
	 * @since 2.4.0
	 */
	public function get_column_location( $content, $item ) {
		if ( ! is_a( $item, 'Appointments_Worker' ) ) {
			return $content;
		}
		$no = __( 'Kein Standort', 'appointments' );
		$location = $this->_worker_to_location( $item->ID );
		if ( empty( $location ) ) {
			return $no;
		}
		$content = $location->get_display_markup();
		if ( empty( $content ) ) {
			return $no;
		}
		return $content;
	}
}
App_Locations_WorkerLocations::serve();
