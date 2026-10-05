<?php
/**
 * Compatibility wrapper for theme overrides of checkout/thankyou.php.
 *
 * WooCommerce passes only the `order` variable to this template since
 * WooCommerce 10.4, but many theme overrides still reference the legacy
 * `$order_id` variable near the top of the file, which produces:
 *
 *   Warning: Undefined variable $order_id in
 *   .../themes/<theme>/woocommerce/checkout/thankyou.php on line N
 *
 * This wrapper makes `$order_id` available again (without ever overwriting
 * a value already provided by WooCommerce or another plugin) and then
 * includes the ORIGINAL theme override in the same variable scope, so the
 * theme keeps rendering with all of its own hooks, styles and behaviour.
 *
 * This file is NOT an overridable template: it is reached only through the
 * CTP_Template_Compat::maybe_wrap_thankyou() filter and never wraps
 * WooCommerce core or plugin templates.
 *
 * @package CTP
 */

defined( 'ABSPATH' ) || exit;

// Legacy variable used by older theme overrides of this template.
if ( ! isset( $order_id ) ) {
	$order_id = 0;
	if ( isset( $order ) && is_object( $order ) && is_callable( array( $order, 'get_id' ) ) ) {
		$order_id = (int) $order->get_id();
	}
}

// Render the original theme override in this same variable scope.
$ctp_compat_original = CTP_Template_Compat::get_wrapped_path();
if ( is_string( $ctp_compat_original ) && '' !== $ctp_compat_original && file_exists( $ctp_compat_original ) ) {
	include $ctp_compat_original;
}
