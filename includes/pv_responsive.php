<?php
// Mobile / tablet layer: rules apply only below 992px wide, so desktop is unchanged. Printed once per page.
// The stylesheet is mobile-only (media) and the script async, so neither delays desktop page loading.
if (empty($GLOBALS["pv_responsive_loaded"])) { $GLOBALS["pv_responsive_loaded"] = true; ?>
<style>.pv-nav-btn, .pv-nav-backdrop { display: none; }</style>
<link rel="stylesheet" href="assets/pv-responsive.css?v=7" media="(max-width: 991.98px)">
<script src="assets/pv-responsive.js?v=7" async></script>
<?php }
