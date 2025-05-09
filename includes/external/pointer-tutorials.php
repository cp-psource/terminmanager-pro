<?php
/*
Pointer Tutorials Module
By Aaron Edwards (Incsub)
http://uglyrobot.com/

Copyright 2011-2012 Incsub (http://incsub.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License (Version 2 - GPLv2) as published by
the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

----- How to Use ------
It is best to call this in the admin_init action hook. Here is an example:

	//show the tutorial
	add_action( 'admin_init', 'tutorial' );
	
	function tutorial() {
		//load the file
		require_once( dirname(__FILE__) . '/includes/pointer-tutorials.php' );
		
		//create our tutorial, with default redirect prefs
		$tutorial = new Pointer_Tutorial(__('My Tutorial', 'mytextdomain'), 'my_tutorial', true, false);
		
		//add our textdomain that matches the current plugin
		$tutorial->set_textdomain = 'mytextdomain';
		
		//add the capability a user must have to view the tutorial
		$tutorial->set_capability = 'manage_options';
		
		//optionally add some custom css. This example give our title a red background and loads up our modified pointer image sprite to the up arrow will be red too
		$tutorial->add_style('.my_tutorial-pointer .wp-pointer-content h3 {	background-color: #b12c15; }
													.my_tutorial-pointer .wp-pointer-arrow { background-image: url("'.plugins_url( 'includes/images/arrow-pointer-red.png' , __FILE__ ).'"); }');
		
		//optional shortcut to add a custom icon, just pass a url
		$tutorial->add_icon( plugins_url( 'includes/images/my-logo-white.png' , __FILE__ ) );
		
		//start registering steps. Note the 'content' argument is very important, and should be escaped with esc_js() as it will go in JSON
		$tutorial->add_step(admin_url('index.php'), 'index.php', '#psource_widget', __('Step Number One', 'mytextdomain'), array(
				'content'  => '<p>' . esc_js( __('On each category page, plugins and themes are listed in an easy to read grid format.', 'mytextdomain') ) . '</p>',
				'position' => array( 'edge' => 'bottom', 'align' => 'left' ),
			));
		$tutorial->add_step(admin_url('index.php'), 'index.php', '#toplevel_page_psource', __('Step Number Two', 'mytextdomain'), array(
				'content'  => '<p>' . esc_js( __('On each category page, plugins and themes are listed in an easy to read grid format.', 'mytextdomain') ) . '</p>',
				'position' => array( 'edge' => 'top', 'align' => 'right' ),
			));
		$tutorial->add_step(admin_url('index.php'), 'index.php', '#wdv-release-install', __('Step Number Three', 'mytextdomain'), array(
				'content'  => '<p>' . esc_js( __('On each category page, plugins and themes are listed in an easy to read grid format.', 'mytextdomain') ) . '</p>',
				'position' => array( 'edge' => 'left', 'align' => 'top' ),
			));
		
		//second page steps
		$tutorial->add_step(admin_url('admin.php?page=my-plugin'), 'toplevel_page_psource', '.nav-tab-wrapper', __('Step Number Four', 'mytextdomain'), array(
				'content'  => '<p>' . esc_js( __('On each category page, plugins and themes are listed in an easy to read grid format.', 'mytextdomain') ) . '</p>',
				'position' => array( 'edge' => 'top', 'align' => 'center' ),
			));
		$tutorial->add_step(admin_url('admin.php?page=my-plugin'), 'toplevel_page_psource', '.wdv-grid-wrap .themepost:not(.installed):first', __('Step Number Five', 'mytextdomain'), array(
				'content'  => '<p>' . esc_js( __('On each category page, plugins and themes are listed in an easy to read grid format.', 'mytextdomain') ) . '</p>',
				'position' => array( 'edge' => 'left', 'align' => 'center' ),
			));
		$tutorial->add_step(admin_url('admin.php?page=my-plugin'), 'toplevel_page_psource', '.wdv-grid-wrap .themepost:not(.installed):first .themescreens .metainfo a', __('Step Number Six', 'mytextdomain'), array(
				'content'  => '<p>' . esc_js( __('On each category page, plugins and themes are listed in an easy to read grid format.', 'mytextdomain') ) . '</p>',
				'position' => array( 'edge' => 'top', 'align' => 'left' ),
			));
		
		//start the tutorial
		$tutorial->initialize();
		
		You may want to later show a link to restart the tutorial, or start at a certain step. You can grab a link for that via start_link($step). 
		$step = 0; //Note that steps start at 0, then 1,2,3 etc.
		$link = $tutorial->start_link($step);
	}

Have fun!
*/

if ( !class_exists( 'Pointer_Tutorial' ) ) {
	/*
	* class Pointer_Tutorial
	*
	*	@param string $tutorial_name Required: The name of this tutorial. Used for user settings and css classes.
	*	@param bool $redirect_first_load Optional: Set to true to redirect and show first step for those who have not completed the tutorial. Default true
	*	@param bool $force_completion Optional: Set to true to redirect and show the current step for those who have not completed the tutorial. Basically forces the tutorial to be completed or dismissed. Default false.
	*/
	class Pointer_Tutorial {
		// Existing code...

		public function set_textdomain( $domain ) {
			$this->textdomain = trim( $domain );
		}

		// Existing code...
	}
}

if (method_exists($tutorial, 'set_textdomain')) {
	$tutorial->set_textdomain('appointments');
} else {
	// Optional: Log or debug that the method does not exist
	error_log('The method set_textdomain() does not exist in Pointer_Tutorial.');
}
.wp-pointer-buttons a.prev { float: left; }
.wp-pointer-buttons a.dismiss {	color: #FFFFFF; font-size: 10px; position: absolute; right: 3px; top: 1px; }
.wp-pointer-buttons span.tut-step {	font-size: 9px; font-size: 9px; left: 0; bottom: -3px; position: absolute; text-align: center; width: 100%; }';
				echo $this->admin_css;
				echo "\n</style>\n";
			}
		}
		
		/**
		 * Handles the AJAX step complete callback.
		 *
		 */
		function ajax_dismiss() {
			if ( !is_numeric($_POST['pointer']) )
				die( '0' );
			
			if ('next' == $_POST['step']) {				
				$pointer = intval($_POST['pointer']) + 1;
			} else if ('prev' == $_POST['step']) {
				$pointer = intval($_POST['pointer']) - 1;
			} else if (!$this->hide_dismiss) {
				$pointer = count($this->registered_pointers);	//dismissing tutorial, so set to last step		
			} else {
				die( '0' );
			}
		
			update_user_meta( get_current_user_id(), "current-{$this->tutorial_key}-step", $pointer );
			die( '1' );
		}
		
		/**
		 * Listens for clicks to start/restart a tutorial, or jumping to a step.
		 *
		 */
		function catch_tutorial_start() {
			if ( is_admin() && isset($_GET[$this->tutorial_key.'-start']) )
				$this->restart( intval($_GET[$this->tutorial_key.'-start']) );
		}
		
		/**
		 * Print the pointer javascript data in the footer.
		 */
		function print_footer_list() {
			// Get current step
			$current_step = (int) get_user_meta( get_current_user_id(), "current-{$this->tutorial_key}-step", true );
			?>
			<script type="text/javascript">
			//<![CDATA[
			jQuery(document).ready( function($) {
			<?php
			$count = 0;
			foreach ( $this->page_pointers as $pointer_id => $settings) {
				$count++;
				
				extract( $settings );
				
				//add our tutorial class for styling
				if (empty($args['pointerClass']))
					$args['pointerClass'] = $this->tutorial_key . '-pointer';
				else
					$args['pointerClass'] .= ' ' . $this->tutorial_key . '-pointer';
				
				//add our buttons
				
				//get next link thats on a different page
				$next_link = '';
				$next_pointer = '';
				$next_name = __('Weiter &raquo;', $this->textdomain);
				$last_step = false;
				if ( $count >= count($this->page_pointers) && isset($this->registered_pointers[$pointer_id+1]) ) {
					$next_url = $this->registered_pointers[$pointer_id+1]['url'];
					$next_link = ", function() { window.location = '$next_url'; }";
					$next_title = $this->registered_pointers[$pointer_id+1]['title'];
				} else if ( isset($this->page_pointers[$pointer_id+1]) ) {
					$next_url = $this->registered_pointers[$pointer_id+1]['url'];
					if ( $this->registered_pointers[$pointer_id+1]['url'] != $this->registered_pointers[$pointer_id]['url'] ) {
						$next_link = ", function() { window.location = '$next_url'; }";
					}
					$next_pointer = $this->page_pointers[$pointer_id+1]['selector'];
					$next_pointer_id = $pointer_id + 1;
					$next_pointer = "$('$next_pointer').pointer( options$next_pointer_id ).pointer('open').focus();";
					$next_title = $this->page_pointers[$pointer_id+1]['title'];
				} else {
					$next_name = __('Verwerfen', $this->textdomain);
					$next_title = sprintf(__('Verwirf %s', $this->textdomain), $this->tutorial_name);
					$last_step = true;
				}
				
				$prev_link = '';
				$prev_pointer = '';
				$prev_name = __('&laquo; Zurück', $this->textdomain);
				if ( $count == 1 && isset($this->registered_pointers[$pointer_id-1]) ) { //if first step for the page and theres a previous page
					$prev_url = $this->registered_pointers[$pointer_id-1]['url'];
					$prev_link = ", function() { window.location = '$prev_url'; }";
					$prev_title = $this->registered_pointers[$pointer_id-1]['title'];
				} else if ( isset($this->page_pointers[$pointer_id-1]) ) {
					$prev_pointer = $this->page_pointers[$pointer_id-1]['selector'];
					$prev_pointer_id = $pointer_id - 1;
					$prev_pointer = "$('$prev_pointer').pointer( options$prev_pointer_id ).pointer('open').focus();";
					$prev_title = $this->page_pointers[$pointer_id-1]['title'];
					$prev_url = $this->registered_pointers[$pointer_id-1]['url'];
					if ( $this->registered_pointers[$pointer_id-1]['url'] != $this->registered_pointers[$pointer_id]['url'] ) {
						$prev_link = ", function() { window.location = '$prev_url'; }";
					}
				}
				
				$close_name = __('Verwerfen', $this->textdomain);
				$close_title = sprintf(__('Verwirf %s', $this->textdomain), $this->tutorial_name);
				?>
				/*step <?php echo $pointer_id; ?> pointer<?php if ($pointer_id == $current_step) { ?> (Current)<?php } ?>*/
				var options<?php echo $pointer_id; ?> = <?php echo json_encode( $args ); ?>;
	
				options<?php echo $pointer_id; ?> = $.extend( options<?php echo $pointer_id; ?>, {
					next: function() {
						$.post( ajaxurl, {
							pointer: '<?php echo $pointer_id; ?>',
							step: 'next',
							action: 'dismiss-<?php echo $this->tutorial_key; ?>-pointer'
						}<?php echo $next_link; ?>);
						<?php echo $next_pointer; ?>
					},
					prev: function() {
						$.post( ajaxurl, {
							pointer: '<?php echo $pointer_id; ?>',
							step: 'prev',
							action: 'dismiss-<?php echo $this->tutorial_key; ?>-pointer'
						}<?php echo $prev_link; ?>);
						<?php echo $prev_pointer; ?>
					},
					close: function() {
						$.post( ajaxurl, {
							pointer: '<?php echo $pointer_id; ?>',
							step: 'close',
							action: 'dismiss-<?php echo $this->tutorial_key; ?>-pointer'
						});
					},
					buttons: function( event, t ) {
						var $buttons = $(
							'<div>' +
							<?php if ($pointer_id > 0) { ?>
							'<a class="prev button" href="#" title="<?php echo esc_attr($prev_title); ?>"><?php echo $prev_name; ?></a> ' +
							<?php } ?>
							<?php if (!$last_step && !$this->hide_dismiss) { ?>
							'<a class="dismiss" href="#" title="<?php echo esc_attr($close_title); ?>"><?php echo $close_name; ?></a> ' +
							<?php } ?>
							<?php if (!$this->hide_step) { ?>
							'<span class="tut-step"><?php printf( __('%s: Tipp %d von %d', $this->textdomain), $this->tutorial_name, $pointer_id+1, count($this->registered_pointers) ); ?></span>' +
							<?php } ?>
							'<a class="next button" href="#" title="<?php echo esc_attr($next_title); ?>"><?php echo $next_name; ?></a>' +
							'</div>'
						);
						$buttons.find('.next').on( 'click.pointer', function() {
							t.element.pointer('destroy');
							options<?php echo $pointer_id; ?>.next();
							return false;
						});
						<?php if (!$this->hide_dismiss) { ?>
						$buttons.find('.dismiss').on( 'click.pointer', function() {
							t.element.pointer('destroy');
							options<?php echo $pointer_id; ?>.close();
							return false;
						});
						<?php } ?>
						<?php if ($pointer_id > 0) { ?>
						$buttons.find('.prev').on( 'click.pointer', function() {
							t.element.pointer('destroy');
							options<?php echo $pointer_id; ?>.prev();
							return false;
						});
						<?php } ?>
						return $buttons;
					}
				});
				<?php if ($pointer_id == $current_step) { ?>
				$('<?php echo $selector; ?>').pointer( options<?php echo $pointer_id; ?> ).pointer('open');
				<?php
				}
			}
			
			?>
			});
			//]]>
			</script>
			<?php
		}
	
	}
}