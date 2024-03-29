<?php
global $appointments, $wpdb;
?>

<?php if ( isset( $_GET['added'] ) ): ?>
	<div class="updated">
		<p><?php _e( 'Dienstleister hinzugefügt', 'appointments' ); ?></p>
	</div>
<?php endif; ?>

<?php foreach ( $sections as $section => $name ): ?>
	<?php $section_file = _appointments_get_settings_section_view_file_path( $tab, $section ); ?>
	<?php if ( $section_file ): ?>
		<div class="app-settings-section" id="app-settings-section-<?php echo $section; ?>">
			<h3><?php echo esc_html( $name ); ?></h3>
			<?php include_once( $section_file ); ?>
			<?php do_action( "appointments_settings_tab-$tab-section-$section" ); ?>
		</div>
	<?php endif; ?>
<?php endforeach; ?>


<?php do_action( "appointments_settings-$tab" );  ?>

<script type="text/javascript">
    jQuery( document ).ready( function( $ ) {
        $( ".add_worker_multiple" ).multiselect( {
            noneSelectedText: '<?php echo esc_js( __( 'Gewählte Services', 'appointments' ) ) ?>',
            checkAllText: '<?php echo esc_js( __( 'Alle wählen', 'appointments' ) ) ?>',
            uncheckAllText: '<?php echo esc_js( __( 'Alle abwählen', 'appointments' ) ) ?>',
            selectedText: '<?php echo esc_js( __( '# ausgewählt', 'appointments' ) ) ?>',
            selectedList: 5,
            position: {
                my: 'left bottom',
                at: 'left top'
            }
        } );
    } );
</script>

