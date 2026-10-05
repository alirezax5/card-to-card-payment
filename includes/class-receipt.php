<?php
/**
 * Secure payment receipt upload, validation and private viewing endpoint.
 *
 * Security model:
 *  - CSRF: WordPress nonce checked on every AJAX upload.
 *  - Authorization: CTP_Security::can_access_order() (admin cap, order owner
 *    or valid guest order key). The order ID from the client is never trusted
 *    on its own (IDOR protection).
 *  - File validation: extension whitelist + real MIME detection
 *    (wp_check_filetype_and_ext + getimagesize + finfo), size limit,
 *    explicit rejection of dangerous file types.
 *  - Storage: protected uploads directory with .htaccess deny rules; files
 *    are served only through the authenticated endpoint.
 *
 * @package CTP
 */

defined( 'ABSPATH' ) || exit;

class CTP_Receipt {

        /** Allowed image extensions => MIME types (strict whitelist). */
        const ALLOWED = array(
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png'  => 'image/png',
                'webp' => 'image/webp',
        );

        /** Dangerous extensions that are always rejected with an explicit message. */
        const BLOCKED = array( 'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar', 'exe', 'js', 'html', 'htm', 'svg', 'sh', 'bat', 'cgi', 'htaccess' );

        /**
         * Maximum number of receipts (current + archived history) accepted per
         * order. Prevents unbounded file/history growth from repeated uploads.
         * Override with the `ctp_max_receipts_per_order` filter (0 = unlimited).
         */
        const MAX_RECEIPTS_PER_ORDER = 30;

        /**
         * Hook AJAX + endpoint handlers.
         */
        public function __construct() {
                add_action( 'wp_ajax_ctp_upload_receipt', array( $this, 'ajax_upload' ) );
                add_action( 'wp_ajax_nopriv_ctp_upload_receipt', array( $this, 'ajax_upload' ) );
                add_action( 'admin_post_ctp_receipt', array( $this, 'serve_receipt' ) );
                add_action( 'admin_post_nopriv_ctp_receipt', array( $this, 'serve_receipt' ) );
        }

        /**
         * Build a private receipt URL routed through the secure endpoint.
         *
         * @param WC_Order $order         Order object.
         * @param int      $attachment_id Attachment ID (0 = current receipt).
         * @return string
         */
        public static function get_receipt_url( $order, $attachment_id = 0 ) {
                $args = array(
                        'action'   => 'ctp_receipt',
                        'order_id' => $order->get_id(),
                );

                if ( $attachment_id ) {
                        $args['receipt'] = absint( $attachment_id );
                }

                // Guests need the order key in the URL (hash-compared server side).
                if ( ! CTP_Security::is_store_admin() && ! CTP_Security::user_owns_order( $order ) ) {
                        $args['order_key'] = rawurlencode( CTP_Security::sanitize_order_key( $order->get_order_key( 'edit' ) ) );
                }

                return esc_url_raw( add_query_arg( $args, admin_url( 'admin-post.php' ) ) );
        }

        /**
         * AJAX handler: customer (or guest) uploads a payment receipt.
         *
         * @return void
         */
        public function ajax_upload() {
                // CSRF protection.
                if ( ! check_ajax_referer( 'ctp_public', 'nonce', false ) ) {
                        wp_send_json_error(
                                array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'card-to-card-payment' ) ),
                                403
                        );
                }

                $order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
                $order    = $order_id ? wc_get_order( $order_id ) : false;

                if ( ! $order instanceof WC_Order ) {
                        wp_send_json_error(
                                array( 'message' => __( 'The order could not be found.', 'card-to-card-payment' ) ),
                                404
                        );
                }

                if ( CTP_GATEWAY_ID !== $order->get_payment_method( 'edit' ) ) {
                        wp_send_json_error(
                                array( 'message' => __( 'This order does not use card-to-card payment.', 'card-to-card-payment' ) ),
                                400
                        );
                }

                // IDOR protection: admin cap, order ownership or valid guest order key.
                if ( ! CTP_Security::can_access_order( $order ) ) {
                        wp_send_json_error(
                                array( 'message' => __( 'You are not allowed to upload a receipt for this order.', 'card-to-card-payment' ) ),
                                403
                        );
                }

                if ( ! CTP_Order::can_upload_receipt( $order ) ) {
                        wp_send_json_error(
                                array( 'message' => __( 'A receipt for this order has already been verified. Please contact the store if you need to change it.', 'card-to-card-payment' ) ),
                                409
                        );
                }

                if ( empty( $_FILES['receipt'] ) || ! is_array( $_FILES['receipt'] ) ) {
                        wp_send_json_error(
                                array( 'message' => __( 'Please choose a file first.', 'card-to-card-payment' ) ),
                                400
                        );
                }

                $file = $_FILES['receipt']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated by self::validate_upload().

                $result = self::process_upload( $order, $file );

                if ( is_wp_error( $result ) ) {
                        CTP_Gateway::log( sprintf( 'Receipt upload rejected for order #%1$d: %2$s', $order->get_id(), $result->get_error_code() ), 'warning' );
                        wp_send_json_error(
                                array( 'message' => $result->get_error_message() ),
                                400
                        );
                }

                wp_send_json_success(
                        array(
                                'message'       => __( 'Receipt uploaded successfully.', 'card-to-card-payment' ),
                                'status_key'    => CTP_Status::RECEIPT_SUBMITTED,
                                'status_label'  => CTP_Status::label( CTP_Status::RECEIPT_SUBMITTED ),
                                'uploaded_at'   => $result['uploaded_at_display'],
                                'thumbnail_url' => $result['thumbnail_url'],
                                'receipt_url'   => $result['receipt_url'],
                        )
                );
        }

        /**
         * Validate and store an uploaded receipt for an order.
         *
         * On SUCCESS the WooCommerce order moves to `on-hold` (payment
         * evidence received, waiting for manual review). On ANY validation
         * or storage failure the order is left untouched, so it remains in
         * Pending payment exactly as before the attempt.
         *
         * @param WC_Order $order Order object.
         * @param array    $file  One entry from $_FILES.
         * @return array|WP_Error Data array on success, WP_Error on failure.
         */
        public static function process_upload( $order, $file ) {
                // Only card-to-card orders may receive receipts/status changes.
                if ( CTP_GATEWAY_ID !== $order->get_payment_method( 'edit' ) ) {
                        return new WP_Error(
                                'ctp_wrong_gateway',
                                __( 'This order does not use card-to-card payment.', 'card-to-card-payment' )
                        );
                }

                $check = self::validate_upload( $file );
                if ( is_wp_error( $check ) ) {
                        return $check;
                }

                // Cap the number of receipts stored per order (current + history).
                $receipts_cap = (int) apply_filters( 'ctp_max_receipts_per_order', self::MAX_RECEIPTS_PER_ORDER, $order );
                if ( $receipts_cap > 0 ) {
                        $existing_count = absint( CTP_Meta::get( $order, CTP_Meta::RECEIPT_ID ) ) ? 1 : 0;
                        $existing_meta  = CTP_Meta::get( $order, CTP_Meta::RECEIPT_HISTORY );
                        $existing_count += is_array( $existing_meta ) ? count( $existing_meta ) : 0;

                        if ( $existing_count >= $receipts_cap ) {
                                return new WP_Error(
                                        'ctp_receipt_limit',
                                        __( 'Too many receipts have been uploaded for this order. Please contact the store to complete the verification.', 'card-to-card-payment' )
                                );
                        }
                }

                // Make sure WordPress upload/media APIs are available (front-end context).
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';

                // Route the upload into a protected directory.
                $dir_filter = function ( $dirs ) {
                        $dirs['subdir'] = '/ctp-receipts/protected' . $dirs['subdir'];
                        $dirs['path']   = $dirs['basedir'] . $dirs['subdir'];
                        $dirs['url']    = $dirs['baseurl'] . $dirs['subdir'];
                        return $dirs;
                };
                add_filter( 'upload_dir', $dir_filter );

                $overrides = array(
                        'test_form' => false,
                        'test_type' => true,
                        'mimes'     => self::ALLOWED,
                        'action'    => 'ctp_upload_receipt',
                );

                $moved = wp_handle_upload( $file, $overrides, current_time( 'Y/m' ) );

                remove_filter( 'upload_dir', $dir_filter );

                if ( ! is_array( $moved ) || empty( $moved['file'] ) ) {
                        $message = is_array( $moved ) && isset( $moved['error'] ) ? $moved['error'] : __( 'Upload failed. Please try again.', 'card-to-card-payment' );
                        return new WP_Error( 'ctp_upload_failed', $message );
                }

                self::protect_directory( dirname( $moved['file'] ) );

                // Never keep the client filename: generate a safe, unpredictable name.
                $ext      = pathinfo( $moved['file'], PATHINFO_EXTENSION );
                $ext      = strtolower( preg_replace( '/[^a-z0-9]/', '', (string) $ext ) );
                $new_name = sanitize_file_name(
                        sprintf(
                                'receipt-order-%1$d-%2$s.%3$s',
                                $order->get_id(),
                                wp_generate_password( 16, false ),
                                $ext
                        )
                );
                $new_path = dirname( $moved['file'] ) . '/' . $new_name;
                if ( ! wp_is_file_mod_allowed( 'ctp_receipt' ) || ! @rename( $moved['file'], $new_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                        $new_path = $moved['file'];
                }

                $attachment_id = wp_insert_attachment(
                        array(
                                'post_mime_type' => isset( $moved['type'] ) ? $moved['type'] : 'image/jpeg',
                                'post_title'     => sprintf(
                                        /* translators: %s: order number. */
                                        __( 'Payment receipt for order %s', 'card-to-card-payment' ),
                                        $order->get_order_number()
                                ),
                                'post_status'    => 'inherit',
                        ),
                        $new_path
                );

                if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
                        return new WP_Error( 'ctp_attachment_failed', __( 'The receipt could not be stored. Please try again.', 'card-to-card-payment' ) );
                }

                // Mark the attachment so media-library queries can hide private
                // receipts from users without store-management capabilities.
                update_post_meta( (int) $attachment_id, '_ctp_is_payment_receipt', 1 );

                wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $new_path ) );

                $now_gmt = current_time( 'mysql', true );

                // Preserve payment history: archive the previous receipt, never delete it.
                $previous_id = absint( CTP_Meta::get( $order, CTP_Meta::RECEIPT_ID ) );
                $history     = CTP_Meta::get( $order, CTP_Meta::RECEIPT_HISTORY );
                $history     = is_array( $history ) ? $history : array();

                if ( $previous_id ) {
                        $history[] = array(
                                'attachment_id'   => $previous_id,
                                'uploaded_at'     => (string) CTP_Meta::get( $order, CTP_Meta::RECEIPT_UPLOADED_AT ),
                                'replaced_at'     => $now_gmt,
                                'previous_status' => (string) CTP_Meta::get( $order, CTP_Meta::STATUS ),
                        );
                }

                CTP_Meta::set( $order, CTP_Meta::RECEIPT_ID, (int) $attachment_id );
                CTP_Meta::set( $order, CTP_Meta::RECEIPT_UPLOADED_AT, $now_gmt );
                CTP_Meta::set( $order, CTP_Meta::STATUS, CTP_Status::RECEIPT_SUBMITTED );
                CTP_Meta::set( $order, CTP_Meta::RECEIPT_HISTORY, $history );
                CTP_Meta::set( $order, CTP_Meta::RECEIPT_REJECTED_AT, null );
                CTP_Meta::set( $order, CTP_Meta::RECEIPT_REJECTION_REASON, null );

                /*
                 * WooCommerce state: the receipt was validated and stored, so
                 * the order is now waiting for manual review -> on-hold.
                 * Already-paid orders are never demoted; an order that is
                 * already on-hold (replacement upload) is not re-transitioned,
                 * which also avoids duplicate status-change notes.
                 * The transition runs BEFORE the receipt note so that a
                 * duplicated submission finds the receipt note as the latest
                 * note and is skipped by add_note_once().
                 */
                if ( ! $order->is_paid() && ! $order->has_status( 'on-hold' ) ) {
                        $order->update_status( 'on-hold', '' );
                }

                CTP_Order::add_note_once(
                        $order,
                        __( 'Card-to-Card payment receipt submitted. Waiting for verification.', 'card-to-card-payment' ),
                        true
                );

                $order->save();

                /**
                 * Fires after a payment receipt was successfully stored on the order.
                 *
                 * @param WC_Order $order          Order object.
                 * @param int      $attachment_id  Receipt attachment ID.
                 */
                do_action( 'ctp_receipt_submitted', $order, (int) $attachment_id );

                return array(
                        'attachment_id'       => (int) $attachment_id,
                        'uploaded_at_display' => CTP_Order::format_meta_datetime( $now_gmt ),
                        'thumbnail_url'       => self::get_receipt_url( $order, (int) $attachment_id ),
                        'receipt_url'         => self::get_receipt_url( $order, (int) $attachment_id ),
                );
        }

        /**
         * Validate one $_FILES entry: size, extension, real MIME type.
         *
         * @param array $file File entry.
         * @return true|WP_Error
         */
        public static function validate_upload( $file ) {
                if ( ! is_array( $file ) || ! isset( $file['tmp_name'] ) ) {
                        return new WP_Error( 'ctp_no_file', __( 'Please choose a file first.', 'card-to-card-payment' ) );
                }

                if ( isset( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
                        switch ( (int) $file['error'] ) {
                                case UPLOAD_ERR_INI_SIZE:
                                case UPLOAD_ERR_FORM_SIZE:
                                        return new WP_Error( 'ctp_too_large', sprintf( /* translators: %s: max size label. */ __( 'The uploaded file is too large. Maximum allowed size: %s.', 'card-to-card-payment' ), CTP_Gateway::get_max_file_size_label() ) );
                                case UPLOAD_ERR_NO_FILE:
                                        return new WP_Error( 'ctp_empty', __( 'Please choose a file first.', 'card-to-card-payment' ) );
                                case UPLOAD_ERR_PARTIAL:
                                        return new WP_Error( 'ctp_partial', __( 'The file was only partially uploaded. Please try again.', 'card-to-card-payment' ) );
                                default:
                                        return new WP_Error( 'ctp_upload_error', __( 'Upload failed. Please try again.', 'card-to-card-payment' ) );
                        }
                }

                $name  = isset( $file['name'] ) ? (string) wp_unslash( $file['name'] ) : '';
                $size  = isset( $file['size'] ) ? (int) $file['size'] : 0;
                $lower = strtolower( $name );

                // Explicit blocklist for dangerous files (defense in depth; the whitelist below is authoritative).
                foreach ( self::BLOCKED as $bad ) {
                        if ( preg_match( '/\.' . preg_quote( $bad, '/' ) . '(\.|$)/', $lower ) ) {
                                return new WP_Error( 'ctp_dangerous', __( 'This file type is not allowed. Please upload a JPG, JPEG, PNG or WEBP image.', 'card-to-card-payment' ) );
                        }
                }

                // Extension whitelist (checked on the client-provided name).
                $ext = strtolower( pathinfo( $lower, PATHINFO_EXTENSION ) );
                if ( ! isset( self::ALLOWED[ $ext ] ) ) {
                        return new WP_Error( 'ctp_bad_ext', __( 'This file type is not allowed. Please upload a JPG, JPEG, PNG or WEBP image.', 'card-to-card-payment' ) );
                }

                // Size limit (declared by PHP).
                $max = CTP_Gateway::get_max_file_size_bytes();
                if ( $size > $max ) {
                        return new WP_Error( 'ctp_too_large', sprintf( /* translators: %s: max size label. */ __( 'The uploaded file is too large. Maximum allowed size: %s.', 'card-to-card-payment' ), CTP_Gateway::get_max_file_size_label() ) );
                }

                $tmp = $file['tmp_name'];
                if ( ! is_uploaded_file( $tmp ) && ! file_exists( $tmp ) ) {
                        return new WP_Error( 'ctp_missing_tmp', __( 'Upload failed. Please try again.', 'card-to-card-payment' ) );
                }

                // Real size on disk (never trust the declared size alone).
                $real_size = (int) filesize( $tmp );
                if ( $real_size > $max ) {
                        return new WP_Error( 'ctp_too_large', sprintf( /* translators: %s: max size label. */ __( 'The uploaded file is too large. Maximum allowed size: %s.', 'card-to-card-payment' ), CTP_Gateway::get_max_file_size_label() ) );
                }
                if ( $real_size < 12 ) {
                        return new WP_Error( 'ctp_not_image', __( 'This file is not a valid image. Please upload a JPG, JPEG, PNG or WEBP image.', 'card-to-card-payment' ) );
                }

                // MIME spoofing protection: check extension + real content signature.
                $check = wp_check_filetype_and_ext( $tmp, $name, self::ALLOWED );
                if ( empty( $check['ext'] ) || empty( $check['type'] ) || ! isset( self::ALLOWED[ $check['ext'] ] ) || self::ALLOWED[ $check['ext'] ] !== $check['type'] ) {
                        return new WP_Error( 'ctp_mime_mismatch', __( 'The file content does not match an allowed image type. Please upload a real JPG, JPEG, PNG or WEBP image.', 'card-to-card-payment' ) );
                }

                // Confirm the content is actually a decodable image.
                $info = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                if ( false === $info || empty( $info['mime'] ) || ! in_array( $info['mime'], self::ALLOWED, true ) ) {
                        return new WP_Error( 'ctp_not_image', __( 'This file is not a valid image. Please upload a JPG, JPEG, PNG or WEBP image.', 'card-to-card-payment' ) );
                }

                return true;
        }

        /**
         * Ensure the directory is protected from direct browsing where possible.
         *
         * @param string $dir Directory path.
         * @return void
         */
        public static function protect_directory( $dir ) {
                $base = self::protected_dir();
                if ( '' === $base ) {
                        return;
                }

                $htaccess = trailingslashit( $base ) . '.htaccess';
                if ( ! file_exists( $htaccess ) && wp_is_writable( $base ) ) {
                        file_put_contents( $htaccess, "# Card-to-Card Payment: receipts are private.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n" );
                }

                $index = trailingslashit( $base ) . 'index.html';
                if ( ! file_exists( $index ) && wp_is_writable( $base ) ) {
                        file_put_contents( $index, '' );
                }

                if ( $dir && is_dir( $dir ) && 0 === strpos( wp_normalize_path( $dir ), wp_normalize_path( trailingslashit( $base ) ) ) ) {
                        $sub_index = trailingslashit( $dir ) . 'index.html';
                        if ( ! file_exists( $sub_index ) && wp_is_writable( $dir ) ) {
                                file_put_contents( $sub_index, '' );
                        }
                }
        }

        /**
         * Protected storage directory under uploads.
         *
         * @return string Absolute path or empty string on failure.
         */
        public static function protected_dir() {
                static $dir = null;
                if ( null !== $dir ) {
                        return $dir;
                }
                $uploads = wp_get_upload_dir();
                $dir     = trailingslashit( $uploads['basedir'] ) . 'ctp-receipts/protected';
                if ( ! is_dir( $dir ) ) {
                        wp_mkdir_p( $dir );
                }
                return is_dir( $dir ) ? $dir : '';
        }

        /**
         * Secure receipt-view endpoint: streams the image only to authorized
         * visitors (store admin, order owner, or holder of the guest order key)
         * for receipts that belong to the requested order.
         *
         * @return void
         */
        public function serve_receipt() {
                $order_id      = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
                $attachment_id = isset( $_GET['receipt'] ) ? absint( wp_unslash( $_GET['receipt'] ) ) : 0;

                $order = $order_id ? wc_get_order( $order_id ) : false;

                if ( ! $order instanceof WC_Order ) {
                        self::deny( 404 );
                }

                // IDOR protection: verify access to the ORDER first.
                if ( ! CTP_Security::can_access_order( $order ) ) {
                        self::deny( 403 );
                }

                if ( ! $attachment_id ) {
                        $attachment_id = absint( CTP_Meta::get( $order, CTP_Meta::RECEIPT_ID ) );
                }

                // Verify the receipt belongs to this order (no cross-order access).
                if ( ! CTP_Security::can_view_receipt( $order, $attachment_id ) ) {
                        self::deny( 403 );
                }

                $path = get_attached_file( $attachment_id );
                if ( ! $path || ! file_exists( $path ) ) {
                        self::deny( 404 );
                }

                $mime = get_post_mime_type( $attachment_id );
                if ( ! in_array( $mime, self::ALLOWED, true ) ) {
                        self::deny( 403 );
                }

                nocache_headers();
                header( 'X-Content-Type-Options: nosniff' );
                header( 'Content-Type: ' . $mime );
                header( 'Content-Length: ' . (string) filesize( $path ) );
                header( 'Content-Disposition: inline; filename="' . sanitize_file_name( basename( $path ) ) . '"' );
                readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
                exit;
        }

        /**
         * Deny a receipt request with a generic message (no information leak).
         *
         * @param int $code HTTP status.
         * @return void
         */
        protected static function deny( $code ) {
                status_header( $code );
                nocache_headers();
                if ( 404 === $code ) {
                        wp_die( esc_html__( 'The requested receipt could not be found.', 'card-to-card-payment' ), '', array( 'response' => 404 ) );
                }
                wp_die( esc_html__( 'You are not allowed to view this receipt.', 'card-to-card-payment' ), '', array( 'response' => 403 ) );
        }
}
