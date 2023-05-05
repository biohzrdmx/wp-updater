<?php

/**
 * Plugin Name: Updater
 * Description: Manual plugin updater for advanced users
 * Author: biohzrdmx
 * Version: 2.0
 * Plugin URI: http://github.com/biohzrdmx/wp-updater
 * Author URI: http://github.com/biohzrdmx/
 */

	if( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

	if( ! class_exists('Updater') ) {

		# Include Composer autoloader
		require_once dirname(__FILE__) . '/vendor/autoload.php';

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
				add_submenu_page('', 'Updater', __('Manual update', 'updater'), 'manage_options', 'updater-update', 'Updater::callbackUpdatePage');
			}

			public static function actionEnqueueScripts($hook) {
				if (! in_array( $hook, ['plugins_page_updater', 'admin_page_updater-update'] ) ) {
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

			public static function callbackUpdatePage() {
				if ( ! current_user_can( 'manage_options' ) ) {
					return;
				}
				$plugin_file = self::getItem( $_GET, 'plugin' );
				$plugin_path = sprintf('%s/%s', WP_PLUGIN_DIR, $plugin_file);
				$data = get_plugin_data( $plugin_path );
				if ($_POST) {
					$file = self::getItem( $_FILES, 'file' );
					$github = self::getItem( $_POST, 'github' );
					$nonce = self::getItem( $_POST, '_wpnonce' );
					if ( wp_verify_nonce($nonce) ) {
						$plugin_zip = '';
						if ($github && $github['url'] ?? null) {
							$url = $github['url'] ?? '';
							$token = $github['token'] ?? '';
							$cache = ( $github['cache'] ?? 0 ) == 1;
							if ($url) {
								$name = preg_replace('~https?://github.com/~', '', $url);
								$repository = self::apiGetRepository($name, $token);
								if ($repository) {
									$entries = self::apiGetReleases($name, $token);
									if ($entries) {
										$releases = [];
										foreach ($entries as $entry) {
											$timestamp = strtotime($entry->published_at);
											$releases[$timestamp] = (object)[
												'name' => $entry->name,
												'tag' => $entry->tag_name,
												'url' => $entry->zipball_url,
											];
										}
										krsort($releases);
										$latest = array_shift($releases);
										if ($latest) {
											$plugin_zip = self::apiGetZipball(md5($name), $latest->url, $token);
										}
									} else {
										$plugin_zip = self::apiGetZipball(md5($name), "https://api.github.com/repos/{$name}/zipball/master", $token);
									}
									if ( file_exists($plugin_zip) && filesize($plugin_zip) > 0 ) {
										$zip = new ZipArchive();
										$zip->open($plugin_zip);
										$first = $zip->getNameIndex(0);
										$parts = explode('/', $plugin_file);
										$plugin_name = $parts[0] ?? '';
										if ( $plugin_name && str_contains($first, '/') ) {
											$root = substr($first, 0, strpos($first, '/'));
											if ($plugin_name != $root) {
												$index = 0;
												while($item_name = $zip->getNameIndex($index)){
													$renamed = str_replace($root, $plugin_name, $item_name);
													$zip->renameIndex($index, $renamed);
													$index++;
												}
											}
										} else {
											$plugin_zip = '';
										}
										$zip->close();
									}
								}
							}
						} else {
							$plugin_zip = $file ? $file['tmp_name'] : '';
						}
						if ( $plugin_zip && file_exists($plugin_zip) ) {
							WP_Filesystem();
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
						if ($github && !$cache) {
							if ( file_exists($plugin_zip) ) {
								unlink($plugin_zip);
							}
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
							<br>
							<form action="" method="post" enctype="multipart/form-data">
								<div class="box">
									<h3 class="has-icon">
										<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path fill="#03A9F4" d="M512 288c0 35.35-21.49 64-48 64c-32.43 0-31.72-32-55.64-32C394.9 320 384 330.9 384 344.4V480c0 17.67-14.33 32-32 32h-71.64C266.9 512 256 501.1 256 487.6C256 463.1 288 464.4 288 432c0-26.51-28.65-48-64-48s-64 21.49-64 48c0 32.43 32 31.72 32 55.64C192 501.1 181.1 512 167.6 512H32c-17.67 0-32-14.33-32-32v-135.6C0 330.9 10.91 320 24.36 320C48.05 320 47.6 352 80 352C106.5 352 128 323.3 128 288S106.5 223.1 80 223.1c-32.43 0-31.72 32-55.64 32C10.91 255.1 0 245.1 0 231.6v-71.64c0-17.67 14.33-31.1 32-31.1h135.6C181.1 127.1 192 117.1 192 103.6c0-23.69-32-23.24-32-55.64c0-26.51 28.65-47.1 64-47.1s64 21.49 64 47.1c0 32.43-32 31.72-32 55.64c0 13.45 10.91 24.36 24.36 24.36H352c17.67 0 32 14.33 32 31.1v71.64c0 13.45 10.91 24.36 24.36 24.36c23.69 0 23.24-32 55.64-32C490.5 223.1 512 252.7 512 288z"/></svg>
										<span><?php echo esc_html( $data['Name'] ); ?></span>
									</h3>
									<p><?php _e( 'Currently at version', 'updater' ) ?> <?php echo esc_html( $data['Version'] ) ?></p>
								</div>
								<div class="box">
									<h3 class="has-icon">
										<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path fill="#795548" d="M32 432C32 458.5 53.49 480 80 480h352c26.51 0 48-21.49 48-48V160H32V432zM160 236C160 229.4 165.4 224 172 224h168C346.6 224 352 229.4 352 236v8C352 250.6 346.6 256 340 256h-168C165.4 256 160 250.6 160 244V236zM480 32H32C14.31 32 0 46.31 0 64v48C0 120.8 7.188 128 16 128h480C504.8 128 512 120.8 512 112V64C512 46.31 497.7 32 480 32z"/></svg>
										<span><?php _e( 'Upload a ZIP package', 'updater' ) ?></span>
									</h3>
									<input type="file" name="file" id="file">
									<?php wp_nonce_field() ?>
									<p><?php printf(__('Maximum size: %s'), $size) ?></p>
								</div>
								<!--  -->
								<div class="box no-margin">
									<h3 class="has-icon">
										<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 496 512"><path fill="#222" d="M165.9 397.4c0 2-2.3 3.6-5.2 3.6-3.3.3-5.6-1.3-5.6-3.6 0-2 2.3-3.6 5.2-3.6 3-.3 5.6 1.3 5.6 3.6zm-31.1-4.5c-.7 2 1.3 4.3 4.3 4.9 2.6 1 5.6 0 6.2-2s-1.3-4.3-4.3-5.2c-2.6-.7-5.5.3-6.2 2.3zm44.2-1.7c-2.9.7-4.9 2.6-4.6 4.9.3 2 2.9 3.3 5.9 2.6 2.9-.7 4.9-2.6 4.6-4.6-.3-1.9-3-3.2-5.9-2.9zM244.8 8C106.1 8 0 113.3 0 252c0 110.9 69.8 205.8 169.5 239.2 12.8 2.3 17.3-5.6 17.3-12.1 0-6.2-.3-40.4-.3-61.4 0 0-70 15-84.7-29.8 0 0-11.4-29.1-27.8-36.6 0 0-22.9-15.7 1.6-15.4 0 0 24.9 2 38.6 25.8 21.9 38.6 58.6 27.5 72.9 20.9 2.3-16 8.8-27.1 16-33.7-55.9-6.2-112.3-14.3-112.3-110.5 0-27.5 7.6-41.3 23.6-58.9-2.6-6.5-11.1-33.3 2.6-67.9 20.9-6.5 69 27 69 27 20-5.6 41.5-8.5 62.8-8.5s42.8 2.9 62.8 8.5c0 0 48.1-33.6 69-27 13.7 34.7 5.2 61.4 2.6 67.9 16 17.7 25.8 31.5 25.8 58.9 0 96.5-58.9 104.2-114.8 110.5 9.2 7.9 17 22.9 17 46.4 0 33.7-.3 75.4-.3 83.6 0 6.5 4.6 14.4 17.3 12.1C428.2 457.8 496 362.9 496 252 496 113.3 383.5 8 244.8 8zM97.2 352.9c-1.3 1-1 3.3.7 5.2 1.6 1.6 3.9 2.3 5.2 1 1.3-1 1-3.3-.7-5.2-1.6-1.6-3.9-2.3-5.2-1zm-10.8-8.1c-.7 1.3.3 2.9 2.3 3.9 1.6 1 3.6.7 4.3-.7.7-1.3-.3-2.9-2.3-3.9-2-.6-3.6-.3-4.3.7zm32.4 35.6c-1.6 1.3-1 4.3 1.3 6.2 2.3 2.3 5.2 2.6 6.5 1 1.3-1.3.7-4.3-1.3-6.2-2.2-2.3-5.2-2.6-6.5-1zm-11.4-14.7c-1.6 1-1.6 3.6 0 5.9 1.6 2.3 4.3 3.3 5.6 2.3 1.6-1.3 1.6-3.9 0-6.2-1.4-2.3-4-3.3-5.6-2z"/></svg>
										<span><?php _e( 'From a GitHub repository', 'updater' ) ?></span>
									</h3>
									<table class="form-table">
										<tbody>
											<tr>
												<th><label for="github-url"><?php _e( 'Repository URL', 'updater' ) ?></label></th>
												<td><input type="text" name="github[url]" id="github-url" class="regular-text"></td>
											</tr>
											<tr>
												<th><label for="github-token"><?php _e( 'API Token', 'updater' ) ?><sup>+</sup></label></th>
												<td><input type="password" name="github[token]" id="github-token" class="regular-text"></td>
											</tr>
											<tr class="hide">
												<th><label for="github-cache"><?php _e( 'Keep package after install', 'updater' ) ?></label></th>
												<td><input type="checkbox" class="js-toggle-switch" id="github-cache" name="github[cache]" value="1"></td>
											</tr>
										</tbody>
									</table>
									<p><sup>+</sup> <em><?php _e( 'API token required only for private repositories.', 'updater' ) ?></em></p>
								</div>
								<!--  -->
								<p class="submit">
									<a href="<?php echo admin_url('plugins.php') ?>" class="button button-secondary"><?php _e('Back to plugins', 'updater'); ?></a>
									<input type="submit" name="submit" id="submit" class="button button-primary" value="<?php _e('Update plugin', 'updater'); ?>">
								</p>
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
					$url = admin_url("admin.php?page=updater-update&amp;plugin={$plugin_file}");
					$action = "<a href=\"{$url}\">{$label}</a>";
					$actions[''] = $action;
				}
				return $actions;
			}

			/**
			 * Get repository data
			 * @param  string $name  Repository name
			 * @param  string $token Personal access token
			 * @return mixed
			 */
			protected static function apiGetRepository($name, $token = '') {
				$ret = null;
				try {
					$endpoint = sprintf('repos/%s', $name);
					$data = self::apiRequest('GET', $endpoint, [], $token);
					if ($data) {
						$ret = @json_decode($data);
					}
				} catch (Exception $e) {
					// Do nothing
				}
				return $ret;
			}

			/**
			 * Get repository releases
			 * @param  string $name  Repository name
			 * @param  string $token Personal access token
			 * @return mixed
			 */
			protected static function apiGetReleases($name, $token = '') {
				$ret = null;
				try {
					$endpoint = sprintf('repos/%s/releases', $name);
					$data = self::apiRequest('GET', $endpoint, [], $token);
					if ($data) {
						$ret = @json_decode($data);
					}
				} catch (Exception $e) {
					// Do nothing
				}
				return $ret;
			}

			/**
			 * Get repository zipball
			 * @param  string $name  Repository name
			 * @param  string $url   Zipball URL
			 * @param  string $token Personal access token
			 * @return void
			 */
			protected static function apiGetZipball($name, $url, $token = '') {
				try {
					$path = dirname(__FILE__) . "/cache/{$name}.zip";
					if (! file_exists($path) ) {
						$headers = [
							'User-Agent' => 'curl/7.54'
						];
						if ($token) {
							$headers['Authorization'] = "token {$token}";
						}
						$curly = Curly\Curly::newInstance()
							->setMethod('GET')
							->setHeaders($headers)
							->setURL($url)
							->execute();
						# Get response
						$response = $curly->getResponse();
						if ($response) {
							$data = $response->getBody(true);
							if ($response->getStatus() == 302) {
								# Write cache
								$location = $response->getHeader('location');
								self::downloadFile($location, $path, $token);
								return $path;
							} else {
								$status = $response->getStatus();
								$parsed = @json_decode($data);
								throw new RuntimeException( sprintf('An error ocurred: (%s) %s', $status, $parsed ? $parsed->message : $data) );
							}
						} else {
							throw new RuntimeException( $curly->getError() );
						}
					} else {
						return $path;
					}
				} catch (Exception $e) {
					// Do nothing
				}
				return false;
			}

			/**
			 * Download file
			 * @param  string $url         File URL
			 * @param  string $destination File destination path
			 * @param  string $token       Personal access token
			 * @return void
			 */
			protected static function downloadFile($url, $destination, $token = '') {
				$file = fopen($destination, 'wb');
				if ( is_resource($file) ) {
					$headers = [
						'User-Agent' => 'curl/7.54'
					];
					if ($token) {
						$headers['Authorization'] = "token {$token}";
					}
					# Create Curly instance and execute request
					$curly = Curly\Curly::newInstance()
						->setMethod('GET')
						->setResource($file)
						->setHeaders($headers)
						->setURL($url)
						->execute();
					# Get response
					$response = $curly->getResponse();
					# Close the file
					fclose($file);
				} else {
					throw new RuntimeException('Unable to open file');
				}
			}

			/**
			 * Execute API request
			 * @param  string $method   HTTP method
			 * @param  string $endpoint API endpoint
			 * @param  array  $headers  Headers array
			 * @param  string $token    Personal access token
			 * @return string
			 */
			protected static function apiRequest($method, $endpoint, $headers = [], $token = '') {
				$data = '';
				$headers['User-Agent'] = 'curl/7.54';
				if ($token) {
					$headers['Authorization'] = "token {$token}";
				}
				$url = sprintf('https://api.github.com/%s', $endpoint);
				// # Caching stuff
				// $cached = sprintf(dirname(__FILE__) . '/cache/%s.json', md5($url));
				// if ( file_exists($cached) ) {
				// 	# Get cached data
				// 	$data = file_get_contents($cached);
				// } else {
					# Create Curly instance and execute request
					$curly = Curly\Curly::newInstance( dirname(__FILE__) . '/cacert.pem' )
						->setMethod($method)
						->setHeaders($headers)
						->setURL($url)
						->execute();
					# Get response
					$response = $curly->getResponse();
					# Check response
					if ($response) {
						$data = $response->getBody(true);
						if ($response->getStatus() == 200) {
							# Write cache
							// file_put_contents($cached, $data);
						} else {
							$status = $response->getStatus();
							$parsed = @json_decode($data);
							throw new RuntimeException( sprintf('An error ocurred: (%s) %s', $status, $parsed ? $parsed->message : $data) );
						}
					} else {
						throw new RuntimeException( $curly->getError() );
					}
				// }
				return $data;
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