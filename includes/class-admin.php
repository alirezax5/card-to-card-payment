<?php
/**
 * Admin UI: gateway assets, cards repeater enqueues, order metabox, AJAX
 * verify/reject/delete actions, payment-status orders column.
 *
 * @package CTP
 */

defined( 'ABSPATH' ) || exit;

class CTP_Admin {

        /**
         * Register hooks.
         */
        public function __construct() {
                add_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );
                add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
                add_action( 'admin_post_ctp_receipt', array( $this, 'noop' ), 5 ); // placeholder to keep signature stable.

                add_action( 'wp_ajax_ctp_admin_verify', array( $this, 'ajax_verify' ) );
                add_action( 'wp_ajax_ctp_admin_reject', array( $this, 'ajax_reject' ) );
                add_action( 'wp_ajax_ctp_admin_delete_receipt', array( $this, 'ajax_delete_receipt' ) );

                // Payment status column on the orders list (classic + HPOS screens).
                add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_orders_column' ), 20 );
                add_action( 'manage_edit-shop_order_custom_column', array( $this, 'render_orders_column' ), 20, 2 );
                add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_orders_column' ), 20 );
                add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_orders_column' ), 20, 2 );
        }

        /**
         * No-op placeholder (the real endpoint is registered by CTP_Receipt).
         *
         * @return void
         */
        public function noop() {
                // Intentionally empty.
        }

        /**
         * Enqueue admin CSS/JS on relevant screens only.
         *
         * @param string $hook Current admin page hook.
         * @return void
         */
        public function enqueue_assets( $hook ) {
                $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
                $screen_id = $screen ? $screen->id : $hook;

                $is_settings  = 'woocommerce_page_wc-settings' === $screen_id;
                $is_order_edit = ( false !== strpos( (string) $screen_id, 'shop_order' ) ) || ( false !== strpos( (string) $screen_id, 'wc-orders' ) );
                $is_orders_list = $is_order_edit && ! isset( $_GET['post'] ) && ! isset( $_GET['id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

                if ( ! $is_settings && ! $is_order_edit ) {
                        return;
                }

                wp_enqueue_style( 'ctp-admin', CTP_PLUGIN_URL . 'admin/css/admin.css', array(), CTP_VERSION );

                wp_register_script( 'ctp-admin', CTP_PLUGIN_URL . 'admin/js/admin.js', array(), CTP_VERSION, true );
                wp_localize_script(
                        'ctp-admin',
                        'ctpAdmin',
                        array(
                                'i18n'   => array(
                                        'confirmRemove'      => __( 'Remove this card?', 'card-to-card-payment' ),
                                        'confirmVerify'      => __( 'Verify this card-to-card payment and mark the order as paid?', 'card-to-card-payment' ),
                                        'confirmDelete'      => __( 'Remove this receipt? The order will return to waiting for a new receipt.', 'card-to-card-payment' ),
                                        'reasonRequired'     => __( 'Please enter the rejection reason shown to the customer.', 'card-to-card-payment' ),
                                        'saved'              => __( 'Saved.', 'card-to-card-payment' ),
                                        'actionFailed'       => __( 'The action failed. Please refresh the page and try again.', 'card-to-card-payment' ),
                                        'securityFailed'     => __( 'Security check failed. Please refresh the page and try again.', 'card-to-card-payment' ),
                                        'notAllowed'         => __( 'You are not allowed to perform this action.', 'card-to-card-payment' ),
                                        'reloading'          => __( 'Done. Reloading…', 'card-to-card-payment' ),
                                        'cardNumber'         => __( 'Card number', 'card-to-card-payment' ),
                                        'firstName'          => __( 'First name', 'card-to-card-payment' ),
                                        'lastName'           => __( 'Last name', 'card-to-card-payment' ),
                                        'enabled'            => __( 'Enabled', 'card-to-card-payment' ),
                                        'invalidNumber'      => __( 'Warning: card numbers usually have 16 digits.', 'card-to-card-payment' ),
                                ),
                        )
                );
                wp_enqueue_script( 'ctp-admin' );
        }

        /**
         * Register the Card-to-Card metabox on the order edit screen.
         *
         * @return void
         */
        public function register_metabox() {
                $screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
                $screens = array_unique( array_filter( array( 'shop_order', $screen ) ) );

                foreach ( $screens as $s ) {
                        add_meta_box(
                                'ctp-payment-box',
                                __( 'Card-to-Card Payment', 'card-to-card-payment' ),
                                array( $this, 'render_metabox' ),
                                $s,
                                'normal',
                                'high'
                        );
                }
        }

        /**
         * Render the metabox.
         *
         * @param mixed $post_or_order WP_Post or WC_Order (HPOS passes an order-like object).
         * @return void
         */
        public function render_metabox( $post_or_order ) {
                $order = ( $post_or_order instanceof WP_Post ) ? wc_get_order( $post_or_order->ID ) : ( $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->get_id() ) );

                if ( ! $order instanceof WC_Order || CTP_GATEWAY_ID !== $order->get_payment_method( 'edit' ) ) {
                        return;
                }

                $order_id    = $order->get_id();
                $status      = (string) CTP_Meta::get( $order, CTP_Meta::STATUS );
                $status      = '' === $status ? CTP_Status::WAITING : $status;
                $receipt_id  = absint( CTP_Meta::get( $order, CTP_Meta::RECEIPT_ID ) );
                $uploaded    = (string) CTP_Meta::get( $order, CTP_Meta::RECEIPT_UPLOADED_AT );
                $rejection   = (string) CTP_Meta::get( $order, CTP_Meta::RECEIPT_REJECTION_REASON );
                $rejected_at = (string) CTP_Meta::get( $order, CTP_Meta::RECEIPT_REJECTED_AT );
                $verified_at = (string) CTP_Meta::get( $order, CTP_Meta::VERIFIED_AT );
                $verified_by = absint( CTP_Meta::get( $order, CTP_Meta::VERIFIED_BY ) );
                $history     = CTP_Meta::get( $order, CTP_Meta::RECEIPT_HISTORY );
                $history     = is_array( $history ) ? $history : array();
                $nonce       = wp_create_nonce( 'ctp_admin_' . $order_id );
                ?>
                <div class="ctp-metabox" data-order-id="<?php echo esc_attr( $order_id ); ?>">
                        <input type="hidden" class="ctp-metabox__nonce" value="<?php echo esc_attr( $nonce ); ?>" />

                        <p class="ctp-metabox__status ctp-status-pill ctp-status-pill--<?php echo esc_attr( CTP_Status::css_class( $status ) ); ?>" data-ctp-admin-status>
                                <span class="ctp-status-pill__label"><?php echo esc_html__( 'Payment Status:', 'card-to-card-payment' ); ?></span>
                                <strong><?php echo esc_html( CTP_Status::label( $status ) ); ?></strong>
                        </p>

                        <?php if ( CTP_Status::VERIFIED === $status && $verified_at ) : ?>
                                <p class="ctp-metabox__verified">
                                        <?php
                                        echo esc_html(
                                                sprintf(
                                                        /* translators: 1: verification date, 2: admin user login. */
                                                        __( 'Verified on %1$s by %2$s.', 'card-to-card-payment' ),
                                                        CTP_Order::format_meta_datetime( $verified_at ),
                                                        $verified_by ? ( get_the_author_meta( 'user_login', $verified_by ) ? get_the_author_meta( 'user_login', $verified_by ) : 'admin' ) : 'admin'
                                                )
                                        );
                                        ?>
                                </p>
                        <?php endif; ?>

                        <?php if ( CTP_Status::REJECTED === $status ) : ?>
                                <p class="ctp-metabox__rejection">
                                        <strong><?php echo esc_html__( 'Rejected:', 'card-to-card-payment' ); ?></strong>
                                        <?php if ( $rejected_at ) : ?>
                                                <?php echo esc_html( CTP_Order::format_meta_datetime( $rejected_at ) ); ?> &mdash;
                                        <?php endif; ?>
                                        <?php echo '' !== $rejection ? esc_html( $rejection ) : esc_html__( '(no reason given)', 'card-to-card-payment' ); ?>
                                </p>
                        <?php endif; ?>

                        <?php if ( $receipt_id ) : ?>
                                <div class="ctp-metabox__receipt">
                                        <p class="ctp-metabox__receipt-label"><strong><?php echo esc_html__( 'Payment Receipt:', 'card-to-card-payment' ); ?></strong></p>
                                        <a href="<?php echo esc_url( CTP_Receipt::get_receipt_url( $order, $receipt_id ) ); ?>" target="_blank" rel="noopener">
                                                <img
                                                        class="ctp-metabox__thumb"
                                                        src="<?php echo esc_url( CTP_Receipt::get_receipt_url( $order, $receipt_id ) ); ?>"
                                                        width="200"
                                                        alt="<?php echo esc_attr__( 'Payment receipt thumbnail', 'card-to-card-payment' ); ?>"
                                                />
                                        </a>
                                        <?php if ( $uploaded ) : ?>
                                                <p class="ctp-metabox__uploaded">
                                                        <?php
                                                        echo esc_html(
                                                                sprintf(
                                                                        /* translators: %s: upload date. */
                                                                        __( 'Uploaded: %s', 'card-to-card-payment' ),
                                                                        CTP_Order::format_meta_datetime( $uploaded )
                                                                )
                                                        );
                                                        ?>
                                                </p>
                                        <?php endif; ?>
                                        <p class="ctp-metabox__links">
                                                <a class="button" href="<?php echo esc_url( CTP_Receipt::get_receipt_url( $order, $receipt_id ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html__( 'View full size', 'card-to-card-payment' ); ?></a>
                                        </p>
                                </div>
                        <?php else : ?>
                                <p class="ctp-metabox__noreceipt"><?php echo esc_html__( 'No receipt has been uploaded yet.', 'card-to-card-payment' ); ?></p>
                        <?php endif; ?>

                        <?php if ( ! empty( $history ) ) : ?>
                                <p class="ctp-metabox__history">
                                        <?php
                                        echo esc_html(
                                                sprintf(
                                                        /* translators: %d: number of previous receipts. */
                                                        __( 'Receipt history: %d previous receipt(s) are preserved on this order.', 'card-to-card-payment' ),
                                                        count( $history )
                                                )
                                        );
                                        ?>
                                </p>
                        <?php endif; ?>

                        <div class="ctp-metabox__actions">
                                <button type="button" class="button button-primary ctp-action-verify" data-order-id="<?php echo esc_attr( $order_id ); ?>">
                                        <?php echo esc_html__( 'Verify Payment', 'card-to-card-payment' ); ?>
                                </button>
                                <button type="button" class="button ctp-action-reject" data-order-id="<?php echo esc_attr( $order_id ); ?>">
                                        <?php echo esc_html__( 'Reject Receipt', 'card-to-card-payment' ); ?>
                                </button>
                                <?php if ( $receipt_id ) : ?>
                                        <button type="button" class="button ctp-action-delete" data-order-id="<?php echo esc_attr( $order_id ); ?>">
                                                <?php echo esc_html__( 'Remove Receipt', 'card-to-card-payment' ); ?>
                                        </button>
                                <?php endif; ?>
                        </div>

                        <div class="ctp-reject-dialog" hidden>
                                <p class="ctp-reject-dialog__title"><?php echo esc_html__( 'Reject receipt', 'card-to-card-payment' ); ?></p>
                                <label for="ctp-reject-reason-<?php echo esc_attr( $order_id ); ?>"><?php echo esc_html__( 'Rejection reason (shown to the customer):', 'card-to-card-payment' ); ?></label>
                                <textarea id="ctp-reject-reason-<?php echo esc_attr( $order_id ); ?>" class="ctp-reject-dialog__reason" rows="3"></textarea>
                                <p class="ctp-reject-dialog__actions">
                                        <button type="button" class="button button-primary ctp-reject-confirm"><?php echo esc_html__( 'Confirm rejection', 'card-to-card-payment' ); ?></button>
                                        <button type="button" class="button ctp-reject-cancel"><?php echo esc_html__( 'Cancel', 'card-to-card-payment' ); ?></button>
                                </p>
                        </div>

                        <p class="ctp-metabox__feedback" data-ctp-admin-feedback role="status" aria-live="polite"></p>
                </div>
                <?php
        }

        /**
         * Add a payment-status column to the orders list.
         *
         * @param array $columns Columns.
         * @return array
         */
        public function add_orders_column( $columns ) {
                $columns['ctp_payment_status'] = __( 'Card-to-Card Status', 'card-to-card-payment' );
                return $columns;
        }

        /**
         * Render the payment-status column.
         *
         * WooCommerce's HPOS order list table passes the WC_Order OBJECT as
         * the second argument, while the classic posts list passes the post
         * ID. Both shapes are accepted; an order object must never be cast
         * to an integer.
         *
         * @param string $column     Column key.
         * @param mixed  $order_or_id WC_Order object (HPOS) or order/post ID (classic).
         * @return void
         */
        public function render_orders_column( $column, $order_or_id ) {
                if ( 'ctp_payment_status' !== $column ) {
                        return;
                }

                if ( is_object( $order_or_id ) && $order_or_id instanceof WC_Order ) {
                        $order = $order_or_id;
                } else {
                        $order_id = absint( $order_or_id );
                        $order    = $order_id ? wc_get_order( $order_id ) : false;
                }

                if ( ! $order instanceof WC_Order || CTP_GATEWAY_ID !== $order->get_payment_method( 'edit' ) ) {
                        echo '&mdash;';
                        return;
                }
                $status = (string) CTP_Meta::get( $order, CTP_Meta::STATUS );
                $status = '' === $status ? CTP_Status::WAITING : $status;
                printf(
                        '<span class="ctp-status-pill ctp-status-pill--%1$s">%2$s</span>',
                        esc_attr( CTP_Status::css_class( $status ) ),
                        esc_html( CTP_Status::label( $status ) )
                );
        }

        /**
         * Guard an admin AJAX request: capability + per-order nonce.
         *
         * @return WC_Order|void Sends JSON error and exits on failure.
         */
        protected function guard_admin_ajax() {
                $order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
                $order    = $order_id ? wc_get_order( $order_id ) : false;

                if ( ! $order instanceof WC_Order ) {
                        wp_send_json_error(
                                array( 'message' => __( 'The order could not be found.', 'card-to-card-payment' ) ),
                                404
                        );
                }

                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_send_json_error(
                                array( 'message' => __( 'You are not allowed to perform this action.', 'card-to-card-payment' ) ),
                                403
                        );
                }

                check_ajax_referer( 'ctp_admin_' . $order_id, 'nonce' );

                if ( CTP_GATEWAY_ID !== $order->get_payment_method( 'edit' ) ) {
                        wp_send_json_error(
                                array( 'message' => __( 'This order does not use card-to-card payment.', 'card-to-card-payment' ) ),
                                400
                        );
                }

                return $order;
        }

        /**
         * AJAX: verify payment.
         *
         * @return void
         */
        public function ajax_verify() {
                $order = $this->guard_admin_ajax();

                if ( CTP_Status::VERIFIED === (string) CTP_Meta::get( $order, CTP_Meta::STATUS ) ) {
                        wp_send_json_error(
                                array( 'message' => __( 'This payment has already been verified.', 'card-to-card-payment' ) ),
                                409
                        );
                }

                CTP_Order::verify_payment( $order, get_current_user_id() );

                wp_send_json_success(
                        array( 'message' => __( 'Payment verified.', 'card-to-card-payment' ) )
                );
        }

        /**
         * AJAX: reject receipt.
         *
         * @return void
         */
        public function ajax_reject() {
                $order = $this->guard_admin_ajax();

                $reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
                if ( '' === trim( $reason ) ) {
                        wp_send_json_error(
                                array( 'message' => __( 'Please enter the rejection reason shown to the customer.', 'card-to-card-payment' ) ),
                                400
                        );
                }

                CTP_Order::reject_receipt( $order, $reason, get_current_user_id() );

                wp_send_json_success(
                        array( 'message' => __( 'Receipt rejected.', 'card-to-card-payment' ) )
                );
        }

        /**
         * AJAX: remove current receipt (history preserved).
         *
         * @return void
         */
        public function ajax_delete_receipt() {
                $order = $this->guard_admin_ajax();

                CTP_Order::delete_receipt( $order, get_current_user_id() );

                wp_send_json_success(
                        array( 'message' => __( 'Receipt removed.', 'card-to-card-payment' ) )
                );
        }
}
