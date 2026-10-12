<?php
// The clinic runs on Philippine time (UTC+8), the same as the database clock (PHP's server default was Europe/Berlin, 6 hours behind)
date_default_timezone_set('Asia/Manila');

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "vet_system";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Force UTF-8 encoding for proper display of special characters (?, ?, etc.)
$conn->set_charset("utf8");

// Global Security Guard for Forced Password Changes
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Session cookie: not sent on cross-site POSTs (re-issued with SameSite=Lax, HttpOnly)
if (session_status() === PHP_SESSION_ACTIVE && !headers_sent() && PHP_SAPI !== 'cli') {
    setcookie(session_name(), session_id(), ['path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
}

// Cross-site request guard (CSRF): a POST made while logged in must come from this site.
// Browsers always send Origin (or at least Referer) with form posts and fetch() calls, so a page on another site is refused here.
if (!empty($_SESSION['user_id']) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && PHP_SAPI !== 'cli') {
    $pv_from = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
    $pv_host = strtolower($_SERVER['HTTP_HOST'] ?? '');
    if ($pv_from === 'null' || $pv_from !== '' && strtolower((string)parse_url($pv_from, PHP_URL_HOST) . (parse_url($pv_from, PHP_URL_PORT) ? ':' . parse_url($pv_from, PHP_URL_PORT) : '')) !== $pv_host) {
        http_response_code(403);
        exit('This request was blocked because it did not come from the PragmaVet website. Please go back, refresh the page and try again.');
    }
}

// Who is acting — used by the boarding audit-trail triggers
if (!empty($_SESSION['user_id'])) {
    $pv_actor = $_SESSION['user'] ?? 'User';
    if (!empty($_SESSION['role']) && stripos($pv_actor, '(') === false) $pv_actor .= ' (' . $_SESSION['role'] . ')';
    $conn->query("SET @pv_actor = '" . $conn->real_escape_string(mb_substr($pv_actor, 0, 150)) . "'");

    // Close out bookings whose date passed without anyone acting on them (at most once an hour)
    if (PHP_SAPI !== 'cli') {
        include_once __DIR__ . '/includes/closeout.php';
        pv_closeout_stale($conn, mb_substr($pv_actor, 0, 150));
    }
}

if(isset($_SESSION['user_id'])) {
    $current_page = basename($_SERVER['PHP_SELF']);
    if($current_page !== 'force_change.php' && $current_page !== 'logout.php' && $current_page !== 'index.php' && $current_page !== 'otp.php') {
        $stmt_force = $conn->prepare("SELECT force_password_change FROM users WHERE id=?");
        $stmt_force->bind_param("i", $_SESSION['user_id']);
        $stmt_force->execute();
        $force_res = $stmt_force->get_result()->fetch_assoc();
        if($force_res && $force_res['force_password_change'] == 1) {
            header("Location: force_change.php");
            exit();
        }
    }
}

// ===== AUTO-INJECT SHARED UI + TABLER.IO BOOTSTRAP =====
include_once __DIR__ . '/includes/saas_globals.php';

// RBAC: buttons and links of tasks the admin switched off for this vet staff member / veterinarian are hidden on every page
// (the server also refuses those actions — see includes/permissions.php)
if (in_array($_SESSION['role'] ?? '', ['staff', 'doctor'], true)) {
    include_once __DIR__ . '/includes/permissions.php';
    $GLOBALS['pv_task_ui'] = pv_ui_script($conn);
}

if (!empty($GLOBALS['pragmavet_script_tag'])) {
    if (ob_get_level() == 0) {
        ob_start();
    }
    register_shutdown_function(function() {
        $html = '';
        while (ob_get_level() > 0) {
            $html = ob_get_clean() . $html;
        }
        if (empty($html) || strlen($html) < 100) { echo $html; return; }
        if (strpos($html, '<!DOCTYPE') === false && strpos($html, '<html') === false) { echo $html; return; }

        // ===== INJECT TABLER.IO CSS BEFORE </head> =====
        if (strpos($html, 'tabler.min.css') === false && strpos($html, '</head>') !== false) {
            $tabler_css = '
    <!-- Tabler.io Bootstrap Integration -->
    <link href="assets/tabler/css/tabler.min.css" rel="stylesheet">
    <link href="assets/tabler/css/tabler-compat.css" rel="stylesheet">
';
            $html = str_replace('</head>', $tabler_css . '</head>', $html);
        }

        // ===== INJECT MOUSETRAIL.JS BEFORE </body> =====
        if (strpos($html, 'mousetrail.js') === false) {
            $script_tag = $GLOBALS['pragmavet_script_tag'];
            if (strpos($html, '</body>') !== false) {
                $html = str_replace('</body>', $script_tag . "\n" . '</body>', $html);
            } else {
                $html .= "\n" . $script_tag;
            }
        }

        // ===== INJECT RBAC TASK SCRIPT BEFORE THE LAST </body> =====
        if (!empty($GLOBALS['pv_task_ui']) && ($pb = strrpos($html, '</body>')) !== false) {
            $html = substr($html, 0, $pb) . $GLOBALS['pv_task_ui'] . "
" . substr($html, $pb);
        }

        // ===== INJECT TABLER.IO JS BEFORE </body> =====
        if (strpos($html, 'tabler.min.js') === false) {
            $tabler_js = '<script src="assets/tabler/js/tabler.min.js"></script>';
            if (strpos($html, '</body>') !== false) {
                $html = str_replace('</body>', $tabler_js . "\n" . '</body>', $html);
            } else {
                $html .= "\n" . $tabler_js;
            }
        }

        // ===== INJECT GLOBAL SWEETALERT CONFIRM OVERRIDE BEFORE </body> =====
        if (strpos($html, 'sweetalert2@11') === false) {
            $swal_js = '
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    // Intercept native onsubmit/onclick confirms
    document.querySelectorAll("form, a, button").forEach(el => {
        let attrType = el.hasAttribute("onsubmit") ? "onsubmit" : (el.hasAttribute("onclick") ? "onclick" : null);
        if (attrType) {
            let attr = el.getAttribute(attrType);
            if (attr && attr.includes("confirm(")) {
                let match = attr.match(/confirm\([\'"](.*?)(?:\'|")\)/);
                if (match) {
                    let msg = match[1];
                    el.removeAttribute(attrType);
                    
                    if (el.tagName === "FORM") {
                        el.addEventListener("submit", function(e) {
                            e.preventDefault();
                            Swal.fire({
                                title: "Confirm Action",
                                text: msg,
                                icon: "warning",
                                showCancelButton: true,
                                confirmButtonColor: "#2563eb",
                                cancelButtonColor: "#64748b",
                                confirmButtonText: "Yes, proceed!"
                            }).then((result) => {
                                if (result.isConfirmed) {
                                    el.submit();
                                }
                            });
                        });
                    } else {
                        // For links/buttons
                        el.addEventListener("click", function(e) {
                            e.preventDefault();
                            let targetHref = el.getAttribute("href");
                            Swal.fire({
                                title: "Confirm Action",
                                text: msg,
                                icon: "warning",
                                showCancelButton: true,
                                confirmButtonColor: "#2563eb",
                                cancelButtonColor: "#64748b",
                                confirmButtonText: "Yes, proceed!"
                            }).then((result) => {
                                if (result.isConfirmed) {
                                    if (targetHref && targetHref !== "#") {
                                        window.location.href = targetHref;
                                    } else if (el.tagName === "BUTTON" && el.type === "submit") {
                                        el.closest("form").submit();
                                    }
                                }
                            });
                        });
                    }
                }
            }
        }
    });
});
</script>';
            if (strpos($html, '</body>') !== false) {
                $html = str_replace('</body>', $swal_js . "\n" . '</body>', $html);
            } else {
                $html .= "\n" . $swal_js;
            }
        }

        echo $html;
    });
}
?>
