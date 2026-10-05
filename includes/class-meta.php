<?php
/**
 * Centralized metadata keys and payment status definitions.
 *
 * @package CTP
 */

defined( 'ABSPATH' ) || exit;

/**
 * All order metadata keys used by the plugin live here so they are never
 * scattered across the code base.
 */
final class CTP_Meta {

        /** Payment status meta key (waiting / receipt_submitted / verified / rejected). */
        const STATUS = '_card_to_card_payment_status';

        /** Current receipt attachment ID. */
        const RECEIPT_ID = '_card_to_card_receipt_id';

        /** GMT timestamp (mysql) of the latest receipt upload. */
        const RECEIPT_UPLOADED_AT = '_card_to_card_receipt_uploaded_at';

        /** GMT timestamp (mysql) of the last rejection. */
        const RECEIPT_REJECTED_AT = '_card_to_card_receipt_rejected_at';

        /** Admin-entered rejection reason. */
        const RECEIPT_REJECTION_REASON = '_card_to_card_receipt_rejection_reason';

        /** GMT timestamp (mysql) when an admin verified the payment. */
        const VERIFIED_AT = '_card_to_card_payment_verified_at';

        /** WordPress user ID of the administrator who verified the payment. */
        const VERIFIED_BY = '_card_to_card_payment_verified_by';

        /** Array of previous receipts (full payment history). */
        const RECEIPT_HISTORY = '_card_to_card_receipt_history';

        /**
         * Read an order meta value using the WooCommerce CRUD API (HPOS safe).
         *
         * @param WC_Order $order Order object.
         * @param string   $key   Meta key.
         * @return mixed
         */
        public static function get( $order, $key ) {
                return $order->get_meta( $key, true );
        }

        /**
         * Update an order meta value using the WooCommerce CRUD API (HPOS safe).
         *
         * @param WC_Order $order Order object.
         * @param string   $key   Meta key.
         * @param mixed    $value Meta value.
         * @return void
         */
        public static function set( $order, $key, $value ) {
                if ( null === $value ) {
                        $order->delete_meta_data( $key );
                } else {
                        $order->update_meta_data( $key, $value );
                }
        }
}

/**
 * Payment status constants and customer-facing labels.
 */
final class CTP_Status {

        const WAITING           = 'waiting';
        const RECEIPT_SUBMITTED = 'receipt_submitted';
        const VERIFIED          = 'verified';
        const REJECTED          = 'rejected';

        /**
         * All valid statuses.
         *
         * @return string[]
         */
        public static function all() {
                return array( self::WAITING, self::RECEIPT_SUBMITTED, self::VERIFIED, self::REJECTED );
        }

        /**
         * Human readable, translatable labels keyed by status.
         *
         * @return array<string,string>
         */
        public static function labels() {
                return array(
                        self::WAITING           => __( 'Waiting for payment', 'card-to-card-payment' ),
                        self::RECEIPT_SUBMITTED => __( 'Receipt submitted and awaiting review', 'card-to-card-payment' ),
                        self::VERIFIED          => __( 'Payment verified', 'card-to-card-payment' ),
                        self::REJECTED          => __( 'Receipt rejected', 'card-to-card-payment' ),
                );
        }

        /**
         * Label for a single status.
         *
         * @param string $status Status key.
         * @return string
         */
        public static function label( $status ) {
                $labels = self::labels();
                return isset( $labels[ $status ] ) ? $labels[ $status ] : $labels[ self::WAITING ];
        }

        /**
         * CSS modifier class for a status (safe slug).
         *
         * @param string $status Status key.
         * @return string
         */
        public static function css_class( $status ) {
                $status = (string) $status;
                return in_array( $status, self::all(), true ) ? $status : self::WAITING;
        }
}
