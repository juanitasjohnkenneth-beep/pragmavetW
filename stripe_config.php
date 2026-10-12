<?php
// Autoload composer dependencies
if(file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Ensure you replace these with your actual Stripe Secret and Publishable Keys from you dashboard
$stripe_secret_key = "sk_test_000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000";
$stripe_publishable_key = "pk_test_000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000";

if(class_exists('\Stripe\Stripe')){
    \Stripe\Stripe::setApiKey($stripe_secret_key);
}
?>
