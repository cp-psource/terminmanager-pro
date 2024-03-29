<?php

class Appointments_Admin_Appointments_Page {

	public $page_id = '';

	private $filters = array(
		's' => false,
		'worker_id' => false,
		'service_id' => false,
		'type' => 'active',
		'status' => array( 'confirmed', 'paid' ),
	);

	private $sorting = array(
		'orderby' => 'start',
		'order' => 'ASC',
	);

	private $pagination_args = array(
		'per_page' => 50,
		'page' => 1,
	);

	public function __construct() {
		$this->page_id = add_menu_page( 'Appointments', __( 'Terminmanager','appointments' ), App_Roles::get_capability( 'manage_options', App_Roles::CTX_PAGE_APPOINTMENTS ),  'appointments', array( &$this, 'appointment_list' ),'dashicons-clock' );
		add_action( 'load-' . $this->page_id, array( $this, 'on_load' ) );
		add_action( 'load-' . $this->page_id, array( $this, 'set_screen_options' ) );
		add_action( 'load-' . $this->page_id, array( $this, 'add_help' ) );
		$this->maybe_reset_filters();
	}

	public function add_help() {
		$content = '';
		$content .= '<ul>';
		$content .= '<li>'. __( '<b>Abgeschlossen:</b> Der Termin war überfällig, nachdem er bestätigt oder bezahlt wurde', 'appointments' ) .'</li>';
		$content .= '<li>'. __( '<b>Entfernt:</b> Der Termin wurde nicht bezahlt oder in der vorgegebenen Zeit nicht manuell bestätigt', 'appointments' ) .'</li>';
		$content .= '<li>'. __( '<b>Reserviert von GCal:</b> Wenn Du Termine aus Google Kalender mithilfe der Google Kalender-API importierst, dh Deinen Kalender mit Terminmanager synchronisierst, werden Ereignisse in Deinem Google Kalender als Termine betrachtet und hier angezeigt. Diese Datensätze können hier nicht bearbeitet werden. Verwende stattdessen Deinen Google Kalender. Sie werden auch im Terminmanager automatisch aktualisiert.', 'appointments' ) .'</li>';
		$content .= '</ul>';
		$content .= '<h3>'. __( 'Wenn Du eine Zahlung benötigst:', 'appointments' ) .'</h3>';
		$content .= '<ul>';
		$content .= '<li>'. __( '<b>Aktiv/Bezahlt:</b> Von Paypal bezahlt und bestätigt', 'appointments' ) .'</li>';
		$content .= '<li>'. __( '<b>Ausstehend:</b> Der Kunde hat den Termin beantragt, aber noch nicht bezahlt.', 'appointments' ) .'</li>';
		$content .= '</ul>';
		$content .= '<h3>'. __( 'Wenn Du keine Zahlung benötigst:', 'appointments' ) .'</h3>';
		$content .= '<ul>';
		$content .= '<li>'. __( '<b>Aktiv/Bestätigt:</b> Manuell bestätigt', 'appointments' ) .'</li>';
		$content .= '<li>'. __( '<b>Ausstehend:</b> Der Kunde hat den Termin beantragt, dieser wird jedoch nicht manuell bestätigt.', 'appointments' ) .'</li>';
		$content .= '</ul>';
		$screen = get_current_screen();
		$screen->add_help_tab(
			array(
				'id'       => 'appointment',
				'title'    => __( 'Termine' ),
				'content'  => $content,
			)
		);
	}

	public function on_load() {

		add_action( 'admin_enqueue_scripts', array( &$this, 'admin_scripts' ) );
		$appointments = appointments();

		// Bulk status change
		if ( isset( $_REQUEST['bulk_status'] ) && $_REQUEST['app_new_status'] && isset( $_REQUEST['app'] ) && is_array( $_REQUEST['app'] ) ) {

			$result = 0;
			$new_status = $_REQUEST['app_new_status'];
			foreach ( $_REQUEST['app'] as $app_id ) {
				$result = $result + (int) appointments_update_appointment_status( absint( $app_id ), $new_status );
			}

			if ( $result ) {

				$userdata = get_userdata( get_current_user_id() );
				add_action( 'admin_notices', array( $this, 'updated' ) );
				do_action( 'app_bulk_status_change',  $_REQUEST['app'] );

				$appointments->log( sprintf( __( 'Status des Termins (der Termine) mit ID(s):%s geändert in %s vom Benutzer:%s', 'appointments' ),  implode( ', ', $_REQUEST['app'] ), $new_status, $userdata->user_login ) );
				appointments_clear_cache();
			}
		}

		// Delete removed app records
		if ( isset( $_POST['delete_removed'] ) && 'delete_removed' == $_POST['delete_removed']
		     && isset( $_POST['app'] ) && is_array( $_POST['app'] ) ) {
			$result = 0;
			foreach ( $_POST['app'] as $app_id ) {
				$result = $result + appointments_delete_appointment( $app_id );
			}

			if ( $result ) {
				global $current_user;
				$userdata = get_userdata( $current_user->ID );
				add_action( 'admin_notices', array( $this, 'deleted' ) );
				do_action( 'app_deleted',  $_POST['app'] );
				$appointments->log( sprintf( __( 'Termin(e) mit ID(s):%s gelöscht vom Benutzer:%s', 'appointments' ),  implode( ', ', $_POST['app'] ), $userdata->user_login ) );
			}
		}

	}

	public function set_screen_options() {
		$options = appointments_get_options();
		$default = 50;
		if ( isset( $options['records_per_page'] ) && absint( $options['records_per_page'] ) ) {
			$default = absint( $options['records_per_page'] );
		}

		add_screen_option( 'per_page', array( 'label' => __( 'Warteschlangenelemente pro Seite', 'appointments' ), 'default' => $default, 'option' => 'appointments_records_per_page' ) );
	}

	public function save_screen_options( $status, $option, $value ) {
		if ( 'appointments_records_per_page' == $option ) {
			return absint( $value );
		}

		return $status;
	}

	private function maybe_reset_filters() {
		if ( isset( $_GET['filter_reset_action'] ) ) {
			$remove = array(
				'app_service_id',
				'app_provider_id',
				'app_new_status',
				's',
				'order',
				'orderby',
			);
			$remove[] = 'filter_reset_action';
			wp_safe_redirect( remove_query_arg( $remove ) );
			exit;
		}
	}

	public function admin_scripts() {
		$appointments = appointments();
		$appointments->admin->admin_scripts();
		_appointments_enqueue_jquery_ui_datepicker();
		wp_enqueue_script( 'appointments-admin-appointments', appointments_plugin_url() . 'admin/js/admin-appointments-list.js', array( 'jquery' ), true );

		$date_format = $appointments->safe_date_format();
		wp_localize_script( 'appointments-admin-appointments', 'Appi18n', array(
			'deleteRecordsConfirm' => esc_js( __( 'Bist Du sicher, die ausgewählten Datensätze zu löschen?', 'appointments' ) ),
			'unexpectedError' => esc_js( __( 'Unerwarteter Fehler','appointments' ) ),
			'dateFormat' => $date_format,
		) );
	}

	public function get_types() {
		return apply_filters( 'appointments_list_types', array(
			'active' => __( 'Aktive Termine', 'appointments' ),
			'pending' => __( 'Ausstehende Termine', 'appointments' ),
			'completed' => __( 'Abgeschlossene Termine', 'appointments' ),
			'reserved' => __( 'Reserviert von GCal', 'appointments' ),
			'removed' => __( 'Termine entfernt', 'appointments' ),
		));
	}

	private function parse_sorting() {
		$defaults = array(
			'orderby' => 'start',
			'order' => 'ASC',
		);

		if ( isset( $_GET['orderby'] ) && $_GET['orderby'] ) {
			$this->sorting['orderby'] = $_GET['orderby'];
		}
		if ( isset( $_GET['order'] ) && $_GET['order'] ) {
			$this->sorting['order'] = strtoupper( $_GET['order'] );
		}

		$this->sorting = wp_parse_args( $this->sorting, $defaults );
	}

	private function parse_filters() {
		if ( isset( $_GET['s'] ) ) {
			$s = stripslashes( $_GET['s'] );
			if ( $s ) {
				$this->filters['s'] = $s;
			}
		}

		$this->filters['worker_id'] = isset( $_GET['app_provider_id'] ) && absint( $_GET['app_provider_id'] ) ? absint( $_GET['app_provider_id'] ) : false;
		$this->filters['service_id'] = isset( $_GET['app_service_id'] ) && absint( $_GET['app_service_id'] ) ? absint( $_GET['app_service_id'] ) : false;

		$allowed_types = array_keys( $this->get_types() );
		if ( isset( $_GET['type'] ) && in_array( $_GET['type'], $allowed_types ) ) {
			$this->filters['type'] = $_GET['type'];
		}

		switch ( $this->filters['type'] ) {
			case 'active':
				$this->filters['status'] = array( 'confirmed', 'paid' );
				break;
			default:
				$this->filters['status'] = array( $this->filters['type'] );
				break;
		}
	}

	private function parse_pagination_args() {
		if ( empty( $_GET['paged'] ) ) {
			$paged = 1;
		} else {
			$paged = ( (int) $_GET['paged'] );
		}

		$current_screen = get_current_screen();
		$screen_option = $current_screen->get_option( 'per_page', 'option' );
		$rpp = get_user_meta( get_current_user_id(), $screen_option, true );
		if ( empty( $rpp ) || $rpp < 1 ) {
			$rpp = $current_screen->get_option( 'per_page', 'default' );
		}

		$this->pagination_args['per_page'] = $rpp;
		$this->pagination_args['page']     = $paged;
	}

	/**
	 *	Creates the list for Appointments admin page
	 */
	function appointment_list() {
		$appointments = appointments();

		$this->parse_filters();
		$this->parse_sorting();
		$this->parse_pagination_args();

		$type = $this->filters['type'];
		$s = $this->filters['s'];
		$worker_id = $this->filters['worker_id'];
		$service_id = $this->filters['service_id'];
		$status = $this->filters['status'];
		$orderby = $this->sorting['orderby'];
		$order = $this->sorting['order'];

		$rpp = $this->pagination_args['per_page'];
		$paged = $this->pagination_args['page'];

		// Count appointments by statuses
		$args = array(
			's' => $s,
			'worker' => $worker_id,
			'service' => $service_id,
		);

		$status_count = appointments_count_appointments( $args );

		// Get appointments
		$args['per_page'] = $rpp;
		$args['page'] = $paged;
		$args['status'] = $this->filters['status'];
		$args['orderby'] = $this->sorting['orderby'];
		$args['order'] = $this->sorting['order'];

		$apps = appointments_get_appointments( $args );

		// Get the total number of appointments
		$args['count'] = true;
		$total = appointments_get_appointments( $args );

		$columns = array(
			'cb' => '<input type="checkbox" />',
			'app_ID' => __( 'ID','appointments' ),
			'date' => __( 'Termindatum','appointments' ),
			'user' => __( 'Kunde','appointments' ),
			'service' => __( 'Service','appointments' ),
			'worker' => __( 'Dienstleister','appointments' ),
			'created' => __( 'Erstellt am','appointments' ),
			'status' => __( 'Status','appointments' ),
		);

		$default_columns = $columns;

		$columns = apply_filters( 'appointments_my_appointments_list_columns', $columns );

		$sortable_columns = array( 'created' => 'ID', 'date' => 'start' );
		$sortables = array();
		foreach ( $sortable_columns as $col => $field ) {
			$sortables[ $col ] = array( 'field' => $field );
			if ( $this->sorting['orderby'] === $field ) {
				$sortables[ $col ]['order'] = 'ASC' === $this->sorting['order'] ? 'ASC' : 'DESC';
				$sortables[ $col ]['sorting'] = true;
			} else {
				$sortables[ $col ]['order'] = 'ASC';
				$sortables[ $col ]['sorting'] = false;
			}
		}

		$pag_args = array(
			'base' => add_query_arg( 'paged', '%#%' ),
			'format' => '',
			'total' => ceil( $total / $rpp ),
			'current' => $paged,
			'type' => 'array',
		);
		$trans_navigation = paginate_links( $pag_args );

		$pagination_args = array(
			'total' => $total,
			'total_pages' => ceil( $total / $rpp ),
			'current' => $paged,
		);

		?>
		<div class='wrap'>
			<h1><?php echo __( 'Terminmanager','appointments' ); ?><a href="javascript:void(0)" class="add-new-h2"><?php _e( 'Erstelle Termin', 'appointments' )?></a>
				<img class="add-new-waiting" style="display:none;" src="<?php echo admin_url( 'images/spinner.gif' )?>" alt="">
			</h1>

			<?php include_once( appointments_plugin_dir() . 'admin/views/page-appointments-status-filter.php' ); ?>
			<?php include_once( appointments_plugin_dir() . 'admin/views/page-appointments-nav-filter.php' ); ?>
			<?php include_once( appointments_plugin_dir() . 'admin/views/page-appointments-list.php' ); ?>

		</div> <!-- wrap -->
		<?php

	}

	public static function pagination( $args, $which = 'top' ) {
		$total_items = $args['total'];
		$total_pages = $args['total_pages'];

		if ( $total_pages > 1 ) {
			echo '<h2 class="screen-reader-text">' . __( 'Terminlisten-Navigation', 'appointments' ) . '</h2>';
		}

		$output = '<span class="displaying-num">' . sprintf( _n( '%s Artikel', '%s Artikel', $total_items ), number_format_i18n( $total_items ) ) . '</span>';

		$current = $args['current'];

		$current_url = set_url_scheme( 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] );

		$current_url = remove_query_arg( array( 'hotkeys_highlight_last', 'hotkeys_highlight_first' ), $current_url );

		$page_links = array();

		$total_pages_before = '<span class="paging-input">';
		$total_pages_after  = '</span>';

		$disable_first = $disable_last = $disable_prev = $disable_next = false;

		if ( $current == 1 ) {
			$disable_first = true;
			$disable_prev = true;
		}
		if ( $current == 2 ) {
			$disable_first = true;
		}
		if ( $current == $total_pages ) {
			$disable_last = true;
			$disable_next = true;
		}
		if ( $current == $total_pages - 1 ) {
			$disable_last = true;
		}

		if ( $disable_first ) {
			$page_links[] = '<span class="tablenav-pages-navspan" aria-hidden="true">&laquo;</span>';
		} else {
			$page_links[] = sprintf( "<a class='first-page' href='%s'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
				esc_url( remove_query_arg( 'paged', $current_url ) ),
				__( 'Erste Seite' ),
				'&laquo;'
			);
		}

		if ( $disable_prev ) {
			$page_links[] = '<span class="tablenav-pages-navspan" aria-hidden="true">&lsaquo;</span>';
		} else {
			$page_links[] = sprintf( "<a class='prev-page' href='%s'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
				esc_url( add_query_arg( 'paged', max( 1, $current -1 ), $current_url ) ),
				__( 'Vorherige Seite' ),
				'&lsaquo;'
			);
		}

		if ( 'bottom' === $which ) {
			$html_current_page  = $current;
			$total_pages_before = '<span class="screen-reader-text">' . __( 'Aktuelle Seite' ) . '</span><span id="table-paging" class="paging-input">';
		} else {
			$html_current_page = sprintf( "%s<input class='current-page' id='current-page-selector' type='text' name='paged' value='%s' size='%d' aria-describedby='table-paging' />",
				'<label for="current-page-selector" class="screen-reader-text">' . __( 'Aktuelle Seite' ) . '</label>',
				$current,
				strlen( $total_pages )
			);
		}
		$html_total_pages = sprintf( "<span class='total-pages'>%s</span>", number_format_i18n( $total_pages ) );
		$page_links[] = $total_pages_before . sprintf( _x( '%1$s of %2$s', 'paging' ), $html_current_page, $html_total_pages ) . $total_pages_after;

		if ( $disable_next ) {
			$page_links[] = '<span class="tablenav-pages-navspan" aria-hidden="true">&rsaquo;</span>';
		} else {
			$page_links[] = sprintf( "<a class='next-page' href='%s'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
				esc_url( add_query_arg( 'paged', min( $total_pages, $current + 1 ), $current_url ) ),
				__( 'Nächste Seite' ),
				'&rsaquo;'
			);
		}

		if ( $disable_last ) {
			$page_links[] = '<span class="tablenav-pages-navspan" aria-hidden="true">&raquo;</span>';
		} else {
			$page_links[] = sprintf( "<a class='last-page' href='%s'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
				esc_url( add_query_arg( 'paged', $total_pages, $current_url ) ),
				__( 'Letzte Seite' ),
				'&raquo;'
			);
		}

		$pagination_links_class = 'pagination-links';
		$output .= "\n<span class='$pagination_links_class'>" . join( "\n", $page_links ) . '</span>';

		if ( $total_pages ) {
			$page_class = $total_pages < 2 ? ' one-page' : '';
		} else {
			$page_class = ' no-pages';
		}
		$output = "<div class='tablenav-pages{$page_class}'>$output</div>";

		echo $output;
	}

	/**
	 * Prints "deleted" message on top of Admin page
	 */
	function deleted() {
		echo '<div class="updated fade"><p><b>[Terminmanager]</b> '. __( 'Ausgewählte Datensätze gelöscht.','appointments' ).'</p></div>';
	}

	/**
	 * Prints "updated" message on top of Admin page
	 */
	function updated() {
		echo '<div class="updated fade"><p><b>[Terminmanager]</b> '. __( 'Ausgewählte Datensätze aktualisiert.','appointments' ).'</p></div>';
	}
}
