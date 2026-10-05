<?php
/**
 * Template compatibility layer for the order-received (thank you) page.
 *
 * Since WooCommerce 10.4 the checkout/thankyou.php template receives ONLY
 * the `order` variable. Themes whose override still references the legacy
 * `$order_id` variable produce:
 *
 *   Warning: Undefined variable $order_id in
 *   .../themes/<theme>/woocommerce/checkout/thankyou.php on line N
 *
 * This compat layer routes THEME overrides of checkout/thankyou.php through
 * a tiny wrapper file that defines `$order_id` (never overwriting a value
 * provided by WooCommerce itself) and then includes the original theme
 * override in the same variable scope, so the theme keeps rendering exactly
 * as it would with the variable present.
 *
 * Core WooCommerce templates and plugin templates are never wrapped, and
 * the wrapper only activates when the active (child or parent) theme
 * actually overrides the template.
 *
 * @package CTP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Template compatibility helpers.
 */
final class CTP_Template_Compat {

	/**
	 * Absolute path to the original template replaced by the wrapper.
	 *
	 * Read by templates/thankyou-compat.php at include time.
	 *
	 * @var string
	 */
	private static $wrapped_path = '';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'wc_get_template', array( __CLASS__, 'maybe_wrap_thankyou' ), 99, 3 );
	}

	/**
	 * Path the compat wrapper should include (the original theme override).
	 *
	 * @return string
	 */
	public static function get_wrapped_path() {
		return self::$wrapped_path;
	}

	/**
	 * Route theme overrides of checkout/thankyou.php through the wrapper.
	 *
	 * Registered late (priority 99) so plugins that intentionally replace
	 * the template run first; we only wrap the final result when it points
	 * to a file inside the active theme.
	 *
	 * @param string $located       Located template path.
	 * @param string $template_name Template name.
	 * @param array  $args          Template arguments (unused).
	 * @return string
	 */
	public static function maybe_wrap_thankyou( $located, $template_name, $args = array() ) {
		unset( $args );

		if ( 'checkout/thankyou.php' !== $template_name || ! is_string( $located ) || '' === $located ) {
			return $located;
		}

		if ( ! self::is_theme_file( $located ) ) {
			return $located;
		}

		self::$wrapped_path = $located;

		return CTP_PLUGIN_DIR . 'templates/thankyou-compat.php';
	}

	/**
	 * Is the located template a file inside the active (child or parent) theme?
	 *
	 * @param string $path Template path.
	 * @return bool
	 */
	private static function is_theme_file( $path ) {
		$path = wp_normalize_path( (string) $path );

		$theme_dirs = array_unique(
			array(
				wp_normalize_path( trailingslashit( get_stylesheet_directory() ) ),
				wp_normalize_path( trailingslashit( get_template_directory() ) ),
			)
		);

		foreach ( $theme_dirs as $dir ) {
			if ( 0 === strpos( $path, $dir ) ) {
				return true;
			}
		}

		return false;
	}
}
