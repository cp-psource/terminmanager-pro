<?php
/**
 * Widgets for Appointments 1.0
 * V1.2.6.1
 */
class Appointments_Widget_Helper extends WP_Widget {
	var $default_instance = array();

	public function __construct( $id_base, $name, $widget_options = array(), $control_options = array() ) {
		parent::__construct( $id_base, $name, $widget_options, $control_options );
	}

	function parse_instance( $instance ) {
		return wp_parse_args( $instance, $this->default_instance );
	}

	function widget( $args, $instance ) {
		$instance = $this->parse_instance( $instance );

		extract($args);
		$title = apply_filters('widget_title', $instance['title'], $instance, $this->id_base);

		echo $before_widget;

		if (!empty($title)) {
			echo $before_title . $title . $after_title;
		}

		$this->content($instance);

		echo $after_widget;
	}

	function title_field( $title ) {
?>
		<p>
			<label for="<?php echo $this->get_field_id('title'); ?>"><?php _e( 'Titel:', 'appointments' ); ?></label>
			<input type="text" class="widefat" name="<?php echo $this->get_field_name('title')?>" value="<?php echo esc_attr($title); ?>" />

		</p>
<?php
	}
}

class Appointments_Widget_Services extends Appointments_Widget_Helper {

	var $default_instance = array(
		'title' => '',
		'number' => 5
	);

	function __construct() {
		$widget_ops = array(
			'description' => __( 'Liste der Dienste und Links zu Deinen Beschreibungsseiten', 'appointments'),
		);
		parent::__construct('appointments_services', __( 'Terminmanager Services', 'appointments' ), $widget_ops);
	}

	function content( $instance ) {

		extract( $instance );

		global $wpdb;
		$results = $wpdb->get_results("SELECT * FROM " . $wpdb->prefix . "app_services" . " WHERE page >0 LIMIT ".$number." ");

		if ( $results ) {
			echo '<ul>';
			foreach ( $results as $result ) {
				echo '<li>';

				echo '<a href="'.get_permalink($result->page).'" >'. stripslashes( $result->name ) . '</a>';

				echo '</li>';
			}
			echo '</ul>';
		}
	}

	function form( $instance ) {
		$instance = $this->parse_instance( $instance );
		$this->title_field( $instance['title'] );
?>

		<p>
			<label for="<?php echo $this->get_field_id('number'); ?>"><?php _e( 'Anzahl der anzuzeigenden Dienste:', 'appointments' ); ?></label>
			<input type="text" size="2" name="<?php echo $this->get_field_name('number')?>" value="<?php echo esc_attr($instance['number']); ?>" />

		</p>
<?php
	}

	function update( $new_instance, $old_instance ) {
		$instance = $old_instance;
		$new_instance = $this->parse_instance( $new_instance );
		$instance['title'] = strip_tags( $new_instance['title'] );
		$instance['number'] = (int) $new_instance['number'];
		return $instance;
	}
}

class Appointments_Widget_Service_Providers extends Appointments_Widget_Helper {

	var $default_instance = array(
		'title' => '',
		'number' => 5
	);

	function __construct() {
		$widget_ops = array(
			'description' => __( 'Liste der Dienstleister und Links zu ihren Bioseiten', 'appointments'),
		);
		parent::__construct('appointments_service_providers', __( 'Terminmanager Service Providers', 'appointments' ), $widget_ops);
	}

	function content( $instance ) {

		$args = array();

		//extract( $instance );
		$args['limit'] = !empty($instance['number']) && is_numeric($instance['number'])
			? (int)$instance['number']
			: 5
		;
		$args['with_page'] = !empty($instance['allow_no_links']) ? false : true;

		global $appointments;
		$results = appointments_get_workers( $args );

		if ( $results ) {
			echo '<ul>';
			foreach ( $results as $result ) {
				$link_template = !empty($result->page)
					? '<a href="' . get_permalink($result->page) . '">%s</a>'
					: '<span class="app_worker">%s</a>'
				;
				echo '<li>' .
					sprintf($link_template, appointments_get_worker_name($result->ID)) .
				'</li>';
			}
			echo '</ul>';
		}
	}

	function form( $instance ) {
		$instance = $this->parse_instance($instance);
		$this->title_field($instance['title']);

		if (!isset($instance['allow_no_links'])) $instance['allow_no_links'] = false;
?>

		<p>
			<label for="<?php echo $this->get_field_id('number'); ?>"><?php _e( 'Anzahl der anzuzeigenden Dienstleister:', 'appointments' ); ?></label>
			<input type="text" size="2" id="<?php echo $this->get_field_id('number'); ?>" name="<?php echo $this->get_field_name('number')?>" value="<?php echo esc_attr($instance['number']); ?>" />
		</p>
		<p>
			<input type="checkbox" name="<?php echo $this->get_field_name('allow_no_links')?>" id="<?php echo $this->get_field_id('allow_no_links'); ?>" value="1" <?php checked($instance['allow_no_links'], true); ?> />
			<label for="<?php echo $this->get_field_id('allow_no_links'); ?>"><?php _e( 'Dienstleister ohne Biografien anzeigen', 'appointments' ); ?></label>
		</p>
<?php
	}

	function update( $new_instance, $old_instance ) {
		$instance = $old_instance;
		$new_instance = $this->parse_instance( $new_instance );
		$instance['title'] = strip_tags( $new_instance['title'] );
		$instance['number'] = (int) $new_instance['number'];
		$instance['allow_no_links'] = !empty($new_instance['allow_no_links']) ? 1 : 0;
		return $instance;
	}
}

class Appointments_Widget_Monthly_Calendar extends Appointments_Widget_Helper {

	var $default_instance = array(
		'title' 	=> '',
		'add' 		=> 0,
		'page_id'	=> 0
	);

	function __construct() {
		add_action('wp_enqueue_scripts', array($this, 'wp_enqueue_scripts'));
		add_action('wp_print_styles', array($this, 'wp_print_styles'));
		add_action('wp_footer', array($this, 'wp_footer'));
		$widget_ops = array(
			'description' => __( 'Ein Monatskalender, der den Benutzer zur ausgewählten Terminseite weiterleitet, wenn auf einen freien Tag geklickt wird. Verwende mehrere Instanzen, um mehrere Monate anzuzeigen, und stelle nach Bedarf "Monate zum aktuellen Monat hinzufügen" ein, z.B. 0 für die erste Instanz, 1 für die zweite und so weiter.', 'appointments'),
		);
		parent::__construct('appointments_monthly_calendar', __( 'Terminmanager Monatskalender', 'appointments' ), $widget_ops);
	}

	function wp_enqueue_scripts() {
		wp_enqueue_script( 'jquery' );

		// Prevent W3T Minify. Caching is allowed here
		if (!defined('DONOTMINIFY')) define( 'DONOTMINIFY', true );
	}

	function wp_print_styles() {
		global $appointments;
		if (!current_theme_supports('appointments_style')) {
			wp_enqueue_style("appointments", $appointments->plugin_url. "/css/front.css", array(), $appointments->version);
			if (!has_action('wp_head', array($appointments, 'wp_head'))) add_action('wp_head', array($this, 'wp_head'));
		}
	}

	function wp_head () {
		global $appointments;
		$appointments->wp_head();
	}

	function wp_footer () {
		$settings = $this->get_settings();
		$instance = isset($settings[$this->number])
			? $settings[$this->number]
			: null
		;

		if (is_array($instance) && isset( $instance['page_id'] ) ) {
			extract($instance);

			$href = get_permalink($instance["page_id"]);

			$script  = '';
			$script .= '<script type="text/javascript">';
			$script .= "jQuery(document).ready(function($) {";
			//$script .= '$("table.app_monthly_calendar_widget td.free").on("click", function(){';
			$script .= '$("table.app_monthly_calendar_widget td.free").on("click", function(){';
			$script .= 'var wcalendar = $(this).find(".appointments_select_time").val();';
			$script .= 'window.location.href="'.$href.'?wcalendar="+wcalendar;';
			$script .= '});';
			$script .= "});</script>";

			echo $script;
		}
	}

	function content ($instance) {
		extract($instance);

		add_action('app_calendar_widget_before_content', $add);

		echo do_shortcode('[app_monthly_schedule class="app_monthly_calendar_widget" widget="1" title="<h3>START</h3>" logged=" " notlogged=" " add="'.$add.'"]');

		add_action('app_calendar_widget_after_content', $add);
	}

	function form( $instance ) {
		$instance = $this->parse_instance( $instance );
		$this->title_field( $instance['title'] );
?>
		<p>
			<label for="<?php echo $this->get_field_id('page_id'); ?>"><?php _e( 'Terminseite:', 'appointments' ); ?></label>
			<?php
			wp_dropdown_pages(array(
				'selected' => $instance['page_id'], 
				'name' => $this->get_field_name('page_id'),
			));
			?>
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('add'); ?>"><?php _e( 'Monate zum aktuellen Monat hinzufügen:', 'appointments' ); ?></label>
			<input type="text" size="2" name="<?php echo $this->get_field_name('add')?>" value="<?php echo esc_attr($instance['add']); ?>" />

		</p>
<?php
	}

	function update( $new_instance, $old_instance ) {
		$instance = $old_instance;
		$new_instance = $this->parse_instance( $new_instance );
		$instance['title'] = strip_tags( $new_instance['title'] );
		$instance['add'] = (int) $new_instance['add'];
		$instance['page_id'] = (int) $new_instance['page_id'];
		return $instance;
	}
}