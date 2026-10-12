<?php
session_start();
require_once __DIR__ . '/includes/site_chrome.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php pv_site_head('Privacy Policy', 'How PragmaVet collects, uses and protects information in its veterinary clinic management system.'); ?>
</head>
<body>
<?php pv_site_nav(); ?>

<header class="legal-hero">
    <div class="container">
        <div class="legal-crumb"><a href="index.php"><i class="fa fa-house me-1"></i>Home</a> <span class="mx-1">/</span> Legal</div>
        <h1>Privacy Policy</h1>
        <p>How PragmaVet collects, uses and protects the information of pet owners, their pets and clinic users.</p>
        <div class="legal-meta">
            <span><i class="fa fa-calendar me-1"></i>Last updated: <span class="ph">[Insert date]</span></span>
            <span><i class="fa fa-file-lines me-1"></i>Version <span class="ph">[1.0]</span></span>
        </div>
    </div>
</header>

<main class="container legal-wrap">
    <article class="legal-card">
        <div class="legal-note"><i class="fa fa-circle-info mt-1"></i><div><strong>Draft for review.</strong> Items shown like <span class="ph">[this]</span> are placeholders that the clinic must confirm or complete. This page is not legal advice and has not been reviewed for compliance with any specific law.</div></div>

        <nav class="legal-toc" aria-label="Contents">
            <strong>Contents</strong>
            <ol>
                <li><a href="#who">Who we are</a></li>
                <li><a href="#collect">Information we collect</a></li>
                <li><a href="#use">How we use information</a></li>
                <li><a href="#share">Sharing and service providers</a></li>
                <li><a href="#camera">Boarding live camera</a></li>
                <li><a href="#retention">How long we keep information</a></li>
                <li><a href="#security">How we protect information</a></li>
                <li><a href="#rights">Your choices and rights</a></li>
                <li><a href="#children">Children</a></li>
                <li><a href="#changes">Changes to this policy</a></li>
                <li><a href="#contact">Contact us</a></li>
            </ol>
        </nav>

        <h2 id="who">1. Who we are</h2>
        <p>PragmaVet is a veterinary clinic management system used by <span class="ph">[Clinic legal name]</span> ("the clinic", "we", "us") to manage appointments, pet boarding, medical records, billing and communication with pet owners. The clinic is located at 0234 Unit B Palico St, Palico 4, Imus, Cavite.</p>
        <p>The person responsible for privacy matters is <span class="ph">[Name / title of data protection officer or privacy contact]</span>.</p>

        <h2 id="collect">2. Information we collect</h2>
        <h3>Information you give us</h3>
        <ul>
            <li><strong>Account details:</strong> full name, email address, contact number, home address, profile photo (optional) and your password (stored in encrypted, hashed form).</li>
            <li><strong>Pet details:</strong> pet name, species, breed, gender, birth date, weight, photo, allergies and special care instructions.</li>
            <li><strong>Bookings:</strong> appointment and pet boarding requests, chosen services, dates, times, veterinarian and cage, reschedule and cancellation requests.</li>
            <li><strong>Messages and uploads:</strong> messages you send to the clinic through the chat, and photos, videos or PDF files you attach.</li>
            <li><strong>Feedback:</strong> ratings and comments you leave after a visit.</li>
        </ul>
        <h3>Information created when you use the clinic's services</h3>
        <ul>
            <li><strong>Medical records:</strong> consultation notes, diagnoses, treatments, prescriptions, laboratory results, vaccination records and health certificates recorded by clinic staff and veterinarians.</li>
            <li><strong>Billing records:</strong> invoices, amounts paid, payment method and payment references.</li>
            <li><strong>Security and activity records:</strong> sign-in attempts, one-time verification codes sent to your email, and a log of changes made in the system.</li>
        </ul>
        <h3>Payment information</h3>
        <p>Online payments are processed by PayMongo. PragmaVet does not receive or store your full card, GCash or Maya details; we keep the payment reference and status needed to record your payment. <span class="ph">[Confirm payment providers and what payment data is kept.]</span></p>

        <h2 id="use">3. How we use information</h2>
        <ul>
            <li>To create and secure your account, including email verification.</li>
            <li>To schedule, confirm, reschedule and cancel appointments and boarding stays, and to assign veterinarians and cages.</li>
            <li>To provide veterinary care and keep your pet's medical history, vaccinations and certificates up to date.</li>
            <li>To issue invoices and receipts and record payments.</li>
            <li>To send you notifications and emails about your bookings, payments, certificates and your pet's stay.</li>
            <li>To answer your messages and requests.</li>
            <li>To prepare internal reports about clinic operations.</li>
            <li><span class="ph">[Add or remove purposes, and state the legal basis for each, as confirmed by the clinic.]</span></li>
        </ul>
        <p>We do not sell your personal information. <span class="ph">[Confirm.]</span></p>

        <h2 id="share">4. Sharing and service providers</h2>
        <p>Information is available to authorised clinic users (vet staff, veterinarians, administrators and the clinic owner) only as needed for their role. We use the following service providers to run the system:</p>
        <ul>
            <li><strong>PayMongo</strong> — online payments.</li>
            <li><strong>Email delivery</strong> (<span class="ph">[email provider]</span>) — verification codes, password resets and notices.</li>
            <li><strong>Hosting</strong> (<span class="ph">[hosting provider and server location]</span>).</li>
        </ul>
        <p>We may also share information when required by law or to protect the safety of people or animals. <span class="ph">[Describe any other sharing, e.g. with laboratories or referral clinics.]</span></p>

        <h2 id="camera">5. Boarding live camera</h2>
        <p>During a pet's boarding stay, the clinic may provide a live camera view of the pet's cage. Access is limited to the pet's owner while the stay is active and to authorised clinic staff, and it ends when the pet is checked out. <span class="ph">[State whether video is recorded or stored, and for how long.]</span></p>

        <h2 id="retention">6. How long we keep information</h2>
        <p>We keep information for as long as needed for the purposes above and as required by law. <span class="ph">[Insert retention periods, e.g. for accounts, medical records, billing records, messages, camera footage and activity logs.]</span></p>

        <h2 id="security">7. How we protect information</h2>
        <p>We use measures such as encrypted (hashed) passwords, email verification, limits on repeated sign-in and code attempts, role-based access, and restrictions on uploaded files. No system is completely secure, and we cannot guarantee absolute security. <span class="ph">[Describe any additional safeguards, e.g. HTTPS, backups, staff training.]</span></p>

        <h2 id="rights">8. Your choices and rights</h2>
        <p>You can view and update your account and pet details in the system. You may contact us to request access to, correction of, or deletion of your personal information, subject to the clinic's legal and medical record-keeping obligations. <span class="ph">[List the rights available under the applicable law, e.g. the Data Privacy Act of 2012 (Republic Act No. 10173), and how to exercise them.]</span></p>

        <h2 id="children">9. Children</h2>
        <p>PragmaVet accounts are intended for adults responsible for a pet. <span class="ph">[Confirm minimum age and how accounts for minors are handled.]</span></p>

        <h2 id="changes">10. Changes to this policy</h2>
        <p>We may update this policy from time to time. The "Last updated" date above shows when it was last changed. <span class="ph">[Describe how users will be notified of important changes.]</span></p>

        <h2 id="contact">11. Contact us</h2>
        <p>For questions or requests about this policy, contact <span class="ph">[privacy contact name]</span> at <a href="mailto:pragmavet@gmail.com">pragmavet@gmail.com</a>, call 0954-285-3053, or visit 0234 Unit B Palico St, Palico 4, Imus, Cavite.</p>

        <a href="index.php" class="legal-back"><i class="fa fa-arrow-left"></i> Back to homepage</a>
        <div class="legal-related">See also: <a href="terms-of-service.php">Terms of Service</a> · <a href="cookie-policy.php">Cookie Policy</a></div>
    </article>
</main>

<?php pv_site_footer('privacy'); ?>
</body>
</html>
