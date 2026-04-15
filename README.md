# PayChangu payment plugin for aMember Pro

Single-file plugin that connects [aMember Pro](https://www.amember.com/) to [PayChangu](https://paychangu.com/) Standard Checkout: hosted payment page, webhooks (IPN), and server-side transaction verification.

**Version:** 1.0.0 (see `PLUGIN_REVISION` in `paychangu.php`)

## Requirements

- **aMember Pro** (v4 or compatible installation with the paysystem plugin API)
- **PHP** with `curl`, `json`, and `openssl` enabled (typical on hosting)
- A PayChangu merchant account and API keys from the [PayChangu Dashboard](https://dashboard.paychangu.com)

## Installation

### 1. Add the plugin file

Copy `paychangu.php` into your aMember installation:

```text
application/default/plugins/payment/paychangu.php
```

Use SFTP, SSH, or your host’s file manager. The `payment` folder already exists with other gateway plugins.

### 2. Enable the plugin in aMember

1. Sign in to **aMember Control Panel**.
2. Open **Configuration** (or **Setup**) → **Add-ons / Plugins** (wording depends on your aMember version).
3. Find **PayChangu** and enable it if it is not already active.

### 3. Configure the payment method

1. Go to **Configuration** → **Manage Products / Payment Methods** (or **Setup / Payment Methods**).
2. Add or edit a payment method and select **PayChangu**.
3. Fill in the fields:

   | Field | Where to get it |
   |--------|------------------|
   | **PayChangu Secret Key** | Dashboard → **Settings** → **API & Webhooks** |
   | **PayChangu Public Key** | Same section |
   | **Webhook Secret Key** | Same section (used to verify webhook `Signature` headers) |
   | **Payment Currency** | **MWK** or **USD** (must match your product/invoice currency in aMember) |

4. Save the payment method and assign it to the products that should use PayChangu.

### 4. Configure PayChangu (dashboard)

1. Log in to [PayChangu Dashboard](https://dashboard.paychangu.com) → **Settings** → **API & Webhooks**.
2. Set **Webhook URL** to exactly:

   ```text
   https://YOUR-AMEMBER-SITE-ROOT/payment/paychangu/ipn
   ```

   Replace `YOUR-AMEMBER-SITE-ROOT` with the same base URL as in aMember (**Configuration** → **Site settings** → root URL), with no trailing slash before `/payment/...`.

3. Enable the webhook events you need (e.g. successful payment).
4. Save.

The plugin also shows this IPN URL in the PayChangu setup screen in aMember (read-only hint).

### 5. After payment: where customers go

The plugin sends PayChangu a **callback URL** of:

```text
{your aMember root URL}/member
```

So after a successful payment, customers are redirected to your **member** area. **Completing the invoice in aMember** still relies on PayChangu **POSTing** to `/payment/paychangu/ipn`. Always keep the webhook URL in the PayChangu dashboard correct; do not rely only on the browser redirect.

To use a different path (for example `/membership/member`), edit the `callback_url` line in `_process()` inside `paychangu.php`.

## PayChangu documentation

- [Standard Checkout](https://developer.paychangu.com/docs/standard-checkout)
- [Webhooks](https://developer.paychangu.com/docs/webhooks)
- [Transaction verification](https://developer.paychangu.com/docs/transaction-verification)

## Troubleshooting

- **Invoices stay “Pending” after payment**  
  Confirm the webhook URL in PayChangu matches `{root_url}/payment/paychangu/ipn`, SSL is valid, and **Secret Key** / **Webhook Secret** in aMember match the dashboard. Check **Logs** → **Errors** in aMember for `paychangu` entries.

- **“Could not handle payments in [CURRENCY]”**  
  Product/invoice currency must be one the plugin supports (**MWK** or **USD** in this repository) and the PayChangu payment method must use the same currency.

- **cURL / API errors**  
  Ensure outbound HTTPS to `api.paychangu.com` is allowed on the server.

## License

MIT (see header in `paychangu.php`).


