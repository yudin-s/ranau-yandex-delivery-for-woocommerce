<?php

declare(strict_types=1);

namespace Ranau\YandexDelivery;

defined('ABSPATH') || exit;

final class ShippingMethod extends \WC_Shipping_Method
{
    public function __construct($instance_id = 0)
    {
        $this->id = 'ranau_yandex_pickup';
        $this->instance_id = absint($instance_id);
        $this->method_title = __('Яндекс Доставка в ПВЗ', 'ranau-yandex-delivery-for-woocommerce');
        $this->method_description = __('Выбор ПВЗ Яндекса с живым расчетом стоимости.', 'ranau-yandex-delivery-for-woocommerce');
        $this->supports = array('shipping-zones', 'instance-settings');
        $this->init();
    }

    public function init(): void
    {
        $this->instance_form_fields = array(
            'title' => array(
                'title' => __('Название', 'ranau-yandex-delivery-for-woocommerce'),
                'type' => 'text',
                'default' => __('Яндекс Доставка в ПВЗ', 'ranau-yandex-delivery-for-woocommerce'),
            ),
            'free_shipping_threshold' => array(
                'title' => __('Бесплатная доставка от', 'ranau-yandex-delivery-for-woocommerce'),
                'type' => 'price',
                'default' => (string) FreeShippingPolicy::DEFAULT_THRESHOLD,
                'description' => __('Порог считается по сумме товаров до применения купонов и других скидок. Укажите 0, чтобы отключить.', 'ranau-yandex-delivery-for-woocommerce'),
                'desc_tip' => true,
            ),
        );
        $this->title = (string) $this->get_option('title', __('Яндекс Доставка в ПВЗ', 'ranau-yandex-delivery-for-woocommerce'));
        add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
    }

    public function calculate_shipping($package = array()): void
    {
        $point = $this->session_array('ranau_yandex_pickup_point');
        $quote = $this->session_array('ranau_yandex_pickup_quote');
        $policy = new FreeShippingPolicy($this->id);
        $threshold = $policy->threshold($this->instance_id);
        $subtotal = $policy->subtotal_before_discounts((array) $package);
        $free_shipping = $policy->is_eligible((array) $package, $threshold);
        $quoted = !empty($quote['ok']) && isset($quote['price']);
        $ready = !empty($point['id']) && ($free_shipping || $quoted);
        $label = $this->title;

        if ($ready && !empty($quote['days'])) {
            /* translators: %d: maximum delivery time in days. */
            $label .= sprintf(__(' (%d дн.)', 'ranau-yandex-delivery-for-woocommerce'), (int) $quote['days']);
        }

        $this->add_rate(array(
            'id' => $this->get_rate_id(),
            'label' => $label,
            'cost' => $ready && !$free_shipping ? (float) $quote['price'] : 0.0,
            'package' => $package,
            'meta_data' => array(
                'ranau_yandex_pickup_ready' => $ready ? 'yes' : 'no',
                'ranau_yandex_pickup_free_shipping' => $free_shipping ? 'yes' : 'no',
                'ranau_yandex_pickup_free_shipping_threshold' => $threshold,
                'ranau_yandex_pickup_subtotal_before_discounts' => $subtotal,
                'ranau_yandex_pickup_point_id' => (string) ($point['id'] ?? ''),
                'ranau_yandex_pickup_error' => (string) ($quote['code'] ?? ''),
            ),
        ));
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
