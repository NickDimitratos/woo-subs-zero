<?php
// Run with WP-CLI against a WordPress installation containing this plugin.
if (!function_exists('switch_to_locale') || !class_exists('WC_Email')) {
    throw new RuntimeException('WordPress and WooCommerce must be loaded.');
}
WC()->mailer()->get_emails();
$cases = array(
    'nl_NL' => array('Renew Now' => 'Nu verlengen', 'Payment' => 'Betaling', 'Contract number' => 'Contractnummer', 'Your renewal is ready' => 'Uw verlenging staat klaar'),
    'en_US' => array('Renew Now' => 'Renew Now', 'Payment' => 'Payment', 'Contract number' => 'Contract number', 'Your renewal is ready' => 'Your renewal is ready'),
);
foreach ($cases as $locale => $translations) {
    switch_to_locale($locale);
    try {
        foreach ($translations as $source => $expected) {
            if (__($source, 'woo-subzero') !== $expected) {
                throw new RuntimeException('Unexpected translation for ' . $source . ' in ' . $locale);
            }
        }
        $email = new WSZ_Manual_Renewal_Email();
        if ($email->get_default_heading() !== $translations['Your renewal is ready']) {
            throw new RuntimeException('Incorrect default email heading in ' . $locale);
        }
    } finally {
        restore_previous_locale();
    }
}
echo "PASS: renewal email text follows Dutch and English locales.\n";
