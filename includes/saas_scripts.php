<?php
// ===== SHARED PRAGMAVET UI SCRIPTS FROM INDEX.PHP =====
// Include this file right before </body> on any page
?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ============ SCROLL REVEAL ============
    const revealElements = document.querySelectorAll('.reveal, .reveal-left, .reveal-right, .reveal-scale, .reveal-stagger');
    const revealObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
                revealObserver.unobserve(entry.target);
            }
        });
    }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });
    revealElements.forEach(el => revealObserver.observe(el));

    // ============ SCROLL PROGRESS BAR ============
    const progressBar = document.getElementById('scroll-progress');
    if (progressBar) {
        window.addEventListener('scroll', function() {
            const scrollTop = window.scrollY;
            const docHeight = document.documentElement.scrollHeight - window.innerHeight;
            const scrollPercent = (scrollTop / docHeight) * 100;
            progressBar.style.width = scrollPercent + '%';
        });
    }

    // ============ BACK TO TOP ============
    const backToTop = document.getElementById('back-to-top');
    if (backToTop) {
        window.addEventListener('scroll', function() {
            if (window.scrollY > 500) {
                backToTop.classList.add('visible');
            } else {
                backToTop.classList.remove('visible');
            }
        });
        backToTop.addEventListener('click', function() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }

    // ============ NAVBAR SCROLL EFFECT ============
    const navbar = document.getElementById('mainNavbar');
    if (navbar) {
        window.addEventListener('scroll', function() {
            if (window.scrollY > 50) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        });
    }

    // ============ RIPPLE EFFECT ============
    document.addEventListener('click', function(e) {
        const target = e.target.closest('.ripple');
        if (!target) return;
        const rect = target.getBoundingClientRect();
        const ripple = document.createElement('span');
        ripple.className = 'ripple-effect';
        const size = Math.max(rect.width, rect.height);
        ripple.style.width = ripple.style.height = size + 'px';
        ripple.style.left = (e.clientX - rect.left - size / 2) + 'px';
        ripple.style.top = (e.clientY - rect.top - size / 2) + 'px';
        target.appendChild(ripple);
        setTimeout(() => ripple.remove(), 600);
    });

    // ============ COUNTER ANIMATION ============
    const counters = document.querySelectorAll('.counter-number');
    const counterObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const counter = entry.target;
                const target = parseInt(counter.getAttribute('data-target'));
                const suffix = counter.textContent.includes('%') ? '%' : counter.textContent.includes('/') ? '/7' : '';
                let current = 0;
                const increment = target / 60;
                const updateCounter = () => {
                    current += increment;
                    if (current < target) {
                        counter.textContent = Math.ceil(current) + suffix;
                        requestAnimationFrame(updateCounter);
                    } else {
                        counter.textContent = target + suffix;
                    }
                };
                updateCounter();
                counterObserver.unobserve(counter);
            }
        });
    }, { threshold: 0.5 });
    counters.forEach(c => counterObserver.observe(c));

    // ============ PAW CURSOR TRAIL ============
    const pawEmojis = ['🐾', '🐾'];
    let lastPaw = 0;
    document.addEventListener('mousemove', function(e) {
        const now = Date.now();
        if (now - lastPaw < 120) return;
        lastPaw = now;
        const paw = document.createElement('div');
        paw.className = 'paw-trail';
        paw.textContent = pawEmojis[Math.floor(Math.random() * pawEmojis.length)];
        paw.style.left = e.clientX + 'px';
        paw.style.top = e.clientY + 'px';
        paw.style.fontSize = (14 + Math.random() * 10) + 'px';
        document.body.appendChild(paw);
        setTimeout(() => paw.remove(), 1200);
    });

    // ============ IMAGE TILT ============
    const tiltImages = document.querySelectorAll('.tilt-image');
    tiltImages.forEach(img => {
        img.addEventListener('mousemove', function(e) {
            const rect = this.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            const centerX = rect.width / 2;
            const centerY = rect.height / 2;
            const rotateX = (y - centerY) / centerY * -8;
            const rotateY = (x - centerX) / centerX * 8;
            this.style.transform = `scale(1.05) rotateX(${rotateX}deg) rotateY(${rotateY}deg)`;
        });
        img.addEventListener('mouseleave', function() {
            this.style.transform = 'scale(1) rotateX(0) rotateY(0)';
        });
    });

    // ============ AUTO DISMISS ALERTS ============
    setTimeout(function() {
        let alerts = document.querySelectorAll('.alert-container');
        alerts.forEach(a => {
            a.style.transition = 'opacity 0.5s ease-out, transform 0.5s ease-out';
            a.style.opacity = '0';
            a.style.transform = 'translateY(-20px)';
            setTimeout(() => a.remove(), 500);
        });
    }, 4500);
});
</script>
<?php
// ===== END SHARED SCRIPTS =====
?>