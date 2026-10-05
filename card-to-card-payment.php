<?php
/**
 * Plugin Name:       WooCommerce Card-to-Card Payment
 * Plugin URI:        https://github.com/alirezax5/card-to-card-payment
 * Description:       Manual card-to-card payment gateway for WooCommerce with secure receipt upload, admin verification, multiple bank cards, Persian/English multilingual support and HPOS compatibility.
 * Version:           1.4.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            alirezax5
 * Author URI:        https://alirezax5.ir
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       card-to-card-payment
 * Domain Path:       /languages
 * WC requires at least: 6.0
 * WC tested up to:   10.0
 *
 * @package CTP
 */

defined( 'ABSPATH' ) || exit;

define( 'CTP_VERSION', '1.4.0' );
define( 'CTP_PLUGIN_FILE', __FILE__ );
define( 'CTP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CTP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CTP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'CTP_GATEWAY_ID', 'card_to_card' );
define( 'CTP_MIN_WC_VERSION', '6.0' );

/**
 * Declare HPOS (custom order tables) compatibility.
 *
 * @return void
 */
function ctp_declare_hpos_compatibility() {
        if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) && method_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil', 'declare_compatibility' ) ) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        }
}
add_action( 'before_woocommerce_init', 'ctp_declare_hpos_compatibility' );

/**
 * Load the text domain early so gateway default settings are translated.
 *
 * @return void
 */
function ctp_load_textdomain() {
        load_plugin_textdomain( 'card-to-card-payment', false, dirname( CTP_PLUGIN_BASENAME ) . '/languages' );
}
add_action( 'plugins_loaded', 'ctp_load_textdomain', 5 );

/**
 * Persian is the plugin default language. When the site locale is neither
 * Persian nor English (i.e. no translation ships for it), fall back to the
 * Persian translation files. Additional languages can be dropped into the
 * languages/ directory without touching the plugin source.
 *
 * @param string $locale The plugin current locale.
 * @param string $domain Text domain.
 * @return string
 */
function ctp_plugin_locale_fallback( $locale, $domain ) {
        if ( 'card-to-card-payment' === $domain && ! in_array( $locale, array( 'fa_IR', 'en_US' ), true ) ) {
                $mo = CTP_PLUGIN_DIR . 'languages/card-to-card-payment-' . $locale . '.mo';
                if ( ! file_exists( $mo ) ) {
                        return 'fa_IR';
                }
        }
        return $locale;
}
add_filter( 'plugin_locale', 'ctp_plugin_locale_fallback', 10, 2 );

/**
 * Re-load the text domain when the site locale changes at runtime
 * (e.g. multilingual plugins switching languages per request).
 *
 * @param string $locale New locale.
 * @return void
 */
function ctp_reload_textdomain_on_locale_change( $locale ) {
        unset( $locale );
        load_plugin_textdomain( 'card-to-card-payment', false, dirname( CTP_PLUGIN_BASENAME ) . '/languages' );
}
add_action( 'change_locale', 'ctp_reload_textdomain_on_locale_change' );

/**
 * Boot the plugin once all plugins are loaded and WooCommerce is available.
 *
 * @return void
 */
function ctp_boot_plugin() {
        if ( ! class_exists( 'WooCommerce' ) ) {
                add_action( 'admin_notices', 'ctp_render_missing_woocommerce_notice' );
                return;
        }

        if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, CTP_MIN_WC_VERSION, '<' ) ) {
                add_action( 'admin_notices', 'ctp_render_wc_version_notice' );
                return;
        }

        require_once CTP_PLUGIN_DIR . 'includes/class-meta.php';
        require_once CTP_PLUGIN_DIR . 'includes/class-security.php';
        require_once CTP_PLUGIN_DIR . 'includes/class-gateway.php';
        require_once CTP_PLUGIN_DIR . 'includes/class-receipt.php';
        require_once CTP_PLUGIN_DIR . 'includes/class-order.php';
        require_once CTP_PLUGIN_DIR . 'includes/class-admin.php';
        require_once CTP_PLUGIN_DIR . 'includes/class-pay-page.php';
        require_once CTP_PLUGIN_DIR . 'includes/class-template-compat.php';
        require_once CTP_PLUGIN_DIR . 'includes/class-plugin.php';

        // Keep legacy theme overrides of checkout/thankyou.php warning-free
        // (WooCommerce 10.4+ no longer passes the $order_id template variable).
        CTP_Template_Compat::init();

        // Block checkout integration (WooCommerce Blocks payment registry).
        // Loaded only when the blocks payment API exists; guarded inside the file.
        if ( class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
                require_once CTP_PLUGIN_DIR . 'includes/class-blocks-integration.php';
                add_action(
                        'woocommerce_blocks_payment_method_type_registration',
                        'ctp_register_blocks_integration'
                );
        }

        CTP_Plugin::instance();
}
add_action( 'plugins_loaded', 'ctp_boot_plugin', 11 );

/**
 * Register the block-checkout payment method integration.
 *
 * @param \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry Blocks payment registry.
 * @return void
 */
function ctp_register_blocks_integration( $registry ) {
        if ( $registry && class_exists( 'CTP_Blocks_Integration' ) ) {
                $registry->register( new CTP_Blocks_Integration() );
        }
}

/**
 * Admin notice shown when WooCommerce is not active.
 *
 * @return void
 */
function ctp_render_missing_woocommerce_notice() {
        if ( ! current_user_can( 'activate_plugins' ) ) {
                return;
        }
        ?>
        <div class="notice notice-error">
                <p>
                        <?php
                        echo wp_kses_post(
                                sprintf(
                                        /* translators: %s: URL to the plugins screen. */
                                        __( 'WooCommerce Card-to-Card Payment requires <a href="%s">WooCommerce</a> to be installed and activated. The payment gateway was not loaded.', 'card-to-card-payment' ),
                                        esc_url( admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' ) )
                                )
                        );
                        ?>
                </p>
        </div>
        <?php
}

/**
 * Admin notice shown when the WooCommerce version is too old.
 *
 * @return void
 */
function ctp_render_wc_version_notice() {
        if ( ! current_user_can( 'activate_plugins' ) ) {
                return;
        }
        ?>
        <div class="notice notice-error">
                <p>
                        <?php
                        echo esc_html(
                                sprintf(
                                        /* translators: %s: minimum WooCommerce version. */
                                        __( 'WooCommerce Card-to-Card Payment requires WooCommerce %s or newer. Please update WooCommerce.', 'card-to-card-payment' ),
                                        CTP_MIN_WC_VERSION
                                )
                        );
                        ?>
                </p>
        </div>
        <?php
}

/**
 * Activation hook: store version marker only. Nothing destructive. The
 * dedicated payment page is auto-created on init (self-healing).
 *
 * @return void
 */
function ctp_activate_plugin() {
        update_option( 'ctp_version', CTP_VERSION, false );
}
register_activation_hook( __FILE__, 'ctp_activate_plugin' );

/**
 * Deactivation hook: clean shutdown, no data changes.
 *
 * @return void
 */
function ctp_deactivate_plugin() {
        // Intentionally left empty: no order data, files or settings are touched.
}
register_deactivation_hook( __FILE__, 'ctp_deactivate_plugin' );
