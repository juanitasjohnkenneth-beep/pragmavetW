<?php
/**
 * "No refund" cancellation dialog for pet owners (appointments and boarding).
 * Usage from a form:   onsubmit="return pvNoRefundDialog(this, {kind:'boarding', what:'Max · Oct 12 – Oct 14', paid:500})"
 * The form is only submitted after the owner ticks "I understand" and confirms.
 */
?>
<div id="pvCancelDlg" class="pvc-bd" role="dialog" aria-modal="true" aria-labelledby="pvcTitle" aria-hidden="true">
    <div class="pvc-box">
        <div class="pvc-icon"><i class="fa fa-triangle-exclamation"></i></div>
        <h3 id="pvcTitle">Cancel this appointment?</h3>
        <p class="pvc-what" id="pvcWhat"></p>
        <div class="pvc-policy">
            <div class="pvc-policy-h"><i class="fa fa-ban"></i> No refund on cancellation</div>
            <div id="pvcPolicyText">Any reservation fee or deposit you have already paid is <b>non-refundable</b>.</div>
        </div>
        <label class="pvc-ack"><input type="checkbox" id="pvcAck"> <span>I understand that my payment will <b>not be refunded</b> if I cancel.</span></label>
        <div class="pvc-actions">
            <button type="button" class="pvc-keep" id="pvcKeep">Keep it</button>
            <button type="button" class="pvc-go" id="pvcGo" disabled><i class="fa fa-xmark me-1"></i> Yes, cancel</button>
        </div>
    </div>
</div>
<style>
    .pvc-bd { position:fixed; inset:0; background:rgba(15,23,42,.55); backdrop-filter:blur(2px); z-index:100000; display:none; align-items:center; justify-content:center; padding:16px; }
    .pvc-bd.open { display:flex; animation:pvcFade .15s ease; }
    @keyframes pvcFade { from { opacity:0; } to { opacity:1; } }
    .pvc-box { background:#fff; border-radius:22px; width:100%; max-width:440px; padding:26px 26px 22px; box-shadow:0 30px 70px rgba(15,23,42,.3); text-align:center; animation:pvcPop .2s ease; font-family:Inter, sans-serif; }
    @keyframes pvcPop { from { transform:translateY(10px) scale(.97); opacity:0; } to { transform:none; opacity:1; } }
    .pvc-icon { width:62px; height:62px; border-radius:50%; background:#fef2f2; color:#dc2626; display:inline-flex; align-items:center; justify-content:center; font-size:26px; margin-bottom:10px; box-shadow:0 0 0 8px #fff5f5; }
    .pvc-box h3 { font-size:20px; font-weight:800; color:#0f172a; margin:6px 0 4px; }
    .pvc-what { font-size:13.5px; color:#64748b; margin:0 0 14px; }
    .pvc-what:empty { display:none; }
    .pvc-policy { text-align:left; background:#fff7ed; border:1px solid #fed7aa; border-radius:14px; padding:12px 14px; font-size:13.5px; color:#7c2d12; line-height:1.5; }
    .pvc-policy-h { font-weight:800; color:#c2410c; margin-bottom:3px; display:flex; align-items:center; gap:7px; }
    .pvc-ack { display:flex; gap:10px; align-items:flex-start; text-align:left; margin:14px 2px 0; font-size:13.5px; color:#334155; cursor:pointer; }
    .pvc-ack input { width:18px; height:18px; margin-top:2px; accent-color:#dc2626; flex-shrink:0; cursor:pointer; }
    .pvc-actions { display:flex; gap:10px; margin-top:18px; }
    .pvc-actions button { flex:1; border-radius:999px; padding:11px 14px; font-weight:800; font-size:14px; border:none; cursor:pointer; background-image:none !important; box-shadow:none !important; transition:transform .12s, opacity .12s; }
    .pvc-keep { background:#f1f5f9 !important; color:#334155 !important; }
    .pvc-keep:hover { background:#e2e8f0 !important; }
    .pvc-go { background:#dc2626 !important; color:#fff !important; }
    .pvc-go:hover:not(:disabled) { transform:translateY(-1px); }
    .pvc-go:disabled { opacity:.45; cursor:not-allowed; }
</style>
<script>
(function () {
    var dlg = document.getElementById('pvCancelDlg'), ack = document.getElementById('pvcAck'), go = document.getElementById('pvcGo'), keep = document.getElementById('pvcKeep');
    var pendingForm = null, lastFocus = null;
    function peso(v) { return '₱' + Number(v).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function close() { dlg.classList.remove('open'); dlg.setAttribute('aria-hidden', 'true'); pendingForm = null; if (lastFocus) lastFocus.focus(); }

    window.pvNoRefundDialog = function (form, opts) {
        opts = opts || {};
        var boarding = opts.kind === 'boarding';
        var paid = parseFloat(opts.paid || 0);
        document.getElementById('pvcTitle').textContent = boarding ? 'Cancel this boarding reservation?' : 'Cancel this appointment?';
        document.getElementById('pvcWhat').textContent = opts.what || '';
        var fee = boarding ? 'reservation fee or deposit' : 'reservation fee';
        document.getElementById('pvcPolicyText').innerHTML = paid > 0
            ? 'You have paid <b>' + peso(paid) + '</b>. This ' + fee + ' is <b>non-refundable</b> — it will not be returned if you cancel.'
            : 'Any ' + fee + ' you have paid is <b>non-refundable</b> and will not be returned if you cancel.';
        ack.checked = false; go.disabled = true;
        pendingForm = form; lastFocus = document.activeElement;
        dlg.classList.add('open'); dlg.setAttribute('aria-hidden', 'false');
        setTimeout(function () { keep.focus(); }, 30);
        return false; // the form submits only after confirmation
    };
    ack.addEventListener('change', function () { go.disabled = !ack.checked; });
    keep.addEventListener('click', close);
    dlg.addEventListener('click', function (e) { if (e.target === dlg) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && dlg.classList.contains('open')) close(); });
    go.addEventListener('click', function () {
        if (!pendingForm || !ack.checked) return;
        var f = pendingForm;
        go.disabled = true; go.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i> Cancelling…';
        HTMLFormElement.prototype.submit.call(f);
    });
})();
</script>
