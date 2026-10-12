<?php
include("dbconnection.php");
$amount      = (float)($_GET['amount'] ?? 0);
$description = $_GET['description'] ?? 'Pet Boarding Payment';
$success_url = $_GET['success_url'] ?? 'billing.php';
$cancel_url  = $_GET['cancel_url']  ?? 'billing.php';
$invoice_id  = (int)($_GET['invoice_id'] ?? 0);
$pay_type    = $_GET['pay_type'] ?? '';

if(!$pay_type){
    $pay_type = (stripos($description,'downpayment')!==false || stripos($description,'30%')!==false)
              ? 'downpayment' : 'full';
}
$isDownpayment = ($pay_type === 'downpayment');
$payLabel      = $isDownpayment ? '30% Downpayment' : 'Full Payment';
$payColor      = $isDownpayment ? '#3b82f6' : '#22c55e';
$payGradient   = $isDownpayment ? 'linear-gradient(135deg,#1e40af,#3b82f6)' : 'linear-gradient(135deg,#15803d,#22c55e)';
$payIcon       = $isDownpayment ? 'fa-shield-halved' : 'fa-circle-check';
$amountFmt     = '&#8369;' . number_format($amount, 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Secure Payment | PragmaVet</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/pragmavet.css">
    <link href="assets/global-style.css" rel="stylesheet">
<script src="theme.js?v=<?=time()?>"></script>
<link rel="stylesheet" href="theme.css?v=<?=time()?>">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{
    background: linear-gradient(135deg,#0a2342 0%,#0d3b6e 60%,#0a2342 100%);
    min-height:100vh; font-family:'Inter',sans-serif;
    display:flex; flex-direction:column; align-items:center; justify-content:center; padding:20px;
}
.demo-banner{background:linear-gradient(90deg,#f59e0b,#f97316);color:white;text-align:center;font-size:12px;font-weight:700;letter-spacing:.5px;padding:6px 24px;border-radius:20px;margin-bottom:18px;text-transform:uppercase;box-shadow:0 4px 16px rgba(249,115,22,.4);}
.type-pill{display:inline-flex;align-items:center;gap:7px;padding:6px 18px;border-radius:20px;font-size:12px;font-weight:700;color:#fff;margin-bottom:14px;background:<?=$payGradient?>;box-shadow:0 4px 14px rgba(0,0,0,.3);}
.payment-card{background:#fff;border-radius:24px;width:100%;max-width:430px;box-shadow:0 30px 80px rgba(0,0,0,.5);overflow:hidden;}
.pay-header{background:<?=$payGradient?>;padding:28px 28px 22px;text-align:center;}
.pay-header .lock-icon{background:rgba(255,255,255,.2);border-radius:50%;width:52px;height:52px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px;font-size:22px;color:#fff;}
.pay-header .pay-label{color:rgba(255,255,255,.85);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;}
.amount-display{color:#fff;font-size:46px;font-weight:900;letter-spacing:-2px;line-height:1;}
.amount-display .currency{font-size:24px;font-weight:600;vertical-align:super;margin-right:2px;}
.pay-desc{color:rgba(255,255,255,.75);font-size:12px;margin-top:8px;padding:0 8px;line-height:1.5;}
.merchant-row{padding:14px 22px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;gap:12px;}
.merchant-avatar{width:38px;height:38px;border-radius:10px;flex-shrink:0;background:linear-gradient(135deg,#2563eb,#3b82f6);display:flex;align-items:center;justify-content:center;color:#fff;font-size:14px;font-weight:900;}
.merchant-name{font-weight:700;font-size:13px;color:#0f172a;}
.merchant-sub{font-size:11px;color:#64748b;}
.secure-badge{margin-left:auto;font-size:10px;color:#16a34a;font-weight:700;background:#dcfce7;border-radius:20px;padding:3px 10px;flex-shrink:0;}
.pay-summary{margin:16px 22px 0;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:14px;overflow:hidden;}
.pay-summary-row{display:flex;justify-content:space-between;align-items:center;padding:10px 16px;font-size:13px;}
.pay-summary-row+.pay-summary-row{border-top:1px solid #e2e8f0;}
.pay-summary-row .label{color:#64748b;font-weight:500;}
.pay-summary-row .value{font-weight:700;color:#0f172a;}
.pay-summary-row.highlight .value{color:<?=$payColor?>;font-size:15px;}
.nonref-badge{background:#fee2e2;color:#991b1b;font-size:9px;font-weight:700;padding:2px 7px;border-radius:10px;margin-left:6px;}
.method-tabs{display:flex;border-bottom:2px solid #f1f5f9;padding:0 14px;gap:4px;margin-top:16px;}
.method-tab{flex:1;padding:11px 6px;text-align:center;cursor:pointer;border-radius:10px 10px 0 0;font-size:11px;font-weight:700;color:#94a3b8;transition:all .2s;border-bottom:3px solid transparent;user-select:none;}
.method-tab.active{color:<?=$payColor?>;border-bottom-color:<?=$payColor?>;}
.method-tab i{display:block;font-size:20px;margin-bottom:4px;}
.pay-body{padding:18px 22px 22px;}
.pay-panel{display:none;}
.pay-panel.active{display:block;}
.gcash-logo-wrap{display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:14px;}
.gcash-logo-text{font-size:26px;font-weight:900;background:linear-gradient(135deg,#0078FF,#00C7FF);-webkit-background-clip:text;-webkit-text-fill-color:transparent;}
.phone-group{display:flex;align-items:center;background:#f8faff;border:1.5px solid #c7d9f5;border-radius:14px;overflow:hidden;margin-bottom:11px;}
.phone-prefix{padding:13px 16px;font-weight:700;color:#0078FF;background:#eff6ff;border-right:1.5px solid #c7d9f5;font-size:14px;}
.phone-input{flex:1;border:none;background:transparent;padding:13px 16px;font-size:16px;font-weight:600;outline:none;letter-spacing:1px;color:#0f172a;}
.phone-input::placeholder{color:#94a3b8;font-weight:400;font-size:14px;letter-spacing:0;}
.otp-hint{background:#eff6ff;border-radius:10px;padding:9px 13px;font-size:11px;color:#3b82f6;display:flex;align-items:center;gap:8px;margin-bottom:14px;}
.card-brands{display:flex;gap:6px;margin-bottom:13px;}
.card-brand{background:#f1f5f9;border-radius:6px;padding:4px 10px;font-size:11px;font-weight:700;color:#475569;}
.card-brand.visa{color:#1a1f71;}.card-brand.mc{color:#eb001b;}
.card-field{background:#f8faff;border:1.5px solid #c7d9f5;border-radius:12px;padding:12px 16px;margin-bottom:10px;font-size:14px;color:#94a3b8;font-weight:400;}
.card-row{display:flex;gap:10px;}
.card-row .card-field{flex:1;}
.maya-header{text-align:center;margin-bottom:14px;}
.maya-logo-text{font-size:28px;font-weight:900;background:linear-gradient(135deg,#00c389,#00a86b);-webkit-background-clip:text;-webkit-text-fill-color:transparent;}
.maya-sub{font-size:12px;color:#64748b;}
.btn-pay{width:100%;padding:16px;border-radius:14px;border:none;font-size:15px;font-weight:800;color:#fff;cursor:pointer;transition:all .2s;letter-spacing:.3px;margin-top:4px;position:relative;overflow:hidden;}
.btn-pay-gcash{background:linear-gradient(135deg,#0078FF,#00C7FF);box-shadow:0 8px 24px rgba(0,120,255,.35);}
.btn-pay-card{background:linear-gradient(135deg,#2563eb,#3b82f6);box-shadow:0 8px 24px rgba(37,99,235,.35);}
.btn-pay-maya{background:linear-gradient(135deg,#00c389,#00a86b);box-shadow:0 8px 24px rgba(0,195,137,.35);}
.btn-pay:hover{transform:translateY(-2px);filter:brightness(1.07);}
.btn-pay:active{transform:translateY(0);}
.spinner{width:18px;height:18px;border:3px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .8s linear infinite;display:none;vertical-align:middle;margin-right:8px;}
.loading .spinner{display:inline-block;}
.loading .btn-text{opacity:.7;}
@keyframes spin{to{transform:rotate(360deg);}}
.cancel-link{display:block;text-align:center;margin-top:14px;color:#94a3b8;font-size:12px;text-decoration:none;transition:color .2s;}
.cancel-link:hover{color:#ef4444;}
.pay-footer{text-align:center;padding:0 22px 18px;font-size:11px;color:#94a3b8;display:flex;align-items:center;justify-content:center;gap:6px;}
.processing-overlay{display:none;position:fixed;inset:0;background:rgba(10,35,66,.9);backdrop-filter:blur(10px);z-index:9999;align-items:center;justify-content:center;flex-direction:column;gap:18px;}
.processing-overlay.show{display:flex;}
.process-spinner{width:68px;height:68px;border:5px solid rgba(255,255,255,.2);border-top-color:<?=$payColor?>;border-radius:50%;animation:spin .9s linear infinite;}
.process-text{color:#fff;font-size:18px;font-weight:700;}
.process-sub{color:rgba(255,255,255,.6);font-size:13px;}
.success-check{display:none;width:76px;height:76px;background:<?=$payGradient?>;border-radius:50%;align-items:center;justify-content:center;font-size:34px;color:#fff;}
.success-check.show{display:flex;animation:popIn .4s cubic-bezier(.175,.885,.32,1.275);}
@keyframes popIn{from{transform:scale(0);opacity:0;}to{transform:scale(1);opacity:1;}}
</style>
</head>
<body>
<div class="demo-banner"><i class="fa fa-flask me-1"></i> Demo Mode â€” No real money charged</div>
<div class="type-pill"><i class="fa <?=$payIcon?>"></i> Pet Boarding â€” <?=$payLabel?></div>
<div class="payment-card">
<div class="pay-header"><div class="lock-icon"><i class="fa fa-lock"></i></div><div class="pay-label"><?=$isDownpayment?'Reservation Downpayment (Non-Refundable)':'Full Payment Settlement'?></div><div class="amount-display"><span class="currency">&#8369;</span><?=number_format($amount,2)?></div><div class="pay-desc"><?=htmlspecialchars($description)?></div></div>
<div class="merchant-row"><div class="merchant-avatar">PV</div><div><div class="merchant-name">PragmaVet Clinic</div><div class="merchant-sub">Pet Boarding Services</div></div><span class="secure-badge"><i class="fa fa-shield-halved me-1"></i>Verified</span></div>
<div class="pay-summary"><div class="pay-summary-row"><span class="label"><i class="fa fa-hotel me-1"></i>Service</span><span class="value">Pet Boarding</span></div><div class="pay-summary-row"><span class="label"><i class="fa fa-tag me-1"></i>Payment Type</span><span class="value"><?=$payLabel?><?php if($isDownpayment):?><span class="nonref-badge">Non-Refundable</span><?php endif;?></span></div><div class="pay-summary-row highlight"><span class="label"><i class="fa fa-peso-sign me-1"></i>Amount Due</span><span class="value"><?=$amountFmt?></span></div></div>
<div class="method-tabs"><div class="method-tab active" onclick="switchTab('gcash',this)"><i class="fa fa-mobile-screen-button" style="color:#0078FF;"></i>GCash</div><div class="method-tab" onclick="switchTab('card',this)"><i class="fa fa-credit-card" style="color:#2563eb;"></i>Card</div><div class="method-tab" onclick="switchTab('maya',this)"><i class="fa fa-wallet" style="color:#00c389;"></i>Maya</div></div>
<div class="pay-body">
<div class="pay-panel active" id="panel-gcash"><div class="gcash-logo-wrap"><span class="gcash-logo-text">G</span><span style="font-size:22px;font-weight:900;color:#0078FF;">GCash</span></div><div class="phone-group"><div class="phone-prefix">+63</div><input class="phone-input" type="tel" maxlength="10" placeholder="9XX XXX XXXX" value="917 000 0000"></div><div class="otp-hint"><i class="fa fa-info-circle"></i>A 6-digit OTP will be sent to your registered GCash number.</div><button class="btn-pay btn-pay-gcash" onclick="processPayment(this)"><div class="spinner"></div><span class="btn-text"><i class="fa fa-mobile-screen-button me-2"></i>Pay <?=$amountFmt?> via GCash</span></button></div>
<div class="pay-panel" id="panel-card"><div class="card-brands"><span class="card-brand visa">VISA</span><span class="card-brand mc">â—â— Mastercard</span><span class="card-brand">JCB</span><span class="card-brand">AMEX</span></div><div class="card-field">Card number</div><div class="card-row"><div class="card-field">MM / YY</div><div class="card-field">CVV</div></div><div class="card-field">Cardholder name</div><button class="btn-pay btn-pay-card" onclick="processPayment(this)"><div class="spinner"></div><span class="btn-text"><i class="fa fa-lock me-2"></i>Pay <?=$amountFmt?> Securely</span></button></div>
<div class="pay-panel" id="panel-maya"><div class="maya-header"><div class="maya-logo-text">maya</div><div class="maya-sub">Pay using your Maya wallet or card</div></div><div class="phone-group"><div class="phone-prefix">+63</div><input class="phone-input" type="tel" maxlength="10" placeholder="9XX XXX XXXX" value="917 000 0000"></div><div class="otp-hint" style="background:#f0fdf9;color:#059669;"><i class="fa fa-info-circle"></i>You'll receive a push notification in your Maya app.</div><button class="btn-pay btn-pay-maya" onclick="processPayment(this)"><div class="spinner"></div><span class="btn-text"><i class="fa fa-wallet me-2"></i>Pay <?=$amountFmt?> via Maya</span></button></div>
<a href="<?=htmlspecialchars($cancel_url)?>" class="cancel-link" onclick="return confirm('Cancel payment and go back?')"><i class="fa fa-arrow-left me-1"></i> Cancel & go back</a>
</div>
<div class="pay-footer"><i class="fa fa-shield-halved text-success"></i>256-bit SSL &bull; Powered by PayMongo &bull; PCI-DSS Compliant</div>
</div>
<div class="processing-overlay" id="overlay"><div class="process-spinner" id="spinner"></div><div class="success-check" id="successCheck"><i class="fa fa-check"></i></div><div class="process-text" id="processText">Processing payment...</div><div class="process-sub" id="processSub">Please do not close this window</div></div>
<script>
var successUrl = <?=json_encode($success_url)?>;
var invoiceId  = <?=(int)$invoice_id?>;
var payType    = <?=json_encode($pay_type)?>;
function switchTab(name, el){document.querySelectorAll('.method-tab').forEach(t=>t.classList.remove('active'));document.querySelectorAll('.pay-panel').forEach(p=>p.classList.remove('active'));el.classList.add('active');document.getElementById('panel-'+name).classList.add('active');}
function processPayment(btn){btn.classList.add('loading');document.getElementById('overlay').classList.add('show');setTimeout(()=>{document.getElementById('processText').textContent='Verifying payment...';document.getElementById('processSub').textContent='Connecting to payment gateway';},800);if(invoiceId>0){var fd=new FormData();fd.append('invoice_id',invoiceId);fd.append('pay_type',payType);fetch('process_demo_payment.php',{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()).then(data=>{console.log('[PragmaVet Pay] DB updated:',data.message||data);}).catch(err=>{console.warn('[PragmaVet Pay] Direct update failed â€” redirect handler will handle it:',err);});}setTimeout(()=>{document.getElementById('processText').textContent='Payment confirmed!';document.getElementById('processSub').textContent='Updating your reservation...';document.getElementById('spinner').style.display='none';document.getElementById('successCheck').classList.add('show');},2200);setTimeout(()=>{window.location.href=successUrl;},3400);}
</script>
<script src="mousetrail.js"></script>
</body>
</html>