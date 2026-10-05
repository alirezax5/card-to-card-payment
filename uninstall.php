<?php
/**
 * Uninstall handler for WooCommerce Card-to-Card Payment.
 *
 * Deliberate, safe cleanup policy:
 *  - Removes the plugin's own options (gateway settings + version marker).
 *  - Does NOT delete order metadata (payment statuses, receipt references,
 *    history): destroying customer order/payment information unexpectedly is
 *    dangerous. WooCommerce keeps functioning; the meta simply becomes inert.
 *  - Does NOT delete uploaded receipt files. They remain in the protected
 *    uploads folder for the store owner to handle manually if desired.
 *
 * @package CTP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Remove plugin options only (gateway settings + version marker + payment
// page marker). The auto-created payment page itself is kept: it may have
// been customized by the site owner.
delete_option( 'woocommerce_card_to_card_settings' );
delete_option( 'ctp_version' );
delete_option( 'ctp_pay_page_id' );

// Multisite-safe: also clean each site in the network.
if ( is_multisite() ) {
        $ctp_sites = get_sites( array( 'number' => 0, 'fields' => 'ids' ) );
        foreach ( $ctp_sites as $ctp_site_id ) {
                switch_to_blog( $ctp_site_id );
                delete_option( 'woocommerce_card_to_card_settings' );
                delete_option( 'ctp_version' );
                delete_option( 'ctp_pay_page_id' );
                restore_current_blog();
        }
}

/*
 * NOTE: Order metadata (_card_to_card_* keys) and receipt files under
 * wp-content/uploads/ctp-receipts/ are intentionally preserved.
 * If you REALLY want to purge everything, you must do it manually via a
 * database backup-aware process; the plugin refuses to destroy customer
 * payment history automatically.
 */
