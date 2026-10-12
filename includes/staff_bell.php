<?php include_once __DIR__ . '/pv_responsive.php'; ?>
<?php
/**
 * Vet staff notifications: floating bell (top-right of the screen) with unread count + dropdown panel.
 * Uses api_notifications.php (fetch / mark_one / mark_read). Included right after the staff sidebar.
 */
$sbell_uid = (int)($_SESSION['user_id'] ?? 0);
$sbell_unread = (int)($conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id = $sbell_uid AND is_read = 0")->fetch_assoc()['c'] ?? 0);
?>
<button type="button" class="sbell-fab" id="sbellLink" onclick="sbellToggle(event)" title="Notifications" aria-label="Notifications">
    <i class="fa fa-bell"></i>
    <span class="sbell-count" id="sbellBadge" style="<?= $sbell_unread ? '' : 'display:none;' ?>"><?= $sbell_unread ?></span>
</button>

<div class="sbell-backdrop" id="sbellBackdrop" onclick="sbellClose()"></div>
<aside class="sbell-panel" id="sbellPanel" aria-label="Notifications">
    <div class="sbell-head">
        <div><h6>Notifications</h6><div class="sbell-sub" id="sbellSub">Loading…</div></div>
        <div class="d-flex gap-2 align-items-center">
            <button type="button" class="sbell-mark" onclick="sbellMarkAll()" title="Mark all as read"><i class="fa fa-check-double me-1"></i>Mark all read</button>
            <button type="button" class="sbell-x" onclick="sbellClose()" title="Close"><i class="fa fa-xmark"></i></button>
        </div>
    </div>
    <div class="sbell-list" id="sbellList"></div>
</aside>

<style>
    /* Floating bell — top-right of the screen */
    .sbell-fab { position:fixed; top:22px; right:26px; z-index:2010; width:48px; height:48px; border-radius:50%; border:1px solid #e2e8f0; background:#fff !important; color:#334155 !important;
                 display:flex; align-items:center; justify-content:center; font-size:19px; box-shadow:0 8px 24px rgba(15,23,42,.12) !important; cursor:pointer; transition:transform .15s, box-shadow .15s, color .15s; padding:0; }
    .sbell-fab:hover { transform:translateY(-2px); color:#2563eb !important; box-shadow:0 12px 28px rgba(37,99,235,.2) !important; }
    .sbell-fab.active { background:#2563eb !important; color:#fff !important; border-color:#2563eb; }
    .sbell-fab.ring i { animation:sbellRing 1s ease 2; transform-origin:50% 0; }
    @keyframes sbellRing { 0%,100%{transform:rotate(0)} 20%{transform:rotate(16deg)} 40%{transform:rotate(-14deg)} 60%{transform:rotate(9deg)} 80%{transform:rotate(-5deg)} }
    .sbell-count { position:absolute; top:-4px; right:-4px; min-width:21px; height:21px; border-radius:999px; background:#ef4444; color:#fff; font-size:11px; font-weight:800; display:flex; align-items:center; justify-content:center; padding:0 6px; border:2px solid #fff; }
    /* keep page headers clear of the bell */
    .main-wrapper > .top-action-bar, .main-wrapper > .d-flex:first-child { padding-right:70px; }
    /* Vet pages: the bell sits in the page header next to the other buttons, so it never covers them */
    .sbell-fab.sbell-inline { position:relative; top:auto; right:auto; margin-left:4px; flex-shrink:0; }
    .main-wrapper:not(:has(> .top-action-bar)):not(:has(> .d-flex:first-child)) { padding-top:84px; }
    .sbell-backdrop { position:fixed; inset:0; background:rgba(15,23,42,.18); z-index:1990; opacity:0; pointer-events:none; transition:opacity .2s; }
    .sbell-backdrop.open { opacity:1; pointer-events:auto; }
    .sbell-panel { position:fixed; top:80px; right:26px; width:390px; max-width:calc(100vw - 32px); max-height:calc(100vh - 100px); background:#fff; z-index:2000; border-radius:18px; border:1px solid #e8edf3;
                   box-shadow:0 20px 50px rgba(15,23,42,.18); transform:translateY(-8px) scale(.98); transform-origin:top right; opacity:0; pointer-events:none; transition:transform .2s ease, opacity .2s ease; display:flex; flex-direction:column; overflow:hidden; }
    .sbell-panel.open { transform:none; opacity:1; pointer-events:auto; }
    .sbell-head { padding:20px 18px 14px; border-bottom:1px solid #f1f5f9; display:flex; justify-content:space-between; align-items:flex-start; gap:10px; }
    .sbell-head h6 { font-weight:800; font-size:17px; color:#0f172a; margin:0; }
    .sbell-sub { font-size:12px; color:#64748b; margin-top:2px; }
    .sbell-mark { border:none; background:#eff6ff !important; color:#2563eb !important; font-size:12px; font-weight:700; border-radius:999px; padding:6px 11px; box-shadow:none !important; white-space:nowrap; }
    .sbell-x { border:none; background:#f1f5f9 !important; color:#64748b !important; width:30px; height:30px; border-radius:50%; display:flex; align-items:center; justify-content:center; padding:0; box-shadow:none !important; }
    .sbell-list { flex:1; overflow-y:auto; padding:8px; }
    .sbell-item { display:flex; gap:11px; padding:12px; border-radius:14px; cursor:pointer; transition:background .15s; position:relative; }
    .sbell-item:hover { background:#f8fafc; }
    .sbell-item.unread { background:#f5f9ff; }
    .sbell-item.unread::after { content:''; position:absolute; top:16px; right:12px; width:8px; height:8px; border-radius:50%; background:#2563eb; }
    .sbell-ic { width:36px; height:36px; border-radius:11px; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:14px; }
    .sbell-msg { font-size:13px; color:#334155; line-height:1.4; padding-right:14px; }
    .sbell-item.unread .sbell-msg { color:#0f172a; font-weight:600; }
    .sbell-time { font-size:11.5px; color:#94a3b8; margin-top:3px; }
    .sbell-empty { text-align:center; color:#94a3b8; padding:50px 20px; font-size:13px; }
    .sbell-empty i { font-size:34px; opacity:.35; display:block; margin-bottom:10px; }
    @media (max-width: 768px) { .sbell-fab { top:12px; right:12px; } .sbell-panel { top:70px; right:8px; left:8px; width:auto; } }
</style>
<script>
(function () {
    var open = false, data = [];
    function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
    function plain(html) { var d = document.createElement('div'); d.innerHTML = html || ''; return d.textContent || ''; }
    function ago(ts) {
        var d = new Date(String(ts).replace(' ', 'T')), s = (Date.now() - d.getTime()) / 1000;
        if (isNaN(s)) return '';
        if (s < 60) return 'Just now';
        if (s < 3600) return Math.floor(s / 60) + ' min ago';
        if (s < 86400) return Math.floor(s / 3600) + ' h ago';
        if (s < 172800) return 'Yesterday';
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    }
    function kind(t) {
        t = t.toLowerCase();
        if (t.indexOf('boarding') > -1) return ['fa-hotel', '#0891b2'];
        if (t.indexOf('declined') > -1 || t.indexOf('cancel') > -1) return ['fa-circle-xmark', '#dc2626'];
        if (t.indexOf('paid') > -1 || t.indexOf('fee') > -1) return ['fa-coins', '#16a34a'];
        if (t.indexOf('stock') > -1 || t.indexOf('report') > -1) return ['fa-boxes-stacked', '#ea580c'];
        if (t.indexOf('accepted') > -1) return ['fa-circle-check', '#16a34a'];
        if (t.indexOf('new appointment') > -1 || t.indexOf('reschedule') > -1) return ['fa-calendar-plus', '#2563eb'];
        return ['fa-bell', '#7c3aed'];
    }
    function setBadge(n) {
        var b = document.getElementById('sbellBadge'); b.textContent = n; b.style.display = n > 0 ? '' : 'none';
        document.getElementById('sbellSub').textContent = n > 0 ? n + ' unread' : 'You’re all caught up';
    }
    function render() {
        var box = document.getElementById('sbellList');
        if (!data.length) { box.innerHTML = '<div class="sbell-empty"><i class="fa fa-bell-slash"></i>No notifications yet.</div>'; return; }
        box.innerHTML = data.map(function (n, i) {
            var t = plain(n.message), k = kind(t);
            return '<div class="sbell-item' + (n.is_read == 0 ? ' unread' : '') + '" data-i="' + i + '">'
                 + '<div class="sbell-ic" style="background:' + k[1] + '14;color:' + k[1] + ';"><i class="fa ' + k[0] + '"></i></div>'
                 + '<div><div class="sbell-msg">' + esc(t) + '</div><div class="sbell-time">' + ago(n.created_at) + '</div></div></div>';
        }).join('');
        box.querySelectorAll('.sbell-item').forEach(function (el) {
            el.onclick = function () {
                var n = data[+el.dataset.i];
                fetch('api_notifications.php?action=mark_one&id=' + encodeURIComponent(n.id)).finally(function () {
                    if (n.link) location.href = n.link; else { n.is_read = 1; render(); load(); }
                });
            };
        });
    }
    function load() {
        return fetch('api_notifications.php?action=fetch').then(function (r) { return r.json(); }).then(function (d) {
            if (!d || !d.notifications) return;
            var u = parseInt(d.unread_count, 10) || 0, b = document.getElementById('sbellLink');
            if (window._sbellLast !== undefined && u > window._sbellLast) { b.classList.remove('ring'); void b.offsetWidth; b.classList.add('ring'); }
            window._sbellLast = u;
            data = d.notifications; setBadge(u);
            if (open) render();
        }).catch(function () {});
    }
    window.sbellToggle = function (e) { e.preventDefault(); open ? sbellClose() : sbellOpen(); };
    window.sbellOpen = function () {
        open = true; render();
        document.getElementById('sbellPanel').classList.add('open');
        document.getElementById('sbellBackdrop').classList.add('open');
        document.getElementById('sbellLink').classList.add('active');
        load();
    };
    window.sbellClose = function () {
        open = false;
        document.getElementById('sbellPanel').classList.remove('open');
        document.getElementById('sbellBackdrop').classList.remove('open');
        document.getElementById('sbellLink').classList.remove('active');
    };
    window.sbellMarkAll = function () {
        fetch('api_notifications.php?action=mark_read').finally(function () { data.forEach(function (n) { n.is_read = 1; }); setBadge(0); render(); });
    };
    // Vet pages (.main-content header): move the bell into the header, after its buttons / search box
    document.addEventListener('DOMContentLoaded', function () {
        var hdr = document.querySelector('.main-content > .d-flex:first-child'), fab = document.getElementById('sbellLink');
        if (!hdr || !fab || hdr.children.length < 2) return;
        var last = hdr.lastElementChild, wrap = document.createElement('div');
        wrap.className = 'd-flex align-items-center gap-2';
        hdr.replaceChild(wrap, last);
        wrap.appendChild(last);
        wrap.appendChild(fab);
        fab.classList.add('sbell-inline');
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && open) sbellClose(); });
    load();
    setInterval(load, 30000);
})();
</script>
