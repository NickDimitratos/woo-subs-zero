<?php

defined('ABSPATH') || exit;

/** HTML and text formats for manual subscription renewals. */
final class WSZ_Renewal_Email_Template
{
    private static function text(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function details(WC_Order $subscription, WC_Order $order, bool $preview): array
    {
        if ($preview) {
            return array(__('Payment', 'woo-subzero') => '2 / 6', __('Remaining after this payment', 'woo-subzero') => '4', __('Contract number', 'woo-subzero') => '12345678');
        }
        $details = array();
        $number = (int) $order->get_meta('_wsz_manual_payment_number', true);
        $total = (int) $order->get_meta('_wsz_manual_payment_total', true);
        if ($number > 0 && $total >= $number) {
            $details[__('Payment', 'woo-subzero')] = $number . ' / ' . $total;
            $details[__('Remaining after this payment', 'woo-subzero')] = (string) ($total - $number);
        }
        $reference = $subscription->get_meta('_wsz_contract_reference', true);
        if (!is_scalar($reference) || '' === trim((string) $reference)) {
            $references = array();
            foreach ($subscription->get_items('line_item') as $item) {
                $value = $item->get_meta('_wsz_contract_reference', true);
                if (is_scalar($value) && '' !== trim((string) $value)) {
                    $references[] = trim((string) $value);
                }
            }
            $reference = implode(', ', array_values(array_unique($references)));
        }
        $reference = apply_filters('wsz_subs_renewal_contract_reference', $reference, $subscription, $order);
        if (is_scalar($reference) && '' !== trim((string) $reference)) {
            $details[__('Contract number', 'woo-subzero')] = trim((string) $reference);
        }
        return $details;
    }

    public static function html(WC_Order $subscription, WC_Order $order, string $heading, string $additional = '', ?string $body = null, bool $preview = false): string
    {
        $url = function_exists('esc_url') ? esc_url($order->get_checkout_payment_url()) : self::text((string) filter_var($order->get_checkout_payment_url(), FILTER_SANITIZE_URL));
        $amount = html_entity_decode(wp_strip_all_tags($order->get_formatted_order_total()), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $name = is_callable(array($order, 'get_billing_first_name')) ? trim((string) $order->get_billing_first_name()) : '';
        $greeting = '' !== $name ? sprintf(__('Hello %s,', 'woo-subzero'), $name) : __('Hello,', 'woo-subzero');
        $body = null === $body ? __('Your subscription renewal is ready for payment.', 'woo-subzero') : $body;
        $additional_html = '' !== trim($additional) ? '<tr><td style="padding:0 32px 24px;font-size:14px;line-height:22px;color:#6b7280;">' . nl2br(self::text($additional)) . '</td></tr>' : '';
        $details_html = '';
        foreach (self::details($subscription, $order, $preview) as $label => $value) {
            $details_html .= '<tr><td style="padding:12px 14px;border:1px solid #e5e7eb;border-right:0;border-bottom:0;color:#6b7280;font-size:14px;">' . self::text($label) . '</td><td style="padding:12px 14px;border:1px solid #e5e7eb;border-bottom:0;font-size:14px;">' . self::text($value) . '</td></tr>';
        }
        return '<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body style="margin:0;padding:0;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:0;padding:24px;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;"><tr><td align="center">'
            . '<table role="presentation" width="640" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;">'
            . '<tr><td style="padding:26px 32px;background:#111827;"><span style="display:block;margin:0 0 8px;font-size:12px;line-height:16px;letter-spacing:1px;text-transform:uppercase;color:#9ca3af;">' . self::text(__('Subscription renewal', 'woo-subzero')) . '</span>'
            . '<span style="display:block;font-size:24px;line-height:30px;font-weight:bold;color:#ffffff;">' . self::text($heading) . '</span></td></tr>'
            . '<tr><td style="padding:24px 32px 10px;font-size:15px;line-height:23px;color:#2b3441;">' . self::text($greeting) . '<br>' . nl2br(self::text($body)) . '</td></tr>'
            . '<tr><td style="padding:10px 32px 0;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:separate;border-spacing:0;">'
            . '<tr><td colspan="2" style="padding:0 0 8px;font-size:15px;line-height:20px;font-weight:bold;color:#111827;">' . self::text(__('Renewal details', 'woo-subzero')) . '</td></tr>'
            . '<tr><td style="width:34%;padding:12px 14px;border:1px solid #e5e7eb;border-right:0;border-bottom:0;border-radius:10px 0 0 0;color:#6b7280;font-size:14px;">' . self::text(__('Subscription', 'woo-subzero')) . '</td><td style="padding:12px 14px;border:1px solid #e5e7eb;border-bottom:0;border-radius:0 10px 0 0;font-size:14px;">#' . (int) $subscription->get_id() . '</td></tr>'
            . $details_html
            . '<tr><td style="padding:12px 14px;border:1px solid #e5e7eb;border-right:0;border-radius:0 0 0 10px;color:#6b7280;font-size:14px;">' . self::text(__('Amount due', 'woo-subzero')) . '</td><td style="padding:12px 14px;border:1px solid #e5e7eb;border-radius:0 0 10px 0;font-size:14px;font-weight:bold;">' . self::text($amount) . '</td></tr>'
            . '</table></td></tr><tr><td style="padding:24px 32px 26px;"><a href="' . $url . '" style="display:inline-block;padding:14px 24px;border-radius:8px;background:#111827;color:#ffffff;font-size:15px;font-weight:bold;line-height:20px;text-decoration:none;">' . self::text(__('Renew Now', 'woo-subzero')) . '</a></td></tr>'
            . $additional_html
            . '<tr><td style="padding:16px 32px;background:#f9fafb;border-top:1px solid #e5e7eb;font-size:12px;line-height:18px;color:#6b7280;">' . self::text(__('You received this email because a subscription renewal is awaiting payment.', 'woo-subzero')) . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    public static function plain(WC_Order $subscription, WC_Order $order, string $heading, string $additional = '', ?string $body = null, bool $preview = false): string
    {
        $amount = html_entity_decode(wp_strip_all_tags($order->get_formatted_order_total()), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $body = null === $body ? __('Your subscription renewal is ready for payment.', 'woo-subzero') : $body;
        $details = '';
        foreach (self::details($subscription, $order, $preview) as $label => $value) {
            $details .= "\n" . $label . ': ' . $value;
        }
        return $heading . "\n\n" . $body . "\n\n" . sprintf(__('Subscription #%1$d — amount due: %2$s', 'woo-subzero'), $subscription->get_id(), $amount) . $details
            . "\n\n" . __('Renew Now:', 'woo-subzero') . ' ' . $order->get_checkout_payment_url() . ('' !== trim($additional) ? "\n\n" . $additional : '');
    }
}
