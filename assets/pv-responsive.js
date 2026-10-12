/* PragmaVet — mobile menu + table scrolling. Does nothing on desktop widths. */
(function () {
    var MQ = window.matchMedia('(max-width: 991.98px)');

    function setup() {
        // Desktop: leave the page exactly as it is; set up the first time the screen is small
        if (!MQ.matches) return;
        var sidebar = document.querySelector('.sidebar');
        if (sidebar && !document.body.classList.contains('pv-has-sidebar')) {
            document.body.classList.add('pv-has-sidebar');
            if (!document.querySelector('.pv-nav-btn')) {
                var btn = document.createElement('button');
                btn.type = 'button'; btn.className = 'pv-nav-btn d-lg-none'; btn.setAttribute('aria-label', 'Menu');
                btn.innerHTML = '<i class="fa fa-bars"></i>';
                var bd = document.createElement('div'); bd.className = 'pv-nav-backdrop d-lg-none';
                document.body.appendChild(bd); document.body.appendChild(btn);
                var toggle = function (open) {
                    var on = typeof open === 'boolean' ? open : !document.body.classList.contains('pv-nav-open');
                    document.body.classList.toggle('pv-nav-open', on);
                    btn.innerHTML = on ? '<i class="fa fa-xmark"></i>' : '<i class="fa fa-bars"></i>';
                };
                btn.addEventListener('click', function () { toggle(); });
                bd.addEventListener('click', function () { toggle(false); });
                document.addEventListener('keydown', function (e) { if (e.key === 'Escape') toggle(false); });
                // tapping a menu link closes the menu
                sidebar.addEventListener('click', function (e) { if (e.target.closest('a') && MQ.matches) toggle(false); });
                MQ.addEventListener ? MQ.addEventListener('change', function () { if (!MQ.matches) toggle(false); }) : null;
            }
        }
        wrapTables();
    }

    // Wide tables scroll inside their card instead of stretching the page (mobile only)
    function wrapTables() {
        if (!MQ.matches) return;
        document.querySelectorAll('table').forEach(function (t) {
            if (t.closest('.pv-table-scroll, .table-responsive, .modal-body .fc, .fc, .dataTables_scrollBody')) return;
            if (t.parentElement && /(?:^|\s)(table-responsive|dataTables_wrapper)(?:\s|$)/.test(t.parentElement.className) ) return;
            var w = document.createElement('div'); w.className = 'pv-table-scroll';
            t.parentNode.insertBefore(w, t); w.appendChild(t);
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', setup); else setup();
    window.addEventListener('load', wrapTables);
    if (MQ.addEventListener) MQ.addEventListener('change', setup);
})();
