<?php
/**
 * Main plugin bootstrap: registers the gateway, wires assets and services.
 *
 * @package CTP
 */

defined( 'ABSPATH' ) || exit;

final class CTP_Plugin {

        /**
         * Singleton instance.
         *
         * @var CTP_Plugin|null
         */
        private static $instance = null;

        /**
         * Current plugin instance services.
         *
         * @var CTP_Order
         */
        public $order;

        /**
         * Receipt service.
         *
         * @var CTP_Receipt
         */
        public $receipt;

        /**
         * Dedicated payment page service.
         *
         * @var CTP_Pay_Page
         */
        public $pay_page;

        /**
         * Admin service.
         *
         * @var CTP_Admin|null
         */
        public $admin;

        /**
         * Get the singleton instance.
         *
         * @return CTP_Plugin
         */
        public static function instance() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }
                return self::$instance;
        }

        /**
         * Wire everything up.
         */
        private function __construct() {
                $this->order    = new CTP_Order();
                $this->receipt  = new CTP_Receipt();
                $this->pay_page = new CTP_Pay_Page();

                if ( is_admin() || wp_doing_ajax() ) {
                        $this->admin = new CTP_Admin();
                }

                // NOTE: This plugin intentionally does NOT send emails and does
                // NOT override WooCommerce's email system. Order/payment
                // notifications are the job of WooCommerce core and other
                // plugins (e.g. SMS/mail managers), which can hook the public
                // `ctp_order_created`, `ctp_receipt_submitted`,
                // `ctp_receipt_rejected` and `ctp_payment_verified` actions.

                add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
                add_action( 'wp_enqueue_scripts', array( $this, 'frontend_assets' ) );
                add_action( 'init', array( $this, 'maybe_hide_receipt_attachments' ), 99 );
        }

        /**
         * Register the gateway with WooCommerce.
         *
         * @param array $gateways Registered gateways.
         * @return array
         */
        public function register_gateway( $gateways ) {
                $gateways[] = 'CTP_Gateway';
                return $gateways;
        }

        /**
         * Prevent direct attachment URL access from non-privileged users where
         * the theme/plugin would otherwise expose private receipts (defense in
         * depth; files also live in a .htaccess-protected folder).
         *
         * @return void
         */
        public function maybe_hide_receipt_attachments() {
                // Receipt attachments get the `ctp_receipt` role so they can be
                // filtered; the secure endpoint is the canonical access path.
                add_filter( 'ajax_query_attachments_args', array( $this, 'filter_media_library' ) );
                add_action( 'pre_get_posts', array( $this, 'filter_media_library_query' ) );
        }

        /**
         * Keep receipt attachments out of unrelated media library queries for
         * non-admin users.
         *
         * @param array $args Query args.
         * @return array
         */
        public function filter_media_library( $args ) {
                if ( CTP_Security::is_store_admin() ) {
                        return $args;
                }
                $args['meta_query'] = isset( $args['meta_query'] ) ? $args['meta_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                $args['meta_query'][] = array(
                        'key'     => '_ctp_is_payment_receipt',
                        'compare' => 'NOT EXISTS',
                );
                return $args;
        }

        /**
         * Same filter for regular media queries.
         *
         * @param WP_Query $query Query.
         * @return void
         */
        public function filter_media_library_query( $query ) {
                if ( is_admin() && $query->get( 'post_type' ) === 'attachment' && ! CTP_Security::is_store_admin() && ! wp_doing_ajax() ) {
                        $meta_query = $query->get( 'meta_query' );
                        $meta_query = is_array( $meta_query ) ? $meta_query : array();
                        $meta_query[] = array(
                                'key'     => '_ctp_is_payment_receipt',
                                'compare' => 'NOT EXISTS',
                        );
                        $query->set( 'meta_query', $meta_query );
                }
        }

        /**
         * Enqueue frontend assets on relevant WooCommerce pages.
         *
         * @return void
         */
        public function frontend_assets() {
                $relevant = is_checkout() || is_order_received_page() || is_account_page()
                        || function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'view-order' ) )
                        || class_exists( 'CTP_Pay_Page' ) && get_query_var( CTP_Pay_Page::QUERY_VAR );

                if ( ! $relevant ) {
                        return;
                }

                wp_enqueue_style( 'ctp-frontend', CTP_PLUGIN_URL . 'public/css/frontend.css', array(), CTP_VERSION );

                wp_register_script( 'ctp-frontend', CTP_PLUGIN_URL . 'public/js/frontend.js', array(), CTP_VERSION, true );
                wp_localize_script(
                        'ctp-frontend',
                        'ctpSettings',
                        array(
                                'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
                                'nonce'         => wp_create_nonce( 'ctp_public' ),
                                'maxFileSizeMb' => (int) round( CTP_Gateway::get_max_file_size_bytes() / 1048576 ),
                                'i18n'          => array(
                                        'copy'        => __( 'Copy', 'card-to-card-payment' ),
                                        'copied'      => __( 'Copied!', 'card-to-card-payment' ),
                                        'copyFailed'  => __( 'Copying failed. Please copy the number manually.', 'card-to-card-payment' ),
                                        'chooseFile'  => __( 'Please choose a file first.', 'card-to-card-payment' ),
                                        'uploading'   => __( 'Uploading…', 'card-to-card-payment' ),
                                        'uploadFailed'=> __( 'Upload failed. Please try again.', 'card-to-card-payment' ),
                                        'fileTooLarge'=> __( 'The selected file is too large.', 'card-to-card-payment' ),
                                        'invalidType' => __( 'This file type is not allowed. Please upload a JPG, JPEG, PNG or WEBP image.', 'card-to-card-payment' ),
                                        'fileSelected'=> __( 'Selected file:', 'card-to-card-payment' ),
                                        'uploadedLabel'=> __( 'Uploaded:', 'card-to-card-payment' ),
                                        'viewFull'    => __( 'View full size', 'card-to-card-payment' ),
                                        'receiptAlt'  => __( 'Uploaded payment receipt', 'card-to-card-payment' ),
                                        'redirecting' => __( 'Redirecting to your order…', 'card-to-card-payment' ),
                                ),
                        )
                );
                wp_enqueue_script( 'ctp-frontend' );
        }
}
