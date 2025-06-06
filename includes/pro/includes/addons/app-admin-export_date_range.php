<?php
/*
Plugin Name: Datumsbereich exportieren
Description: Ermöglicht das Exportieren von Terminen innerhalb eines Datumsbereichs
Plugin URI: https://cp-psource.github.io/terminmanager-pro/
Version: 1.1
AddonType: Export
Author: PSOURCE
*/

class App_Export_DateRange {

	private function __construct() {}

	public static function serve() {
		$me = new App_Export_DateRange;
		$me->_add_hooks();
	}

	private function _add_hooks() {
		add_action( 'app-export-export_form_end', array( $this, 'inject_range_form' ) );
		add_filter( 'app-export-appointment', array( $this, 'check_appointment_for_inclusion' ), 10, 2 );
	}

	public function check_appointment_for_inclusion( $src, $app ) {
		if ( empty( $_POST['app-export-start'] ) && empty( $_POST['app-export-end'] ) ) {
			return $src; // Not applicable
		}
		if ( empty( $src ) || empty( $app->start ) ) {
			return $src;
		}
		$start = strtotime( $app->start );
		$end = strtotime( $app->end );
		$earliest = ! empty( $_POST['app-export-start'] )
			? strtotime( $_POST['app-export-start'].' 00:00:00' ) - 1
			: 0
			;
		$latest = ! empty( $_POST['app-export-end'] )
			? strtotime( $_POST['app-export-end'].' 00:00:00' ) + DAY_IN_SECONDS
			: time();
		return $start < $latest && $end > $earliest
			? $src
			: false
			;
	}

	public function inject_range_form() {
		$title = __( 'Schließe nur Termine ein zwischen', 'appointments' );
		$start = __( 'Start', 'appointments' );
		$end = __( 'Ende', 'appointments' );
		?>
<label for="app-export-date_range-toggle">
	<input type="checkbox" id="app-export-date_range-toggle" autocomplete="off" />
	<?php echo $title; ?>
</label>
<div id="app-export-date_range" style="display:none">
	<label for="app-export-date_range-start">
		<?php echo $start; ?>
		<input type="text" name="app-export-start" id="app-export-date_range-start" value="" />
	</label>
	<label for="app-export-date_range-end">
		<?php echo $end; ?>
		<input type="text" name="app-export-end" id="app-export-date_range-end" value="" />
	</label>
</div>
<script type="text/javascript">
(function ($) {
	var $root;
	function show () {
		$root
			.find(":text").datepicker({
				dateFormat: "yy-mm-dd"
			})
			.end()
		.show();
	}
	function hide () {
		$root.hide();
	}
	function init () {
		$root = $("#app-export-date_range");
		$("#app-export-date_range-toggle").on('change', function () {
			if ($(this).is(":checked")) show();
			else hide();
		});
	}
	$(init);
})(jQuery);
</script>
		<?php
	}
}
if ( is_admin() ) { App_Export_DateRange::serve(); }
