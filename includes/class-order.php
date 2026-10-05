<?php
/**
 * Customer-facing payment section rendering + core verify/reject logic.
 *
 * Renders on:
 *  - Order-received (thank you) page
 *  - My Account > View Order
 *  - Order-pay page
 *
 * @package CTP
 */

defined( 'ABSPATH' ) || exit;

class CTP_Order {

        /**
         * Hook rendering into WooCommerce templates.
         */
        public function __construct() {
                add_action( 'woocommerce_order_details_after_order_table', array( $this, 'maybe_render' ) );
                add_action( 'woocommerce_pay_order_after_submit', array( $this, 'maybe_render_pay_page' ) );
        }

        /**
         * Render the payment section when the order uses this gateway.
         *
         * @param WC_Order $order Order object.
         * @return void
         */
        public function maybe_render( $order ) {
                if ( $order instanceof WC_Order && CTP_GATEWAY_ID === $order->get_payment_method( 'edit' ) ) {
                        self::render( $order );
                }
        }

        /**
         * Render on the order-pay page.
         *
         * @return void
         */
        public function maybe_render_pay_page() {
                $order_id = absint( get_query_var( 'order-pay' ) );
                if ( ! $order_id ) {
                        return;
                }
                $order = wc_get_order( $order_id );
                if ( $order instanceof WC_Order && CTP_GATEWAY_ID === $order->get_payment_method( 'edit' ) ) {
                        self::render(
                                $order,
                                array( 'received_url' => $order->get_checkout_order_received_url() )
                        );
                }
        }

        /**
         * Can a new (or replacement) receipt be uploaded for this order?
         *
         * Uploading never auto-verifies; once verified no more uploads are allowed.
         *
         * @param WC_Order $order Order object.
         * @return bool
         */
        public static function can_upload_receipt( $order ) {
                $status = (string) CTP_Meta::get( $order, CTP_Meta::STATUS );
                if ( '' === $status ) {
                        $status = CTP_Status::WAITING;
                }
                return in_array( $status, array( CTP_Status::WAITING, CTP_Status::RECEIPT_SUBMITTED, CTP_Status::REJECTED ), true );
        }

        /**
         * Format a GMT mysql timestamp using site timezone and format settings.
         *
         * @param string $gmt_mysql GMT timestamp in mysql format.
         * @return string
         */
        public static function format_meta_datetime( $gmt_mysql ) {
                $ts = (string) $gmt_mysql ? strtotime( (string) $gmt_mysql ) : 0;
                if ( ! $ts ) {
                        return '';
                }
                $format = get_option( 'date_format', 'F j, Y' ) . ' ' . get_option( 'time_format', 'g:i a' );
                return wp_date( $format, $ts );
        }

        /**
         * Add an order note only when the exact same note was not just added
         * to the same order. Protects the order notes timeline from duplicate
         * entries when the same action is accidentally submitted twice
         * (double click, racing requests) while still allowing legitimate
         * later repetitions of the same action.
         *
         * @param WC_Order $order         Order object.
         * @param string   $note          Note text (translation-ready).
         * @param bool     $customer_note True = visible to the customer.
         * @return bool True when a note was added, false when skipped.
         */
        public static function add_note_once( $order, $note, $customer_note = false ) {
                $note = trim( wp_strip_all_tags( (string) $note ) );
                if ( '' === $note ) {
                        return false;
                }

                /**
                 * Filters the duplicate-note protection window in seconds.
                 * An identical note recorded within this window is considered
                 * an accidental double submission and is skipped. 0 disables
                 * the protection entirely.
                 *
                 * @param int $window Window in seconds (default 120).
                 */
                $window = (int) apply_filters( 'ctp_duplicate_note_window', 2 * MINUTE_IN_SECONDS );

                if ( $window > 0 && function_exists( 'wc_get_order_notes' ) ) {
                        $cutoff = time() - $window;
                        $recent = wc_get_order_notes(
                                array(
                                        'order_id' => $order->get_id(),
                                        'limit'    => 20,
                                )
                        );
                        foreach ( $recent as $recent_note ) {
                                if ( empty( $recent_note->content ) || trim( wp_strip_all_tags( (string) $recent_note->content ) ) !== $note ) {
                                        continue;
                                }
                                $note_ts = 0;
                                if ( ! empty( $recent_note->date_created ) && is_object( $recent_note->date_created ) && method_exists( $recent_note->date_created, 'getTimestamp' ) ) {
                                        $note_ts = (int) $recent_note->date_created->getTimestamp();
                                }
                                if ( 0 === $note_ts || $note_ts >= $cutoff ) {
                                        return false;
                                }
                        }
                }

                $order->add_order_note( $note, $customer_note ? 1 : 0, true );
                return true;
        }

        /**
         * Core: administrator verifies the payment.
         *
         * Stores verification metadata, adds an order note and moves the order
         * forward with WooCommerce's own payment_complete() lifecycle (stock
         * reduction + the correct paid status per order contents: processing
         * for orders that need fulfilment, completed for virtual/downloadable
         * ones). The plugin never forces a fixed status.
         *
         * @param WC_Order $order    Order object.
         * @param int      $admin_id Admin user ID.
         * @return void
         */
        public static function verify_payment( $order, $admin_id ) {
                $now_gmt = current_time( 'mysql', true );

                // Idempotent: a second verification call keeps the original
                // timestamp/verifier and never adds a duplicate note.
                $already_verified = CTP_Status::VERIFIED === (string) CTP_Meta::get( $order, CTP_Meta::STATUS );

                if ( ! $already_verified ) {
                        CTP_Meta::set( $order, CTP_Meta::STATUS, CTP_Status::VERIFIED );
                        CTP_Meta::set( $order, CTP_Meta::VERIFIED_AT, $now_gmt );
                        CTP_Meta::set( $order, CTP_Meta::VERIFIED_BY, absint( $admin_id ) );

                        self::add_note_once(
                                $order,
                                __( 'Card-to-Card payment receipt approved and payment verified.', 'card-to-card-payment' )
                        );

                        $order->save();
                }

                if ( ! $order->is_paid() ) {
                        $order->payment_complete();
                }

                self::sync_order_analytics( $order );

                do_action( 'ctp_payment_verified', $order, $admin_id );
        }

        /**
         * Reflect the verified payment in WooCommerce Analytics immediately.
         *
         * WooCommerce Analytics builds its reports from lookup tables that are
         * fed by an order importer. When the store uses the scheduled (batched)
         * import mode, that importer only runs on a recurring schedule, so a
         * freshly verified order can be missing from the Analytics Orders /
         * Revenue reports for a long time. Verifying the receipt is the moment
         * the sale becomes real for this gateway, so run the same import the
         * WooCommerce scheduler would run, scoped to this order only. The
         * import is an idempotent upsert: running it here alongside any
         * scheduled import is safe.
         *
         * @param WC_Order $order Order object.
         * @return void
         */
        private static function sync_order_analytics( $order ) {
                $order_id = $order->get_id();

                // Preferred: the full WooCommerce Admin scheduler import (stats,
                // products, coupons, taxes, customers, refunds, cache invalidation).
                $scheduler = '\Automattic\WooCommerce\Internal\Admin\Schedulers\OrdersScheduler';
                if ( class_exists( $scheduler ) && method_exists( $scheduler, 'import' ) ) {
                        $scheduler::import( $order_id );
                        return;
                }

                // Older WooCommerce Admin layout: sync the stats lookup row directly.
                $stats = '\Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\DataStore';
                if ( class_exists( $stats ) && method_exists( $stats, 'sync_order' ) ) {
                        $stats::sync_order( $order_id );

                        $cache = '\Automattic\WooCommerce\Admin\API\Reports\Cache';
                        if ( class_exists( $cache ) && method_exists( $cache, 'invalidate' ) ) {
                                $cache::invalidate();
                        }
                }
        }

        /**
         * Core: administrator rejects a receipt.
         *
         * Stores the rejection reason + timestamp, adds an order note and
         * returns the order to `pending`: after a rejection the order is once
         * again waiting for acceptable payment evidence, exactly like a fresh
         * order without a receipt. The rejection reason, timestamps and the
         * full receipt history are preserved (audit trail), and the customer
         * can upload a replacement receipt.
         *
         * @param WC_Order $order   Order object.
         * @param string   $reason  Rejection reason shown to the customer.
         * @param int      $admin_id Admin user ID.
         * @return void
         */
        public static function reject_receipt( $order, $reason, $admin_id ) {
                $now_gmt = current_time( 'mysql', true );
                $reason  = sanitize_textarea_field( (string) $reason );

                CTP_Meta::set( $order, CTP_Meta::STATUS, CTP_Status::REJECTED );
                CTP_Meta::set( $order, CTP_Meta::RECEIPT_REJECTED_AT, $now_gmt );
                CTP_Meta::set( $order, CTP_Meta::RECEIPT_REJECTION_REASON, $reason );

                /*
                 * Back to Pending payment: the order again waits for acceptable
                 * payment evidence. Paid orders are never demoted. The
                 * transition runs BEFORE the rejection note so that a duplicate
                 * rejection finds the rejection note as the latest note and is
                 * skipped by add_note_once().
                 */
                if ( ! $order->is_paid() && ! $order->has_status( 'pending' ) ) {
                        $order->update_status( 'pending', '' );
                }

                self::add_note_once(
                        $order,
                        sprintf(
                                /* translators: 1: admin user login, 2: rejection reason. */
                                __( 'Card-to-Card payment receipt was rejected by %1$s. Reason: %2$s', 'card-to-card-payment' ),
                                get_the_author_meta( 'user_login', absint( $admin_id ) ) ? get_the_author_meta( 'user_login', absint( $admin_id ) ) : 'admin',
                                '' !== $reason ? $reason : __( '(no reason given)', 'card-to-card-payment' )
                        )
                );

                $order->save();

                do_action( 'ctp_receipt_rejected', $order, $reason, $admin_id );
        }

        /**
         * Core: administrator deletes (removes) the current receipt.
         *
         * The file and the history entry are preserved; the plugin payment
         * status returns to `waiting` and the WooCommerce order returns to
         * `pending`, so a new receipt can be uploaded.
         *
         * @param WC_Order $order   Order object.
         * @param int      $admin_id Admin user ID.
         * @return void
         */
        public static function delete_receipt( $order, $admin_id ) {
                $now_gmt  = current_time( 'mysql', true );
                $prev_id  = absint( CTP_Meta::get( $order, CTP_Meta::RECEIPT_ID ) );
                $history  = CTP_Meta::get( $order, CTP_Meta::RECEIPT_HISTORY );
                $history  = is_array( $history ) ? $history : array();

                if ( $prev_id ) {
                        $history[] = array(
                                'attachment_id'   => $prev_id,
                                'uploaded_at'     => (string) CTP_Meta::get( $order, CTP_Meta::RECEIPT_UPLOADED_AT ),
                                'removed_at'      => $now_gmt,
                                'previous_status' => (string) CTP_Meta::get( $order, CTP_Meta::STATUS ),
                        );
                }

                CTP_Meta::set( $order, CTP_Meta::RECEIPT_HISTORY, $history );
                CTP_Meta::set( $order, CTP_Meta::RECEIPT_ID, null );
                CTP_Meta::set( $order, CTP_Meta::RECEIPT_UPLOADED_AT, null );
                CTP_Meta::set( $order, CTP_Meta::STATUS, CTP_Status::WAITING );
                CTP_Meta::set( $order, CTP_Meta::RECEIPT_REJECTED_AT, null );
                CTP_Meta::set( $order, CTP_Meta::RECEIPT_REJECTION_REASON, null );

                // No receipt anymore -> waiting for payment evidence again.
                if ( ! $order->is_paid() && ! $order->has_status( 'pending' ) ) {
                        $order->update_status( 'pending', '' );
                }

                self::add_note_once(
                        $order,
                        __( 'The card-to-card payment receipt was removed by the store admin. Waiting for a new receipt.', 'card-to-card-payment' )
                );

                $order->save();
        }

        /**
         * Render the full customer payment section.
         *
         * @param WC_Order $order Order object.
         * @param array    $args  Optional context:
         *                        - received_url: (string) order-received URL.
         *                          When provided, the post-upload "View order"
         *                          link is rendered and frontend.js redirects
         *                          to it after a successful upload. Only pass
         *                          this from the dedicated payment page / the
         *                          order-pay page, never from the thank-you page
         *                          itself.
         * @return void
         */
        public static function render( $order, $args = array() ) {
                static $rendered = array();
                $order_id = $order->get_id();
                if ( isset( $rendered[ $order_id ] ) ) {
                        return;
                }

                // Authorization: the hosting page normally authorizes the visitor, but
                // never rely on that alone: verify admin / owner / valid order key here.
                if ( ! CTP_Security::can_access_order( $order ) ) {
                        return;
                }

                $rendered[ $order_id ] = true;

                $status    = (string) CTP_Meta::get( $order, CTP_Meta::STATUS );
                $status    = '' === $status ? CTP_Status::WAITING : $status;
                $cards     = CTP_Gateway::get_active_cards();
                $receipt_id = absint( CTP_Meta::get( $order, CTP_Meta::RECEIPT_ID ) );
                $uploaded   = (string) CTP_Meta::get( $order, CTP_Meta::RECEIPT_UPLOADED_AT );
                $rejected_at = (string) CTP_Meta::get( $order, CTP_Meta::RECEIPT_REJECTED_AT );
                $rejection   = (string) CTP_Meta::get( $order, CTP_Meta::RECEIPT_REJECTION_REASON );
                $can_upload  = self::can_upload_receipt( $order );
                $amount      = wp_kses_post( $order->get_formatted_order_total() );
                $order_key   = CTP_Security::sanitize_order_key( $order->get_order_key( 'edit' ) );
                $nonce       = wp_create_nonce( 'ctp_public' );
                $max_size    = CTP_Gateway::get_max_file_size_label();
                $received_url = isset( $args['received_url'] ) ? esc_url_raw( (string) $args['received_url'] ) : '';
                ?>
                <div class="ctp-payment-wrapper" data-order-id="<?php echo esc_attr( $order_id ); ?>">

                        <h2 class="ctp-payment-wrapper__title"><?php echo esc_html__( 'Card-to-Card Payment Information', 'card-to-card-payment' ); ?></h2>

                        <div class="ctp-status ctp-status--<?php echo esc_attr( CTP_Status::css_class( $status ) ); ?>">
                                <span class="ctp-status__label"><?php echo esc_html__( 'Status:', 'card-to-card-payment' ); ?></span>
                                <span class="ctp-status__value" data-ctp-status><?php echo esc_html( CTP_Status::label( $status ) ); ?></span>
                        </div>

                        <p class="ctp-amount">
                                <span class="ctp-amount__label"><?php echo esc_html__( 'Amount payable:', 'card-to-card-payment' ); ?></span>
                                <strong class="ctp-amount__value"><?php echo wp_kses_post( $amount ); ?></strong>
                        </p>

                        <div class="ctp-instructions">
                                <?php echo wp_kses_post( wpautop( wptexturize( CTP_Gateway::get_instructions_safe() ) ) ); ?>
                        </div>

                        <p class="ctp-manual-notice"><?php echo esc_html__( 'Your payment is verified manually by the store after you upload the receipt. The order is not processed automatically.', 'card-to-card-payment' ); ?></p>

                        <?php if ( CTP_Status::REJECTED === $status ) : ?>
                                <div class="ctp-rejection" role="alert">
                                        <p class="ctp-rejection__title"><?php echo esc_html__( 'The previous receipt was rejected.', 'card-to-card-payment' ); ?></p>
                                        <?php if ( '' !== $rejection ) : ?>
                                                <p class="ctp-rejection__reason"><strong><?php echo esc_html__( 'Rejection reason:', 'card-to-card-payment' ); ?></strong> <?php echo esc_html( $rejection ); ?></p>
                                        <?php endif; ?>
                                        <?php if ( $rejected_at ) : ?>
                                                <p class="ctp-rejection__date"><?php echo esc_html__( 'Rejected on:', 'card-to-card-payment' ); ?> <?php echo esc_html( self::format_meta_datetime( $rejected_at ) ); ?></p>
                                        <?php endif; ?>
                                        <p class="ctp-rejection__next"><?php echo esc_html__( 'Please upload a new receipt below.', 'card-to-card-payment' ); ?></p>
                                </div>
                        <?php endif; ?>

                        <?php if ( empty( $cards ) ) : ?>
                                <div class="ctp-no-cards">
                                        <p><?php echo esc_html__( 'No active cards are configured. Please contact the store to complete your payment.', 'card-to-card-payment' ); ?></p>
                                        <?php if ( CTP_Security::is_store_admin() ) : ?>
                                                <p>
                                                        <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . CTP_GATEWAY_ID ) ); ?>">
                                                                <?php echo esc_html__( 'Configure cards', 'card-to-card-payment' ); ?>
                                                        </a>
                                                </p>
                                        <?php endif; ?>
                                </div>
                        <?php else : ?>
                                <div class="ctp-cards">
                                        <?php foreach ( $cards as $card ) : ?>
                                                <div class="ctp-card">
                                                        <div class="ctp-card__heading"><?php echo esc_html__( 'Card number', 'card-to-card-payment' ); ?></div>
                                                        <div class="ctp-card__row">
                                                                <span class="ctp-card-number" dir="ltr"><?php echo esc_html( $card['display'] ); ?></span>
                                                                <button
                                                                        type="button"
                                                                        class="ctp-copy-button"
                                                                        data-card-number="<?php echo esc_attr( $card['number'] ); ?>"
                                                                        aria-label="<?php echo esc_attr__( 'Copy card number', 'card-to-card-payment' ); ?>"
                                                                ><?php echo esc_html__( 'Copy', 'card-to-card-payment' ); ?></button>
                                                                <span class="ctp-copy-feedback" role="status" aria-live="polite"></span>
                                                        </div>
                                                        <?php if ( '' !== $card['holder'] ) : ?>
                                                                <div class="ctp-card__holder">
                                                                        <span class="ctp-card__holder-label"><?php echo esc_html__( 'Card holder:', 'card-to-card-payment' ); ?></span>
                                                                        <?php echo esc_html( $card['holder'] ); ?>
                                                                </div>
                                                        <?php endif; ?>
                                                </div>
                                        <?php endforeach; ?>
                                </div>
                        <?php endif; ?>

                        <div class="ctp-receipt">
                                <?php if ( $receipt_id ) : ?>
                                        <h3 class="ctp-receipt__title"><?php echo esc_html__( 'Payment Receipt', 'card-to-card-payment' ); ?></h3>
                                        <figure class="ctp-receipt__figure">
                                                <a href="<?php echo esc_url( CTP_Receipt::get_receipt_url( $order, $receipt_id ) ); ?>" target="_blank" rel="noopener">
                                                        <img
                                                                class="ctp-receipt__thumb"
                                                                src="<?php echo esc_url( CTP_Receipt::get_receipt_url( $order, $receipt_id ) ); ?>"
                                                                width="220"
                                                                alt="<?php echo esc_attr__( 'Uploaded payment receipt', 'card-to-card-payment' ); ?>"
                                                                loading="lazy"
                                                        />
                                                </a>
                                                <figcaption class="ctp-receipt__meta">
                                                        <?php if ( $uploaded ) : ?>
                                                                <span class="ctp-receipt__date"><?php echo esc_html__( 'Uploaded:', 'card-to-card-payment' ); ?> <?php echo esc_html( self::format_meta_datetime( $uploaded ) ); ?></span>
                                                        <?php endif; ?>
                                                        <a class="ctp-receipt__full" href="<?php echo esc_url( CTP_Receipt::get_receipt_url( $order, $receipt_id ) ); ?>" target="_blank" rel="noopener">
                                                                <?php echo esc_html__( 'View full size', 'card-to-card-payment' ); ?>
                                                        </a>
                                                </figcaption>
                                        </figure>
                                <?php endif; ?>
                                <div class="ctp-receipt__latest" data-ctp-receipt></div>
                        </div>

                        <?php if ( $can_upload && ! empty( $cards ) ) : ?>
                                <form class="ctp-receipt-upload" method="post" enctype="multipart/form-data" novalidate>
                                        <h3 class="ctp-receipt-upload__title">
                                                <?php
                                                echo esc_html(
                                                        CTP_Status::REJECTED === $status
                                                                ? __( 'Upload a new payment receipt', 'card-to-card-payment' )
                                                                : __( 'Upload your payment receipt', 'card-to-card-payment' )
                                                );
                                                ?>
                                        </h3>
                                        <input type="hidden" name="order_id" value="<?php echo esc_attr( $order_id ); ?>" />
                                        <input type="hidden" name="order_key" value="<?php echo esc_attr( $order_key ); ?>" />
                                        <input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>" />
                                        <input type="hidden" name="action" value="ctp_upload_receipt" />

                                        <label class="ctp-receipt-upload__file-label" for="ctp-receipt-file-<?php echo esc_attr( $order_id ); ?>">
                                                <?php echo esc_html__( 'Choose file', 'card-to-card-payment' ); ?>
                                        </label>
                                        <input
                                                id="ctp-receipt-file-<?php echo esc_attr( $order_id ); ?>"
                                                class="ctp-file-input"
                                                type="file"
                                                name="receipt"
                                                accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                        />

                                        <p class="ctp-file-hint">
                                                <?php
                                                echo esc_html(
                                                        sprintf(
                                                                /* translators: 1: allowed formats, 2: max size. */
                                                                __( 'Allowed formats: %1$s. Maximum size: %2$s.', 'card-to-card-payment' ),
                                                                'JPG, JPEG, PNG, WEBP',
                                                                $max_size
                                                        )
                                                );
                                                ?>
                                        </p>
                                        <p class="ctp-file-name" data-ctp-file-name aria-live="polite"></p>

                                        <button type="submit" class="ctp-upload-button">
                                                <?php
                                                echo esc_html(
                                                        CTP_Status::REJECTED === $status
                                                                ? __( 'Submit new receipt', 'card-to-card-payment' )
                                                                : __( 'Submit receipt', 'card-to-card-payment' )
                                                );
                                                ?>
                                        </button>

                                        <p class="ctp-upload-feedback" data-ctp-feedback role="status" aria-live="polite"></p>
                                </form>
                        <?php elseif ( CTP_Status::VERIFIED === $status ) : ?>
                                <p class="ctp-verified-note"><?php echo esc_html__( 'Your payment has been verified. Thank you!', 'card-to-card-payment' ); ?></p>
                        <?php endif; ?>

                        <?php if ( '' !== $received_url ) : ?>
                                <p class="ctp-received-row">
                                        <a class="ctp-received-link" data-ctp-received href="<?php echo esc_url( $received_url ); ?>" hidden>
                                                <?php echo esc_html__( 'View order', 'card-to-card-payment' ); ?>
                                        </a>
                                </p>
                        <?php endif; ?>
                </div>
                <?php
        }
}
