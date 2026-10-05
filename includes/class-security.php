<?php
/**
 * Security helpers: order authorization, receipt authorization, input hardening.
 *
 * Implements IDOR protection. Every customer-facing entry point must route
 * through CTP_Security before touching order data.
 *
 * @package CTP
 */

defined( 'ABSPATH' ) || exit;

final class CTP_Security {

	/**
	 * Read and sanitize the WooCommerce order key from the current request.
	 *
	 * Supports both `order_key` (plugin endpoints) and `key` (WooCommerce
	 * order-received / order-pay pages) parameters.
	 *
	 * @return string Sanitized order key or empty string.
	 */
	public static function get_request_order_key() {
		$raw = '';
		if ( isset( $_REQUEST['order_key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- key is compared, never trusted for authorization alone.
			$raw = wp_unslash( $_REQUEST['order_key'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( isset( $_REQUEST['key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw = wp_unslash( $_REQUEST['key'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		return self::sanitize_order_key( $raw );
	}

	/**
	 * Order keys are alnum + underscore + dash only.
	 *
	 * @param string $key Raw key.
	 * @return string
	 */
	public static function sanitize_order_key( $key ) {
		return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $key );
	}

	/**
	 * Does the current user have store-administration capability?
	 *
	 * @return bool
	 */
	public static function is_store_admin() {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Is the current logged-in user the owner of the order?
	 *
	 * Never assumes the user is logged in (guest checkout support).
	 *
	 * @param WC_Order $order Order object.
	 * @return bool
	 */
	public static function user_owns_order( $order ) {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return false;
		}
		return $user_id === (int) $order->get_customer_id( 'edit' );
	}

	/**
	 * Verify the guest order key against the order.
	 *
	 * @param WC_Order $order Order object.
	 * @param string   $key   Order key.
	 * @return bool
	 */
	public static function order_key_is_valid( $order, $key ) {
		$key = self::sanitize_order_key( $key );
		if ( '' === $key ) {
			return false;
		}
		$expected = (string) $order->get_order_key( 'edit' );
		return '' !== $expected && hash_equals( $expected, $key );
	}

	/**
	 * Master authorization check.
	 *
	 * Grants access when ANY of the following holds:
	 *  - Current user has `manage_woocommerce` capability (store admin).
	 *  - Current logged-in user owns the order (customer_id match).
	 *  - The request carries the order's valid, hash-compared order key
	 *    (WooCommerce's standard mechanism for guest access).
	 *
	 * Never trusts a bare order ID.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool
	 */
	public static function can_access_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}
		if ( self::is_store_admin() ) {
			return true;
		}
		if ( self::user_owns_order( $order ) ) {
			return true;
		}
		$key = self::get_request_order_key();
		if ( $key && self::order_key_is_valid( $order, $key ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Does a receipt attachment belong to the given order?
	 *
	 * Checks the current receipt and the historical receipts list.
	 *
	 * @param WC_Order $order         Order object.
	 * @param int      $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function receipt_belongs_to_order( $order, $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id || ! $order instanceof WC_Order ) {
			return false;
		}
		if ( absint( CTP_Meta::get( $order, CTP_Meta::RECEIPT_ID ) ) === $attachment_id ) {
			return true;
		}
		$history = CTP_Meta::get( $order, CTP_Meta::RECEIPT_HISTORY );
		if ( is_array( $history ) ) {
			foreach ( $history as $entry ) {
				if ( is_array( $entry ) && isset( $entry['attachment_id'] ) && absint( $entry['attachment_id'] ) === $attachment_id ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Can the current visitor view the given receipt of the given order?
	 *
	 * @param WC_Order $order         Order object.
	 * @param int      $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function can_view_receipt( $order, $attachment_id ) {
		if ( ! self::can_access_order( $order ) ) {
			return false;
		}
		return self::receipt_belongs_to_order( $order, $attachment_id );
	}

	/**
	 * Mask a card number for logging purposes: keep only the last 4 digits.
	 *
	 * Full card numbers are never logged.
	 *
	 * @param string $number Card number (digits only).
	 * @return string
	 */
	public static function mask_card_number( $number ) {
		$digits = preg_replace( '/\D/', '', (string) $number );
		$length = strlen( $digits );
		if ( $length <= 4 ) {
			return str_repeat( '*', $length );
		}
		return str_repeat( '*', $length - 4 ) . substr( $digits, -4 );
	}
}
