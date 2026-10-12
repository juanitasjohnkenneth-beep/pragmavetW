<?php
/**
 * Confirm with PayMongo that a checkout session was really paid before the system records the payment.
 * The return URL alone proves nothing — anyone can open it by hand.
 * Requires paymongo_config.php (paymongo_retrieve_checkout, DEMO_MODE).
 */
function pm_checkout_paid($session_id, $expected_amount = null) {
    if (DEMO_MODE) return true; // local demo page only: no gateway to ask
    if (empty($session_id)) return false;

    $cs = paymongo_retrieve_checkout($session_id);
    $attrs = $cs['data']['attributes'] ?? [];
    $paid = ($attrs['payment_intent']['attributes']['status'] ?? '') === 'succeeded';
    foreach (($attrs['payments'] ?? []) as $p) {
        if (($p['attributes']['status'] ?? '') === 'paid') $paid = true;
    }
    if (!$paid) return false;

    // The session must be for at least the amount we expected (PayMongo amounts are in centavos)
    if ($expected_amount !== null) {
        $sum = 0;
        foreach (($attrs['line_items'] ?? []) as $li) $sum += (int)($li['amount'] ?? 0) * max(1, (int)($li['quantity'] ?? 1));
        if ($sum < (int)round($expected_amount * 100)) return false;
    }
    return true;
}
