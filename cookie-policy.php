<?php
session_start();
require_once __DIR__ . '/includes/site_chrome.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php pv_site_head('Cookie Policy', 'The cookies and browser storage PragmaVet uses, and how you can control them.'); ?>
</head>
<body>
<?php pv_site_nav(); ?>

<header class="legal-hero">
    <div class="container">
        <div class="legal-crumb"><a href="index.php"><i class="fa fa-house me-1"></i>Home</a> <span class="mx-1">/</span> Legal</div>
        <h1>Cookie Policy</h1>
        <p>The cookies and browser storage PragmaVet uses, why we use them, and how you can control them.</p>
        <div class="legal-meta">
            <span><i class="fa fa-calendar me-1"></i>Last updated: Oct 12, 2026</span>
            <span><i class="fa fa-file-lines me-1"></i>Version 1.0</span>
        </div>
    </div>
</header>

<main class="container legal-wrap">
    <article class="legal-card">
        <div class="legal-note"><i class="fa fa-circle-info mt-1"></i><div><strong>Draft for review.</strong> This page is not legal advice and has not been reviewed under any specific law.</div></div>

        <nav class="legal-toc" aria-label="Contents">
            <strong>Contents</strong>
            <ol>
                <li><a href="#what">What cookies are</a></li>
                <li><a href="#essential">Essential cookies</a></li>
                <li><a href="#storage">Preferences stored in your browser</a></li>
                <li><a href="#thirdparty">Third-party services</a></li>
                <li><a href="#notused">What we do not use</a></li>
                <li><a href="#control">How to control cookies</a></li>
                <li><a href="#changes">Changes to this policy</a></li>
                <li><a href="#contact">Contact us</a></li>
            </ol>
        </nav>

        <h2 id="what">1. What cookies are</h2>
        <p>Cookies are small files a website saves in your browser. Similar technologies, such as your browser's local storage, let a site remember small settings on your device. PragmaVet uses only a few of these, mainly to keep you signed in and remember simple preferences.</p>

        <h2 id="essential">2. Essential cookies</h2>
        <p>These are needed for the system to work and cannot be switched off in PragmaVet.</p>
        <div class="legal-table-wrap">
            <table class="legal-table">
                <thead><tr><th>Name</th><th>Purpose</th><th>Duration</th></tr></thead>
                <tbody>
                    <tr><td>PHPSESSID</td><td>Keeps you signed in as you move between pages, and protects forms against requests from other websites. It is only sent to this website and cannot be read by page scripts.</td><td>Until you sign out or close your browser</td></tr>
                </tbody>
            </table>
        </div>

        <h2 id="storage">3. Preferences stored in your browser</h2>
        <p>These items are saved only on your device. They are not sent to us with your requests and do not identify you.</p>
        <div class="legal-table-wrap">
            <table class="legal-table">
                <thead><tr><th>Name</th><th>Type</th><th>Purpose</th></tr></thead>
                <tbody>
                    <tr><td>theme</td><td>Local storage</td><td>Remembers your light or dark display setting on pages that offer it.</td></tr>
                    <tr><td>pvCctvRotation</td><td>Local storage</td><td>Remembers how you rotated the boarding live camera picture.</td></tr>
                    <tr><td>pragma_reminder_dismissed_date</td><td>Local storage</td><td>Hides the clinic reminders pop-up for the rest of the day after you choose "Don't show again today".</td></tr>
                    <tr><td>pragma_reminder_session_dismissed</td><td>Session storage</td><td>Hides the clinic reminders pop-up for the current browser session after you close it.</td></tr>
                </tbody>
            </table>
        </div>

        <h2 id="thirdparty">4. Third-party services</h2>
        <p>Some parts of PragmaVet are loaded from other providers. When your browser loads them, those providers may receive technical information such as your IP address and browser type, and may set their own cookies under their own policies.</p>
        <ul>
            <li><strong>Fonts, icons and page libraries:</strong> Google Fonts, jsDelivr, cdnjs (Cloudflare), unpkg, jQuery CDN and DataTables CDN.</li>
            <li><strong>Profile placeholder images:</strong> UI Avatars, and stock images used on some pages.</li>
            <li><strong>Online payments:</strong> PayMongo, when you choose to pay online. Its checkout page is operated by PayMongo.</li>
        </ul>
        

        <h2 id="notused">5. What we do not use</h2>
        <p>PragmaVet does not currently use analytics, advertising or social-media tracking cookies. This page will be updated if such tools are ever added.</p>

        <h2 id="control">6. How to control cookies</h2>
        <p>You can view and delete cookies and stored site data in your browser settings. Blocking the essential session cookie will stop you from signing in. Clearing stored preferences simply resets them to their defaults.</p>

        <h2 id="changes">7. Changes to this policy</h2>
        <p>We will update this page if the cookies or storage we use change. The "Last updated" date shows the latest version.</p>

        <h2 id="contact">8. Contact us</h2>
        <p>Questions about cookies? Email <a href="mailto:pragmavet@gmail.com">pragmavet@gmail.com</a> or call 0954-285-3053. See also our <a href="privacy-policy.php">Privacy Policy</a>.</p>

        <a href="index.php" class="legal-back"><i class="fa fa-arrow-left"></i> Back to homepage</a>
        <div class="legal-related">See also: <a href="privacy-policy.php">Privacy Policy</a> · <a href="terms-of-service.php">Terms of Service</a></div>
    </article>
</main>

<?php pv_site_footer('cookies'); ?>
</body>
</html>
