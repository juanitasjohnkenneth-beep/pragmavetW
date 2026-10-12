<?php
// ═══════════════════════════════════════════════════════════════════
// PayMongo Configuration — PragmaVet
// ═══════════════════════════════════════════════════════════════════

// 🔧  FALSE = Sandbox/Live PayMongo (real API calls, test or live)
// 🔧  TRUE  = Demo mode (no API, uses local payment_demo.php)
define('DEMO_MODE', false);

// ── PayMongo Sandbox (Test) Keys ──────────────────────────────────
// These are TEST keys — no real money is charged.
// Sandbox supports: card (test numbers), gcash, paymaya, grab_pay
define('PAYMONGO_SECRET_KEY', 'sk_test_sNy6qv1kmCTaVRdFuCTzb5sn');
define('PAYMONGO_PUBLIC_KEY', 'pk_test_sNy6qv1kmCTaVRdFuCTzb5sn');
define('PAYMONGO_API_BASE',   'https://api.paymongo.com/v1');

/**
 * Send a raw POST request to the PayMongo API.
 */
function paymongo_post(string $endpoint, array $payload): array {
    $auth = base64_encode(PAYMONGO_SECRET_KEY . ':');
    $ch   = curl_init(PAYMONGO_API_BASE . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Basic ' . $auth,
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) return ['error' => 'cURL Error: ' . $err];
    
    $decoded = json_decode($response, true);
    if ($decoded === null) return ['error' => 'Invalid API response (HTTP ' . $httpCode . ')'];
    return $decoded;
}

/**
 * Send a raw GET request to the PayMongo API.
 */
function paymongo_get(string $endpoint): array {
    $auth = base64_encode(PAYMONGO_SECRET_KEY . ':');
    $ch   = curl_init(PAYMONGO_API_BASE . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Basic ' . $auth,
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err) return ['error' => 'cURL Error: ' . $err];
    return json_decode($response, true) ?? ['error' => 'Invalid API response'];
}

/**
 * Create a checkout session.
 * DEMO_MODE = true  → redirects to local payment_demo.php (zero real money)
 * DEMO_MODE = false → calls PayMongo API for sandbox/live payment
 */
function paymongo_create_checkout(
    float  $amount_pesos,
    string $description,
    string $success_url,
    string $cancel_url,
    array  $metadata = []
): array {

    // ── DEMO MODE ──────────────────────────────────────────────
    if (DEMO_MODE) {
        $domain   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                  . '://' . $_SERVER['HTTP_HOST'] . '/pragmavet';
        $pay_type_demo = (stripos($description,'downpayment')!==false || stripos($description,'30%')!==false)
                       ? 'downpayment' : 'full';
        $invoice_id_demo = (int)($metadata['invoice_id'] ?? 0);
        $demo_url = $domain . '/payment_demo.php'
                  . '?amount='      . urlencode($amount_pesos)
                  . '&description=' . urlencode($description)
                  . '&success_url=' . urlencode($success_url)
                  . '&cancel_url='  . urlencode($cancel_url)
                  . '&invoice_id='  . $invoice_id_demo
                  . '&pay_type='    . urlencode($pay_type_demo);
        return ['url' => $demo_url];
    }

    // ── SANDBOX / LIVE MODE ────────────────────────────────────
    $centavos = intval(round($amount_pesos * 100));
    
    // Minimum amount check (PayMongo requires at least ₱100 = 10000 centavos)
    if ($centavos < 10000) {
        $centavos = 10000;
    }

    $payload  = [
        'data' => [
            'attributes' => [
                'amount'               => $centavos,
                'currency'             => 'PHP',
                'description'          => $description,
                'line_items'           => [[
                    'currency'    => 'PHP',
                    'amount'      => $centavos,
                    'description' => $description,
                    'name'        => 'PragmaVet — ' . mb_substr($description, 0, 80),
                    'quantity'    => 1,
                ]],
                'payment_method_types' => ['gcash', 'card', 'paymaya', 'grab_pay'],
                'success_url'          => $success_url,
                'cancel_url'           => $cancel_url,
                'metadata'             => $metadata,
            ],
        ],
    ];

    $result = paymongo_post('/checkout_sessions', $payload);

    if (isset($result['data']['attributes']['checkout_url'])) {
        return [
            'url' => $result['data']['attributes']['checkout_url'],
            'id'  => $result['data']['id'],
        ];
    }

    // Error handling
    $errorMsg = $result['errors'][0]['detail'] 
             ?? ($result['errors'][0]['code'] ?? null)
             ?? ($result['error'] ?? 'Unknown PayMongo error. Check your API keys.');
    return ['error' => $errorMsg];
}

/**
 * Retrieve a checkout session by ID (for verification).
 */
function paymongo_retrieve_checkout(string $session_id): array {
    return paymongo_get('/checkout_sessions/' . $session_id);
}
