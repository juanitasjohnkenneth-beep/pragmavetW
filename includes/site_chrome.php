<?php
/**
 * Public site header + footer for standalone pages (Privacy Policy, Terms of Service, Cookie Policy).
 * Same look as the homepage (index.php): Inter font, PragmaVet blue, dark navy footer with the blue accent line.
 * The homepage keeps its own markup; this file only serves the separate pages.
 */

/** <head> assets and the shared header/footer styles. */
function pv_site_head($title, $description = '') {
    $t = htmlspecialchars($title . ' | PragmaVet');
    $d = htmlspecialchars($description);
    echo <<<HTML
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$t}</title>
    <meta name="description" content="{$d}">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1e40af; --primary-dark: #172554; --primary-light: #2563eb; --accent: #60a5fa;
            --dark: #1A2332; --gray-100: #F8FAFC; --gray-200: #F1F5F9; --gray-300: #E2E8F0; --gray-500: #64748B; --gray-600: #475569;
            --gradient-accent: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            --shadow-glow: 0 0 25px rgba(96, 165, 250, 0.3);
            --transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; background: var(--gray-100); color: var(--dark); margin: 0; -webkit-font-smoothing: antialiased; }
        ::selection { background: var(--accent); color: var(--primary-dark); }

        /* Header — same as the homepage navbar */
        .navbar { background: rgba(255, 255, 255, 0.92) !important; backdrop-filter: blur(20px) saturate(180%); -webkit-backdrop-filter: blur(20px) saturate(180%); padding: 12px 0; border-bottom: 1px solid rgba(226, 232, 240, 0.7); }
        .navbar-brand { background: var(--gradient-accent); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; font-weight: 900; font-size: 26px; letter-spacing: -0.8px; }
        .navbar-brand i { -webkit-text-fill-color: initial; background: var(--gradient-accent); -webkit-background-clip: text; background-clip: text; color: transparent; margin-right: 4px; }
        .nav-link { color: var(--gray-600) !important; font-weight: 600; font-size: 14.5px; margin: 0 8px; padding: 8px 12px !important; border-radius: 8px; transition: var(--transition); }
        .nav-link:hover { color: var(--primary) !important; background: rgba(96, 165, 250, 0.06); }
        .btn-login-nav { background: #5b21b6 !important; color: #fff !important; border: 0; padding: 10px 28px !important; border-radius: 50px; font-weight: 700; font-size: 14px; margin-left: 12px; box-shadow: 0 8px 20px rgba(91, 33, 182, .28); text-decoration: none; }
        .btn-login-nav:hover { background: #4c1d95 !important; color: #fff !important; }
        .btn-outline-dashboard { border: 2px solid var(--primary-light); color: var(--primary) !important; border-radius: 50px; padding: 8px 22px !important; font-weight: 700; font-size: 14px; text-decoration: none; }
        .btn-outline-dashboard:hover { background: var(--primary); color: #fff !important; }
        @media (max-width: 991px) {
            .navbar-brand { font-size: 22px; }
            .navbar-collapse { background: rgba(255, 255, 255, 0.98); border-radius: 20px; padding: 16px; margin-top: 12px; box-shadow: 0 20px 45px -10px rgba(30, 64, 175, 0.15); }
            .btn-login-nav, .btn-outline-dashboard { margin: 8px 0 0; width: 100%; text-align: center; display: block; }
        }

        /* Footer — same as the homepage footer (dark navy, blue accent line) */
        .footer { background: var(--dark); color: rgba(255, 255, 255, 0.85); padding: 70px 0 30px; position: relative; overflow: hidden; }
        .footer::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: var(--gradient-accent); }
        .footer h4 { font-weight: 800; margin-bottom: 20px; color: #fff; font-size: 1.5rem; }
        .footer h5 { font-weight: 700; margin-bottom: 20px; color: #fff; font-size: 1.05rem; }
        .footer p { color: rgba(255, 255, 255, 0.6); font-size: 0.95rem; line-height: 1.7; }
        .footer-link { color: rgba(255, 255, 255, 0.6); text-decoration: none; transition: var(--transition); display: block; margin-bottom: 10px; font-size: 0.95rem; padding: 2px 0; position: relative; width: fit-content; }
        .footer-link::before { content: ''; position: absolute; bottom: 0; left: 0; width: 0; height: 2px; background: var(--gradient-accent); transition: var(--transition); }
        .footer-link:hover, .footer-link.current { color: #fff; }
        .footer-link:hover { padding-left: 5px; }
        .footer-link:hover::before, .footer-link.current::before { width: 100%; }
        .footer-contact-item { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; color: rgba(255, 255, 255, 0.6); font-size: 0.95rem; }
        .footer-contact-item i { width: 34px; height: 34px; border-radius: 10px; background: rgba(96, 165, 250, 0.1); color: var(--accent); display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0; }
        .footer-social { display: flex; gap: 10px; margin-top: 20px; flex-wrap: wrap; }
        .footer-social a { width: 42px; height: 42px; border-radius: 12px; background: rgba(255, 255, 255, 0.06); color: rgba(255, 255, 255, 0.6); display: flex; align-items: center; justify-content: center; font-size: 18px; transition: var(--transition); text-decoration: none; border: 1px solid rgba(255, 255, 255, 0.05); }
        .footer-social a:hover { background: var(--gradient-accent); color: #fff; transform: translateY(-4px) scale(1.05); box-shadow: var(--shadow-glow); }
        .footer-divider { border: none; height: 1px; background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent); margin: 30px 0; opacity: 1; }
        .footer-bottom { text-align: center; color: rgba(255, 255, 255, 0.4); font-size: 0.85rem; }

    </style>
    <link rel="stylesheet" href="assets/pragmavet.css">
    <link href="assets/global-style.css" rel="stylesheet">
    <link href="assets/landing.css?v=3" rel="stylesheet">
    <style>
        /* Same overrides as the homepage: PragmaVet blue, purple Login button */
        :root { --primary: #1e40af; --primary-light: #2563eb; --accent: #60a5fa; --gradient-accent: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); }
        .btn-login-nav { background: #5b21b6 !important; background-image: none !important; color: #fff !important; border: 0 !important; box-shadow: 0 8px 20px rgba(91, 33, 182, .28) !important; }
        .btn-login-nav:hover { background: #4c1d95 !important; color: #fff !important; }
        .btn-login-nav i { color: #fff !important; }

        /* Legal pages */
        .legal-hero { background: linear-gradient(135deg, #1e40af 0%, #2563eb 55%, #1e40af 100%); color: #fff; padding: 56px 0 64px; }
        .legal-crumb { font-size: 13px; opacity: .85; margin-bottom: 10px; }
        .legal-crumb a { color: #fff; text-decoration: none; font-weight: 600; }
        .legal-crumb a:hover { text-decoration: underline; }
        .legal-hero h1 { font-weight: 900; font-size: clamp(30px, 5vw, 44px); letter-spacing: -0.8px; margin: 0 0 8px; }
        .legal-hero p { margin: 0; opacity: .9; font-size: 16px; max-width: 680px; }
        .legal-meta { display: inline-flex; flex-wrap: wrap; gap: 8px; margin-top: 18px; }
        .legal-meta span { background: rgba(255, 255, 255, .14); border: 1px solid rgba(255, 255, 255, .22); border-radius: 999px; padding: 5px 12px; font-size: 12.5px; font-weight: 600; }
        .legal-wrap { margin-top: -36px; padding-bottom: 70px; }
        .legal-card { background: #fff; border: 1px solid var(--gray-300); border-radius: 24px; box-shadow: 0 20px 45px -10px rgba(30, 64, 175, 0.12); padding: 40px clamp(20px, 5vw, 56px); }
        .legal-note { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; border-radius: 14px; padding: 14px 16px; font-size: 14px; margin-bottom: 28px; display: flex; gap: 10px; }
        .legal-toc { background: var(--gray-100); border: 1px solid var(--gray-300); border-radius: 16px; padding: 18px 22px; margin-bottom: 30px; }
        .legal-toc strong { display: block; font-size: 13px; text-transform: uppercase; letter-spacing: .06em; color: var(--gray-500); margin-bottom: 8px; }
        .legal-toc ol { margin: 0; padding-left: 20px; columns: 2; column-gap: 32px; }
        .legal-toc li { margin-bottom: 4px; break-inside: avoid; }
        .legal-toc a { color: var(--primary-light); text-decoration: none; font-weight: 600; font-size: 14.5px; }
        .legal-toc a:hover { text-decoration: underline; }
        .legal-card h2 { font-size: 21px; font-weight: 800; color: #0f172a; margin: 34px 0 12px; scroll-margin-top: 90px; }
        .legal-card h2:first-of-type { margin-top: 0; }
        .legal-card h3 { font-size: 16px; font-weight: 700; color: #0f172a; margin: 20px 0 8px; }
        .legal-card p, .legal-card li { color: #334155; font-size: 15.5px; line-height: 1.75; }
        .legal-card ul { padding-left: 22px; }
        .legal-card a { color: var(--primary-light); }
        .ph { background: #eff6ff; color: #1e40af; border: 1px dashed #93c5fd; border-radius: 6px; padding: 0 6px; font-weight: 600; font-size: .95em; }
        .legal-table { width: 100%; border-collapse: collapse; margin: 10px 0 6px; font-size: 14.5px; }
        .legal-table th, .legal-table td { border: 1px solid var(--gray-300); padding: 10px 12px; text-align: left; vertical-align: top; }
        .legal-table th { background: var(--gray-100); font-weight: 700; color: #0f172a; }
        .legal-table-wrap { overflow-x: auto; }
        .legal-table td, .legal-table th { overflow-wrap: anywhere; }
        .legal-back { display: inline-flex; align-items: center; gap: 8px; margin-top: 34px; background: var(--primary-light); color: #fff !important; text-decoration: none; font-weight: 700; border-radius: 999px; padding: 11px 22px; }
        .legal-back:hover { background: var(--primary); }
        .legal-related { margin-top: 18px; font-size: 14.5px; color: var(--gray-500); }
        .legal-related a { font-weight: 600; text-decoration: none; }
        @media (max-width: 767px) { .legal-toc ol { columns: 1; } .legal-card { border-radius: 18px; } }
    </style>
HTML;
}

/** Site header (navbar). Section links go back to the homepage. */
function pv_site_nav() {
    $logged_in = !empty($_SESSION['user_id']);
    ?>
    <nav class="navbar navbar-expand-lg sticky-top" id="mainNavbar">
        <div class="container">
            <a class="navbar-brand" href="index.php"><i class="fa fa-paw"></i> PragmaVet</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navmenu" aria-controls="navmenu" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navmenu">
                <ul class="navbar-nav ms-auto align-items-lg-center">
                    <li class="nav-item"><a class="nav-link" href="index.php">Home</a></li>
                    <li class="nav-item"><a class="nav-link" href="index.php#features">Features</a></li>
                    <li class="nav-item"><a class="nav-link" href="index.php#how-it-works">How It Works</a></li>
                    <li class="nav-item"><a class="nav-link" href="index.php#team">Our Team</a></li>
                    <?php if ($logged_in): ?>
                        <li class="nav-item ms-lg-3 d-lg-flex gap-2">
                            <a href="dashboard.php" class="btn-outline-dashboard nav-link"><i class="fa fa-th-large me-1"></i> Dashboard</a>
                            <a href="logout.php" class="btn-login-nav nav-link"><i class="fa fa-sign-out-alt"></i> Logout</a>
                        </li>
                    <?php else: ?>
                        <li class="nav-item"><a href="index.php?login=1" class="btn-login-nav nav-link"><i class="fa fa-lock me-1"></i> Login</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
    <?php
}

/** Site footer — same content and layout as the homepage footer. $current marks the open legal page. */
function pv_site_footer($current = '') {
    $cur = fn($p) => $p === $current ? ' current" aria-current="page' : '';
    ?>
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
                    <a href="privacy-policy.php" class="footer-link<?= $cur('privacy') ?>">Privacy Policy</a>
                    <a href="terms-of-service.php" class="footer-link<?= $cur('terms') ?>">Terms of Service</a>
                    <a href="cookie-policy.php" class="footer-link<?= $cur('cookies') ?>">Cookie Policy</a>
                </div>
                <div class="col-lg-4 col-md-6">
                    <h5>Contact Us</h5>
                    <div class="footer-contact-item"><i class="fa fa-map-marker-alt"></i><span>0234 Unit B Palico St, Palico 4, Imus, Cavite</span></div>
                    <div class="footer-contact-item"><i class="fa fa-envelope"></i><span>pragmavet@gmail.com</span></div>
                    <div class="footer-contact-item"><i class="fa fa-phone"></i><span>0954-285-3053</span></div>
                </div>
            </div>
            <hr class="footer-divider">
            <div class="footer-bottom">
                <p class="mb-0">&copy; 2026 PragmaVet Veterinary Management System. All rights reserved.</p>
            </div>
        </div>
    </footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <?php
}
