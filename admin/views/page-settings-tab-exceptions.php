<?php

$worker_id = isset( $_REQUEST['app_provider_id'] ) ? absint( $_REQUEST['app_provider_id'] ) : 0;
$result = array();
foreach ( array( 'open', 'closed' ) as $stat ) {
	$exceptions = appointments_get_worker_exceptions( $worker_id, $stat );
	if ( isset( $exceptions->days ) ) {
		$result[$stat] = $exceptions->days;
	}
	else {
		$result[$stat] = '';
	}

}

$workers = appointments_get_workers();
?>

<p class="description"><?php _e( 'Hier kannst Du außergewöhnliche Arbeits- oder Nichtarbeitstage für Dein Unternehmen und für Deine Dienstleister definieren. Hier solltest Du Feiertage eingeben. Du kannst auch einen normalerweise arbeitsfreien Wochentag (z.B. einen bestimmten Sonntag) als Arbeitstag definieren. Wenn Du neue Dienstanbieter hinzufügst, werden deren Erwartungen auf den Standardzeitplan festgelegt.', 'appointments'); ?></p>

<?php _e('List for:', 'appointments')?>
&nbsp;
<label class="screen-reader-text" for="app_provider_id"><?php _e( 'Provider wählen', 'appointments' ); ?></label>
<select id="app_provider_id" name="app_provider_id">
	<option value="0"><?php _e('Kein spezieller Provider', 'appointments')?></option>
	<?php if ( $workers ): ?>
		<?php foreach ( $workers as $worker ): ?>
			<option value="<?php echo $worker->ID; ?>" <?php selected( $worker->ID, $worker_id ); ?>>
				<?php echo appointments_get_worker_name( $worker->ID, false ); ?>
			</option>
		<?php endforeach; ?>
	<?php endif; ?>
</select>

<br /><br />
<form method="post" action="" >
	<table class="widefat fixed">
		<tr>
			<td>
				<label for="open_datepick"><?php _e( 'Außergewöhnliche Arbeitstage, z.B. An einem bestimmten Sonntag hast Du Dich entschieden zu arbeiten:', 'appointments' ) ?></label>
			</td>
		</tr>
		<tr>
			<td>
				<div class="app-datepick" data-rel="#open_datepick"></div>
				<input class="datepick" id="open_datepick" type="hidden" name="open[exceptional_days]" value="<?php if ( isset( $result["open"] ) ) echo $result["open"] ?>"/>
			</td>
		</tr>

		<tr>
			<td>
				<label for="closed_datepick"><?php _e( 'Außergewöhnliche NICHT-Arbeitstage, z.B. Urlaub:', 'appointments' ) ?></label>
			</td>
		</tr>
		<tr>
			<td>
				<div class="app-datepick" data-rel="#closed_datepick"></div>
				<input class="text" id="closed_datepick" type="hidden" name="closed[exceptional_days]" value="<?php if ( isset( $result["closed"] ) ) echo $result["closed"] ?>"/>
			</td>
		</tr>

	</table>

	<input type="hidden" name="location" value="0" />
	<input type="hidden" name="worker_id" value="<?php echo $worker_id; ?>" />
	<?php wp_nonce_field( 'app_settings_exceptions-' . $worker_id, 'app_exceptions_nonce' ); ?>
	<?php _appointments_settings_submit_block( 'exceptions' ); ?>

</form>

<?php add_action( 'admin_footer', '_appointments_init_multidatepicker', 1000 ); ?>
<script type="text/javascript">
jQuery(document).ready(function($){
	$('#app_provider_id').on('change', function(){
		var app_provider_id = $('#app_provider_id option:selected').val();
		window.location.href = "<?php echo admin_url('admin.php?page=app_settings&tab=exceptions')?>" + "&app_provider_id=" + app_provider_id;
	});
});
</script>
