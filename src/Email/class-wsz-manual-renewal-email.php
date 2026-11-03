<?php

defined('ABSPATH') || exit;

/** Configurable customer notification for manual renewal and its reminder. */
class WSZ_Manual_Renewal_Email extends WC_Email
{
    private ?WC_Order $subscription = null;
    private bool $reminder;

    public function __construct(bool $reminder = false)
    {
        $this->reminder = $reminder;
        $this->id = $reminder ? 'wsz_manual_renewal_reminder' : 'wsz_manual_renewal';
        $this->title = $reminder ? __('WSZ renewal payment reminder', 'woo-subzero') : __('WSZ subscription renewal payment', 'woo-subzero');
        $this->description = $reminder
            ? __('Sent once when a manual renewal remains unpaid after the configured number of days.', 'woo-subzero')
            : __('Sent to the customer when a manual subscription renewal is ready for payment.', 'woo-subzero');
        $this->customer_email = true;
        parent::__construct();
    }

    public function get_default_subject()
    {
        return $this->reminder ? __('Reminder: your subscription renewal is awaiting payment', 'woo-subzero') : __('Your subscription renewal is ready to pay', 'woo-subzero');
    }

    public function get_default_heading()
    {
        return $this->reminder ? __('Your renewal is still awaiting payment', 'woo-subzero') : __('Your renewal is ready', 'woo-subzero');
    }

    public function get_default_body(): string
    {
        return $this->reminder ? __('This is a reminder that your subscription renewal is still awaiting payment.', 'woo-subzero') : __('Your subscription renewal is ready for payment.', 'woo-subzero');
    }

    public function init_form_fields()
    {
        parent::init_form_fields();
        $this->form_fields['body_text'] = array(
            'title' => __('Main message', 'woo-subzero'), 'type' => 'textarea',
            'description' => __('Plain text shown above renewal details. Available placeholders: {customer_name}, {subscription_id}, {amount}.', 'woo-subzero'),
            'default' => '', 'placeholder' => $this->get_default_body(), 'desc_tip' => true,
        );
        if ($this->reminder) {
            $this->form_fields['reminder_days'] = array(
                'title' => __('Days before reminder', 'woo-subzero'), 'type' => 'text', 'default' => '15',
                'description' => __('Days after the renewal order is created. Allowed: 1–365. Existing scheduled reminders retain their original date.', 'woo-subzero'),
                'custom_attributes' => array('inputmode' => 'numeric', 'pattern' => '[0-9]*'), 'desc_tip' => true,
            );
        }
    }

    public function trigger(WC_Order $subscription, WC_Order $order): bool
    {
        $this->setup_locale();
        try {
            $this->subscription = $subscription;
            $this->object = $order;
            $this->recipient = $order->get_billing_email();
            return $this->is_enabled() && '' !== $this->get_recipient()
                && $this->send($this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments());
        } finally {
            $this->restore_locale();
        }
    }

    private function body_text(): string
    {
        $text = (string) $this->get_option('body_text', $this->get_default_body());
        $name = trim((string) $this->object->get_billing_first_name());
        $amount = html_entity_decode(wp_strip_all_tags($this->object->get_formatted_order_total()), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $subscription = $this->subscription instanceof WC_Order ? $this->subscription : $this->object;
        return strtr($text, array('{customer_name}' => $name, '{subscription_id}' => (string) $subscription->get_id(), '{amount}' => $amount));
    }

    public function get_content_html()
    {
        if (!($this->object instanceof WC_Order)) {
            return '';
        }
        $subscription = $this->subscription instanceof WC_Order ? $this->subscription : $this->object;
        return WSZ_Renewal_Email_Template::html($subscription, $this->object, $this->get_heading(), (string) $this->get_additional_content(), $this->body_text(), !($this->subscription instanceof WC_Order));
    }

    public function get_content_plain()
    {
        if (!($this->object instanceof WC_Order)) {
            return '';
        }
        $subscription = $this->subscription instanceof WC_Order ? $this->subscription : $this->object;
        return WSZ_Renewal_Email_Template::plain($subscription, $this->object, $this->get_heading(), (string) $this->get_additional_content(), $this->body_text(), !($this->subscription instanceof WC_Order));
    }
}

/** Separate class keeps WooCommerce preview and settings routing distinct. */
final class WSZ_Manual_Renewal_Reminder_Email extends WSZ_Manual_Renewal_Email
{
    public function __construct()
    {
        parent::__construct(true);
    }
}
