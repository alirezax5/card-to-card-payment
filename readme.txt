=== WooCommerce Card-to-Card Payment ===
Contributors: ctppayment
Tags: woocommerce, payment, gateway, card-to-card, manual payment, receipt, iran
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
WC requires at least: 6.0
WC tested up to: 10.0

Manual card-to-card payment gateway for WooCommerce with secure receipt upload and admin verification. Persian-first, fully translatable, HPOS compatible.

== Description ==

WooCommerce Card-to-Card Payment adds a manual bank payment gateway to WooCommerce. The customer transfers the order total to one of your bank cards (card-to-card), uploads the payment receipt, and you verify the payment manually. The order is never marked as paid automatically.

**Features**

* Real WooCommerce payment gateway (WooCommerce → Settings → Payments)
* Unlimited bank cards via a dynamic admin repeater (add, remove, enable/disable, reorder)
* Card numbers handled strictly as strings; formatted for display as `1234 5678 1234 5678`
* One-click copy button for card numbers (desktop and mobile, with fallback)
* Exact WooCommerce order total shown with the shop's currency formatting
* Orders placed with this gateway stay in **Pending payment** until a receipt is uploaded; a submitted receipt moves the order to **On hold** for review
* **Dedicated payment page**: after checkout the customer is taken straight to an auto-created page (`[card_to_card_payment]` shortcode) showing your bank cards and the receipt upload form; the thank-you page follows after uploading
* Secure receipt upload: JPG / JPEG / PNG / WEBP only, real MIME checking (anti spoofing), size limit (default 5 MB, configurable)
* Private receipt storage in a protected uploads folder; receipts are served only through an authorized endpoint (IDOR-safe)
* Guest checkout fully supported via WooCommerce order keys
* Receipt replacement after rejection, with full receipt history preserved
* Admin order page panel: thumbnail, full-size view, verify, reject (with reason), remove receipt
* Payment states: waiting → receipt submitted → verified / rejected
* No email takeover: the plugin never overrides WooCommerce's mail system; WooCommerce core emails and other plugins (SMS/notification managers) keep full control of notifications
* HPOS compatible (works with custom order tables enabled or disabled)
* Persian (فارسی) default language with full RTL support, English included, translation-ready (POT/PO/MO)
* Accessible UI: labels, ARIA live regions, keyboard navigation, status not by color alone

== Installation ==

1. In WordPress admin go to **Plugins → Add New Plugin → Upload Plugin**.
2. Upload `card-to-card-payment.zip` and activate the plugin.
3. Go to **WooCommerce → Settings → Payments** and enable **Card-to-Card Payment**.
4. Add your bank cards (number, first name, last name, enabled) and save.
5. Adjust title, description, instructions and the maximum receipt size if needed.

== Frequently Asked Questions ==

= Which file types can customers upload? =

JPG, JPEG, PNG and WEBP images only. The plugin checks the file extension, the declared MIME type and the real file signature (so a PHP file renamed to .jpg is rejected). Maximum default size is 5 MB (configurable 1–50 MB).

= Are receipts public? =

No. Receipts are stored in `wp-content/uploads/ctp-receipts/protected/` with an `.htaccess` deny rule (Apache / LiteSpeed). They are served only through an authorized endpoint that checks admin capability, order ownership, or the guest order key. On Nginx, add a location rule denying access to `/wp-content/uploads/ctp-receipts/`.

= Does it work with guest checkout? =

Yes. Guests access their payment page and upload receipts using WooCommerce's standard order key mechanism. One guest can never access another guest's order or receipt.

= Does it support HPOS? =

Yes. The plugin uses WooCommerce CRUD APIs only (`wc_get_order`, `get_meta`, `update_meta_data`, `save`) and declares HPOS compatibility. It works with HPOS enabled or disabled.

= How are notifications handled? =

The plugin deliberately does NOT send emails and does NOT override or disable WooCommerce's email system. Standard WooCommerce status emails (and any SMS/notification plugin you use) keep working as usual. For custom integrations, the plugin fires public action hooks you can use: `ctp_order_created`, `ctp_receipt_submitted`, `ctp_receipt_rejected` and `ctp_payment_verified` — all receive the `WC_Order` object.

= How do I compile translation files? =

The plugin ships ready-to-use `.pot`, `.po` and `.mo` files. To edit translations: open the `.po` in Poedit, translate, and save (Poedit compiles the `.mo`). Or run: `msgfmt -o card-to-card-payment-fa_IR.mo card-to-card-payment-fa_IR.po`

== Changelog ==

= 1.4.0 =
* New: correct WooCommerce order-status workflow for the Card-to-Card gateway. Orders now stay in **Pending payment** when placed (no receipt yet); a successfully uploaded receipt moves the order to **On hold** (waiting for verification); a rejected receipt returns the order to **Pending payment** so the customer can submit a replacement; verification completes the payment through WooCommerce's native `payment_complete()` lifecycle (processing/completed per order contents, stock reduction, paid date).
* New: order notes for every transition (gateway selected, receipt submitted, receipt rejected, receipt approved), with duplicate-note protection for accidental double submissions.
* New: receipt processing now double-checks the order's payment method before applying any Card-to-Card status change, so other gateways (COD, BACS, online payments) are never touched.
* Fix: rejecting a receipt no longer leaves the order in "On hold" pretending that payment is still under review.
* No destructive migration: existing orders keep their current status; only new transitions follow the new workflow. Paid/verified orders are never demoted.

= 1.3.0 =
* Fix: "Undefined variable $order_id" warning on the order-received (thank you) page for themes whose thankyou.php override still uses the legacy variable (WooCommerce 10.4+ passes only the order object). The theme override now renders warning-free; the fix applies automatically, no theme edit needed.
* Security hardening: receipt attachments are now flagged so the media-library hiding rule works as intended; a per-order receipt upload cap prevents unbounded file growth (filter `ctp_max_receipts_per_order`, default 30); the post-upload receipt preview is built with safe DOM APIs instead of innerHTML; hardened Content-Disposition filename.

= 1.2.0 =
* Fix: PHP warning "Object of class ...Overrides\Order could not be converted to int" on the HPOS orders list — the payment-status column now accepts the order object WooCommerce passes.
* New: payment page heading and description can be edited in the gateway settings (WooCommerce → Settings → Payments → Card-to-Card Payment); empty values hide them.

= 1.1.1 =
* Fix: verified payments now appear in WooCommerce Analytics (Orders / Revenue) immediately. Stores using the scheduled analytics importer no longer wait for the next batch — verification triggers the per-order import itself.

= 1.1.0 =
* New: dedicated payment page — after placing the order the customer is redirected to a page showing the order summary, bank cards and the receipt upload form first; the thank-you page follows after the upload.
* New: automatic redirect from the payment page to the order-received page after a successful receipt upload.
* Removed: plugin email notifications. The plugin no longer sends or overrides emails — WooCommerce core and other plugins manage notifications; public `ctp_*` action hooks remain available for integrations.

= 1.0.0 =
* Initial release: gateway, unlimited cards repeater, secure receipt upload with admin verification, guest support, HPOS compatibility, Persian/English translations, WooCommerce emails.

== Upgrade Notice ==

= 1.4.0 =
Order statuses now follow the correct workflow: Pending payment until a receipt is uploaded, On hold while waiting for verification, back to Pending payment after rejection. Existing orders are not changed.

= 1.3.0 =
Fixes the "Undefined variable $order_id" warning on the thank-you page (no theme edit needed) plus several security hardenings.

= 1.2.0 =
Fixes the HPOS orders-list PHP warning and adds editable payment page texts.

= 1.1.1 =
Verified card-to-card payments are now reflected in WooCommerce Analytics right away.

= 1.1.0 =
New dedicated payment page flow; email notifications removed (other plugins / WooCommerce core handle notifications).
