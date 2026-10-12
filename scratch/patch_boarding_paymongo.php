<?php
$file = 'c:/xampp/htdocs/pragmavet/boarding.php';
$content = file_get_contents($file);

// Replace the old demo redirect block with real PayMongo call
$old_block = '    $domain = (isset($_SERVER[\'HTTPS\']) && $_SERVER[\'HTTPS\'] === \'on\' ? \'https\' : \'http\') . \'://\' . ($_SERVER[\'HTTP_HOST\'] ?? \'localhost\') . \'/pragmavet\';
    $success_url = $domain . \'/boarding.php?pm_success=\' . urlencode($pay_type) . \'&res_id=\' . $res_id . \'&total=\' . $total . \'&dp=\' . $dp;
    $cancel_url  = $domain . \'/boarding.php?pm_cancelled=1&res_id=\' . $res_id;

    $demo_url = $domain . \'/payment_demo.php\'
        . \'?amount=\' . urlencode($charge)
        . \'&description=\' . urlencode($label)
        . \'&success_url=\' . urlencode($success_url)
        . \'&cancel_url=\' . urlencode($cancel_url);

    js_redirect($demo_url);
}';

$new_block = '    $protocol    = (isset($_SERVER[\'HTTPS\']) && $_SERVER[\'HTTPS\'] === \'on\') ? \'https\' : \'http\';
    $domain      = $protocol . \'://\' . ($_SERVER[\'HTTP_HOST\'] ?? \'localhost\') . \'/pragmavet\';
    $success_url = $domain . \'/boarding.php?pm_success=\' . urlencode($pay_type) . \'&res_id=\' . $res_id . \'&total=\' . $total . \'&dp=\' . $dp;
    $cancel_url  = $domain . \'/boarding.php?pm_cancelled=1&res_id=\' . $res_id;

    // Create PayMongo checkout session
    $result = paymongo_create_checkout(
        (float)$charge,
        $label,
        $success_url,
        $cancel_url,
        [\'res_id\' => (string)$res_id, \'pay_type\' => $pay_type, \'type\' => \'boarding\']
    );

    if (isset($result[\'url\'])) {
        js_redirect($result[\'url\']);
    } else {
        // Delete the pending reservation on PayMongo failure so it doesn\'t block the cage
        $conn->query("DELETE FROM boarding_reservations WHERE id=$res_id AND status=\'Pending\'");
        $err = $result[\'error\'] ?? json_encode($result);
        $_SESSION[\'message\']  = "Payment gateway error. Please try again. ($err)";
        $_SESSION[\'msg_type\'] = \'danger\';
        js_redirect(\'boarding.php\');
    }
}';

if (str_contains($content, $old_block)) {
    $content = str_replace($old_block, $new_block, $content);
    file_put_contents($file, $content);
    echo "SUCCESS: PayMongo checkout injected into boarding.php\n";
} else {
    echo "NOT FOUND: Could not locate demo redirect block. Trying alternative match...\n";
    // Try a more forgiving version
    $pattern = '/\$demo_url\s*=\s*\$domain\s*\.\s*\'\/payment_demo\.php\'.*?js_redirect\(\$demo_url\);\s*\}/s';
    if (preg_match($pattern, $content, $m)) {
        echo "Found via regex. Match:\n" . substr($m[0], 0, 200) . "\n";
    } else {
        echo "Could not find via regex either.\n";
    }
}
?>
