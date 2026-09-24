<?php

declare(strict_types=1);

namespace Ranau\YandexDelivery;

defined('ABSPATH') || exit;

final class FreeShippingPolicy
{
    public const DEFAULT_THRESHOLD = 5000.0;

    private string $method_id;

    public function __construct(string $method_id)
    {
        $this->method_id = $method_id;
    }

    public function threshold(?int $instance_id = null): float
    {
        $instance_id = $instance_id ?? $this->selected_instance_id();
        if ($instance_id <= 0) {
            $instance_id = $this->first_enabled_instance_id();
        }
        $settings = $instance_id > 0
            ? get_option('woocommerce_' . $this->method_id . '_' . $instance_id . '_settings', array())
            : array();
        $raw = is_array($settings) ? ($settings['free_shipping_threshold'] ?? self::DEFAULT_THRESHOLD) : self::DEFAULT_THRESHOLD;

        return max(0.0, (float) str_replace(',', '.', (string) $raw));
    }

    public function subtotal_before_discounts(array $package = array()): float
    {
        if (function_exists('WC') && WC()->cart && method_exists(WC()->cart, 'get_displayed_subtotal')) {
            return $this->round_amount((float) WC()->cart->get_displayed_subtotal());
        }

        $subtotal = 0.0;
        $include_tax = function_exists('wc_prices_include_tax') && wc_prices_include_tax();
        foreach ((array) ($package['contents'] ?? array()) as $item) {
            $subtotal += (float) ($item['line_subtotal'] ?? 0.0);
            if ($include_tax) {
                $subtotal += (float) ($item['line_subtotal_tax'] ?? 0.0);
            }
        }

        return $this->round_amount($subtotal);
    }

    public function is_eligible(array $package = array(), ?float $threshold = null): bool
    {
        $threshold = $threshold ?? $this->threshold();
        return $threshold > 0.0 && $this->subtotal_before_discounts($package) >= $this->round_amount($threshold);
    }

    private function selected_instance_id(): int
    {
        if (!function_exists('WC') || !WC()->session) {
            return 0;
        }

        foreach ((array) WC()->session->get('chosen_shipping_methods', array()) as $rate_id) {
            if (preg_match('/^' . preg_quote($this->method_id, '/') . ':(\d+)$/', (string) $rate_id, $matches)) {
                return (int) $matches[1];
            }
        }

        return 0;
    }

    private function first_enabled_instance_id(): int
    {
        if (!class_exists('WC_Shipping_Zones') || !class_exists('WC_Shipping_Zone')) {
            return 0;
        }

        $zones = (array) \WC_Shipping_Zones::get_zones();
        $zones[] = array('shipping_methods' => (new \WC_Shipping_Zone(0))->get_shipping_methods(true));
        foreach ($zones as $zone) {
            foreach ((array) ($zone['shipping_methods'] ?? array()) as $method) {
                if (
                    is_object($method)
                    && (string) ($method->id ?? '') === $this->method_id
                    && (string) ($method->enabled ?? 'yes') === 'yes'
                ) {
                    return (int) ($method->instance_id ?? 0);
                }
            }
        }

        return 0;
    }

    private function round_amount(float $amount): float
    {
        $decimals = function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2;
        return round($amount, $decimals);
    }
}
