<?php
/*
Plugin Name: Polster
Description: Ermöglicht das Hinzufügen von Auffüllzeiten für Deine Terminintervalle.
Plugin URI: https://cp-psource.github.io/terminmanager-pro/
Version: 1.1
AddonType: Schedule
Author: PSOURCE
*/

class App_Schedule_Paddings {

	const PADDING_BEFORE = 'before';
	const PADDING_AFTER = 'after';

	const PADDING_TYPE_CUMULATIVE = 'cumulative';
	const PADDING_TYPE_LARGEST = 'largest';
	const PADDING_TYPE_SMALLEST = 'smallest';

	private $_data;

	/** @var  Appointments $_core */
	private $_core;

	private $_allowed_paddings = array();
	private $_allowed_positions = array();

	private function __construct() {
		$this->_allowed_paddings = array(
			self::PADDING_TYPE_CUMULATIVE => __( 'Kumulativ', 'appointments' ),
			self::PADDING_TYPE_LARGEST => __( 'Größten', 'appointments' ),
			self::PADDING_TYPE_SMALLEST => __( 'Kleinste', 'appointments' ),
		);
		$this->_allowed_positions = array(
			self::PADDING_BEFORE => __( 'Vor', 'appointments' ),
			self::PADDING_AFTER => __( 'Dannach', 'appointments' ),
		);
	}

	public static function serve() {
		$me = new App_Schedule_Paddings;
		$me->_add_hooks();
		return $me;
	}

	private function _add_hooks() {
		add_action( 'plugins_loaded', array( $this, 'initialize' ) );

		//Apply padding to front-end timetable.
		add_filter( 'app-timetable-step_increment', array( $this, 'apply_step_increment_padding' ) );
		//Apply padding to backend admin settings.
		add_filter( 'app_admin_min_time', array( $this, 'apply_admin_min_time_padding' ) );

		// UI - resolution type
		add_action( 'app-settings-time_settings', array( $this, 'show_settings' ) );
		add_filter( 'app-options-before_save', array( $this, 'save_settings' ) );

		// Augment service settings pages
		add_filter( 'app-settings-services-service-name', array( $this, 'add_service_selection' ), 10, 2 );
		add_action( 'appointments_add_new_service_form', array( $this, 'add_new_service_selection' ) );
		add_filter( 'appointments_get_service', array( $this, 'add_service_padding' ) );

		// Save Service paddings
		add_action( 'appointments_insert_service', array( $this, 'save_service_padding' ) );
		add_action( 'psource_appointments_update_service', array( $this, 'save_service_padding' ) );

		// Augment worker settings pages
		add_filter( 'app-settings-workers-worker-name', array( $this, 'add_worker_selection' ), 10, 2 );
		add_action( 'appointments_add_new_worker_form', array( $this, 'add_new_worker_selection' ) );
		add_filter( 'appointments_get_worker', array( $this, 'add_worker_padding' ) );

		// Save worker paddings
		add_action( 'psource_appointments_update_worker', array( $this, 'save_worker_padding' ) );
		add_action( 'appointments_insert_worker', array( $this, 'save_worker_padding' ) );

		add_filter( 'appointments_default_options', array( $this, 'default_options' ) );

		add_filter( 'app-properties-for-calendar', array( $this, 'apointments_properties_for_calendar' ), 10, 3 );
		/**
		 * Add service paddings to columns
		 *
		 * @since 2.4.0
		 */
		add_filter( 'manage_appointments_service_columns', array( $this, 'add_columns' ) );
		add_filter( 'manage_appointments_service_provider_columns', array( $this, 'add_columns' ) );
		add_filter( 'default_hidden_columns', array( $this, 'add_default_hidden_columns' ), 10, 2 );
		add_filter( 'appointments_list_column_paddings', array( $this, 'column_paddings' ), 10, 2 );
	}

	public function default_options( $defaults ) {
	    $defaults['schedule_padding'] = array( 'type' => self::PADDING_TYPE_LARGEST );
	    $defaults['service_padding'] = array();
	    $defaults['worker_padding'] = array();
	    return $defaults;
	}

	/**
	 * Get this extension options
	 *
	 * @return array
	 */
	public function get_options() {
		$options = appointments_get_options();
		return array(
			'schedule_padding' => $options['schedule_padding'],
			'service_padding' => $options['service_padding'],
			'worker_padding' => $options['worker_padding'],
		);
	}

	public function initialize() {
		global $appointments;
		$this->_core = $appointments;
		$options = appointments_get_options();
		// Stubbing the model data
		$this->_data = $options;
	}

	public function save_settings( $options ) {
		if ( ! empty( $_POST['schedule_padding'] ) && in_array( $_POST['schedule_padding'], array_keys( $this->_allowed_paddings ) ) ) {
			$options['schedule_padding'] = array( 'type' => $_POST['schedule_padding'] );
		}
		return $options;
	}

	public function show_settings() {
		$options = appointments_get_options();
		$saved = $options['schedule_padding']['type'];
		$type_help = array(
			self::PADDING_TYPE_SMALLEST => __( '... Anwenden der kleineren Polsterung der beiden', 'appointments' ),
			self::PADDING_TYPE_LARGEST => __( '... Anwenden der größeren Polsterung der beiden', 'appointments' ),
			self::PADDING_TYPE_CUMULATIVE => __( '... Addiere die beiden und wende das Ergebnis an', 'appointments' ),
		);
		?>
        <tr valign="top">
            <th scope="row"><?php _e( 'Auffüllauflösungstyp', 'appointments' ); ?></th>
            <td>
                <p><?php _e( 'Wenn sowohl dem aktuellen Dienst als auch dem aktuellen Dienstanbieter Auffüllungen zugewiesen wurden, löse diese durch auf...', 'appointments' ); ?></p>
                <?php foreach ( $this->_allowed_paddings as $padding => $name ) :  ?>
                    <p>
                        <label for="app-padding-<?php echo esc_attr( $padding ); ?>">
                            <input type="radio" id="app-padding-<?php echo esc_attr( $padding ); ?>" name="schedule_padding" value="<?php echo esc_attr( $padding ); ?>" <?php checked( $saved, $padding ); ?> />
                            <?php echo $name; ?>
                        </label><br>
                        <span class="description"><?php echo $type_help[ $padding ]; ?></span>
                    </p>
                <?php endforeach; ?>
            </td>
        </tr>
		<?php
	}

	public function add_service_selection( $out, $service_id ) {
		$options = appointments_get_options();
		$paddings = array( self::PADDING_BEFORE => 0, self::PADDING_AFTER => 0 );
		if ( isset( $options['service_padding'][ $service_id ] ) ) {
			$paddings = $options['service_padding'][ $service_id ];
		}
		$range = range( 0, 180, 5 );

		ob_start();
		?>
        <div class="app-service_padding">
            <h4><?php esc_html_e( 'Auffüllzeiten', 'appointments' ); ?></h4>
            <label for="service_padding_before-<?php echo esc_attr( $service_id ); ?>">
                <?php echo $this->_allowed_positions[ self::PADDING_BEFORE ]; ?>&nbsp;
                <select id="service_padding_before-<?php echo esc_attr( $service_id ); ?>" name="service_padding_before[<?php echo esc_attr( $service_id ); ?>]">
                    <?php foreach ( $range as $value ) :
						$label = appointment_convert_minutes_to_human_format( $value );
?>
                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $paddings[ self::PADDING_BEFORE ], $value ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label for="service_padding_after-<?php echo esc_attr( $service_id ); ?>">
		        <?php echo $this->_allowed_positions[ self::PADDING_AFTER ]; ?>&nbsp;
                <select id="service_padding_after-<?php echo esc_attr( $service_id ); ?>" name="service_padding_after[<?php echo esc_attr( $service_id ); ?>]">
                    <?php foreach ( $range as $value ) :
						$label = appointment_convert_minutes_to_human_format( $value );
?>
                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $paddings[ self::PADDING_AFTER ], $value ); ?>><?php echo esc_html( $label ); ?></option>
			        <?php endforeach; ?>
                </select>
            </label>
        </div>
        <?php
		return $out . ob_get_clean();
	}

	public function add_new_service_selection() {
		$paddings = array( self::PADDING_BEFORE => 0, self::PADDING_AFTER => 0 );
		$range = range( 0, 180, 5 );
		?>
		<tr>
			<th scope="row">
				<?php _e( 'Polsterungen', 'appointments' ); ?>
			</th>
			<td>
				<label for="service_padding_before">
					<?php echo $this->_allowed_positions[ self::PADDING_BEFORE ]; ?>&nbsp;
					<select id="service_padding_before" name="service_padding_before">
                        <?php foreach ( $range as $value ) :
							$label = appointment_convert_minutes_to_human_format( $value );
	?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $paddings[ self::PADDING_BEFORE ], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label for="service_padding_after">
					<?php echo $this->_allowed_positions[ self::PADDING_AFTER ]; ?>&nbsp;
					<select id="service_padding_after" name="service_padding_after">
                        <?php foreach ( $range as $value ) :
							$label = appointment_convert_minutes_to_human_format( $value );
?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $paddings[ self::PADDING_AFTER ], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</td>
		</tr>
		<?php
	}

	public function add_worker_selection( $out, $worker_id ) {
		$options = appointments_get_options();
		$paddings = array( self::PADDING_BEFORE => 0, self::PADDING_AFTER => 0 );
		if ( isset( $options['worker_padding'][ $worker_id ] ) ) {
			$paddings = $options['worker_padding'][ $worker_id ];
		}
		$range = range( 0, 180, 5 );

		ob_start();
		?>
        <div class="app-worker_padding">
            <h4><?php esc_html_e( 'Auffüllzeiten', 'appointments' ); ?></h4>
            <label for="worker_padding_before-<?php echo esc_attr( $worker_id ); ?>">
				<?php echo $this->_allowed_positions[ self::PADDING_BEFORE ]; ?>&nbsp;
                <select id="worker_padding_before-<?php echo esc_attr( $worker_id ); ?>" name="worker_padding_before[<?php echo esc_attr( $worker_id ); ?>]">
                    <?php foreach ( $range as $value ) :
						$label = appointment_convert_minutes_to_human_format( $value );
	?>
                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $paddings[ self::PADDING_BEFORE ], $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
                </select>
            </label>
            <label for="worker_padding_after-<?php echo esc_attr( $worker_id ); ?>">
				<?php echo $this->_allowed_positions[ self::PADDING_AFTER ]; ?>&nbsp;
                <select id="worker_padding_after-<?php echo esc_attr( $worker_id ); ?>" name="worker_padding_after[<?php echo esc_attr( $worker_id ); ?>]">
                    <?php foreach ( $range as $value ) :
						$label = appointment_convert_minutes_to_human_format( $value );
	?>
                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $paddings[ self::PADDING_AFTER ], $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
                </select>
            </label>
        </div>
		<?php
		return $out . ob_get_clean();
	}

	public function add_new_worker_selection() {
		$paddings = array( self::PADDING_BEFORE => 0, self::PADDING_AFTER => 0 );
		$range = range( 0, 180, 5 );

		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Auffüllzeiten', 'appointments' ); ?></th>
			<td>
				<label for="worker_padding_before">
					<?php echo $this->_allowed_positions[ self::PADDING_BEFORE ]; ?>&nbsp;
					<select id="worker_padding_before" name="worker_padding_before">
                        <?php foreach ( $range as $value ) :
							$label = appointment_convert_minutes_to_human_format( $value );
	?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $paddings[ self::PADDING_BEFORE ], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label for="worker_padding_after">
					<?php echo $this->_allowed_positions[ self::PADDING_AFTER ]; ?>&nbsp;
					<select id="worker_padding_after" name="worker_padding_after">
                        <?php foreach ( $range as $value ) :
							$label = appointment_convert_minutes_to_human_format( $value );
	?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $paddings[ self::PADDING_AFTER ], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</td>
		</tr>
		<?php
	}

	public function save_service_padding( $service_id ) {
		if (
				isset( $_POST['service_padding_before'] )
				&& is_array( $_POST['service_padding_before'] )
				&& isset( $_POST['service_padding_before'][ $service_id ] )
				&& isset( $_POST['service_padding_after'] )
				&& is_array( $_POST['service_padding_after'] )
				&& isset( $_POST['service_padding_after'][ $service_id ] )
		) {
			$before = absint( $_POST['service_padding_before'][ $service_id ] );
			$after = absint( $_POST['service_padding_after'][ $service_id ] );
		} elseif ( isset( $_POST['service_padding_before'] ) && isset( $_POST['service_padding_after'] ) ) {
			$before = absint( $_POST['service_padding_before'] );
			$after = absint( $_POST['service_padding_after'] );
		} else {
			return;
		}

		$services_padding = get_option( 'appointments_services_padding', array() );
		$services_padding[ $service_id ] = array(
			self::PADDING_BEFORE => $before,
			self::PADDING_AFTER => $after,
		);
		update_option( 'appointments_services_padding', $services_padding );

		$options = appointments_get_options();
		$options['service_padding'][ $service_id ] = array( 'before' => $before, 'after' => $after );
		appointments_update_options( $options );
	}

	public function save_new_service_padding( $service_id ) {
		$before = absint( $_POST['service_padding_before'] );
		$after = absint( $_POST['service_padding_after'] );

		$services_padding = get_option( 'appointments_services_padding', array() );

		$services_padding[ $service_id ] = array(
			self::PADDING_BEFORE => $before,
			self::PADDING_AFTER => $after,
		);

		$options = appointments_get_options();
		$options['service_padding'][ $service_id ] = array( 'before' => $before, 'after' => $after );
		appointments_update_options( $options );
	}

	public function save_worker_padding( $worker_id ) {
		if (
				isset( $_POST['worker_padding_before'] )
				&& is_array( $_POST['worker_padding_before'] )
				&& isset( $_POST['worker_padding_before'][ $worker_id ] )
				&& isset( $_POST['worker_padding_after'] )
				&& is_array( $_POST['worker_padding_after'] )
				&& isset( $_POST['worker_padding_after'][ $worker_id ] )
		) {
			$before = absint( $_POST['worker_padding_before'][ $worker_id ] );
			$after = absint( $_POST['worker_padding_after'][ $worker_id ] );
		} elseif ( isset( $_POST['worker_padding_before'] ) && isset( $_POST['worker_padding_after'] ) ) {
			$before = absint( $_POST['worker_padding_before'] );
			$after = absint( $_POST['worker_padding_after'] );
		} else {
			return;
		}

		$workers_padding = get_option( 'appointments_workers_padding', array() );
		$workers_padding[ $worker_id ] = array(
			self::PADDING_BEFORE => $before,
			self::PADDING_AFTER => $after,
		);
		update_option( 'appointments_workers_padding', $workers_padding );
		$options = appointments_get_options();
		$options['worker_padding'][ $worker_id ] = array( 'before' => $before, 'after' => $after );
		appointments_update_options( $options );
	}

	/**
	 * Set up the padding increment on both ends
	 * and dispatch start time tweak.
	 */
	public function apply_step_increment_padding( $step ) {
		$before = $this->_get_padding_before();
		$after = $this->_get_padding_after();
		if ( ! empty( $before ) ) {
			add_filter( 'app_ccs', array( $this, 'apply_service_padding_before' ) );
		}
		return $before + $step + $after;
	}

	public function apply_service_padding_before( $ccs ) {
		$before = $this->_get_padding_before();
		return $ccs + (int) $before;
	}

	private function _get_padding_before() {
		$service_padding = $this->_get_current_service_padding();
		$worker_padding = $this->_get_current_worker_padding();

		$service_time = ! empty( $service_padding[ self::PADDING_BEFORE ] )
			? (int) $service_padding[ self::PADDING_BEFORE ]
			: 0
		;
		$worker_time = ! empty( $worker_padding[ self::PADDING_BEFORE ] )
			? (int) $worker_padding[ self::PADDING_BEFORE ]
			: 0
		;
		$additive = 0;
		$options = appointments_get_options();
		switch ( $options['schedule_padding']['type'] ) {
			case self::PADDING_TYPE_CUMULATIVE:
				$additive = $service_time + $worker_time;
				break;
			case self::PADDING_TYPE_SMALLEST:
				$additive = $service_time < $worker_time ? $service_time : $worker_time;
				break;
			case self::PADDING_TYPE_LARGEST:
			default:
				$additive = $service_time > $worker_time ? $service_time : $worker_time;
				break;
		}
		return $additive * 60;
	}

	public function _get_padding_after() {
		$service_padding = $this->_get_current_service_padding();
		$worker_padding = $this->_get_current_worker_padding();

		$service_time = ! empty( $service_padding[ self::PADDING_AFTER ] )
			? (int) $service_padding[ self::PADDING_AFTER ]
			: 0
		;
		$worker_time = ! empty( $worker_padding[ self::PADDING_AFTER ] )
			? (int) $worker_padding[ self::PADDING_AFTER ]
			: 0
		;
		$additive = 0;
		$options = appointments_get_options();
		switch ( $options['schedule_padding']['type'] ) {
			case self::PADDING_TYPE_CUMULATIVE:
				$additive = $service_time + $worker_time;
				break;
			case self::PADDING_TYPE_SMALLEST:
				$additive = $service_time < $worker_time ? $service_time : $worker_time;
				break;
			case self::PADDING_TYPE_LARGEST:
			default:
				$additive = $service_time > $worker_time ? $service_time : $worker_time;
				break;
		}
		return $additive * 60;
	}

	private function _get_current_worker_padding() {
	    $options = appointments_get_options();
		if ( $this->_core->worker && ! empty( $this->_data['worker_padding'][ $this->_core->worker ] ) ) {
			// Determine the service padding
			return $options['worker_padding'][ $this->_core->worker ];
		}
		return false;
	}

	private function _get_current_service_padding() {
		global $current_screen;
		if ( is_admin() && ! empty( $current_screen ) && $current_screen->id == 'appointments_page_app_settings' ) {
			if ( ! empty( $_REQUEST['tab'] ) && $_REQUEST['tab'] == 'working_hours' ) {
				$service = 0;
				if ( $this->_core->worker ) {
					$service = $this->resolve_service_id( $this->_core->worker );
				} else {
					$service = $this->resolve_service_id();
				}

				$this->_core->service = $service ? $service : $this->_core->service;
			}
		} else if ( is_admin() && DOING_AJAX ) {
			//Get service ID for the current appointment when using inline edit.
			if ( $_REQUEST['action'] == 'inline_edit' && ! empty( $_REQUEST['app_id'] ) ) {
				$app = appointments_get_appointment( $_REQUEST['app_id'] );
				$this->_core->service = $app->service;
			}
		}
		$options = appointments_get_options();
		if ( $this->_core->service && ! empty( $options['service_padding'][ $this->_core->service ] ) ) {
			// Determine the service padding
			return $options['service_padding'][ $this->_core->service ];
		}
		return false;
	}

	public function apply_admin_min_time_padding( $time ) {
		return $time;
	}

	private function resolve_service_id( $worker = false ) {
		//Try to guess the service ID related to the working hours.
		//This would be accurate only for specific providers providing a single service.
		$services = array();
		if ( $worker ) {
			$services = appointments_get_worker_services( $worker );
		} else {
			$services = appointments_get_services();
		}

		$options = appointments_get_options();
		foreach ( $services as $key => $service ) {
			if ( $options['service_padding'][ $service->ID ][ self::PADDING_BEFORE ] || $options['service_padding'][ $service->ID ][ self::PADDING_AFTER ] ) {
				return $service->ID;
			}
		}

		return false;
	}

	// Include paddings of booked appointments in calendar
	public function apointments_properties_for_calendar( $app_properties, $app, $args ) {

		$appoitment_paddings = 0;
		$options = appointments_get_options();
		$service = $app->service;
		$worker = $app->worker;

		if ( isset( $options['service_padding'][ $service ] ) && ! empty( $options['service_padding'][ $service ] ) ) {

			foreach ( $options['service_padding'][ $service ] as $key => $padding ) {
				if ( is_numeric( $padding ) ) {
					$appoitment_paddings += (int) $padding;
				}
			}
		}

		if ( isset( $options['worker_padding'][ $worker ] ) && ! empty( $options['worker_padding'][ $worker ] ) ) {

			foreach ( $options['worker_padding'][ $worker ] as $key => $padding ) {
				if ( is_numeric( $padding ) ) {
					$appoitment_paddings += (int) $padding;
				}
			}
		}

		$end_timestamp = strtotime( $app_properties['end'] . ' + ' . $appoitment_paddings . ' minute' );
		$app_properties['end'] = date( 'Y-m-d H:i:s', $end_timestamp );

		return $app_properties;
	}

	/**
	 * Add service_padding to $service Object
	 *
	 * @since 2.3.0
	 */
	public function add_service_padding( $service ) {
		if ( is_object( $service ) && isset( $service->ID ) ) {
			$service->service_padding = false;
			$paddings = array( self::PADDING_BEFORE => 0, self::PADDING_AFTER => 0 );
			$options = appointments_get_options();
			if ( isset( $options['service_padding'][ $service->ID ] ) ) {
				$paddings = $options['service_padding'][ $service->ID ];
			}
			$service->service_padding = $paddings;
		}
		return $service;
	}

	/**
	 * Add worker_padding to $worker Object
	 *
	 * @since 2.3.0
	 */
	public function add_worker_padding( $worker ) {
		$options = appointments_get_options();
		$paddings = array( self::PADDING_BEFORE => 0, self::PADDING_AFTER => 0 );
		if ( is_object( $worker ) && isset( $worker->ID ) ) {
			$paddings = array( self::PADDING_BEFORE => 0, self::PADDING_AFTER => 0 );
			$options = appointments_get_options();
			if ( isset( $options['worker_padding'][ $worker->ID ] ) ) {
				$paddings = $options['worker_padding'][ $worker->ID ];
			}
			$worker->worker_padding = $paddings;
		}
		return $worker;
	}

	/**
	 * Add column "paddings" to Services list
	 *
	 * @since 2.4.0
	 */
	public function add_columns( $columns ) {
		$columns['paddings'] = __( 'Polsterungen', 'appointments' );
		return $columns;
	}

	/**
	 * Hide by default column "paddings" to Services list
	 *
	 * @since 2.4.0
	 */
	public function add_default_hidden_columns( $hidden, $screen ) {
		$hidden[] = 'paddings';
		return $hidden;
	}

	/**
	 * helper to get paddings, depend of item class
	 *
	 * @since 2.4.0
	 */
	private function get_column_paddings( $item, $type ) {
		$content = '';
		if (
			isset( $this->_data[ $type ] )
			&& isset( $this->_data[ $type ][ $item->ID ] )
		) {
			if (
				isset( $this->_data[ $type ][ $item->ID ]['before'] )
				&& ! empty( $this->_data[ $type ][ $item->ID ]['before'] )
			) {
				$value = appointment_convert_minutes_to_human_format( $this->_data[ $type ][ $item->ID ]['before'] );
				$content .= sprintf(
					'<li class="before">%s</li>',
					sprintf( __( 'Bevor: %s', 'appointments' ), $value )
				);
			}
			if (
				isset( $this->_data[ $type ][ $item->ID ]['after'] )
				&& ! empty( $this->_data[ $type ][ $item->ID ]['after'] )
			) {
				$value = appointment_convert_minutes_to_human_format( $this->_data[ $type ][ $item->ID ]['after'] );
				$content .= sprintf(
					'<li class="after">%s</li>',
					sprintf( __( 'Dannach: %s', 'appointments' ), $value )
				);
			}
		}
		return $content;
	}

	/**
	 * Add column "paddings" content to Services list
	 *
	 * @since 2.4.0
	 */
	public function column_paddings( $content, $item ) {
		$content = '';
		if ( is_a( $item, 'Appointments_Service' ) ) {
			$content = $this->get_column_paddings( $item, 'service_padding' );
		} else if ( is_a( $item, 'Appointments_Worker' ) ) {
			$content = $this->get_column_paddings( $item, 'worker_padding' );
		}
		if ( empty( $content ) ) {
			return __( 'Keine Polster', 'appointments' );
		}
		return sprintf( '<ul>%s</ul>', $content );
	}
}
App_Schedule_Paddings::serve();
