<?php
session_start();
require_once __DIR__ . '/includes/site_chrome.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php pv_site_head('Terms of Service', 'The terms for using PragmaVet to book appointments and pet boarding and to manage your pets\' records.'); ?>
</head>
<body>
<?php pv_site_nav(); ?>

<header class="legal-hero">
    <div class="container">
        <div class="legal-crumb"><a href="index.php"><i class="fa fa-house me-1"></i>Home</a> <span class="mx-1">/</span> Legal</div>
        <h1>Terms of Service</h1>
        <p>The rules for using PragmaVet to book appointments and pet boarding, pay invoices and view your pets' records.</p>
        <div class="legal-meta">
            <span><i class="fa fa-calendar me-1"></i>Last updated: Oct 12, 2026</span>
            <span><i class="fa fa-file-lines me-1"></i>Version 1.0</span>
        </div>
    </div>
</header>

<main class="container legal-wrap">
    <article class="legal-card">
        <div class="legal-note"><i class="fa fa-circle-info mt-1"></i><div><strong>Draft for review.</strong> Items shown like <span class="ph">[this]</span> are placeholders that the clinic must confirm or complete. This page is not legal advice and has not been reviewed under any specific law.</div></div>

        <nav class="legal-toc" aria-label="Contents">
            <strong>Contents</strong>
            <ol>
                <li><a href="#about">About these terms</a></li>
                <li><a href="#account">Your account</a></li>
                <li><a href="#appointments">Appointments</a></li>
                <li><a href="#boarding">Pet boarding</a></li>
                <li><a href="#payments">Fees and payments</a></li>
                <li><a href="#cancel">Cancellations and refunds</a></li>
                <li><a href="#records">Medical records and information</a></li>
                <li><a href="#use">Acceptable use</a></li>
                <li><a href="#liability">Limits of responsibility</a></li>
                <li><a href="#changes">Changes and termination</a></li>
                <li><a href="#law">Governing law</a></li>
                <li><a href="#contact">Contact us</a></li>
            </ol>
        </nav>

        <h2 id="about">1. About these terms</h2>
        <p>These terms apply to your use of PragmaVet, the online system of PragmaVet Animal Clinic ("the clinic", "we", "us"), for booking veterinary appointments and pet boarding, paying invoices, messaging the clinic and viewing your pets' records. By creating an account or using the system, you agree to these terms and to our <a href="privacy-policy.php">Privacy Policy</a>.</p>

        <h2 id="account">2. Your account</h2>
        <ul>
            <li>You must provide accurate information and verify your email address to use your account.</li>
            <li>You are responsible for keeping your password private and for activity under your account. Tell us promptly if you think your account has been used without your permission.</li>
            <li>Accounts may be locked temporarily after repeated failed sign-in attempts.</li>
            <li>You must be <span class="ph">[minimum age]</span> or older and responsible for the pets you register.</li>
        </ul>

        <h2 id="appointments">3. Appointments</h2>
        <ul>
            <li>Appointments are requests until confirmed by clinic staff. The clinic may approve, decline or propose a new schedule.</li>
            <li>Bookings must be made at least 24 hours in advance and only for days and times the clinic and the chosen veterinarian are available.</li>
            <li>An appointment that is not attended may be marked as a no-show. The reservation fee for a missed appointment is non-refundable.</li>
            <li>Online booking does not replace emergency care. For emergencies, contact the clinic directly at 0954-285-3053.</li>
        </ul>

        <h2 id="boarding">4. Pet boarding</h2>
        <ul>
            <li>Boarding requests are confirmed by clinic staff, who assign or confirm the cage.</li>
            <li>You must provide accurate information about your pet's health, vaccinations, medications, diet and behaviour. Before a stay, your pet must have up-to-date vaccinations and recent deworming and tick and flea treatment. Please bring any special food and medication, with written instructions.</li>
            <li>Check-in and check-out happen at the clinic. Any balance must be settled at check-out.</li>
            <li>Where available, the live camera is provided as a convenience during the stay and may be paused or unavailable at times.</li>
            <li>If your pet is picked up after the booked check-out date, each extra night is charged at the daily boarding rate.</li>
        </ul>

        <h2 id="payments">5. Fees and payments</h2>
        <ul>
            <li>Service prices are set by the clinic and shown in the system or at the clinic. Prices may change; invoices already issued keep their amounts.</li>
            <li>A reservation fee or boarding deposit may be required to secure a booking. Currently: a ₱150 reservation fee for appointments, and for boarding either a ₱500 reservation fee or a 30% deposit of the stay's total.</li>
            <li>Online payments are processed by PayMongo and are subject to its terms. Accepted methods: cash at the clinic, GCash or card at the front desk, and online payment through PayMongo (GCash, Maya or card).</li>
        </ul>

        <h2 id="cancel">6. Cancellations and refunds</h2>
        <ul>
            <li>You may cancel a pending or approved booking in the system up to 24 hours before its scheduled time. Later changes must be arranged with the clinic.</li>
            <li>Reservation fees and deposits are non-refundable when you cancel, as stated in the system before you confirm a cancellation.</li>
            <li>Reservation fees and deposits are non-refundable, including when the clinic cancels or cannot provide the service.</li>
        </ul>

        <h2 id="records">7. Medical records and information</h2>
        <p>Medical records, prescriptions and certificates are prepared by the clinic's veterinarians. The records shown in the system are for your reference; please contact the clinic if you believe something is incorrect. Health certificates are issued only after review and approval by the clinic.</p>

        <h2 id="use">8. Acceptable use</h2>
        <p>You agree not to misuse the system, including by:</p>
        <ul>
            <li>accessing another person's account, pets or records;</li>
            <li>uploading harmful files or content that is unlawful, abusive or unrelated to your pet's care;</li>
            <li>interfering with the system's security or operation, or attempting to bypass payment or booking rules.</li>
        </ul>

        <h2 id="liability">9. Limits of responsibility</h2>
        <p>We aim to keep PragmaVet available and accurate, but the system may occasionally be unavailable or contain errors. <span class="ph">[Insert the clinic's limitation of liability and disclaimer wording, as reviewed by a qualified adviser.]</span></p>

        <h2 id="changes">10. Changes and termination</h2>
        <p>We may update these terms; the "Last updated" date shows the latest version. We may suspend or close accounts that break these terms. You may ask us to close your account at any time, subject to our record-keeping obligations described in the <a href="privacy-policy.php">Privacy Policy</a>.</p>

        <h2 id="law">11. Governing law</h2>
        <p>These terms are governed by the laws of the Republic of the Philippines.</p>

        <h2 id="contact">12. Contact us</h2>
        <p>Questions about these terms? Email <a href="mailto:pragmavet@gmail.com">pragmavet@gmail.com</a>, call 0954-285-3053, or visit 0234 Unit B Palico St, Palico 4, Imus, Cavite.</p>

        <a href="index.php" class="legal-back"><i class="fa fa-arrow-left"></i> Back to homepage</a>
        <div class="legal-related">See also: <a href="privacy-policy.php">Privacy Policy</a> · <a href="cookie-policy.php">Cookie Policy</a></div>
    </article>
</main>

<?php pv_site_footer('terms'); ?>
</body>
</html>
