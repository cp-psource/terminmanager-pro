<?php
/*
Plugin Name: Zusätzliche Felder
Description: Ermöglicht die Eingabe zusätzlicher Felder durch Deine Benutzer.
Plugin URI: https://cp-psource.github.io/terminmanager-pro/
Version: 1.1
AddonType: Users
Author: PSOURCE
*/

class App_Users_AdditionalFields {

	private $_data;
	/** @var  Appointments $_core */
	private $_core;

	private function __construct() {}

	public static function serve() {
		$me = new App_Users_AdditionalFields;
		$me->_add_hooks();
		return $me;
	}

	private function _add_hooks() {
		add_action( 'plugins_loaded', array( $this, 'initialize' ) );
		// Settings
		add_action( 'appointments_settings_tab-main-section-accesibility', array( $this, 'show_settings' ) );
		add_filter( 'app-options-before_save', array( $this, 'save_settings' ) );
		// Field injection
		add_filter( 'app_additional_fields', array( $this, 'inject_additional_fields' ) );
		add_action( 'app-footer_scripts-after', array( $this, 'inject_additional_fields_script' ), 900 );
		add_filter( 'app_get_field_name', array( $this, 'field_names' ) );
		// Display additional notes
		add_action( 'app-appointments_list-edit-client', array( $this, 'display_inline_data' ), 10, 2 );
		// Email filters
		add_filter( 'app_notification_message', array( $this, 'expand_email_macros' ), 10, 3 );
		add_filter( 'app_confirmation_message', array( $this, 'expand_email_macros' ), 10, 3 );
		add_filter( 'app_reminder_message', array( $this, 'expand_email_macros' ), 10, 3 );
		add_filter( 'app_removal_notification_message', array( $this, 'expand_email_macros' ), 10, 3 );
		// GCal expansion filters
		add_filter( 'app-gcal-set_summary', array( $this, 'expand_gcal_macros' ), 10, 2 );
		add_filter( 'app-gcal-set_description', array( $this, 'expand_gcal_macros' ), 10, 2 );
		// Export filtering
		add_filter( 'app-export-columns', array( $this, 'inject_additional_columns' ) );
		add_filter( 'app-export-appointment', array( $this, 'inject_additional_properties' ), 10, 2 );
		// General fields substitution
		add_filter( 'app-internal-additional_fields-expand', array( $this, 'expand_general_fields' ), 10, 2 );
		// Since 1.8.2
		// Validate fields in front-end
		add_filter( 'appointments_post_confirmation_error', array( $this, 'validate_additional_fields' ), 10, 3 );
		// And Admin
		add_filter( 'appointments_inline_edit_error', array( $this, 'validate_additional_fields' ), 10, 3 );
		// Values processing
		add_action( 'psource_appointments_insert_appointment', array( $this, 'save_additional_fields' ), 2 );
		// This will be triggered once the appointment is updated
		add_action( 'psource_appointments_update_appointment_result', array( $this, 'update_additional_fields' ), 2, 2 );
		// Add default options
		add_action( 'appointments_default_options', array( $this, 'default_options' ) );
		/**
		 * GDPR export data
		 * @since 2.3.0
		 */
		add_filter( 'appointments_gdpr_export', array( $this, 'gdpr_add_data_to_export' ), 10, 3 );
	}

	public function default_options( $defaults ) {
		$defaults['additional_fields'] = array();
		return $defaults;
	}

	/**
	 * Validate the submitted additional fields but do not save them
	 *
	 * @param bool|WP_Error $error
	 * @param array $args
	 * @param array $input $_REQUEST input values sent through a form
	 *
	 * @return bool|WP_Error
	 */
	public function validate_additional_fields( $error, $args, $input ) {
		if ( is_wp_error( $error ) ) {
			// There's already an error
			return $error;
		}
		if ( isset( $_REQUEST['action'] ) && 'inline_edit_save' === $_REQUEST['action'] && ! $this->_are_editable() ) {
			// Trying to edit fields not editable from Appointments list
			return $error;
		}
		$additional_fields = $this->_get_additional_fields();
		if ( empty( $additional_fields ) ) {
			// No additional fields to process
			return $error;
		}
		foreach ( $additional_fields as $field ) {
			if ( ! $field->required ) {
				// Not required, no need of validation
				continue;
			}
			if ( empty( $input['additional_fields'][ $field->name ] ) ) {
				return new WP_Error( 'missing_required_additional_field', sprintf( __( 'Das Feld %s wird benötigt', 'appointments' ), $field->label ) );
			}
		}
		return $error;
	}

	/**
	 * Check if additional fields are editable
	 *
	 * @return bool
	 */
	public function _are_editable() {
		$options = appointments_get_options();
		if ( isset( $options['additional_fields-admin_edit'] ) ) {
			$is_editable = (bool) $options['additional_fields-admin_edit'];
		} else {
			$is_editable = true;
		}
		return apply_filters( 'appointments_additional_fields_are_editable', $is_editable );
	}

	/**
	 * Return the list of the additional fields for this site
	 *
	 * @return array of stdClass {
	 *      bool    $required
	 *      string  $label
	 *      string  $name       Slug of the field
	 *      string  $type       Checkbox or text
	 * }
	 */
	private function _get_additional_fields() {
		$fields = array();
		$options = appointments_get_options();
		$options_fields = $options['additional_fields'];
		foreach ( $options_fields as $field ) {
			$_field = new stdClass();
			if ( empty( $field['required'] ) ) {
				$_field->required = false;
			} else {
				$_field->required = true;
			}
			$_field->label = esc_html( $field['label'] );
			$name = $this->_to_clean_name( $field['label'] );
			$_field->name = $name;
			$_field->type = $field['type'];
			$fields[] = $_field;
		}
		return $fields;
	}

	public function update_additional_fields( $result, $app_id ) {
		if ( ! appointments_get_appointment( $app_id ) ) {
			return $result;
		}
		$updated = $this->save_additional_fields( $app_id );
		if ( $updated ) {
			return true;
		}
		return $result;
	}

	/**
	 * Saves additional fields if they have been sent
	 *
	 * @param $app_id
	 *
	 * @return bool If the data has been saved or updated
	 */
	public function save_additional_fields( $app_id ) {
		if ( isset( $_REQUEST['action'] ) && 'inline_edit_save' === $_REQUEST['action'] && ! $this->_are_editable() ) {
			// Trying to edit fields not editable from Appointments list
			return false;
		}
		$additional_fields = $this->_get_additional_fields();
		if ( empty( $additional_fields ) ) {
			return false;
		}
		if ( empty( $_REQUEST['additional_fields'] ) ) {
			return false;
		}
		$data = array();
		$raw = stripslashes_deep( $_REQUEST['additional_fields'] );
		foreach ( $additional_fields as $field ) {
			$data[ $field->name ] = '';
			if ( ! empty( $raw[ $field->name ] ) ) {
				$data[ $field->name ] = wp_strip_all_tags( rawurldecode( $raw[ $field->name ] ) );
			}
		}
		$result = $this->_add_appointment_meta( $app_id, $data );
		if ( ! $result ) {
			return $result;
		}
		add_filter( 'app-appointment-inline_edit-result', array( $this, 'mark_successful_save' ) );
		return true;
	}

	/**
	 * Display additional fields when editing an appointment
	 *
	 * @param string $deprecated Form markup
	 * @param Appointments_Appointment $app Edited appointment
	 *
	 * @return string
	 */
	public function display_inline_data( $deprecated, $app ) {
		$fields = $this->_get_additional_fields();
		if ( empty( $fields ) ) {
			return;
		}
		$disabled = disabled( $this->_are_editable(), false, false );
		$app_meta = $this->_get_appointment_meta( $app->ID );
		$form = '';
		foreach ( $fields as $field ) {
			$value = ! empty( $app_meta[ $field->name ] ) ? esc_attr( $app_meta[ $field->name ] ) : '';
			ob_start();
?>
            <label for="additional_fields-<?php echo $field->name; ?>"><span class="title"><?php echo $field->label; 
				if ( isset( $field->required ) && $field->required ) {
					echo '<b class="required">*</b>';
				}
?>
</span>
                <span class='input-text-wrap'>
                    <?php if ( 'checkbox' === $field->type ) :  ?>
                        <input type="checkbox" class="appointments-field-entry additional_field" data-name="additional_fields[<?php echo $field->name; ?>]" id="additional_fields-<?php echo $field->name; ?>" <?php echo $disabled; ?> <?php checked( '1', $value ); ?> value="1" />
                    <?php else : ?>
                        <input type="text" class="widefat appointments-field-entry additional_field" data-name="additional_fields[<?php echo $field->name; ?>]" id="additional_fields-<?php echo $field->name; ?>" <?php echo $disabled; ?> value="<?php echo esc_attr( $value ); ?>" />
                    <?php endif; ?>
                </span>
            </label>
            <br class="clear">
<?php
			$form = $form . ob_get_clean();
		}
		if ( ! $this->_are_editable() ) {
			echo $form;
		}
		$form .= <<<EO_ADMIN_JS
<script>
(function ($) {
    $.ajaxSetup({
        beforeSend: function (jqxhr, settings) {
            if (!(settings && "data" in settings && settings.data.match(/action=inline_edit_save/))) return;
            var matches = settings.data.match(/\bapp_id=(\d+)/),
                app_id = matches && matches.length ? matches[1] : false,
                root = app_id ? $(':hidden[name="app_id"][value="' + app_id + '"]').closest("tr") : $("body"),
                fields = root.find(".appointments-field-entry")
            ;
            fields.each(function () {
                var me = $(this),
                    name = me.attr("data-name"),
                    value = me.is(":checkbox") ? (me.is(":checked") ? 1 : 0) : me.val()
                ;
                settings.data += '&' + encodeURIComponent(name) + '=' + encodeURIComponent(value);
            });
        }
    });
})(jQuery);
</script>
EO_ADMIN_JS;
		echo $form;
	}

	public function inject_additional_columns( $cols ) {
		$options = appointments_get_options();
		$fields = $options['additional_fields'];
		if ( empty( $fields ) ) {
			return $cols;
		}
		foreach ( $fields as $field ) {
			$name = 'field_' .
				preg_replace( '/[^_a-z]/i', '', $field['label'] ) .
				preg_replace( '/[^0-9]/', '', $field['label'] );
			$cols[] = $name;
		}
		return $cols;
	}

	public function inject_additional_properties( $app, $raw ) {
		if ( empty( $raw->ID ) ) {
			return $app;
		}
		$options = appointments_get_options();
		$fields = $options['additional_fields'];
		if ( empty( $fields ) ) {
			return $app;
		}
		$app_meta = $this->_get_appointment_meta( $raw->ID );
		if ( empty( $app_meta ) ) {
			return $app;
		}
		foreach ( $fields as $field ) {
			$name = strtolower( preg_replace( '/[^_a-z]/i', '', $field['label'] ) .  preg_replace( '/[^0-9]/', '', $field['label'] ) );
			$key   = strtolower( 'field_' .$name );
			$value = '';
			if ( ! empty( $app_meta[ $name ] ) ) {
				$value = html_entity_decode( esc_html( $app_meta[ $name ] ) );
			}
			if ( is_array( $app ) ) {
				if ( empty( $app[ $key ] ) ) {
					$app[ $key ] = $value;
				}
			} else if ( is_object( $app ) ) {
				if ( empty( $app->$key ) ) {
					$app->$key = $value;
				}
			}
		}
		return $app;
	}

	public function expand_general_fields( $text, $app_id ) {
		if ( empty( $text ) || empty( $app_id ) || ! is_numeric( $app_id ) ) {
			return $text;
		}
		return $this->expand_email_macros( $text, null, $app_id );
	}

	public function expand_gcal_macros( $body, $app ) {
		$app_id = ! empty( $app->ID ) ? $app->ID : false;
		if ( empty( $app_id ) ) {
			return $body;
		}
		return $this->expand_email_macros( $body, $app, $app_id );
	}

	public function expand_email_macros( $body, $app, $app_id ) {
		$options = appointments_get_options();
		$fields = $options['additional_fields'];
		if ( empty( $fields ) ) {
			return $body;
		}
		$app_meta = $this->_get_appointment_meta( $app_id );
		if ( empty( $app_meta ) ) {
			return $body;
		}
		foreach ( $fields as $field ) {
			$label = esc_html( $field['label'] );
			$name = $this->_to_clean_name( $field['label'] );
			$macro = $this->_to_email_macro( $field['label'] );
			$value = ! empty( $app_meta[ $name ] ) ? esc_html( $app_meta[ $name ] ) : '';
			$value = apply_filters( 'app-additional_fields-field_value', $value, $field, $app );
			$body = preg_replace( '/\b' . preg_quote( $macro, '/' ) . '\b/U', $value, $body );
		}
		return $body;
	}

	public function mark_successful_save( $result ) {
		if ( ! empty( $result['message'] ) ) { $result['message'] = __( '<span style="color:green;font-weight:bold">Änderungen gespeichert.</span>', 'appointments' ); }
		return $result;
	}

	public function field_names( $map ) {
		$options = appointments_get_options();
		$fields = $options['additional_fields'];
		if ( empty( $fields ) ) { return $map; }
		foreach ( $fields as $field ) {
			$name = $this->_to_clean_name( $field['label'] );
			$map[ $name ] = $field['label'];
		}
		return $map;
	}

	public function inject_additional_fields( $form ) {
		global $current_user;
		$options = appointments_get_options();
		$fields = $options['additional_fields'];
		if ( ! is_array( $fields ) ) {
			$fields = array();
		}
		if ( empty( $fields ) ) {
			return $form;
		}
		ob_start();
		foreach ( $fields as $field ) {
			$clean           = $this->_to_clean_name( $field['label'] );
			$id              = "appointments-{$clean}" . md5( serialize( $field ) );
			$user_meta_value = get_user_meta( $current_user->ID, 'app_' . $clean, true );
			$value           = $user_meta_value ? $user_meta_value : ( 'checkbox' == $field['type'] ? 1 : '' );
			/**
			 * Filters the additional field value in frontend
			 *
			 * @param string $value Current value of the field
			 * @param array $field Field attributes
			 */
			$value = apply_filters( 'appointments_additional_fields_field_value', $value, $field );
?>
                <div class="appointments-field appointments-<?php echo esc_attr( $clean ); ?>-field">
                <label for="<?php echo esc_attr( $id ); ?>"><span><?php
				echo esc_html( $field['label'] );
				if ( isset( $field['required'] ) && $field['required'] ) {
					echo '<b class="required">*</b>';
				}
?></span></label>
                    <input
                            type="<?php echo esc_attr( $field['type'] ); ?>"
                            id="<?php echo esc_attr( $id ); ?>"
                            class="appointments-field-entry appointments-<?php echo esc_attr( $clean ); ?>-field-entry"
                            data-name="additional_fields[<?php echo esc_attr( $clean ); ?>]"
                            value="<?php echo esc_attr( $value ); ?>"
                    />
                </div>
<?php
		}
		return $form . ob_get_clean();
	}

	public function inject_additional_fields_script() {
		$options = appointments_get_options();
		if ( empty( $options['additional_fields'] ) ) {
			return;
		}
?>
        <script type="text/javascript">
        (function( $ ) {
            $( document ).ajaxSend( function( e, xhr, opts ) {
                if ( ! opts.data ) {
                    return true;
    }
    if ( ! opts.data.match( /action=post_confirmation/ ) ) {
        return true;
    }
    var $fields = $( ".appointments-field-entry" );
    $fields.each( function() {
        var $me = $( this ),
                            name = $me.attr( "data-name" ),
                            value = $me.is( ":checkbox" ) ? ($me.is( ":checked" ) ? 1 : 0) : $me.val();
        opts.data += '&' + encodeURIComponent( name ) + '=' + encodeURIComponent( value );
    } );
    } );
    })( jQuery );
    </script>
<?php
	}

	public function initialize() {
		global $appointments;
		$this->_data = $appointments->options;
		$this->_core = $appointments;
	}

	public function save_settings( $options ) {
		//if (empty($_POST['app-additional_fields'])) return $options; // Allow additional fields cleaning up
		if ( empty( $_POST['app-additional_fields'] ) ) { $_POST['app-additional_fields'] = array(); }
		$data = stripslashes_deep( $_POST['app-additional_fields'] );
		$options['additional_fields'] = array();
		foreach ( $data as $field ) {
			if ( ! $field ) { continue; }
			$field = json_decode( rawurldecode( $field ), true );
			$options['additional_fields'][] = $field;
		}
		return $options;
	}

	public function show_settings( $style ) {
		wp_enqueue_script( 'underscore' );
		$_types = array(
			'text' => __( 'Text', 'appointments' ),
			'checkbox' => __( 'Checkbox', 'appointments' ),
		);
		$options = appointments_get_options();
		$fields = $options['additional_fields'];
?>
        <div class="">
            <h3><?php _e( 'Zusätzliche Felder', 'appointments' ); ?></h3>
            <table class="form-table">
                <tr valign="top" class="" <?php echo $style?>>
                    <th scope="row" ><?php _e( 'Zusätzliche Felder', 'appointments' )?></th>
                    <td colspan="2">
                        <div id="app-additional_fields">
                            <?php foreach ( $fields as $field ) { ?>
                                <div class="app-field">
                                    <b><?php echo esc_html( $field['label'] ); ?></b> <em><small>(<?php echo esc_html( $field['type'] ); ?>)</small></em>
                                    <br />
                                    <?php esc_html_e( 'Benötigt', 'appointments' ); ?>: <b><?php echo esc_html( ($field['required'] ? __( 'Ja', 'appointments' ) : __( 'Nein', 'appointments' )) ); ?></b>
                                    <br />
                                    <?php _e( 'E-mail Macro:', 'appointments' ); ?> <code><?php echo esc_html( $this->_to_email_macro( $field['label'] ) ); ?></code>
                                    <span class="description"><?php _e( 'Dies ist der Platzhalter, den Du in Deinen E-Mails verwenden kannst.', 'appointments' ); ?></span>
                                    <input type="hidden" name="app-additional_fields[]" value="<?php echo rawurlencode( json_encode( $field ) ); ?>" />
                                    <a href="#remove" class="app-additional_fields-remove"><?php esc_html_e( 'Entfernen', 'appointments' ); ?></a>
                                </div>
                            <?php } ?>
                        </div>
                        <div id="app-new_additional_field">
                            <h4><?php _e( 'Neues Feld hinzufügen', 'appointments' ); ?></h4>
                            <label for="app-new_additional_field-label">
                                <?php _e( 'Feldbezeichnung:', 'appointments' ); ?>
                                <input type="text" value="" id="app-new_additional_field-label" />
                            </label>
                            <label for="app-new_additional_field-type">
                                <?php _e( 'Feldtyp:', 'appointments' ); ?>
                                <select id="app-new_additional_field-type">
                                    <?php foreach ( $_types as $type => $label ) { ?>
                                        <option value="<?php esc_attr_e( $type ); ?>"><?php echo esc_html( $label ); ?></option>
                                    <?php } ?>
                                </select>
                            </label>
                            <label for="app-new_additional_field-required">
                                <input type="checkbox" value="" id="app-new_additional_field-required" />
                                <?php _e( 'Benötigt?', 'appointments' ); ?>
                            </label>
                            <button type="button" class="button-secondary" id="app-new_additional_field-add"><?php _e( 'Hinzufügen', 'appointments' ); ?></button>
                        </div>
                    </td>
                </tr>
            </table>
        </div>
        <script id="app-additional_fields-template" type="text/template">
        <div class="app-field">
            <b>{{ label }}</b> <em><small>({{ type }})</small></em>
            <br />
            <?php esc_html_e( 'Benötigt', 'appointments' ); ?>: <b>{{ required ? '<?php echo esc_js( __( 'Ja', 'appointments' ) ); ?>' : '<?php echo esc_js( __( 'Nein', 'appointments' ) ); ?>' }}</b>
            <input type="hidden" name="app-additional_fields[]" value="{{ encodeURIComponent(_value) }}" />
            <a href="#remove" class="app-additional_fields-remove"><?php esc_html_e( 'Remove', 'appointments' ); ?></a>
            </div>
            </script>
        <script>
        (function ($) {
            var tpl = $("#app-additional_fields-template").html();
            function parse_template (str, data) {
                var orig_settings = _.templateSettings,
                    t = false
                    ;
                _.templateSettings = {
                evaluate : /\{\[([\s\S]+?)\]\}/g,
                    interpolate : /\{\{([\s\S]+?)\}\}/g
    };
    var compiled = _.template(str);
    _.templateSettings = orig_settings;
    return compiled(data);
    }

    function add_new_field () {
        var $new_fields = $("#app-new_additional_field").find("input,select"),
            $root = $("#app-additional_fields"),
                    data = {}
                    ;
        $new_fields.each(function () {
            var $me = $(this),
                        name = $me.attr("id").replace(/app-new_additional_field-/, ''),
                        value = $me.is(":checkbox") ? $me.is(":checked") : $me.val()
                        ;
            data[name] = value;
    });
    data._value = JSON.stringify(data);
    $root.append(parse_template(tpl, data));
    return false;
    }

    function remove_field () {
        var $me = $(this);
        $me.closest(".app-field").remove();
        return false;
    }

    $(function () {
        $(document).on("click", "#app-new_additional_field-add", add_new_field);
        $(document).on("click", ".app-additional_fields-remove", remove_field);
    });

    })(jQuery);
    </script>
        <style>
            .app-field {
                border: 1px solid #ccc;
                border-radius: 3px;
                padding: 1em;
                margin-bottom: 1em;
                width: 98%;
            }
            .app-field .app-additional_fields-remove {
                display: block;
                float: right;
            }
        </style>
<?php
	}

	private function _to_clean_name( $label ) {
		$clean = preg_replace( '/[^-_a-z0-9]/', '', strtolower( $label ) );
		if ( empty( $clean ) ) { $clean = substr( md5( $label ), 0, 8 ); }
		return $clean;
	}

	private function _to_email_macro( $label ) {
		return 'FIELD_' . strtoupper( $this->_to_clean_name( $label ) );
	}

	private function _add_appointment_meta( $appointment_id, $data ) {
		$appointment = appointments_get_appointment( $appointment_id );
		if ( ! $appointment ) {
			return false;
		}
		if ( ! empty( $appointment_id ) ) {
			return appointments_update_appointment_meta( $appointment_id, 'additional_fields', $data );
		}
		return false;
	}

	private function _remove_appointment_meta( $appointment_id ) {
		appointments_delete_appointment_meta( $appointment_id, 'additional_fields' );
	}

	private function _get_appointment_meta( $appointment_id ) {
		// Fill defaults with empty strings
		$defaults = array();
		foreach ( $this->_get_additional_fields() as $field ) {
			$defaults[ $field->name ] = '';
		}
		$app_data = appointments_get_appointment_meta( $appointment_id, 'additional_fields' );
		if ( empty( $app_data ) ) {
			$app_data = array();
		}
		$app_data = wp_parse_args( $app_data, $defaults );
		return $app_data;
	}

	/**
	 * Add additional fields data to GDPR export.
	 *
	 * @since 2.3.0
	 *
	 * @param array $item Export data for appointment.
	 * @param string $email Appointment email.
	 * @param object $appointment Single appointment data object.
	 */
	public function gdpr_add_data_to_export( $item, $email, $appointment ) {
		$fields = $this->_get_additional_fields();
		$meta = $this->_get_appointment_meta( $appointment->ID );
		if ( empty( $meta ) ) {
			return $item;
		}
		foreach ( $meta as $key => $value ) {
			$name = $key;
			$value = $value;
			foreach ( $fields as $field ) {
				if ( $field->name === $key ) {
					$name = $field->label;
					if ( 'checkbox' === $field->type ) {
						$value = $value? __( 'ausgewählt', 'appointments' ):__( 'nicht ausgewählt', 'appointments' );
					}
				}
			}
			$item['data'][] = array(
				'name' => $name,
				'value' => $value,
			);
		}
		return $item;
	}
}
App_Users_AdditionalFields::serve();

if ( ! function_exists( 'app_additional_fields_expand' ) ) {
	function app_additional_fields_expand( $text, $app_id ) {
		return ! empty( $app_id )
			? apply_filters( 'app-internal-additional_fields-expand', $text, $app_id )
			: $text
			;
	}
}

/**
 * Get an appointment additional fields values
 *
 * @param int $app_id
 *
 * @return array|mixed
 */
function appointments_get_app_additional_fields( $app_id ) {
	$fields = appointments_get_appointment_meta( $app_id, 'additional_fields' );
	if ( empty( $fields ) || ! is_array( $fields ) ) {
		return array();
	}
	return $fields;
}
