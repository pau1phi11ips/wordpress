<?php
/**
 * Plausible Analytics | Admin Actions.
 * @since      1.0.0
 * @package    WordPress
 * @subpackage Plausible Analytics
 */

namespace Plausible\Analytics\WP\Admin;

use Plausible\Analytics\WP\Includes\Helpers;

// Bailout, if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Actions {
	/**
	 * Constructor.
	 * @since  1.0.0
	 * @access public
	 * @return void
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', [ $this, 'register_assets' ] );
		add_action( 'wp_ajax_plausible_analytics_notice_dismissed', [ $this, 'dismiss_notice' ] );
		add_action( 'wp_ajax_plausible_analytics_toggle_option', [ $this, 'toggle_option' ] );
		add_action( 'wp_ajax_plausible_analytics_save_options', [ $this, 'save_options' ] );
	}

	/**
	 * Register Assets.
	 * @since  1.0.0
	 * @since  1.3.0 Don't load CSS admin-wide. JS needs to load admin-wide, since we're throwing admin-wide, dismissable notices.
	 * @access public
	 * @return void
	 */
	public function register_assets( $current_page ) {
		if ( $current_page === 'settings_page_plausible_analytics' || $current_page === 'dashboard_page_plausible_analytics_statistics' ) {
			\wp_enqueue_style(
				'plausible-admin',
				PLAUSIBLE_ANALYTICS_PLUGIN_URL . 'assets/dist/css/plausible-admin.css',
				'',
				filemtime( PLAUSIBLE_ANALYTICS_PLUGIN_DIR . 'assets/dist/css/plausible-admin.css' ),
				'all'
			);
		}

		\wp_enqueue_script(
			'plausible-admin',
			PLAUSIBLE_ANALYTICS_PLUGIN_URL . 'assets/dist/js/plausible-admin.js',
			'',
			filemtime( PLAUSIBLE_ANALYTICS_PLUGIN_DIR . 'assets/dist/js/plausible-admin.js' ),
			true
		);
	}

	/**
	 * Marks a notice as dismissed.
	 * @since v2.0.0 This logic has been revised to generate transients for each available notice separately.
	 * @return void
	 */
	public function dismiss_notice() {
		$id = sanitize_key( $_POST[ 'id' ] );

		set_transient( str_replace( '-', '_', $id ) . '_notice_dismissed', true );
	}

	/**
	 * Save Admin Settings
	 * @since 1.0.0
	 * @return void
	 */
	public function toggle_option() {
		// Sanitize all the post data before using.
		$post_data = $this->clean( $_POST );
		$settings  = Helpers::get_settings();

		if ( $post_data[ 'action' ] !== 'plausible_analytics_toggle_option' ||
			! current_user_can( 'manage_options' ) ||
			wp_verify_nonce( $post_data[ '_nonce' ], 'plausible_analytics_toggle_option' ) < 1 ) {
			wp_send_json_error( __( 'Not allowed.', 'plausible-analytics' ), 403 );
		}

		if ( $post_data[ 'is_list' ] ) {
			/**
			 * Toggle lists.
			 */
			if ( $post_data[ 'toggle_status' ] === 'on' ) {
				if ( ! in_array( $post_data[ 'option_value' ], $settings[ $post_data[ 'option_name' ] ] ) ) {
					$settings[ $post_data[ 'option_name' ] ][] = $post_data[ 'option_value' ];
				}
			} else {
				if ( ( $key = array_search( $post_data[ 'option_value' ], $settings[ $post_data[ 'option_name' ] ] ) ) !== false ) {
					unset( $settings[ $post_data[ 'option_name' ] ][ $key ] );
				}
			}
		} else {
			/**
			 * Single toggles.
			 */
			$settings[ $post_data[ 'option_name' ] ] = $post_data[ 'toggle_status' ];
		}

		// Update all the options to plausible settings.
		update_option( 'plausible_analytics_settings', $settings );

		do_action( 'plausible_analytics_settings_saved' );

		$toggle_status = $post_data[ 'toggle_status' ] === 'on' ? __( 'enabled', 'plausible-analytics' ) : __( 'disabled', 'plausible-analytics' );

		wp_send_json_success( sprintf( '%s %s.', $post_data[ 'option_label' ], $toggle_status ), 200 );
	}

	/**
	 * Clean variables using `sanitize_text_field`.
	 * Arrays are cleaned recursively. Non-scalar values are ignored.
	 * @since  1.3.0
	 * @access public
	 *
	 * @param string|array $var Sanitize the variable.
	 *
	 * @return string|array
	 */
	private function clean( $var ) {
		// If the variable is an array, recursively apply the function to each element of the array.
		if ( is_array( $var ) ) {
			return array_map( [ $this, 'clean' ], $var );
		}

		// If the variable is a scalar value (string, integer, float, boolean).
		if ( is_scalar( $var ) ) {
			// Parse the variable using the wp_parse_url function.
			$parsed = wp_parse_url( $var );
			// If the variable has a scheme (e.g. http:// or https://), sanitize the variable using the esc_url_raw function.
			if ( isset( $parsed[ 'scheme' ] ) ) {
				return esc_url_raw( wp_unslash( $var ), [ $parsed[ 'scheme' ] ] );
			}

			// If the variable does not have a scheme, sanitize the variable using the sanitize_text_field function.
			return sanitize_text_field( wp_unslash( $var ) );
		}

		// If the variable is not an array or a scalar value, return the variable unchanged.
		return $var;
	}

	/**
	 * Save Options
	 * @return void
	 */
	public function save_options() {
		// Sanitize all the post data before using.
		$post_data = $this->clean( $_POST );
		$settings  = Helpers::get_settings();

		if ( $post_data[ 'action' ] !== 'plausible_analytics_save_options' ||
			! current_user_can( 'manage_options' ) ||
			wp_verify_nonce( $post_data[ '_nonce' ], 'plausible_analytics_toggle_option' ) < 1 ) {
			return;
		}

		$options = json_decode( $post_data[ 'options' ] );

		if ( empty( $options ) ) {
			return;
		}

		foreach ( $options as $option ) {
			$settings[ $option->name ] = $option->value;
		}

		update_option( 'plausible_analytics_settings', $settings );

		wp_send_json_success( __( 'Settings saved.', 'plausible-analytics' ), 200 );
	}

	/**
	 * Get all contants starting with NOTICE_ from the Notice class.
	 * This creates a unified way to deal with notice dismissal.
	 * @since 2.0.0
	 * @return array
	 */
	private function get_all_notices() {
		$reflection = new \ReflectionClass( new Notice() );
		$constants  = $reflection->getConstants();

		return array_filter(
			$constants,
			function ( $key ) {
				return strpos( $key, 'NOTICE_' ) !== false;
			},
			ARRAY_FILTER_USE_KEY
		);
	}
}
