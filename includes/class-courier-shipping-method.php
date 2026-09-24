<?php

declare(strict_types=1);

namespace Ranau\YandexDelivery;

defined('ABSPATH') || exit;

final class CourierShippingMethod extends \WC_Shipping_Method
{
    public function __construct($instance_id = 0)
    {
        $this->id = 'ranau_yandex_courier';
        $this->instance_id = absint($instance_id);
        $this->method_title = __('Яндекс Курьер', 'ranau-yandex-delivery-for-woocommerce');
        $this->method_description = __('Курьер и Экспресс Яндекс Доставки с живым расчетом до двери.', 'ranau-yandex-delivery-for-woocommerce');
        $this->supports = array('shipping-zones', 'instance-settings');
        $this->init();
    }

    public function init(): void
    {
        $this->instance_form_fields = array(
            'title' => array(
                'title' => __('Название', 'ranau-yandex-delivery-for-woocommerce'),
                'type' => 'text',
                'default' => __('Яндекс Курьер', 'ranau-yandex-delivery-for-woocommerce'),
            ),
        );
        $this->title = (string) $this->get_option('title', __('Яндекс Курьер', 'ranau-yandex-delivery-for-woocommerce'));
        add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
    }

    public function calculate_shipping($package = array()): void
    {
        $destination = $this->canonical_destination((array) $package);
        if (!(new ExpressClient())->destination_may_be_supported($destination)) {
            return;
        }

        $selection = $this->session_array('ranau_yandex_courier_selection');
        $ready = !empty($selection['id'])
            && !empty($selection['address'])
            && isset($selection['price'])
            && (new ExpressClient())->offer_is_fresh($selection);

        $this->add_rate(array(
            'id' => $this->get_rate_id(),
            'label' => $this->title,
            'cost' => $ready ? (float) $selection['price'] : 0.0,
            'package' => $package,
            'meta_data' => array(
                'ranau_yandex_courier_ready' => $ready ? 'yes' : 'no',
                'ranau_yandex_courier_class' => (string) ($selection['taxi_class'] ?? ''),
                'ranau_yandex_courier_address' => (string) ($selection['address'] ?? ''),
            ),
        ));
    }

    private function canonical_destination(array $package): array
    {
        $destination = is_array($package['destination'] ?? null) ? $package['destination'] : array();
        if (!function_exists('WC') || !WC()->customer) {
            return $destination;
        }

        $destination['city'] = sanitize_text_field((string) WC()->customer->get_shipping_city());
        $destination['country'] = strtoupper(sanitize_text_field((string) (WC()->customer->get_shipping_country() ?: 'RU')));
        $destination['state'] = sanitize_text_field((string) WC()->customer->get_shipping_state());
        $destination['postcode'] = sanitize_text_field((string) WC()->customer->get_shipping_postcode());

        return $destination;
    }

    private function session_array(string $key): array
    {
        if (!function_exists('WC') || !WC()->session) {
            return array();
        }
        $value = WC()->session->get($key, array());
        return is_array($value) ? $value : array();
    }
}
