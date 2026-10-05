<?php
/**
 * WooCommerce payment gateway: Card-to-Card Payment.
 *
 * Supports unlimited bank cards via a dynamic repeater interface, stores
 * card numbers as strings, and keeps orders in Pending payment until a
 * receipt is uploaded, without ever marking them paid automatically.
 *
 * @package CTP
 */

defined( 'ABSPATH' ) || exit;

class CTP_Gateway extends WC_Payment_Gateway {

        /**
         * Whether the payment fields (description) are shown on checkout.
         *
         * @var bool
         */
        public $has_fields = false;

        /**
         * Payment instructions (set from settings).
         *
         * @var string
         */
        public $instructions = '';

        /**
         * Constructor.
         */
        public function __construct() {
                $this->id                 = CTP_GATEWAY_ID;
                $this->icon               = '';
                $this->has_fields         = false;
                $this->method_title       = __( 'Card-to-Card Payment', 'card-to-card-payment' );
                $this->method_description = __( 'Manual bank card-to-card payment. The customer transfers the order total to one of your bank cards and uploads the payment receipt; you verify the payment manually.', 'card-to-card-payment' );

                $this->init_form_fields();
                $this->init_settings();

                $this->enabled      = $this->get_option( 'enabled', 'no' );
                $this->title        = $this->get_option( 'title', '' );
                $this->description  = $this->get_option( 'description', '' );
                $this->instructions = $this->get_option( 'instructions', '' );

                if ( '' === $this->title ) {
                        $this->title = __( 'Card-to-Card Payment', 'card-to-card-payment' );
                }

                add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
                add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
        }

        /**
         * Standard settings fields. The cards repeater is appended separately.
         *
         * @return array
         */
        public function get_form_fields() {
                return apply_filters(
                        'woocommerce_settings_api_form_fields_' . $this->id,
                        array(
                                'enabled'      => array(
                                        'title'   => __( 'Enable/Disable', 'card-to-card-payment' ),
                                        'type'    => 'checkbox',
                                        'label'   => __( 'Enable card-to-card payment', 'card-to-card-payment' ),
                                        'default' => 'no',
                                ),
                                'title'        => array(
                                        'title'       => __( 'Title', 'card-to-card-payment' ),
                                        'type'        => 'text',
                                        'description' => __( 'Payment method title shown to customers at checkout.', 'card-to-card-payment' ),
                                        'default'     => __( 'Card-to-Card Payment', 'card-to-card-payment' ),
                                        'desc_tip'    => true,
                                ),
                                'description'  => array(
                                        'title'   => __( 'Description', 'card-to-card-payment' ),
                                        'type'    => 'textarea',
                                        /* translators: %s: title of the payment method. */
                                        'default' => __( 'Pay by transferring the order total to one of our bank cards (card-to-card), then upload your payment receipt for manual verification.', 'card-to-card-payment' ),
                                ),
                                'instructions' => array(
                                        'title'   => __( 'Payment Instructions', 'card-to-card-payment' ),
                                        'type'    => 'textarea',
                                        'default' => __( 'Please transfer the order total to one of the cards below, then upload your payment receipt. Your order will be processed after the store admin verifies your payment.', 'card-to-card-payment' ),
                                ),
                                'pay_page_heading' => array(
                                        'title'       => __( 'Payment page heading', 'card-to-card-payment' ),
                                        'type'        => 'text',
                                        'description' => __( 'Heading shown on the dedicated payment page, above the order summary. Leave empty to hide the heading.', 'card-to-card-payment' ),
                                        'default'     => __( 'Order summary', 'card-to-card-payment' ),
                                        'desc_tip'    => true,
                                ),
                                'pay_page_description' => array(
                                        'title'       => __( 'Payment page description', 'card-to-card-payment' ),
                                        'type'        => 'textarea',
                                        'description' => __( 'Intro text shown on the dedicated payment page, above the order summary. Basic HTML allowed. Leave empty to show no description.', 'card-to-card-payment' ),
                                        'default'     => '',
                                ),
                                'max_file_mb'  => array(
                                        'title'             => __( 'Maximum receipt file size (MB)', 'card-to-card-payment' ),
                                        'type'              => 'number',
                                        'description'       => __( 'Maximum allowed size of the uploaded payment receipt file. Default: 5 MB.', 'card-to-card-payment' ),
                                        'default'           => '5',
                                        'custom_attributes' => array(
                                                'min'  => '1',
                                                'max'  => '50',
                                                'step' => '1',
                                        ),
                                        'desc_tip'          => true,
                                ),
                                'debug_log'    => array(
                                        'title'   => __( 'Debug logging', 'card-to-card-payment' ),
                                        'type'    => 'checkbox',
                                        'label'   => __( 'Log gateway events (never logs card numbers or receipt contents)', 'card-to-card-payment' ),
                                        'default' => 'no',
                                ),
                        )
                );
        }

        /**
         * Render the gateway settings screen: the standard fields table followed
         * by the bank cards repeater.
         *
         * WooCommerce renders classic gateway settings exclusively through this
         * method, so the cards repeater MUST be appended here. Appending it only
         * to a custom form-field type (or any other hook) silently hides the
         * whole "Bank cards" section from merchants.
         *
         * @return void
         */
        public function admin_options() {
                parent::admin_options();
                // phpcs:ignore WordPress.Security.EscapeOutput -- generate_cards_html() escapes internally.
                echo $this->generate_cards_html();
        }

        /**
         * Render the dynamic bank-cards repeater.
         *
         * @return string
         */
        protected function generate_cards_html() {
                $cards = self::sanitize_cards( $this->get_option( 'cards', array() ) );
                ob_start();
                ?>
                <h3 class="wc-settings-sub-title"><?php echo esc_html__( 'Bank Cards', 'card-to-card-payment' ); ?></h3>
                <table class="form-table">
                        <tbody>
                        <tr valign="top">
                                <th scope="row" colspan="2">
                                        <p class="description">
                                                <?php echo esc_html__( 'Add as many bank cards as you need. Only enabled cards are shown to customers. Drag a card (or use the arrows) to change the display order.', 'card-to-card-payment' ); ?>
                                                <?php if ( 'yes' === $this->enabled && empty( $cards ) ) : ?>
                                                        <strong class="ctp-settings-warning"><?php echo esc_html__( 'The gateway is enabled but no card has been configured yet. Customers cannot pay until you add at least one enabled card.', 'card-to-card-payment' ); ?></strong>
                                                <?php endif; ?>
                                        </p>
                                </th>
                        </tr>
                        <tr valign="top">
                                <td colspan="2">
                                        <div id="ctp-cards-repeater" class="ctp-cards-repeater" data-empty="<?php echo esc_attr__( 'No cards yet. Click the Add card button below to create your first card.', 'card-to-card-payment' ); ?>">
                                                <div class="ctp-cards-list">
                                                        <?php
                                                        foreach ( $cards as $card ) {
                                                                self::render_admin_card_row( $card );
                                                        }
                                                        ?>
                                                </div>
                                                <p class="ctp-cards-empty" <?php echo $cards ? 'hidden' : ''; ?>><?php echo esc_html__( 'No cards yet. Click the Add card button below to create your first card.', 'card-to-card-payment' ); ?></p>
                                                <p class="ctp-cards-repeater__actions">
                                                        <button type="button" class="button button-primary ctp-cards-add"><?php echo esc_html__( 'Add card', 'card-to-card-payment' ); ?></button>
                                                </p>
                                                <script type="text/template" id="ctp-card-template"><?php self::render_admin_card_row( array(), true ); ?></script>
                                        </div>
                                </td>
                        </tr>
                        </tbody>
                </table>
                <?php
                return ob_get_clean();
        }

        /**
         * Render a single card row for the repeater.
         *
         * @param array $card     Card data.
         * @param bool  $template True when rendering the JS template row.
         * @return void
         */
        protected static function render_admin_card_row( $card, $template = false ) {
                $card = wp_parse_args(
                        is_array( $card ) ? $card : array(),
                        array(
                                'id'         => '',
                                'number'     => '',
                                'first_name' => '',
                                'last_name'  => '',
                                'enabled'    => 1,
                        )
                );
                $is_disabled = ! $template && empty( $card['enabled'] );
                ?>
                <div class="ctp-card-row<?php echo $is_disabled ? ' is-disabled' : ''; ?>" data-card-id="<?php echo esc_attr( $template ? '__ID__' : $card['id'] ); ?>">
                        <span class="ctp-card-row__drag" aria-hidden="true" title="<?php echo esc_attr__( 'Drag to reorder', 'card-to-card-payment' ); ?>"></span>
                        <div class="ctp-card-row__fields">
                                <p class="ctp-card-field ctp-card-field--number">
                                        <label class="ctp-card-row__label" for="ctp-card-number-<?php echo esc_attr( $template ? '__ID__' : $card['id'] ); ?>"><?php echo esc_html__( 'Card number', 'card-to-card-payment' ); ?></label>
                                        <span class="ctp-card-row__number-wrap">
                                                <input type="text" id="ctp-card-number-<?php echo esc_attr( $template ? '__ID__' : $card['id'] ); ?>" class="ctp-card-row__number" inputmode="numeric" autocomplete="off" dir="ltr" value="<?php echo esc_attr( $template ? '__NUMBER__' : self::format_card_number( $card['number'] ) ); ?>" aria-label="<?php echo esc_attr__( 'Card number', 'card-to-card-payment' ); ?>" />
                                        </span>
                                </p>
                                <p class="ctp-card-field">
                                        <label class="ctp-card-row__label"><?php echo esc_html__( 'First name', 'card-to-card-payment' ); ?></label>
                                        <input type="text" class="ctp-card-row__first-name regular-text" autocomplete="off" value="<?php echo esc_attr( $template ? '__FIRST_NAME__' : $card['first_name'] ); ?>" aria-label="<?php echo esc_attr__( 'First name', 'card-to-card-payment' ); ?>" />
                                </p>
                                <p class="ctp-card-field">
                                        <label class="ctp-card-row__label"><?php echo esc_html__( 'Last name', 'card-to-card-payment' ); ?></label>
                                        <input type="text" class="ctp-card-row__last-name regular-text" autocomplete="off" value="<?php echo esc_attr( $template ? '__LAST_NAME__' : $card['last_name'] ); ?>" aria-label="<?php echo esc_attr__( 'Last name', 'card-to-card-payment' ); ?>" />
                                </p>
                                <p class="ctp-card-field ctp-card-field--enabled">
                                        <span class="ctp-card-row__label"><?php echo esc_html__( 'Enabled', 'card-to-card-payment' ); ?></span>
                                        <label class="ctp-switch">
                                                <input type="checkbox" class="ctp-card-row__enabled" <?php checked( ! empty( $card['enabled'] ) || $template ); ?> />
                                                <span class="ctp-switch__track" aria-hidden="true"></span>
                                                <span class="ctp-switch__state ctp-switch__state--on" aria-hidden="true"><?php echo esc_html__( 'Enabled', 'card-to-card-payment' ); ?></span>
                                                <span class="ctp-switch__state ctp-switch__state--off" aria-hidden="true"><?php echo esc_html__( 'Disabled', 'card-to-card-payment' ); ?></span>
                                        </label>
                                </p>
                                <p class="ctp-card-row__preview description" aria-live="polite"></p>
                        </div>
                        <div class="ctp-card-row__actions">
                                <div class="ctp-card-row__reorder">
                                        <button type="button" class="button ctp-card-row__up" aria-label="<?php echo esc_attr__( 'Move card up', 'card-to-card-payment' ); ?>" title="<?php echo esc_attr__( 'Move card up', 'card-to-card-payment' ); ?>">&uarr;</button>
                                        <button type="button" class="button ctp-card-row__down" aria-label="<?php echo esc_attr__( 'Move card down', 'card-to-card-payment' ); ?>" title="<?php echo esc_attr__( 'Move card down', 'card-to-card-payment' ); ?>">&darr;</button>
                                </div>
                                <button type="button" class="button-link button-link-delete ctp-card-row__remove" aria-label="<?php echo esc_attr__( 'Remove card', 'card-to-card-payment' ); ?>"><?php echo esc_html__( 'Remove', 'card-to-card-payment' ); ?></button>
                        </div>
                </div>
                <?php
        }

        /**
         * Save settings; sanitize the cards repeater JSON explicitly.
         *
         * @return bool
         */
        public function process_admin_options() {
                $post_data = $this->get_post_data();
                $field     = 'woocommerce_card_to_card_cards';

                $raw_cards = null;
                if ( isset( $post_data[ $field ] ) ) {
                        $raw_cards = wp_unslash( $post_data[ $field ] );
                }

                // Sanitize the repeater JSON ourselves; never let it pass through generic cleaning.
                $cards = self::sanitize_cards( $raw_cards );

                $saved = parent::process_admin_options();

                $this->update_option( 'cards', $cards );

                self::log(
                        sprintf(
                                /* translators: %d: number of saved cards. */
                                __( 'Gateway settings saved with %d card(s).', 'card-to-card-payment' ),
                                count( $cards )
                        )
                );

                return $saved;
        }

        /**
         * Sanitize the raw cards input (JSON string or array).
         *
         * Card numbers are ALWAYS handled as strings and stored as digits only.
         * Names are text-sanitized; each card keeps a stable unique id.
         *
         * @param mixed $raw Raw input.
         * @return array[] List of sanitized card arrays.
         */
        public static function sanitize_cards( $raw ) {
                if ( is_string( $raw ) ) {
                        $decoded = json_decode( $raw, true );
                } elseif ( is_array( $raw ) ) {
                        $decoded = $raw;
                } else {
                        $decoded = null;
                }

                if ( ! is_array( $decoded ) ) {
                        return array();
                }

                $cards = array();
                foreach ( $decoded as $item ) {
                        if ( ! is_array( $item ) ) {
                                continue;
                        }

                        // Never cast card numbers to int: strip everything but digits, keep as string.
                        $number = isset( $item['number'] ) ? preg_replace( '/\D/', '', (string) $item['number'] ) : '';
                        if ( '' === $number ) {
                                continue;
                        }

                        $id = isset( $item['id'] ) ? sanitize_key( (string) $item['id'] ) : '';
                        if ( '' === $id ) {
                                $id = 'card_' . wp_generate_password( 10, false );
                        }

                        $cards[] = array(
                                'id'         => $id,
                                'number'     => $number,
                                'first_name' => isset( $item['first_name'] ) ? sanitize_text_field( (string) $item['first_name'] ) : '',
                                'last_name'  => isset( $item['last_name'] ) ? sanitize_text_field( (string) $item['last_name'] ) : '',
                                'enabled'    => ( empty( $item['enabled'] ) || 'false' === $item['enabled'] ) ? 0 : 1,
                        );
                }

                return $cards;
        }

        /**
         * Get enabled (active) cards, formatted for display.
         *
         * Reads the stored settings option directly from the database so the
         * cards are always current, even within the same request that saved them.
         *
         * @return array[] Each item: id, number (digits), display (grouped), first_name, last_name, holder.
         */
        public static function get_active_cards() {
                $option = get_option( 'woocommerce_' . CTP_GATEWAY_ID . '_settings', array() );
                $cards  = is_array( $option ) && isset( $option['cards'] ) ? $option['cards'] : array();
                $cards  = self::sanitize_cards( $cards );

                $active = array();
                foreach ( $cards as $card ) {
                        if ( empty( $card['enabled'] ) || '' === $card['number'] ) {
                                continue;
                        }
                        $holder = trim( $card['first_name'] . ' ' . $card['last_name'] );
                        $active[] = array(
                                'id'         => $card['id'],
                                'number'     => $card['number'],
                                'display'    => self::format_card_number( $card['number'] ),
                                'first_name' => $card['first_name'],
                                'last_name'  => $card['last_name'],
                                'holder'     => $holder,
                        );
                }

                return apply_filters( 'ctp_active_cards', $active );
        }

        /**
         * Group a card number into blocks of 4 for display: 6037 9918 1234 5678.
         *
         * @param string $number Card number.
         * @return string
         */
        public static function format_card_number( $number ) {
                $digits = preg_replace( '/\D/', '', (string) $number );
                if ( '' === $digits ) {
                        return '';
                }
                $groups = str_split( $digits, 4 );
                return implode( ' ', $groups );
        }

        /**
         * Maximum receipt size in bytes from settings (default 5 MB).
         *
         * @return int
         */
        public static function get_max_file_size_bytes() {
                $option = get_option( 'woocommerce_' . CTP_GATEWAY_ID . '_settings', array() );
                $mb     = is_array( $option ) && isset( $option['max_file_mb'] ) ? (float) $option['max_file_mb'] : 5.0;
                if ( $mb < 1 ) {
                        $mb = 1;
                }
                if ( $mb > 50 ) {
                        $mb = 50;
                }
                return (int) round( $mb * 1024 * 1024 );
        }

        /**
         * Formatted maximum size for display.
         *
         * @return string
         */
        public static function get_max_file_size_label() {
                $bytes = self::get_max_file_size_bytes();
                /* translators: %d: maximum file size in megabytes. */
                return sprintf( _x( '%d MB', 'file size', 'card-to-card-payment' ), (int) round( $bytes / 1024 / 1024 ) );
        }

        /**
         * Whether debug logging is enabled in settings.
         *
         * @return bool
         */
        public static function debug_enabled() {
                $option = get_option( 'woocommerce_' . CTP_GATEWAY_ID . '_settings', array() );
                return is_array( $option ) && isset( $option['debug_log'] ) && 'yes' === $option['debug_log'];
        }

        /**
         * Safe logging via WooCommerce logger. Never logs card numbers, receipt
         * contents, keys, tokens or nonces.
         *
         * @param string $message Message (already free of sensitive data).
         * @param string $level   WC_Logger level.
         * @return void
         */
        public static function log( $message, $level = 'info' ) {
                if ( ! self::debug_enabled() || ! function_exists( 'wc_get_logger' ) ) {
                        return;
                }
                wc_get_logger()->log( $level, $message, array( 'source' => 'card-to-card-payment' ) );
        }

        /**
         * Checkout description with translated fallback.
         *
         * @return string
         */
        public function get_description_safe() {
                $description = $this->get_option( 'description', '' );
                if ( '' === trim( (string) $description ) ) {
                        $description = __( 'Pay by transferring the order total to one of our bank cards (card-to-card), then upload your payment receipt for manual verification.', 'card-to-card-payment' );
                }
                return $description;
        }

        /**
         * Payment instructions with translated fallback.
         *
         * @return string
         */
        public static function get_instructions_safe() {
                $option     = get_option( 'woocommerce_' . CTP_GATEWAY_ID . '_settings', array() );
                $instruction = is_array( $option ) && isset( $option['instructions'] ) ? trim( (string) $option['instructions'] ) : '';
                if ( '' === $instruction ) {
                        $instruction = __( 'Please transfer the order total to one of the cards below, then upload your payment receipt. Your order will be processed after the store admin verifies your payment.', 'card-to-card-payment' );
                }
                return $instruction;
        }

        /**
         * Heading shown on the dedicated payment page, above the order summary.
         *
         * Falls back to the translated field default when the settings were
         * never saved. An intentionally saved empty value hides the heading.
         *
         * @return string
         */
        public static function get_pay_page_heading() {
                $option = get_option( 'woocommerce_' . CTP_GATEWAY_ID . '_settings', array() );
                if ( is_array( $option ) && array_key_exists( 'pay_page_heading', $option ) ) {
                        return trim( (string) $option['pay_page_heading'] );
                }
                return __( 'Order summary', 'card-to-card-payment' );
        }

        /**
         * Admin-managed intro text shown on the dedicated payment page.
         *
         * @return string Raw value (sanitized on output).
         */
        public static function get_pay_page_description() {
                $option = get_option( 'woocommerce_' . CTP_GATEWAY_ID . '_settings', array() );
                $value  = is_array( $option ) && isset( $option['pay_page_description'] ) ? (string) $option['pay_page_description'] : '';
                return trim( $value );
        }

        /**
         * Show the description on the classic checkout page.
         *
         * @return void
         */
        public function payment_fields() {
                if ( is_checkout() ) {
                        echo wp_kses_post( wpautop( wptexturize( $this->get_description_safe() ) ) );
                }
        }

        /**
         * Handle the pay-order page (order-pay endpoint).
         *
         * @param int $order_id Order ID.
         * @return void
         */
        public function receipt_page( $order_id ) {
                $order = wc_get_order( $order_id );
                if ( $order ) {
                        CTP_Order::render( $order );
                }
        }

        /**
         * Process the payment: never mark as paid, never complete automatically.
         *
         * Order lifecycle for this gateway (WooCommerce-native states only):
         *   1. Order placed, no receipt yet  -> `pending` (Payment pending).
         *   2. Receipt uploaded + validated  -> `on-hold` (see CTP_Receipt).
         *   3. Receipt verified by admin     -> payment_complete() (see CTP_Order).
         *   4. Receipt rejected by admin     -> back to `pending` (see CTP_Order).
         *
         * @param int $order_id Order ID.
         * @return array
         */
        public function process_payment( $order_id ) {
                $order = wc_get_order( $order_id );

                if ( ! $order instanceof WC_Order ) {
                        wc_add_notice( __( 'Payment error: the order could not be found. Please try again.', 'card-to-card-payment' ), 'error' );
                        return array( 'result' => 'failure' );
                }

                if ( '' === (string) CTP_Meta::get( $order, CTP_Meta::STATUS ) ) {
                        CTP_Meta::set( $order, CTP_Meta::STATUS, CTP_Status::WAITING );
                }

                /*
                 * Manual gateway: the order stays in Pending payment until the
                 * customer uploads a receipt (the upload moves it to on-hold
                 * for review). Only a NON-pending order is transitioned here,
                 * e.g. a failed order being paid again through the order-pay
                 * endpoint; a fresh checkout order is already `pending`.
                 */
                $created_note = __( 'Card-to-Card payment selected. Waiting for payment receipt.', 'card-to-card-payment' );
                if ( ! $order->has_status( 'pending' ) ) {
                        $order->update_status( 'pending', $created_note );
                } else {
                        CTP_Order::add_note_once( $order, $created_note );
                }
                $order->save();

                do_action( 'ctp_order_created', $order );

                if ( function_exists( 'WC' ) && null !== WC() && null !== WC()->cart ) {
                        WC()->cart->empty_cart();
                }

                // Send the customer to the dedicated payment page (bank cards +
                // receipt upload form) FIRST. The order-received page follows
                // after the receipt has been uploaded.
                return array(
                        'result'   => 'success',
                        'redirect' => CTP_Pay_Page::get_url( $order ),
                );
        }
}
