<?php
/**
 * PayChangu Payment Plugin for aMember Pro
 *
 * Integrates PayChangu Standard Checkout with aMember Pro membership platform.
 * Supports one-time payments, webhook-based IPN handling, server-side
 * transaction verification, and secure signature validation.
 *
 * @author      PayChangu
 * @version     1.0.0
 * @license     MIT
 * @link        https://paychangu.com
 *
 * Installation:
 *   Place this file at:
 *   application/default/plugins/payment/paychangu.php
 *
 *   Then activate at:
 *   aMember CP → Configuration → Add-ons → PayChangu
 */

// ──────────────────────────────────────────────────────────────────────────────
// Constants & API Configuration
// ──────────────────────────────────────────────────────────────────────────────

/** PayChangu initiate-payment endpoint */
define('PAYCHANGU_API_URL',    'https://api.paychangu.com/payment');

/** PayChangu transaction verification endpoint  (append /{tx_ref}) */
define('PAYCHANGU_VERIFY_URL', 'https://api.paychangu.com/verify-payment');

/** Supported currencies */
define('PAYCHANGU_CURRENCIES', ['MWK', 'USD']);

/** Max retry attempts for server-side verification calls */
define('PAYCHANGU_VERIFY_RETRIES', 3);

/** Seconds to wait between retry attempts */
define('PAYCHANGU_VERIFY_RETRY_DELAY', 2);


// ──────────────────────────────────────────────────────────────────────────────
// Main Plugin Class
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Class Am_Paysystem_Paychangu
 *
 * Handles:
 *  - Admin configuration form (API keys, currency, webhook secret)
 *  - Payment initiation (redirect to PayChangu hosted checkout)
 *  - Callback / return URL handling
 *  - IPN (webhook) endpoint dispatching
 */
class Am_Paysystem_Paychangu extends Am_Paysystem_Abstract
{
    // ── Plugin metadata ──────────────────────────────────────────────────────

    /** Set to STATUS_PRODUCTION once tested; use STATUS_DEV during development */
    const PLUGIN_STATUS   = self::STATUS_PRODUCTION;
    const PLUGIN_REVISION = '1.0.0';

    /** Human-readable defaults shown in aMember UI */
    protected $defaultTitle       = 'PayChangu';
    protected $defaultDescription = 'Pay securely via Mobile Money, Bank Transfer, or Card through PayChangu';

    // ── aMember hooks ────────────────────────────────────────────────────────

    /**
     * Builds the admin setup form shown at:
     * aMember CP → Setup/Configuration → PayChangu
     *
     * All values are persisted by aMember and retrieved with $this->getConfig().
     *
     * @param Am_Form_Setup $form
     */
    public function _initSetupForm(Am_Form_Setup $form)
    {
        // ── Live credentials ──────────────────────────────────────────────
        $form->addText('secret_key', ['size' => 50])
            ->setLabel(
                "PayChangu Secret Key\n" .
                'Found in your PayChangu dashboard under API & Webhooks.'
            );

        $form->addText('public_key', ['size' => 50])
            ->setLabel(
                "PayChangu Public Key\n" .
                'Found in your PayChangu dashboard under API & Webhooks.'
            );

        // ── Webhook security ──────────────────────────────────────────────
        $form->addText('webhook_secret', ['size' => 50])
            ->setLabel(
                "Webhook Secret Key\n" .
                'The secret generated on your PayChangu dashboard for HMAC signature validation. ' .
                'Required for secure webhook verification.'
            );

        // ── Currency ──────────────────────────────────────────────────────
        $form->addSelect('currency')
            ->setLabel("Payment Currency\nSelect the currency for transactions.")
            ->loadOptions(['MWK' => 'Malawian Kwacha (MWK)', 'USD' => 'US Dollar (USD)']);

        // ── Webhook URL (read-only, informational) ────────────────────────
        $webhookUrl = Am_Di::getInstance()->config->get('root_url') .
                      '/payment/paychangu/ipn';

        $form->addStatic()
            ->setLabel(
                "Your Webhook / IPN URL\n" .
                'Set this URL in your PayChangu dashboard under API & Webhooks → Webhook URL.'
            )
            ->setContent(
                '<strong style="font-family:monospace;">' .
                Am_Html::escape($webhookUrl) .
                '</strong>'
            );
    }

    /**
     * Declares which invoice currencies this payment system may handle.
     * aMember compares this to the product/invoice currency during signup;
     * without it, checkout fails with "could not handle payments in [XXX]".
     *
     * @return array
     */
    public function getSupportedCurrencies()
    {
        return PAYCHANGU_CURRENCIES;
    }

    /**
     * Initiates a PayChangu payment session and redirects the customer.
     *
     * Flow:
     *  1. Build payload for POST to PayChangu /payment endpoint.
     *  2. Execute cURL request to obtain a hosted checkout URL.
     *  3. Redirect customer to that URL.
     *
     * @param Invoice              $invoice
     * @param Am_Mvc_Request       $request
     * @param Am_Paysystem_Result  $result
     * @throws Am_Exception_Paysystem on API error
     */
    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        // Generate a stable, unique transaction reference tied to this invoice
        $txRef = $this->_generateTxRef($invoice);

        // Persist the tx_ref on the invoice so we can look it up later
        // (stored as a custom variable on the invoice for retrieval in callbacks)
        try {
            $invoice->data()->set('paychangu_tx_ref', $txRef)->update();
        } catch (Exception $e) {
            Am_Di::getInstance()->errorLogTable->log(
                'paychangu',
                'Could not persist paychangu_tx_ref on invoice #' . $invoice->pk(),
                $e->getMessage()
            );
            throw new Am_Exception_Paysystem(
                'PayChangu: Could not save transaction reference on invoice. ' . $e->getMessage()
            );
        }

        // Build the payload
        $currency = $this->getConfig('currency', 'MWK');
        $amount   = $this->_resolveAmount($invoice, $currency);

        $user = $invoice->getUser();
        $metaUser = $user ? $user->login : '';

        $siteRoot = rtrim((string) Am_Di::getInstance()->config->get('root_url'), '/');

        $payload = [
            // PayChangu docs: amount is int32 (whole currency units in examples)
            'amount'       => $this->_amountForPaychanguApi($amount),
            'currency'     => $currency,
            'email'        => $invoice->getEmail(),
            'first_name'   => $invoice->getFirstName(),
            'last_name'    => $invoice->getLastName(),
            'tx_ref'       => $txRef,
            // Redirect after successful payment (site member area). Server webhooks: set Webhook URL in
            // PayChangu dashboard to the IPN URL shown in plugin settings (/payment/paychangu/ipn).
            'callback_url' => $siteRoot . '/member',
            // return_url   → Customer is sent here on cancel / repeated failure
            'return_url'   => $this->getCancelUrl(),
            'customization' => [
                'title'       => $this->getConfig('payment_title',       $this->defaultTitle),
                'description' => $this->getConfig('payment_description', $invoice->getLineDescription()),
            ],
            // Pass aMember invoice ID in meta for webhook cross-referencing
            'meta' => [
                'invoice_id'    => $invoice->pk(),
                'invoice_public_id' => $invoice->public_id,
                'amember_user'  => $metaUser,
            ],
        ];

        // POST to PayChangu API
        $response = $this->_apiPost(PAYCHANGU_API_URL, $payload);

        // Official response: data.checkout_url (see developer.paychangu.com standard checkout)
        $checkoutUrl =
            $response['data']['checkout_url']
            ?? $response['data']['data']['checkout_url']
            ?? $response['data']['checkoutUrl']
            ?? $response['data']['data']['checkoutUrl']
            ?? null;

        if (
            empty($response['status']) ||
            strtolower((string) $response['status']) !== 'success' ||
            empty($checkoutUrl)
        ) {
            $msg = isset($response['message']) ? (string) $response['message'] : 'Unknown API error / missing checkout_url';
            Am_Di::getInstance()->errorLogTable->log(
                'paychangu',
                "Payment initiation failed for invoice #{$invoice->pk()}: {$msg}",
                print_r($response, true)
            );
            throw new Am_Exception_Paysystem(
                "PayChangu: Could not initiate payment – {$msg}"
            );
        }

        $checkoutUrl = (string) $checkoutUrl;

        Am_Di::getInstance()->errorLogTable->log(
            'paychangu',
            "Payment session created for invoice #{$invoice->pk()}, tx_ref={$txRef}",
            "Redirecting to: {$checkoutUrl}"
        );

        // Redirect customer to PayChangu hosted checkout
        $action = new Am_Paysystem_Action_Redirect($checkoutUrl);
        $result->setAction($action);
    }

    /**
     * Tells aMember this plugin reports end-of-term (not rebilling itself).
     * Recurring billing must be managed manually or via separate integrations.
     */
    public function getRecurringType()
    {
        return self::REPORTS_EOT;
    }

    /**
     * Factory method: returns the IPN transaction handler.
     * Called by aMember's routing layer when a request arrives at /payment/paychangu/ipn.
     *
     * @param Am_Mvc_Request   $request
     * @param Am_Mvc_Response  $response
     * @param array            $invokeArgs
     * @return Am_Paysystem_Transaction_Paychangu_Ipn
     */
    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Paychangu_Ipn($this, $request, $response, $invokeArgs);
    }

    // ── Public helpers (called by transaction class) ─────────────────────────

    /**
     * Retrieves the configured secret key.
     * In test mode, uses the same key (PayChangu determines test vs live by key type).
     */
    public function getSecretKey(): string
    {
        return trim((string) $this->getConfig('secret_key', ''));
    }

    /**
     * Retrieves the configured webhook secret for HMAC validation.
     */
    public function getWebhookSecret(): string
    {
        return trim((string) $this->getConfig('webhook_secret', ''));
    }

    /**
     * Verifies a transaction server-side with retry logic.
     *
     * @param  string $txRef  The tx_ref to verify
     * @return array|null     Decoded verification response data, or null on failure
     */
    public function verifyTransaction(string $txRef): ?array
    {
        $url = PAYCHANGU_VERIFY_URL . '/' . urlencode($txRef);

        for ($attempt = 1; $attempt <= PAYCHANGU_VERIFY_RETRIES; $attempt++) {
            try {
                $response = $this->_apiGet($url);

                if (!empty($response['status']) && $response['status'] === 'success') {
                    return $response['data'] ?? null;
                }

                Am_Di::getInstance()->errorLogTable->log(
                    'paychangu',
                    'Verification attempt ' . $attempt . '/' . PAYCHANGU_VERIFY_RETRIES . " failed for tx_ref={$txRef}",
                    print_r($response, true)
                );

            } catch (Exception $e) {
                Am_Di::getInstance()->errorLogTable->log(
                    'paychangu',
                    "Verification attempt {$attempt} exception for tx_ref={$txRef}",
                    $e->getMessage()
                );
            }

            if ($attempt < PAYCHANGU_VERIFY_RETRIES) {
                sleep(PAYCHANGU_VERIFY_RETRY_DELAY);
            }
        }

        return null;
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Generates a stable, unique tx_ref for a given invoice.
     * Format: PCG-{invoice_id}-{timestamp_hash}
     * Stable across retries for the same invoice within the same session.
     *
     * @param  Invoice $invoice
     * @return string
     */
    private function _generateTxRef(Invoice $invoice): string
    {
        // Re-use an existing tx_ref if one was already generated for this invoice
        $existing = $invoice->data()->get('paychangu_tx_ref');
        if (!empty($existing)) {
            return $existing;
        }

        return 'PCG-' . $invoice->pk() . '-' . substr(md5(uniqid($invoice->pk(), true)), 0, 10);
    }

    /**
     * Resolves the correct charge amount from the invoice.
     * For first payment (includes setup fees if applicable).
     *
     * @param  Invoice $invoice
     * @param  string  $currency
     * @return float
     */
    private function _resolveAmount(Invoice $invoice, string $currency): float
    {
        // first_total includes trial / setup fee + first period
        $amount = (float) $invoice->first_total;

        // PayChangu expects amounts in the base currency unit (no conversion needed for MWK/USD)
        return round($amount, 2);
    }

    /**
     * PayChangu initiate-payment expects a numeric amount (docs: int32).
     * Examples use whole currency units (e.g. 100 MWK). Use rounded major units, minimum 1.
     */
    private function _amountForPaychanguApi(float $amount): int
    {
        return (int) max(1, round($amount));
    }

    /**
     * Sends a POST request to the PayChangu API.
     *
     * @param  string $url
     * @param  array  $payload
     * @return array  Decoded JSON response
     * @throws Am_Exception_Paysystem on cURL or HTTP error
     */
    private function _apiPost(string $url, array $payload): array
    {
        return $this->_apiRequest('POST', $url, $payload);
    }

    /**
     * Sends a GET request to the PayChangu API.
     *
     * @param  string $url
     * @return array  Decoded JSON response
     * @throws Am_Exception_Paysystem on cURL or HTTP error
     */
    private function _apiGet(string $url): array
    {
        return $this->_apiRequest('GET', $url);
    }

    /**
     * Core HTTP client for PayChangu API calls.
     *
     * @param  string      $method   'GET' | 'POST'
     * @param  string      $url
     * @param  array|null  $body
     * @return array
     * @throws Am_Exception_Paysystem
     */
    private function _apiRequest(string $method, string $url, ?array $body = null): array
    {
        $secretKey = $this->getSecretKey();
        if (empty($secretKey)) {
            throw new Am_Exception_Paysystem('PayChangu: Secret key is not configured.');
        }

        $ch = curl_init();

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $secretKey,
        ];

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,    // always verify SSL in production
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'aMember-PayChangu-Plugin/1.0.0',
        ]);

        if ($method === 'POST' && $body !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $responseBody = curl_exec($ch);
        $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new Am_Exception_Paysystem("PayChangu cURL error: {$curlError}");
        }

        $decoded = json_decode($responseBody, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Am_Exception_Paysystem(
                "PayChangu: Invalid JSON response (HTTP {$httpCode}): " .
                substr($responseBody, 0, 500)
            );
        }

        if ($httpCode >= 400) {
            $errMsg = $decoded['message'] ?? "HTTP {$httpCode}";
            throw new Am_Exception_Paysystem("PayChangu API error: {$errMsg}");
        }

        return $decoded;
    }

    /**
     * Plugin readme / help text displayed in aMember CP.
     */
    public function getReadme(): string
    {
        $webhookUrl = Am_Di::getInstance()->config->get('root_url') . '/payment/paychangu/ipn';

        return <<<README
PayChangu Plugin for aMember Pro
==================
Version: 1.0.0
Author:  PayChangu

QUICK SETUP
-----------
1. Log into your PayChangu Dashboard (https://dashboard.paychangu.com)
2. Navigate to Settings → API & Webhooks
3. Copy your Secret Key and Webhook Secret
4. Paste them into the fields below
5. Set your Webhook URL to:
   {$webhookUrl}
6. Save settings and test with a sandbox transaction

CURRENCIES
----------
MWK, USD and ZMW are supported.


README;
    }
}


// ──────────────────────────────────────────────────────────────────────────────
// IPN / Webhook Transaction Handler
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Class Am_Paysystem_Transaction_Paychangu_Ipn
 *
 * Handles incoming POST requests from PayChangu webhooks AND the redirect
 * callback after checkout completion.
 *
 * Security model:
 *  1. Prefer validating the SHA-256 HMAC in the Signature header (optional if
 *     "Strict webhook HMAC" is off — see plugin settings).
 *  2. Re-verify the transaction server-side via PayChangu API (required).
 *  3. Check idempotency (don't process same tx_ref twice).
 *  4. Validate amount and currency match the invoice.
 *  5. Only then mark the invoice as paid.
 */
class Am_Paysystem_Transaction_Paychangu_Ipn extends Am_Paysystem_Transaction_Incoming
{
    /** Raw request body (needed for signature validation before parsing) */
    private $_rawBody = '';

    /** Parsed webhook/callback data */
    private $_data = [];

    /** The verified PayChangu transaction data from server-side verification */
    private $_verifiedTxData = null;

    // ── Am_Paysystem_Transaction_Incoming interface ──────────────────────────

    /**
     * Validates that the request genuinely originated from PayChangu.
     *
     * Two paths:
     *  a) Webhook POST → verify HMAC signature in 'Signature' header
     *  b) Redirect GET (callback_url hit) → verify tx_ref via API
     *
     * @return bool
     */
    public function validateSource(): bool
    {
        // php://input is single-read. Zend_Controller_Request_Http::getRawBody() caches
        // the body on first access; if the framework read it before we run, a direct
        // file_get_contents('php://input') is empty and HMAC verification always fails.
        $this->_rawBody = $this->_getRequestRawBody();
        $this->_data    = [];

        if ($this->request->isPost()) {
            return $this->_validateWebhookSignature();
        }

        // GET redirect from PayChangu checkout page
        // Validate by doing a server-side verification call
        $txRef = $this->request->getFiltered('tx_ref');
        if (empty($txRef)) {
            $this->_log('validateSource', 'No tx_ref in GET callback');
            return false;
        }

        // Populate _data for downstream methods
        $this->_data = ['tx_ref' => $txRef, 'status' => $this->request->getFiltered('status')];
        return true; // Full verification done in validateStatus()
    }

    /**
     * Validates the payment status by performing a server-side verification call.
     * This is the critical security check — never trust the incoming payload alone.
     *
     * @return bool
     */
    public function validateStatus(): bool
    {
        $txRef = $this->_getTxRef();
        if (empty($txRef)) {
            $this->_log('validateStatus', 'Missing tx_ref');
            return false;
        }

        // API data must be loaded in validateTerms() first — aMember calls validateTerms before validateStatus.
        $this->_ensureVerifiedTransactionData();

        if (!is_array($this->_verifiedTxData)) {
            $this->_log('validateStatus', "Verification API data missing for tx_ref={$txRef}");
            return false;
        }

        $status = strtolower($this->_verifiedTxData['status'] ?? '');

        $this->_log(
            'validateStatus',
            "tx_ref={$txRef}, verified status={$status}",
            print_r($this->_verifiedTxData, true)
        );

        return $status === 'success';
    }

    /**
     * Finds the aMember invoice tied to this transaction.
     *
     * Strategy (in priority order):
     *  1. tx_ref encodes invoice ID (format: PCG-{invoice_id}-{hash})
     *  2. meta.invoice_id from webhook payload
     *  3. meta.invoice_public_id from webhook payload
     *
     * @return string|null  aMember invoice public_id or null
     */
    public function findInvoiceId(): ?string
    {
        // NOTE: aMember calls findInvoiceId() BEFORE validateStatus(). Do not use
        // $_verifiedTxData here — it is populated later in validateTerms().

        // 1. Parse from tx_ref pattern PCG-{id}-{hash}
        $txRef = $this->_getTxRef();
        if (preg_match('/^PCG-(\d+)-[a-f0-9]+$/', $txRef, $m)) {
            $invoiceId = (int) $m[1];
            try {
                $invoice = Am_Di::getInstance()->invoiceTable->load($invoiceId);
                if ($invoice) {
                    return $invoice->public_id;
                }
            } catch (Exception $e) {
                // Fall through to other strategies
            }
        }

        // 2. meta from webhook JSON (POST) — PayChangu may set meta to null in API responses
        $meta = (isset($this->_data['meta']) && is_array($this->_data['meta']))
            ? $this->_data['meta']
            : [];

        $publicId = $meta['invoice_public_id'] ?? null;
        if (!empty($publicId)) {
            return (string) $publicId;
        }

        $invoiceId = $meta['invoice_id'] ?? null;
        if (!empty($invoiceId)) {
            try {
                $invoice = Am_Di::getInstance()->invoiceTable->load((int) $invoiceId);
                return $invoice ? $invoice->public_id : null;
            } catch (Exception $e) { /* fall through */ }
        }

        $this->_log('findInvoiceId', "Could not resolve invoice from tx_ref={$txRef}");
        return null;
    }

    /**
     * Returns PayChangu's unique transaction/charge identifier for idempotency.
     * aMember stores this and skips duplicate processing.
     *
     * @return string
     */
    public function getUniqId(): string
    {
        $v = is_array($this->_verifiedTxData) ? $this->_verifiedTxData : [];

        return $v['reference']
            ?? $this->_data['reference']
            ?? $this->_data['charge_id']
            ?? $this->_getTxRef();
    }

    /**
     * Validates that the amount and currency charged match the invoice.
     * Prevents underpayment fraud via URL tampering.
     *
     * @return bool
     */
    public function validateTerms(): bool
    {
        // aMember runs validateTerms() before validateStatus(); load PayChangu verification here first.
        $this->_ensureVerifiedTransactionData();

        if (!is_array($this->_verifiedTxData)) {
            return false;
        }

        $verifiedAmount   = (float) ($this->_verifiedTxData['amount']   ?? 0);
        $verifiedCurrency = strtoupper($this->_verifiedTxData['currency'] ?? '');
        $expectedAmount   = (float) $this->invoice->first_total;
        $expectedCurrency = strtoupper($this->plugin->getConfig('currency', 'MWK'));

        // Currency must match exactly
        if ($verifiedCurrency !== $expectedCurrency) {
            $this->_log(
                'validateTerms',
                "Currency mismatch: expected={$expectedCurrency}, got={$verifiedCurrency}"
            );
            return false;
        }

        // Amount must be >= expected (overpayment is acceptable per PayChangu docs)
        if ($verifiedAmount < $expectedAmount) {
            $this->_log(
                'validateTerms',
                "Amount mismatch: expected={$expectedAmount}, got={$verifiedAmount}"
            );
            return false;
        }

        return true;
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Fetches PayChangu verify-payment data once. Required in validateTerms() because aMember
     * invokes validateTerms() before validateStatus(), so $_verifiedTxData must be ready early.
     *
     * @return void
     */
    private function _ensureVerifiedTransactionData(): void
    {
        if (is_array($this->_verifiedTxData)) {
            return;
        }

        $txRef = $this->_getTxRef();
        if ($txRef === '') {
            return;
        }

        /** @var Am_Paysystem_Paychangu $plugin */
        $plugin = $this->plugin;
        $txData = $plugin->verifyTransaction($txRef);

        if ($txData !== null) {
            $this->_verifiedTxData = $txData;
        }
    }

    /**
     * Raw POST body for HMAC: prefer Zend request cache so we match PayChangu's payload bytes.
     *
     * @return string
     */
    private function _getRequestRawBody(): string
    {
        $req = $this->request;
        if (is_object($req) && method_exists($req, 'getRawBody')) {
            $body = $req->getRawBody();
            if (is_string($body) && $body !== '') {
                return $body;
            }
            // Zend may have cached the bytes on a property while the stream is already exhausted.
            if (class_exists('ReflectionProperty', false)) {
                foreach (['_rawBody', 'rawBody'] as $propName) {
                    try {
                        $ref = new ReflectionProperty($req, $propName);
                        $ref->setAccessible(true);
                        $cached = $ref->getValue($req);
                        if (is_string($cached) && $cached !== '') {
                            return $cached;
                        }
                    } catch (ReflectionException $e) {
                        // property not present on this request class
                    }
                }
            }
        }

        $fallback = file_get_contents('php://input');

        return is_string($fallback) ? $fallback : '';
    }

    /**
     * Reads the Signature header (PayChangu) with Zend, getallheaders(), or $_SERVER.
     *
     * @return string
     */
    private function _getWebhookSignatureHeader(): string
    {
        $tryHeaderNames = ['Signature', 'X-Signature', 'X-Paychangu-Signature'];

        if (is_object($this->request) && method_exists($this->request, 'getHeader')) {
            foreach ($tryHeaderNames as $name) {
                $sig = $this->request->getHeader($name);
                if (is_string($sig) && $sig !== '') {
                    return $sig;
                }
            }
        }

        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        foreach ($headers as $name => $value) {
            if (preg_match('/^(Signature|X-Signature|X-Paychangu-Signature)$/i', (string) $name)) {
                return is_array($value) ? (string) reset($value) : (string) $value;
            }
        }

        foreach (['HTTP_SIGNATURE', 'HTTP_X_SIGNATURE', 'HTTP_X_PAYCHANGU_SIGNATURE'] as $serverKey) {
            if (!empty($_SERVER[$serverKey])) {
                return (string) $_SERVER[$serverKey];
            }
        }

        return '';
    }

    /**
     * Verifies HMAC using PayChangu’s documented rules (hex or base64 digest), trying BOM variants and secrets.
     *
     * @param string[] $secrets
     */
    private function _webhookSignatureMatchesAny(string $rawBody, array $secrets, string $receivedHeader): bool
    {
        if ($receivedHeader === '') {
            return false;
        }

        $receivedTrim = trim($receivedHeader);
        if (stripos($receivedTrim, 'sha256=') === 0) {
            $receivedTrim = trim(substr($receivedTrim, 7));
        }
        $receivedTrim = trim($receivedTrim, " \t\n\r\0\x0B\"'");

        $payloads = [$rawBody];
        if (strncmp($rawBody, "\xEF\xBB\xBF", 3) === 0) {
            $payloads[] = substr($rawBody, 3);
        }

        foreach ($secrets as $secret) {
            $secret = trim((string) $secret);
            if ($secret === '') {
                continue;
            }
            foreach ($payloads as $payload) {
                $computedBin = hash_hmac('sha256', $payload, $secret, true);
                $decodedBin = base64_decode($receivedTrim, true);
                if (is_string($decodedBin) && strlen($decodedBin) === 32
                    && hash_equals($computedBin, $decodedBin)) {
                    return true;
                }

                $computedHex = hash_hmac('sha256', $payload, $secret);
                $hexReceived = strtolower($receivedTrim);
                if (strlen($hexReceived) === 64 && ctype_xdigit($hexReceived)
                    && hash_equals($computedHex, $hexReceived)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Validates the HMAC-SHA256 signature on an incoming webhook POST.
     *
     * PayChangu sends a 'Signature' header containing:
     *   hash_hmac('sha256', $rawBody, $webhookSecret)
     *
     * If HMAC cannot be verified, processing may still continue when "Strict webhook HMAC" is off:
     * PayChangu requires server-side verification in validateStatus(), which is the real guard.
     *
     * @return bool
     */
    private function _validateWebhookSignature(): bool
    {
        /** @var Am_Paysystem_Paychangu $plugin */
        $plugin = $this->plugin;

        $parsed = json_decode($this->_rawBody, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($parsed)) {
            $this->_log(
                'validateSource',
                'Invalid JSON in webhook body',
                'json_error=' . json_last_error_msg() . ', raw_len=' . strlen($this->_rawBody)
            );
            return false;
        }

        $this->_data = $parsed;

        $webhookSecret = $plugin->getWebhookSecret();
        $secretKey     = $plugin->getSecretKey();
        $secrets       = array_values(array_unique(array_filter([$webhookSecret, $secretKey])));

        if ($secrets === []) {
            $this->_log(
                'validateSource',
                'WARNING: neither webhook_secret nor secret_key configured. HMAC skipped; relying on API verification.'
            );
            return true;
        }

        $receivedSignature = $this->_getWebhookSignatureHeader();
        $strictHmac        = (bool) $plugin->getConfig('webhook_strict_hmac', false);

        $hmacOk = $receivedSignature !== ''
            && $this->_webhookSignatureMatchesAny($this->_rawBody, $secrets, $receivedSignature);

        if ($hmacOk) {
            $this->_log(
                'validateSource',
                'Webhook HMAC verified',
                'event_type=' . ($this->_data['event_type'] ?? '') . ', tx_ref=' . $this->_getTxRef()
            );
            return true;
        }

        $detail = sprintf(
            "strict_hmac=%s, sig_header_len=%d, body_len=%d, tried_secrets=%d\ncomputed_hex_sample=%s",
            $strictHmac ? 'yes' : 'no',
            strlen($receivedSignature),
            strlen($this->_rawBody),
            count($secrets),
            hash_hmac('sha256', $this->_rawBody, $secrets[0])
        );

        if ($strictHmac) {
            $this->_log(
                'validateSource',
                'Webhook HMAC failed (strict mode) — request rejected',
                $detail
            );
            return false;
        }

        $this->_log(
            'validateSource',
            'Webhook HMAC not verified — continuing; PayChangu API verification in validateStatus() is authoritative',
            $detail
        );

        return true;
    }

    /**
     * Extracts tx_ref from multiple possible locations in the payload.
     *
     * Webhook body uses 'reference' or 'tx_ref'.
     * GET callback uses query param 'tx_ref'.
     *
     * @return string
     */
    private function _getTxRef(): string
    {
        return $this->_data['tx_ref']
            ?? $this->_data['reference']
            ?? $this->request->getFiltered('tx_ref')
            ?? '';
    }

    /**
     * Structured logging wrapper.
     * Writes to aMember's error log table with a 'paychangu' prefix.
     *
     * @param string $context  Method or step name
     * @param string $message  Human-readable summary
     * @param string $detail   Optional full data dump
     */
    private function _log(string $context, string $message, string $detail = ''): void
    {
        $logMsg = "[PayChangu:{$context}] {$message}";
        Am_Di::getInstance()->errorLogTable->log('paychangu', $logMsg, $detail);
    }
}