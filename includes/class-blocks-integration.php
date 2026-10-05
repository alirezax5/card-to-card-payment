<?php
/**
 * WooCommerce Blocks (cart/checkout blocks) integration for the gateway.
 *
 * WooCommerce's block-based Checkout only renders payment methods that are
 * registered in its payment-method registry. Classic gateways without a
 * block integration are silently hidden on the checkout block ("There are
 * no payment methods available"), even when they are enabled.
 *
 * This class registers the gateway as a block-supported payment method so
 * it always appears in the checkout block. The full card list + receipt
 * upload UI is shown after checkout (order-received / order-pay / view
 * order), which is the correct place because the order does not exist yet
 * during checkout.
 *
 * @package CTP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bail gracefully on WooCommerce versions without the blocks API.
 */
if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        return;
}

/**
 * Block integration for the Card-to-Card gateway.
 */
final class CTP_Blocks_Integration extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {

        /**
         * Payment method name (must match the gateway ID).
         *
         * @var string
         */
        protected $name = CTP_GATEWAY_ID;

        /**
         * Load gateway settings.
         *
         * @return void
         */
        public function initialize() {
                $this->settings = get_option( 'woocommerce_' . CTP_GATEWAY_ID . '_settings', array() );
                $this->settings = is_array( $this->settings ) ? $this->settings : array();
        }

        /**
         * Only active when the gateway is enabled.
         *
         * @return bool
         */
        public function is_active() {
                return isset( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'];
        }

        /**
         * Scripts to register for the block checkout.
         *
         * @return array
         */
        public function get_payment_method_script_handles() {
                wp_register_script(
                        'ctp-blocks-checkout',
                        CTP_PLUGIN_URL . 'public/js/blocks-checkout.js',
                        array(
                                'wc-blocks-registry',
                                'wc-settings',
                                'wc-sanitize',
                                'wp-element',
                                'wp-html-entities',
                                'wp-i18n',
                        ),
                        CTP_VERSION,
                        true
                );
                if ( function_exists( 'wp_set_script_translations' ) ) {
                        wp_set_script_translations( 'ctp-blocks-checkout', 'card-to-card-payment', CTP_PLUGIN_DIR . 'languages' );
                }
                return array( 'ctp-blocks-checkout' );
        }

        /**
         * Data passed to the block checkout script.
         *
         * @return array
         */
        public function get_payment_method_data() {
                $option      = $this->settings;
                $title       = isset( $option['title'] ) && '' !== trim( (string) $option['title'] ) ? $option['title'] : __( 'Card-to-Card Payment', 'card-to-card-payment' );
                $description = isset( $option['description'] ) ? $option['description'] : '';
                if ( '' === trim( (string) $description ) ) {
                        $description = __( 'Pay by transferring the order total to one of our bank cards (card-to-card), then upload your payment receipt for manual verification.', 'card-to-card-payment' );
                }

                return array(
                        'title'       => $title,
                        'description' => $description,
                        'supports'    => array(
                                'features' => $this->get_supported_features(),
                        ),
                );
        }
}
