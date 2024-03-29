<?php

class App_Shortcode_WorkerLocationsShortcode extends App_Shortcode {

	protected $_requested_location_id;

	public function __construct () {
		$this->name = __( 'Worker Locations', 'appointments' );
		if (!empty($_REQUEST['app_provider_location']) && is_numeric($_REQUEST['app_provider_location'])) {
			$this->_requested_location_id = (float)$_REQUEST['app_provider_location'];
		}

		if (!is_admin() && !empty($this->_requested_location_id)) {
			add_filter('app_workers', array($this, 'filter_workers'));
		}
	}

	public function get_defaults() {
		return array(
			'select' => array(
				'type' => 'text',
				'name' => __( 'Title', 'appointments' ),
				'value' => __('Please select a provider location:', 'appointments'),
				'help' => __('Text above the select menu. Default: "Please select a provider location"', 'appointments'),
			),
			'show' => array(
				'type' => 'text',
				'name' => __( 'Button text', 'appointments' ),
				'value' => __('Show available providers', 'appointments'),
				'help' => __('Button text to show the results for the selected. Default: "Show available providers"', 'appointments'),
			),
			'autorefresh' => array(
				'type' => 'checkbox',
				'name' => __( 'Autorefresh', 'appointments' ),
				'value' => 0,
				'help' => __('If checked, Show button will not be displayed and page will be automatically refreshed as client changes selection. Note: Client cannot browse through the selections and thus check descriptions on the fly (without the page is refreshed). Default: disabled', 'appointments'),
			),
			'order_by' => array(
				'type' => 'select',
				'name' => __( 'Order By', 'appointments' ),
				'options' => array(
					array( 'text' => 'ID', 'value' => 'ID' ),
					array( 'text' => 'ID DESC', 'value' => 'ID DESC' ),
					array( 'text' => 'name', 'value' => 'name' ),
					array( 'text' => 'name DESC', 'value' => 'name DESC' ),
				),
				'value' => 'ID',
				'help' => __('Sort order, by service providers. Possible values: ID, name. Optionally DESC (descending) can be used, e.g. "name DESC" will reverse the order. Default: "ID"', 'appointments'),
			),
		);
	}

	public function filter_workers ($workers) {
		$result = array();
		foreach ($workers as $wrk) {
			if (empty($wrk->ID)) continue;
			$location_id = App_Locations_WorkerLocations::worker_to_location_id($wrk->ID);
			if (!empty($location_id) && $this->_requested_location_id == $location_id) {
				$result[] = $wrk;
			}
		}

		return $result;
	}

	public function process_shortcode ($args=array(), $content='') {
		global $appointments;
		$args = wp_parse_args($args, $this->_defaults_to_args());

		$workers = appointments_get_workers(array( 'orderby' => $args['order_by'] ) );
		$model = App_Locations_Model::get_instance();
		$locations = array();

		foreach ($workers as $wrk) {
			if (empty($wrk->ID)) continue;
			$location_id = App_Locations_WorkerLocations::worker_to_location_id($wrk->ID);
			if (!empty($location_id)) $locations[$location_id] = $model->find_by('id', $location_id);
		}
		$locations = array_values(array_filter($locations));

		if (empty($locations)) return $content;
		$ret = '';

		$ret .= '<div class="app_provider_locations">';
		$ret .= '<div class="app_provider_locations_dropdown">';
		$ret .= '<div class="app_provider_locations_dropdown_title">';
		$ret .= $args['select'];
		$ret .= '</div>';
		$ret .= '<div class="app_provider_locations_dropdown_select">';
		$ret .= '<select name="app_provider_location">';
		foreach ($locations as $location) {
			/** @var App_Locations_DefaultLocation $location */
			$ret .= '<option value="' . esc_attr($location->get_id()) . '" ' . selected($this->_requested_location_id, $location->get_id(), false) . '>' . esc_html($location->get_display_markup(false)) . '</option>';
		}
		$ret .= '</select>';
		if (empty($args['autorefresh'])) $ret .= '<input type="button" class="app_provider_locations_button" value="'.esc_attr($args['show']).'">';
		$ret .= '</div>';
		$ret .= '</div>';

		$href = add_query_arg(
			'app_provider_location', '::apl::',
			remove_query_arg(array(
				//'app_service_location',
				'app_provider_location',
				//'app_provider_id',
				'app_service_id'
			))
		);

		$script =<<<EO_SELECTION_JAVASCRIPT
function app_provider_locations_redirect () {
	var selected = $(".app_provider_locations_dropdown_select select").first().val();
	window.location = '{$href}'.replace(/::apl::/, selected);
}
$(".app_provider_locations_button").on("click", app_provider_locations_redirect);
EO_SELECTION_JAVASCRIPT;
		if (!empty($args['autorefresh'])) {
			$script .= '$(".app_provider_locations_dropdown_select select").change(app_provider_locations_redirect);';
		}
		$appointments->add2footer($script);
		
		return $ret;
	}

	public function get_usage_info () {
		return __('Creates a dropdown menu of available provider locations.', 'appointments');
	}
}



class App_Shortcode_RequiredWorkerLocationsShortcode extends App_Shortcode_WorkerLocationsShortcode {

	public function __construct() {
		parent::__construct();
		$this->name = __( 'Required Provider Locations', 'appointments' );
	}

	public function get_usage_info () {
		return __('Creates a dropdown menu of available provider locations which will be converted to a provider list once a location has been chosen.', 'appointments');
	}

	public function process_shortcode ($args=array(), $content='') {
		$instance = App_Shortcodes::get_shortcode_instance('app_service_providers');
		if (!empty($this->_requested_location_id) && $instance && method_exists($instance, 'process_shortcode')) return $instance->process_shortcode($args, $content);
		else return parent::process_shortcode($args, $content);
	}
}