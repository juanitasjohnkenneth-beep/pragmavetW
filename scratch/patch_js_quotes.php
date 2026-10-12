<?php
$file = 'c:/xampp/htdocs/pragmavet/appointments.php';
$content = file_get_contents($file);

// Replace addslashes with json_encode for JS variables
$old_js = '    const S = {
        step: 1,
        clientName: "<?=addslashes($user_name ?? \'Client\')?>",
        clientPhone: "<?=addslashes($myAddr ? $myAddr : \'Registered Client\')?>",
        pets: [],
        multi: false,
        svcId: 1,
        svcName: "Consultation",
        vetId: "",
        date: "",
        time: "",
        timeLabel: "",
        loc: "Clinic Visit",
        addr: "<?=addslashes($myAddr ?? \'\')?>",
        notes: ""
    };';

$new_js = '    const S = {
        step: 1,
        clientName: <?=json_encode($user_name ?? \'Client\')?>,
        clientPhone: <?=json_encode($myAddr ? $myAddr : \'Registered Client\')?>,
        pets: [],
        multi: false,
        svcId: 1,
        svcName: "Consultation",
        vetId: "",
        date: "",
        time: "",
        timeLabel: "",
        loc: "Clinic Visit",
        addr: <?=json_encode($myAddr ?? \'\')?>,
        notes: ""
    };';

$content = str_replace($old_js, $new_js, $content);
file_put_contents($file, $content);
echo "Fixed JS string injection.";
?>
