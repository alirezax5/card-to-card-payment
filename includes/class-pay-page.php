<?php
/**
 * Dedicated card-to-card payment page: bank cards + receipt upload form.
 *
 * Checkout flow: after the customer selects card-to-card payment and places
 * the order, WooCommerce redirects them to THIS page first (not to the
 * order-received page). The page shows the order summary, the configured bank
 * cards and the receipt upload form. After a successful upload the customer
 * continues to the order-received (thank you) page automatically.
 *
 * Implementation notes:
 *  - The page is auto-created once (like WooCommerce's own cart/checkout
 *    pages) and renders through the active theme via the
 *    `[card_to_card_payment]` shortcode, so header/footer/styles always come
 *    from the theme (classic AND block themes, RTL aware).
 *  - The URL carries the order ID plus the WooCommerce order key (guests).
 *    Logged-in owners and store admins are recognized automatically.
 *  - CTP_Security::can_access_order() is the single authorization gate,
 *    exactly like every other customer-facing surface (IDOR protection).
 *
 * @package CTP
 */

defined( 'ABSPATH' ) || exit;

class CTP_Pay_Page {

        /** Query var carrying the order ID. */
        const QUERY_VAR = 'ctp_pay';

        /** Shortcode tag rendered inside the auto-created page. */
        const SHORTCODE = 'card_to_card_payment';

        /** Option storing the auto-created page ID. */
        const PAGE_ID_OPTION = 'ctp_pay_page_id';

        /**
         * Wire query vars, the shortcode and page auto-creation.
         */
        public function __construct() {
                add_filter( 'query_vars', array( $this, 'register_query_var' ) );
                add_action( 'init', array( $this, 'ensure_page' ) );
                add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
                add_action( 'wp_head', array( $this, 'print_noindex_meta' ) );
        }

        /**
         * Register the order-ID query var.
         *
         * @param array $vars Public query vars.
         * @return array
         */
        public function register_query_var( $vars ) {
                $vars[] = self::QUERY_VAR;
                return $vars;
        }

        /**
         * Make sure the payment page exists (self-healing, multisite safe).
         *
         * Created once per site with the shortcode in its content; recreated
         * if the page was deleted. Never touched again afterwards.
         *
         * @return void
         */
        public function ensure_page() {
                $page_id = (int) get_option( self::PAGE_ID_OPTION );

                if (
                        $page_id
                        && 'page' === get_post_type( $page_id )
                        && 'publish' === get_post_status( $page_id )
                        && false !== strpos( (string) get_post_field( 'post_content', $page_id ), '[' . self::SHORTCODE . ']' )
                ) {
                        return;
                }

                $page_id = wp_insert_post(
                        array(
                                'post_title'     => __( 'Complete your card-to-card payment', 'card-to-card-payment' ),
                                'post_name'      => 'ctp-payment',
                                'post_content'   => '<!-- wp:shortcode -->[' . self::SHORTCODE . ']<!-- /wp:shortcode -->',
                                'post_status'    => 'publish',
                                'post_type'      => 'page',
                                'comment_status' => 'closed',
                                'ping_status'    => 'closed',
                        ),
                        true
                );

                if ( ! is_wp_error( $page_id ) && $page_id ) {
                        update_option( self::PAGE_ID_OPTION, (int) $page_id, false );
                }
        }

        /**
         * Build the dedicated payment page URL for an order.
         *
         * The URL always carries the order ID and the order key so guests can
         * open the page.
         *
         * @param WC_Order|int $order Order object or order ID.
         * @return string
         */
        public static function get_url( $order ) {
                $order = $order instanceof WC_Order ? $order : wc_get_order( $order );
                if ( ! $order instanceof WC_Order ) {
                        return '';
                }

                $page_id = (int) get_option( self::PAGE_ID_OPTION );
                $base    = $page_id ? get_permalink( $page_id ) : '';
                if ( ! is_string( $base ) || '' === $base ) {
                        $base = home_url( '/' );
                }

                $args = array( self::QUERY_VAR => $order->get_id() );

                $key = CTP_Security::sanitize_order_key( $order->get_order_key( 'edit' ) );
                if ( '' !== $key ) {
                        $args['key'] = $key;
                }

                return esc_url_raw( add_query_arg( $args, $base ) );
        }

        /**
         * Shortcode handler.
         *
         * @return string
         */
        public function render_shortcode() {
                $order_id = absint( get_query_var( self::QUERY_VAR ) );

                return '<div class="ctp-pay-page">' . self::render_page_content( $order_id ) . '</div>';
        }

        /**
         * Render the page content: order summary and the payment section
         * (bank cards + receipt upload form), or a denial notice.
         *
         * Self-authorizing: unauthorized visitors only ever see a generic
         * notice (defense in depth, same as CTP_Order::render()).
         *
         * @param int|null $order_id Order ID; null reads the current query var.
         * @return string
         */
        public static function render_page_content( $order_id = null ) {
                if ( null === $order_id ) {
                        $order_id = absint( get_query_var( self::QUERY_VAR ) );
                }

                $order = $order_id ? wc_get_order( $order_id ) : false;

                // Unknown order, or an order that does not use this gateway.
                // Reply with a generic notice: never leak whether an order exists.
                if ( ! $order instanceof WC_Order || CTP_GATEWAY_ID !== $order->get_payment_method( 'edit' ) ) {
                        return self::render_notice( __( 'The requested page could not be found.', 'card-to-card-payment' ) );
                }

                // IDOR protection: store admin, order owner, or valid order key.
                if ( ! CTP_Security::can_access_order( $order ) ) {
                        return self::render_notice( __( 'You are not allowed to view this payment page.', 'card-to-card-payment' ) );
                }

                ob_start();
                self::render_order_summary( $order );
                CTP_Order::render( $order, array( 'received_url' => $order->get_checkout_order_received_url() ) );
                return (string) ob_get_clean();
        }

        /**
         * Compact order summary so the customer can double-check the amount
         * before transferring the money.
         *
         * @param WC_Order $order Order object.
         * @return void
         */
        protected static function render_order_summary( $order ) {
                $heading     = CTP_Gateway::get_pay_page_heading();
                $description = CTP_Gateway::get_pay_page_description();
                ?>
                <div class="ctp-order-summary">
                        <?php if ( '' !== $heading ) : ?>
                                <h2 class="ctp-order-summary__title"><?php echo esc_html( $heading ); ?></h2>
                        <?php endif; ?>
                        <?php if ( '' !== $description ) : ?>
                                <div class="ctp-order-summary__description"><?php echo wp_kses_post( wpautop( wptexturize( $description ) ) ); ?></div>
                        <?php endif; ?>
                        <p class="ctp-order-summary__number">
                                <?php echo esc_html__( 'Order number:', 'card-to-card-payment' ); ?>
                                <strong><?php echo esc_html( $order->get_order_number() ); ?></strong>
                        </p>

                        <table class="ctp-order-summary__table">
                                <tbody>
                                <?php foreach ( $order->get_items() as $item ) : ?>
                                        <tr>
                                                <td class="ctp-order-summary__name">
                                                        <?php echo esc_html( $item->get_name() ); ?>
                                                        <?php
                                                        $qty = (int) $item->get_quantity();
                                                        if ( $qty > 1 ) :
                                                                ?>
                                                                <span class="ctp-order-summary__qty">&times; <?php echo esc_html( number_format_i18n( $qty ) ); ?></span>
                                                        <?php endif; ?>
                                                </td>
                                                <td class="ctp-order-summary__total"><?php echo wp_kses_post( $order->get_formatted_line_subtotal( $item ) ); ?></td>
                                        </tr>
                                <?php endforeach; ?>
                                <tr class="ctp-order-summary__grand">
                                        <td><?php echo esc_html__( 'Order total:', 'card-to-card-payment' ); ?></td>
                                        <td><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td>
                                </tr>
                                </tbody>
                        </table>
                </div>
                <?php
        }

        /**
         * Generic denial notice (no information leak).
         *
         * @param string $message Message.
         * @return string
         */
        protected static function render_notice( $message ) {
                return '<div class="ctp-pay-page__notice" role="alert">' . esc_html( $message ) . '</div>';
        }

        /**
         * Keep the private payment page (which contains an order key) out of
         * search engines.
         *
         * @return void
         */
        public function print_noindex_meta() {
                if ( get_query_var( self::QUERY_VAR ) ) {
                        echo '<meta name="robots" content="noindex, nofollow" />' . "\n";
                }
        }
}
