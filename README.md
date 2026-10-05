# WooCommerce Card-to-Card Payment

Manual **Card-to-Card Payment Gateway for WooCommerce** with secure receipt upload and manual admin verification.

Persian-first, fully translatable, accessible, and compatible with **WooCommerce HPOS**.

## Plugin Information

| Property | Value |
|---|---|
| **Contributors** | AI |
| **Publisher** | alirezax5 |
| **Version** | 1.4.0 |
| **Requires WordPress** | 5.8+ |
| **Tested up to WordPress** | 6.7 |
| **Requires PHP** | 7.4+ |
| **Requires WooCommerce** | 6.0+ |
| **Tested up to WooCommerce** | 10.0 |
| **License** | GPLv2 or later |

### Tags

`woocommerce` `payment` `gateway` `card-to-card` `manual-payment` `receipt` `iran`

## Description

**WooCommerce Card-to-Card Payment** adds a manual bank payment gateway to WooCommerce.

The payment process is simple:

1. The customer places an order using the Card-to-Card gateway.
2. The customer transfers the exact order total to one of your configured bank cards.
3. The customer uploads the payment receipt.
4. An administrator reviews the receipt.
5. The administrator approves or rejects the payment.

> The plugin never marks an order as paid automatically. Payment is completed only after manual verification.

## Features

- Real WooCommerce payment gateway under **WooCommerce → Settings → Payments**
- Unlimited bank cards with add/remove, enable/disable and reorder support
- Card numbers handled as strings and formatted as `1234 5678 1234 5678`
- One-click copy button for card numbers
- Exact WooCommerce order total using shop currency formatting
- Correct order status workflow: **Pending payment → On hold → Processing/Completed**
- Dedicated payment page using `[card_to_card_payment]`
- Secure JPG/JPEG/PNG/WEBP receipt uploads with real MIME validation
- Configurable upload size (default 5 MB)
- Private protected receipt storage
- Guest checkout support through WooCommerce order keys
- Receipt replacement after rejection with history preservation
- Admin verification/rejection controls
- No takeover of WooCommerce emails
- HPOS compatibility
- Persian-first with RTL support and English translations
- Accessible labels, ARIA live regions and keyboard navigation

## Order Status Workflow

```text
Order Created
     │
     ▼
Pending Payment
     │
     │ Receipt Uploaded
     ▼
   On Hold
     │
     ├── Receipt Rejected ──► Pending Payment
     │
     └── Receipt Approved
                │
                ▼
       payment_complete()
                │
                ▼
      Processing / Completed
```

Existing paid or verified orders are never automatically demoted.

## Dedicated Payment Page

After checkout, the customer is redirected to a dedicated payment page containing:

```text
[card_to_card_payment]
```

It displays the order summary, order total, available bank cards, cardholder information, copy controls, receipt upload form, and current payment status.

After a successful receipt upload, the customer is redirected to the WooCommerce order-received / thank-you page.

## Secure Receipt Upload

Supported formats:

- JPG
- JPEG
- PNG
- WEBP

The plugin validates file extension, declared MIME type, and real file signature.

Default maximum size is **5 MB**, configurable between **1–50 MB**.

The default per-order receipt limit is **30**, adjustable with:

```php
ctp_max_receipts_per_order
```

## Private Receipt Storage

Receipts are stored under:

```text
wp-content/uploads/ctp-receipts/protected/
```

For Apache/LiteSpeed, an `.htaccess` deny rule protects the directory. Receipts are served only through an authorized endpoint checking administrator capability, authenticated order ownership, or a valid guest order key.

For Nginx, direct access to the following path should be denied:

```text
/wp-content/uploads/ctp-receipts/
```

## Admin Order Management

Administrators can:

- View receipt thumbnails and full-size receipts
- Verify payments
- Reject payments with a reason
- Remove receipts
- Review receipt history
- Inspect payment state

## Notifications

The plugin does **not** replace or disable WooCommerce's email system.

Public integration hooks:

```php
ctp_order_created
ctp_receipt_submitted
ctp_receipt_rejected
ctp_payment_verified
```

All hooks receive the `WC_Order` object.

## HPOS Compatibility

The plugin uses WooCommerce CRUD APIs:

```php
wc_get_order()
$order->get_meta()
$order->update_meta_data()
$order->save()
```

HPOS works both enabled and disabled.

## Localization

Included languages:

- فارسی (Persian)
- English

Included translation formats:

```text
.pot
.po
.mo
```

Compile Persian translations with:

```bash
msgfmt -o card-to-card-payment-fa_IR.mo card-to-card-payment-fa_IR.po
```

## Installation

1. Go to **Plugins → Add New Plugin → Upload Plugin**.
2. Upload `card-to-card-payment.zip` and activate it.
3. Go to **WooCommerce → Settings → Payments**.
4. Enable **Card-to-Card Payment**.
5. Add bank cards and save.
6. Customize gateway/payment-page text and maximum receipt size if needed.

## Frequently Asked Questions

### Which file types can customers upload?

JPG, JPEG, PNG and WEBP only. The plugin validates the extension, MIME type and real file signature.

### Are receipts public?

No. They are stored in a protected uploads directory and served through an authorization-controlled endpoint.

### Does guest checkout work?

Yes. WooCommerce's standard order-key mechanism is used.

### Does the plugin support HPOS?

Yes.

### How are notifications handled?

WooCommerce core and third-party notification plugins remain in control. Custom integrations can use the public `ctp_*` hooks.

## Changelog

### 1.4.0

- Correct WooCommerce order-status workflow.
- Orders remain **Pending payment** until receipt submission.
- Receipt submission moves orders to **On hold**.
- Rejection returns orders to **Pending payment**.
- Approval uses native `payment_complete()`.
- Added order notes with duplicate-note protection.
- Added payment-method checks before Card-to-Card status changes.
- Fixed rejected receipts leaving orders incorrectly on hold.
- No destructive migration of existing orders.

### 1.3.0

- Fixed `Undefined variable $order_id` on the thank-you page.
- Improved receipt attachment privacy handling.
- Added per-order receipt upload cap.
- Replaced unsafe preview `innerHTML` usage with safe DOM APIs.
- Hardened `Content-Disposition` filenames.

### 1.2.0

- Fixed HPOS order-list object-to-int PHP warning.
- Added editable payment-page heading and description.

### 1.1.1

- Verified payments now appear in WooCommerce Analytics immediately.

### 1.1.0

- Added dedicated payment page.
- Added automatic redirect to thank-you page after upload.
- Removed plugin-owned email notifications.

### 1.0.0

- Initial release.

## Upgrade Notice

### 1.4.0

Correct order workflow: **Pending payment → On hold → Processing/Completed**, with rejection returning the order to **Pending payment**.

### 1.3.0

Fixes the `$order_id` warning and adds receipt security hardening.

### 1.2.0

Fixes the HPOS orders-list warning and adds editable payment-page text.

### 1.1.1

Verified payments are reflected in WooCommerce Analytics immediately.

### 1.1.0

Introduces the dedicated payment-page flow and removes plugin-owned email notifications.

## License

**GNU General Public License v2.0 or later**

https://www.gnu.org/licenses/gpl-2.0.html

## Publisher

**alirezax5**
