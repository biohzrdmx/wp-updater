<?php

/**
 * Plugin Name: Updater
 * Description: Manual plugin updater for advanced users
 * Author: biohzrdmx
 * Version: 1.0
 * Plugin URI: http://github.com/biohzrdmx/wp-updater
 * Author URI: http://github.com/biohzrdmx/
 */

	if( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

	if( ! class_exists('Updater') ) {

		/**
		 * Updater class
		 */
		class Updater {

			public static function init() {
				$folder = dirname( plugin_basename(__FILE__) );
				$ret = load_plugin_textdomain('updater', false, "{$folder}/lang");
			}

			public static function actionAdminMenu() {
				add_submenu_page('plugins.php', 'Updater', __('Manual update', 'updater'), 'manage_options', 'updater', 'Updater::callbackAdminPage');
				add_submenu_page(null, 'Updater', __('Manual update', 'updater'), 'manage_options', 'updater-copy', 'Updater::callbackCopyPage');
			}

			public static function actionEnqueueScripts($hook) {
				if (! in_array( $hook, ['plugins_page_updater'] ) ) {
					return;
				}
				wp_enqueue_style( 'updater_admin_css', plugins_url('updater.css', __FILE__) );
				wp_enqueue_script( 'updater_admin_js', plugins_url('updater.js', __FILE__), array('jquery') );
			}

			public static function actionAdminInit() {
				register_setting( 'updater', 'updater_options' );
				add_settings_section( 'updater_settings', __( 'Supported plugins', 'updater' ), function() {
					?>
						<p><?php _e('The Manual update action will be shown for the following plugins:', 'updater'); ?></p>
					<?php
				}, 'updater' );
				$plugins = get_plugins();
				if ($plugins) {
					foreach ($plugins as $plugin) {
						add_settings_field( "updater_field_{$plugin['Name']}", __($plugin['Title'], 'updater'), 'Updater::fieldToggle', 'updater', 'updater_settings', [ 'label_for' => "updater_field_{$plugin['Name']}", 'class' => 'updater_row' ] );
					}
				}
			}

			public static function adminSettingsLink($links, $file) {
				$folder = dirname( plugin_basename(__FILE__) );
				$links = (array) $links;
				if ( $file === "{$folder}/plugin.php" && current_user_can( 'manage_options' ) ) {
					$url = admin_url('admin.php?page=updater');
					$link = sprintf( '<a href="%s">%s</a>', $url, __( 'Settings', 'updater' ) );
					array_unshift($links, $link);
				}
				return $links;
			}

			public static function fieldToggle($args) {
				$options = get_option( 'updater_options' );
				?>
					<input type="checkbox" class="js-toggle-switch" id="<?php echo esc_attr( $args['label_for'] ); ?>" <?php echo ( isset( $options[ $args['label_for'] ] ) ? 'checked="checked"' : '' ); ?> name="updater_options[<?php echo esc_attr( $args['label_for'] ); ?>]" value="1">
				<?php
			}

			public static function callbackAdminPage() {
				if ( ! current_user_can( 'manage_options' ) ) {
					return;
				}
				if ( isset( $_GET['settings-updated'] ) ) {
					add_settings_error( 'updater_messages', 'updater_message', __( 'Settings Saved', 'updater' ), 'updated' );
				}
				settings_errors( 'updater_messages' );
				?>
					<div class="wrap">
						<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
						<form action="options.php" method="post">
							<?php
								settings_fields( 'updater' );
								do_settings_sections( 'updater' );
								submit_button( __('Save Settings', 'updater') );
							?>
						</form>
					</div>
				<?php
			}

			public static function callbackCopyPage() {
				if ( ! current_user_can( 'manage_options' ) ) {
					return;
				}
				$plugin_file = self::getItem( $_GET, 'plugin' );
				$plugin_path = sprintf('%s/%s', WP_PLUGIN_DIR, $plugin_file);
				$data = get_plugin_data( $plugin_path );
				if ($_POST) {
					$file = self::getItem( $_FILES, 'file' );
					$nonce = self::getItem( $_POST, '_wpnonce' );
					if ( wp_verify_nonce($nonce) ) {
						if ( $file && file_exists( $file['tmp_name'] ) ) {
							WP_Filesystem();
							$plugin_zip = $file['tmp_name'];
							include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
							wp_cache_flush();
							$upgrader = new Plugin_Upgrader();
							$check = $upgrader->check_package($plugin_zip);
							if (! is_wp_error($check) ) {
								$installed = $upgrader->install( $plugin_zip, ['overwrite_package' => true] );
								if ( is_wp_error($installed) ) {
									add_settings_error( 'updater_messages', 'updater_message', $installed->get_error_message(), 'error' );
								}
							} else {
								add_settings_error( 'updater_messages', 'updater_message', $check->get_error_message(), 'error' );
							}
						} else {
							add_settings_error( 'updater_messages', 'updater_message', __( 'No file specified', 'updater' ), 'error' );
						}
					} else {
						add_settings_error( 'updater_messages', 'updater_message', __( 'Invalid nonce', 'updater' ), 'error' );
					}
					settings_errors( 'updater_messages' );
					?>
						<div class="wrap">
							<a href="<?php echo esc_html( admin_url('plugins.php') ) ?>"><?php _e('Go back to plugins page') ?></a>
						</div>
					<?php
				} else {
					$bytes = wp_max_upload_size();
					$size = size_format( $bytes );
					?>
						<div class="wrap">
							<h1><?php _e( 'Manual update', 'updater' ); ?></h1>
							<form action="" method="post" enctype="multipart/form-data">
								<h2><?php echo esc_html( $data['Name'] ); ?></h2>
								<p><?php _e( 'Currently at version', 'updater' ) ?> <?php echo esc_html( $data['Version'] ) ?></p>
								<h3><?php _e( 'Upload a ZIP package', 'updater' ) ?></h3>
								<input type="file" name="file" id="file">
								<?php wp_nonce_field() ?>
								<p><?php printf(__('Maximum size: %s'), $size) ?></p>
								<!--  -->
								<p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary" value="<?php _e('Update plugin', 'updater'); ?>"></p>
							</form>
						</div>
					<?php
				}
			}

			public static function actionRowItems($actions, $plugin_file, $plugin_data = [], $context = '') {
				$plugin_path = sprintf('%s/%s', WP_PLUGIN_DIR, $plugin_file);
				$data = get_plugin_data($plugin_path);
				$options = get_option( 'updater_options' );
				$key = "updater_field_{$data['Name']}";
				if ( self::getItem($options, $key) == 1 ) {
					$label = __('Manual update', 'updater');
					$url = admin_url("admin.php?page=updater-copy&amp;plugin={$plugin_file}");
					$action = "<a href=\"{$url}\">{$label}</a>";
					$actions[''] = $action;
				}
				return $actions;
			}

			/**
			 * Get an item from an array/object, or a default value if it's not set
			 * @param  mixed $var      Array or object
			 * @param  mixed $key      Key or index, depending on the array/object
			 * @param  mixed $default  A default value to return if the item it's not in the array/object
			 * @return mixed           The requested item (if present) or the default value
			 */
			protected static function getItem($var, $key, $default = '') {
				return is_object($var) ?
					( isset( $var->$key ) ? $var->$key : $default ) :
					( isset( $var[$key] ) ? $var[$key] : $default );
			}
		}

		add_action( 'init', 'Updater::init' );
		add_action( 'admin_init', 'Updater::actionAdminInit' );
		add_action( 'admin_menu', 'Updater::actionAdminMenu' );
		add_filter( 'plugin_action_links','Updater::actionRowItems', 20, 2 );
		add_action( 'admin_enqueue_scripts', 'Updater::actionEnqueueScripts' );
		add_filter( 'plugin_action_links', 'Updater::adminSettingsLink', 10, 5 );
	}
?>