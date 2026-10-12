<?php
// Quick test: verify PayMongo sandbox API key works
require 'paymongo_config.php';

echo "=== PayMongo Sandbox API Test ===\n\n";
echo "Secret Key: " . substr(PAYMONGO_SECRET_KEY, 0, 12) . "...\n";
echo "Demo Mode: " . (DEMO_MODE ? 'ON' : 'OFF') . "\n\n";

// Test 1: Create a checkout session
echo "--- Test: Create Checkout Session ---\n";
$result = paymongo_create_checkout(
    150.00,
    'PragmaVet Test Payment - Sandbox',
    'http://localhost/pragmavet/billing.php?pm_success=full&invoice_id=0',
    'http://localhost/pragmavet/billing.php?pm_cancelled=1',
    ['invoice_id' => '0', 'type' => 'test']
);

if (isset($result['url'])) {
    echo "SUCCESS! Checkout URL created:\n";
    echo $result['url'] . "\n\n";
    if (isset($result['id'])) {
        echo "Session ID: " . $result['id'] . "\n";
    }
} else {
    echo "ERROR: " . ($result['error'] ?? 'Unknown error') . "\n";
    echo "Full response: " . json_encode($result, JSON_PRETTY_PRINT) . "\n";
}
