<?php
/*
Plugin Name: Dauer
Description: Ermöglicht das Vornehmen von Änderungen an der Berechnung der Servicedauer
Plugin URI: https://cp-psource.github.io/terminmanager-pro/
Version: 1.1
AddonType: Schedule
Author: PSOURCE
*/

class App_Schedule_Durations {

	private $_duration_flag_changes_applied = false;
	private $_boundaries_flag_changes_applied = false;

	private function __construct() {}

	public static function serve() {
		$me = new App_Schedule_Durations;
		$me->_add_hooks();
	}

	private function _add_hooks() {
		add_filter( 'init', array( $this, 'apply_duration_calculus' ), 99 );

		add_action( 'app-settings-time_settings', array( $this, 'show_settings' ) );
		add_filter( 'app-options-before_save', array( $this, 'save_settings' ) );

		add_action( 'appointments_default_options', array( $this, 'default_options' ) );

		add_filter( 'appointments_use_legacy_duration_calculus', array( $this, 'apply_use_legacy_duration' ), 1 );
		add_filter( 'appointments_use_legacy_break_times_padding_calculus', array( $this, 'apply_use_break_times_padding_calculus' ), 1 );
		add_filter( 'appointments_use_legacy_boundaries_padding_calculus', array( $this, 'apply_use_boundaries_padding_calculus' ), 1 );
	}

	public function apply_use_legacy_duration( $value ) {
		$options = appointments_get_options();
		if ( 'legacy' === $options['duration_calculus'] ) {
			return true;
		}
		return $value;
	}

	public function apply_use_break_times_padding_calculus( $value ) {
		$options = appointments_get_options();
		if ( 'legacy' != $options['breaks_calculus'] ) {
			return true;
		}
		return $value;
	}

	public function apply_use_boundaries_padding_calculus( $value ) {
		$options = appointments_get_options();
		if ( 'legacy' === $options['boundaries_calculus'] ) {
			return true;
		}
		return $value;
	}


	/**
	 * Add default options to Terminmanager options
	 *
	 * @param array $defaults
	 *
	 * @return array
	 */
	public function default_options( $defaults ) {
		$defaults['duration_calculus'] = 'legacy';
		$defaults['boundaries_calculus'] = 'legacy';
		$defaults['breaks_calculus'] = 'legacy';
		return $defaults;
	}

	public function apply_duration_calculus() {
		$options = appointments_get_options();

		if ( ! defined( 'APP_USE_LEGACY_DURATION_CALCULUS' ) ) {
			if ( 'legacy' === $options['duration_calculus'] ) {
				$this->_duration_flag_changes_applied = true;
			}
		}
		if ( ! defined( 'APP_USE_LEGACY_BOUNDARIES_CALCULUS' ) ) {
			if ( 'legacy' === $options['boundaries_calculus'] ) {
				$this->_boundaries_flag_changes_applied = true;
			}
		}
		if ( ! defined( 'APP_BREAK_TIMES_PADDING_CALCULUS' ) ) {
			if ( 'legacy' != $options['breaks_calculus'] ) {
				$this->_breaks_flag_changes_applied = true;
			}
		}
	}

	public function save_settings( $options ) {
		if ( ! empty( $_POST['duration_calculus'] ) ) {
			$allowed = array( 'legacy', 'service' );
			if ( in_array( $_POST['duration_calculus'], $allowed ) ) {
				$options['duration_calculus'] = $_POST['duration_calculus'];
			}
		}
		if ( ! empty( $_POST['boundaries_calculus'] ) ) {
			$allowed = array( 'legacy', 'detect_overlap' );
			if ( in_array( $_POST['boundaries_calculus'], $allowed ) ) {
				$options['boundaries_calculus'] = $_POST['boundaries_calculus'];
			}
		}
		if ( ! empty( $_POST['breaks_calculus'] ) ) {
			$allowed = array( 'legacy', 'pad' );
			if ( in_array( $_POST['breaks_calculus'], $allowed ) ) {
				$options['breaks_calculus'] = $_POST['breaks_calculus'];
			}
		}
		return $options;
	}

	public function show_settings() {
		$this->_show_legacy_duration_settings();
	}

	private function _show_legacy_duration_settings() {
		$options = appointments_get_options();
		echo '<tr valign="top">' .
			'<th scope="row" >' . __( 'Zeitschlitz-Berechnungsmethode', 'appointments' ) . '</th>' .
		'';
		echo '<td colspan="2">';

		// Duration
		if ( defined( 'APP_USE_LEGACY_DURATION_CALCULUS' ) ) {
			// User defined
			echo '<div class="error below-h2">' .
				'<p>' . __( 'Deine Dauerberechnung wird durch den definierten Wert bestimmt.', 'appointments' ) . '</p>' .
			'</div>';
		} else {
			$durations = array(
				'legacy' => __( 'Berechnung der minimalen zeitbasierten Termindauer <em>(Legacy)</em>', 'appointments' ),
				'service' => __( 'Service-Dauer-basierte Berechnung', 'appointments' ),
			);
			$method = ! empty( $options['duration_calculus'] ) ? $options['duration_calculus'] : 'service';
			foreach ( $durations as $key => $label ) {
				$checked = checked( $key, $method, false );
				echo "<input type='radio' name='duration_calculus' id='app-duration_calculus-{$key}' value='{$key}' {$checked} />" .
					'&nbsp;' .
					"<label for='app-duration_calculus-{$key}'>{$label}</label>" .
				'</br >';
			}
		}
		// Boundaries
		echo '<h4>' . __( 'Grenzerkennung', 'appointments' ) . '</h4>';
		if ( defined( 'APP_USE_LEGACY_BOUNDARIES_CALCULUS' ) ) {
			echo '<div class="error below-h2">' .
				'<p>' . __( 'Deine Grenzrechnung wird durch den definierten Wert bestimmt.', 'appointments' ) . '</p>' .
			'</div>';
		} else {
			$boundaries = array(
				'legacy' => __( 'Genaue Periodenübereinstimmung <em>(legacy)</em>', 'appointments' ),
				'detect_overlap' => __( 'Überlappung erkennen', 'appointments' ),
			);
			$method = ! empty( $options['boundaries_calculus'] ) ? $options['boundaries_calculus'] : 'detect_overlap';
			foreach ( $boundaries as $key => $label ) {
				$checked = checked( $key, $method, false );
				echo "<input type='radio' name='boundaries_calculus' id='app-boundaries_calculus-{$key}' value='{$key}' {$checked} />" .
					'&nbsp;' .
					"<label for='app-boundaries_calculus-{$key}'>{$label}</label>" .
				'</br >';
			}
		}
		// Break times
		echo '<h4>' . __( 'Pausenzeitrechnung', 'appointments' ) . '</h4>';
		if ( defined( 'APP_BREAK_TIMES_PADDING_CALCULUS' ) ) {
			// User defined
			echo '<div class="error below-h2">' .
				'<p>' . __( 'Deine Pausenzeitrechnung wird durch den definierten Wert bestimmt.', 'appointments' ) . '</p>' .
			'</div>';
		} else {
			$breaks = array(
				'legacy' => __( 'Ungültige geschlossene Offsets <em>(legacy)</em>', 'appointments' ),
				'pad' => __( 'Verschiebung nächste Periode', 'appointments' ),
			);
			$method = ! empty( $options['breaks_calculus'] ) ? $options['breaks_calculus'] : 'legacy';
			foreach ( $breaks as $key => $label ) {
				$checked = checked( $key, $method, false );
				echo "<input type='radio' name='breaks_calculus' id='app-breaks_calculus-{$key}' value='{$key}' {$checked} />" .
					'&nbsp;' .
					"<label for='app-breaks_calculus-{$key}'>{$label}</label>" .
				'</br >';
			}
		}

		echo '</td>';
		echo '</tr>';
	}
}
App_Schedule_Durations::serve();
