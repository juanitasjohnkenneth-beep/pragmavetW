<?php
/**
 * Health & vaccination certificate (HTML for Dompdf — tables only, Dompdf ignores grid/flex).
 * $d: certificate_requests row joined with pet + owner fields (pet_name, species, breed, gender, age, weight, date_of_birth,
 *     fullname, email, contact_number, address, purpose, pet_id)
 * $o: issued (Y-m-d), valid_until (Y-m-d), last_exam (Y-m-d), history (latest consultation row or null), vet_name, req_id
 */
function pv_certificate_html($conn, array $d, array $o) {
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $fd = fn($v) => $v ? date('F j, Y', strtotime($v)) : '—';
    $age = $d['age'] ?? null;
    if (!empty($d['date_of_birth']) && $d['date_of_birth'] !== '0000-00-00') {
        $diff = (new DateTime($d['date_of_birth']))->diff(new DateTime($o['issued']));
        $age = $diff->y > 0 ? $diff->y . ' yr' . ($diff->y > 1 ? 's' : '') . ($diff->m ? ' ' . $diff->m . ' mo' : '') : $diff->m . ' month' . ($diff->m === 1 ? '' : 's');
    } elseif ($age) $age .= ' yr' . ((int)$age > 1 ? 's' : '');
    $cert_no = 'PV-HC-' . date('Y', strtotime($o['issued'])) . '-' . str_pad((int)$o['req_id'], 4, '0', STR_PAD_LEFT);

    // Clinic-verified vaccinations (most recent first)
    $vax = [];
    $vq = $conn->query("SELECT vaccine_name, date_given, next_due, batch_number FROM vaccination_records
                        WHERE pet_id = " . (int)$d['pet_id'] . " AND COALESCE(administered_by, '') <> '' AND date_given <= '" . $conn->real_escape_string($o['issued']) . "'
                        ORDER BY date_given DESC, id DESC LIMIT 6");
    if ($vq) while ($v = $vq->fetch_assoc()) $vax[] = $v;
    $vax_rows = '';
    foreach ($vax as $i => $v) {
        $due = $v['next_due'] ? $fd($v['next_due']) : '—';
        $vax_rows .= '<tr' . ($i % 2 ? ' class="alt"' : '') . '><td><b>' . $e($v['vaccine_name']) . '</b></td><td>' . $fd($v['date_given']) . '</td><td class="due">' . $due . '</td><td>' . $e($v['batch_number'] ?: '—') . '</td></tr>';
    }
    if (!$vax_rows) $vax_rows = '<tr><td colspan="4" class="none">No clinic-verified vaccination on record.</td></tr>';

    $h = $o['history'] ?? null;
    $findings = $h
        ? '<table class="kv"><tr><td class="k">Consultation date</td><td>' . $fd($h['appointment_date']) . '</td></tr>'
          . '<tr><td class="k">Assessment / diagnosis</td><td>' . nl2br($e($h['diagnosis'] ?: '—')) . '</td></tr>'
          . '<tr><td class="k">Treatment given</td><td>' . nl2br($e($h['treatment'] ?: '—')) . '</td></tr></table>'
        : '<p class="none" style="margin:0;">No consultation notes on file for this pet.</p>';

    $row = fn($k, $v) => '<tr><td class="k">' . $k . '</td><td>' . $v . '</td></tr>';
    $purpose = trim((string)($d['purpose'] ?? ''));

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
        @page { margin: 0; }
        body { margin: 0; font-family: "DejaVu Sans", Arial, sans-serif; color: #0f172a; font-size: 10.5px; }
        .frame { position: fixed; top: 22px; left: 22px; right: 22px; bottom: 22px; border: 3px solid #1e40af; }
        .frame-in { position: fixed; top: 29px; left: 29px; right: 29px; bottom: 29px; border: 1px solid #93c5fd; }
        .wm { position: fixed; top: 560px; left: 0; right: 0; text-align: center; font-size: 96px; font-weight: bold; color: #f3f6ff; letter-spacing: 8px; }
        .page { position: relative; padding: 48px 56px 40px; }
        table { border-collapse: collapse; width: 100%; }
        .hd td { vertical-align: middle; }
        .logo { width: 62px; height: 42px; padding-top: 20px; border-radius: 31px; background: #1e40af; color: #fff; text-align: center; font-size: 21px; font-weight: bold; line-height: 1; }
        .cl { font-size: 19px; font-weight: bold; color: #1e3a8a; letter-spacing: 1.5px; }
        .cs { font-size: 9.5px; color: #475569; margin-top: 2px; }
        .no { border: 1px solid #bfdbfe; background: #eff6ff; border-radius: 6px; padding: 7px 10px; text-align: right; font-size: 9px; color: #475569; }
        .no b { display: block; font-size: 11px; color: #1e3a8a; }
        .rule { border-top: 2px solid #1e40af; border-bottom: 1px solid #93c5fd; height: 2px; margin: 14px 0 16px; }
        .title { text-align: center; }
        .title .t1 { font-size: 26px; font-weight: bold; color: #1e3a8a; letter-spacing: 3px; }
        .title .t2 { font-size: 12px; font-weight: bold; color: #2563eb; letter-spacing: 5px; margin-top: 2px; }
        .certify { text-align: center; font-size: 10.5px; color: #334155; line-height: 1.6; margin: 12px 30px 6px; }
        .purpose { text-align: center; margin-bottom: 12px; }
        .purpose span { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; border-radius: 10px; padding: 3px 12px; font-size: 9.5px; font-weight: bold; }
        .sec { font-size: 9.5px; font-weight: bold; color: #fff; background: #1e40af; padding: 5px 10px; letter-spacing: 1px; }
        .box { border: 1px solid #cbd5e1; }
        .kv td { padding: 5px 10px; border-bottom: 1px solid #eef2f7; vertical-align: top; }
        .kv tr:last-child td { border-bottom: 0; }
        .kv .k { width: 38%; color: #64748b; font-weight: bold; font-size: 9.5px; }
        .vx th { background: #eff6ff; color: #1e3a8a; font-size: 9px; text-align: left; padding: 5px 10px; border-bottom: 1px solid #bfdbfe; }
        .vx td { padding: 5px 10px; border-bottom: 1px solid #eef2f7; }
        .vx tr.alt td { background: #f8fafc; }
        .vx .due { color: #b91c1c; font-weight: bold; }
        .none { color: #94a3b8; font-style: italic; text-align: center; padding: 8px; }
        .valid td { padding: 8px 10px; text-align: center; font-size: 10px; }
        .valid .v1 { background: #1e40af; color: #fff; font-weight: bold; }
        .valid .v2 { background: #eff6ff; color: #1e3a8a; border: 1px solid #bfdbfe; }
        .sig td { vertical-align: bottom; text-align: center; }
        .line { border-top: 1px solid #0f172a; padding-top: 5px; font-weight: bold; font-size: 10.5px; }
        .small { font-size: 8.5px; color: #64748b; font-weight: normal; }
        .seal { width: 92px; height: 70px; padding-top: 22px; border-radius: 46px; border: 2px dashed #93c5fd; color: #93c5fd; font-size: 8px; font-weight: bold; text-align: center; line-height: 1.3; margin: 0 auto; }
        .foot { position: fixed; left: 56px; right: 56px; bottom: 44px; text-align: center; font-size: 8.5px; color: #64748b; line-height: 1.5; border-top: 1px solid #dbeafe; padding-top: 8px; }
    </style></head><body>
    <div class="frame"></div><div class="frame-in"></div><div class="wm">PRAGMAVET</div>
    <div class="page">
        <table class="hd"><tr>
            <td style="width:74px;"><div class="logo">PV</div></td>
            <td><div class="cl">PRAGMAVET ANIMAL CLINIC</div>
                <div class="cs">Complete Veterinary Medical &amp; Boarding Care</div>
                <div class="cs">0234 Unit B Palico St, Palico 4, Imus, Cavite &nbsp;·&nbsp; 046-8851-221 &nbsp;·&nbsp; 0954-285-3053 &nbsp;·&nbsp; pragmavet@gmail.com</div></td>
            <td style="width:150px;"><div class="no">Certificate No.<b>' . $cert_no . '</b>Issued ' . $fd($o['issued']) . '</div></td>
        </tr></table>
        <div class="rule"></div>

        <div class="title"><div class="t1">CERTIFICATE OF HEALTH</div><div class="t2">AND VACCINATION</div></div>
        <div class="certify">' . (!empty($o['last_exam'])
            ? 'This is to certify that the animal described below was examined by a licensed veterinarian of PragmaVet Animal Clinic on <b>' . $fd($o['last_exam']) . '</b>. Its vaccination record and the findings of its most recent consultation are stated in this certificate.'
            : 'This is to certify that the animal described below is a registered patient of PragmaVet Animal Clinic. Its vaccination record and the findings on file are stated in this certificate.') . '</div>
        ' . ($purpose !== '' ? '<div class="purpose"><span>Issued for: ' . $e($purpose) . '</span></div>' : '<div style="height:8px;"></div>') . '

        <table><tr>
            <td style="width:50%;padding-right:7px;vertical-align:top;"><div class="box"><div class="sec">PATIENT INFORMATION</div><table class="kv">'
                . $row('Name', '<b>' . $e($d['pet_name']) . '</b>') . $row('Species', $e($d['species'] ?: '—')) . $row('Breed', $e($d['breed'] ?: '—'))
                . $row('Sex', $e($d['gender'] ?: '—')) . $row('Age', $e($age ?: '—')) . $row('Weight', $d['weight'] ? $e($d['weight']) . ' kg' : '—') . '</table></div></td>
            <td style="width:50%;padding-left:7px;vertical-align:top;"><div class="box"><div class="sec">OWNER INFORMATION</div><table class="kv">'
                . $row('Name', '<b>' . $e($d['fullname']) . '</b>') . $row('Contact', $e($d['contact_number'] ?: '—')) . $row('Email', $e($d['email']))
                . $row('Address', $e($d['address'] ?: '—')) . '</table></div></td>
        </tr></table>

        <div class="box" style="margin-top:12px;"><div class="sec">IMMUNIZATION RECORD</div>
            <table class="vx"><tr><th>Vaccine</th><th>Date given</th><th>Next due</th><th>Batch no.</th></tr>' . $vax_rows . '</table></div>

        <div class="box" style="margin-top:12px;"><div class="sec">CLINICAL FINDINGS</div><div style="padding:2px 0;">' . $findings . '</div></div>

        <table class="valid" style="margin-top:12px;"><tr>
            <td class="v1" style="width:34%;">VALID FOR 30 DAYS</td>
            <td class="v2">Issued ' . $fd($o['issued']) . ' &nbsp;·&nbsp; Valid until <b>' . $fd($o['valid_until']) . '</b></td>
        </tr></table>

        <table class="sig" style="margin-top:56px;"><tr>
            <td style="width:42%;"><div style="height:34px;"></div><div class="line">' . $e($o['vet_name'] ?: 'Licensed Veterinarian') . '<br><span class="small">Attending Licensed Veterinarian &nbsp;·&nbsp; PRC License No. ____________</span></div></td>
            <td style="width:16%;"></td>
            <td style="width:42%;"><div class="seal">PRAGMAVET<br>ANIMAL CLINIC<br>OFFICIAL SEAL</div></td>
        </tr></table>

        <div class="foot">This certificate was generated by the PragmaVet clinic system from the patient\'s medical record and is valid only with the veterinarian\'s signature and clinic seal.<br>
            To verify, call the clinic at 046-8851-221 and quote Certificate No. ' . $cert_no . '.</div>
    </div></body></html>';
}

/** Render a certificate to a PDF file (overwrites $path). */
function pv_certificate_pdf($conn, array $d, array $o, $path) {
    $opt = new \Dompdf\Options();
    $opt->set('defaultFont', 'DejaVu Sans');
    $opt->set('isHtml5ParserEnabled', true);
    $pdf = new \Dompdf\Dompdf($opt);
    $pdf->loadHtml(pv_certificate_html($conn, $d, $o), 'UTF-8');
    $pdf->setPaper('A4', 'portrait');
    $pdf->render();
    return file_put_contents($path, $pdf->output()) !== false;
}
