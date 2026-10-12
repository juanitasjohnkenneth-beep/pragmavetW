<?php
// ===== AUTO INJECT SHARED UI ELEMENTS =====
// This file is included at the end of body to inject scroll progress, 
// back-to-top button, paw cursor, shared styles & scripts

// Only run on actual page views, not API/AJAX
$current_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
$skip_patterns = ['api_', 'temp_', 'fix_', 'patch', 'upgrade_', 'migrate_', 'check_', 'tmp_', 'test', 'dbconnection', 'apply_theme', 'log_js_error', 'chat_widget', 'process_demo_', 'payment_demo', 'paymongo_', 'helpers_', 'create_doctor'];
foreach ($skip_patterns as $pattern) {
    if (strpos($current_script, $pattern) !== false) return;
}
if (empty($_SERVER['SCRIPT_NAME']) || $current_script === '') return;

// Get the current page buffer
$html = ob_get_clean();

// 1. INJECT SHARED META/LINKS BEFORE </head>
$head_inject = '
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="assets/global-style.css" rel="stylesheet">
    <!-- Tabler.io Bootstrap Integration -->
    <link href="assets/tabler/css/tabler.min.css" rel="stylesheet">
    <link href="assets/tabler/css/tabler-compat.css" rel="stylesheet">
';

// Only inject if not already present
if (strpos($html, 'bootstrap@5.3.2') === false) {
    $html = str_replace('</head>', $head_inject . '</head>', $html);
}

// Ensure Tabler.io CSS is injected even if bootstrap is already present
if (strpos($html, 'tabler.min.css') === false) {
    $tabler_head = '
    <!-- Tabler.io Bootstrap Integration -->
    <link href="assets/tabler/css/tabler.min.css" rel="stylesheet">
    <link href="assets/tabler/css/tabler-compat.css" rel="stylesheet">
';
    $html = str_replace('</head>', $tabler_head . '</head>', $html);
}

// Inject shared inline styles for backward compatibility (keep saas_styles for pages that need it)
if (strpos($html, '--primary: #0E5A6B') === false && strpos($html, 'saas_styles') === false && strpos($html, 'global-style') === false) {
    ob_start();
    include __DIR__ . '/saas_styles.php';
    $styles = ob_get_clean();
    $html = str_replace('</head>', $styles . '<!-- saas_styles -->' . "\n" . '</head>', $html);
}

// 2. INJECT UNIVERSAL UI ELEMENTS AFTER <body>
$body_inject = '
    <!-- SCROLL PROGRESS BAR -->
    <div id="scroll-progress"></div>
    <!-- BACK TO TOP -->
    <button id="back-to-top" aria-label="Back to top">
        <i class="fa fa-arrow-up"></i>
    </button>
';

// Inject after <body> tag if these elements don't exist
if (strpos($html, 'scroll-progress') === false) {
    $html = preg_replace('/<body[^>]*>/', '$0' . "\n" . $body_inject, $html, 1);
}

// 3. INJECT SHARED FOOTER (if page doesn't have one already)
if (strpos($html, 'class="footer"') === false && strpos($html, 'PragmaVet</h4>') === false) {
    $footer_html = '
    <footer class="footer">
        <div class="container">
            <div class="row g-4 mb-4">
                <div class="col-lg-4 col-md-6">
                    <h4><i class="fa fa-paw" style="color: var(--accent);"></i> PragmaVet</h4>
                    <p>The ultimate all-in-one veterinary clinic management system built for speed, security, and aesthetics.</p>
                    <div class="footer-social">
                        <a href="#" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                        <a href="#" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
                        <a href="#" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                        <a href="#" aria-label="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
                        <a href="#" aria-label="YouTube"><i class="fab fa-youtube"></i></a>
                    </div>
                </div>
                <div class="col-lg-2 col-md-6">
                    <h5>Platform</h5>
                    <a href="index.php" class="footer-link">Home</a>
                    <a href="index.php#features" class="footer-link">Features</a>
                    <a href="index.php#how-it-works" class="footer-link">How it Works</a>
                    <a href="index.php#team" class="footer-link">Our Team</a>
                </div>
                <div class="col-lg-2 col-md-6">
                    <h5>Legal</h5>
                    <a href="#" class="footer-link">Privacy Policy</a>
                    <a href="#" class="footer-link">Terms of Service</a>
                    <a href="#" class="footer-link">Cookie Policy</a>
                </div>
                <div class="col-lg-4 col-md-6">
                    <h5>Contact Us</h5>
                    <div class="footer-contact-item">
                        <i class="fa fa-map-marker-alt"></i>
                        <span>0234 Unit B Palico St, Palico 4, Imus, Cavite</span>
                    </div>
                    <div class="footer-contact-item">
                        <i class="fa fa-envelope"></i>
                        <span>pragmavet@gmail.com</span>
                    </div>
                    <div class="footer-contact-item">
                        <i class="fa fa-phone"></i>
                        <span>0954-285-3053</span>
                    </div>
                </div>
            </div>
            <hr class="footer-divider">
            <div class="footer-bottom">
                <p class="mb-0">&copy; 2026 PragmaVet Veterinary Management System. All rights reserved.</p>
            </div>
        </div>
    </footer>
';
    // Inject footer before </body>
    $html = str_replace('</body>', $footer_html . "\n" . '</body>', $html);
}

// 4. INJECT SHARED SCRIPTS BEFORE </body>
$scripts_inject = '
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/tabler/js/tabler.min.js"></script>
<script>
document.addEventListener(\'DOMContentLoaded\', function() {
    // SCROLL REVEAL
    const revealElements = document.querySelectorAll(\'.reveal, .reveal-left, .reveal-right, .reveal-scale, .reveal-stagger\');
    if (revealElements.length) {
        const revealObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add(\'visible\');
                    revealObserver.unobserve(entry.target);
                }
            });
        }, { threshold: 0.1, rootMargin: \'0px 0px -50px 0px\' });
        revealElements.forEach(el => revealObserver.observe(el));
    }

    // SCROLL PROGRESS
    const progressBar = document.getElementById(\'scroll-progress\');
    if (progressBar) {
        window.addEventListener(\'scroll\', function() {
            const scrollTop = window.scrollY;
            const docHeight = document.documentElement.scrollHeight - window.innerHeight;
            progressBar.style.width = ((scrollTop / docHeight) * 100) + \'%\';
        });
    }

    // BACK TO TOP
    const backToTop = document.getElementById(\'back-to-top\');
    if (backToTop) {
        window.addEventListener(\'scroll\', function() {
            backToTop.classList.toggle(\'visible\', window.scrollY > 500);
        });
        backToTop.addEventListener(\'click\', function() {
            window.scrollTo({ top: 0, behavior: \'smooth\' });
        });
    }

    // NAVBAR SCROLL
    const navbar = document.getElementById(\'mainNavbar\');
    if (navbar) {
        window.addEventListener(\'scroll\', function() {
            navbar.classList.toggle(\'scrolled\', window.scrollY > 50);
        });
    }

    // RIPPLE EFFECT
    document.addEventListener(\'click\', function(e) {
        const target = e.target.closest(\'.ripple\');
        if (!target) return;
        const rect = target.getBoundingClientRect();
        const ripple = document.createElement(\'span\');
        ripple.className = \'ripple-effect\';
        const size = Math.max(rect.width, rect.height);
        ripple.style.width = ripple.style.height = size + \'px\';
        ripple.style.left = (e.clientX - rect.left - size / 2) + \'px\';
        ripple.style.top = (e.clientY - rect.top - size / 2) + \'px\';
        target.appendChild(ripple);
        setTimeout(() => ripple.remove(), 600);
    });

    // PAW CURSOR TRAIL
    let lastPaw = 0;
    document.addEventListener(\'mousemove\', function(e) {
        const now = Date.now();
        if (now - lastPaw < 120) return;
        lastPaw = now;
        const paw = document.createElement(\'div\');
        paw.className = \'paw-trail\';
        paw.textContent = \'🐾\';
        paw.style.left = e.clientX + \'px\';
        paw.style.top = e.clientY + \'px\';
        paw.style.fontSize = (14 + Math.random() * 10) + \'px\';
        document.body.appendChild(paw);
        setTimeout(() => paw.remove(), 1200);
    });

    // AUTO DISMISS ALERTS
    setTimeout(function() {
        document.querySelectorAll(\'.alert-container\').forEach(a => {
            a.style.transition = \'opacity 0.5s ease-out, transform 0.5s ease-out\';
            a.style.opacity = \'0\';
            a.style.transform = \'translateY(-20px)\';
            setTimeout(() => a.remove(), 500);
        });
    }, 4500);
});
</script>
';

if (strpos($html, 'paw-trail') === false && strpos($html, 'saas_scripts') === false) {
    $html = str_replace('</body>', '<!-- saas_scripts -->' . "\n" . $scripts_inject . "\n" . '</body>', $html);
}

echo $html;
// ===== END AUTO INJECT =====
?>