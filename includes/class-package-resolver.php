<?php

declare(strict_types=1);

namespace Ranau\YandexDelivery;

defined('ABSPATH') || exit;

final class PackageResolver
{
    public function resolve_cart(): array
    {
        if (!function_exists('WC') || !WC()->cart) {
            return $this->error('cart_unavailable', __('Не удалось получить состав корзины.', 'ranau-yandex-delivery-for-woocommerce'));
        }

        $weight_grams = 0;
        $length_cm = 0;
        $width_cm = 0;
        $height_cm = 0;
        $express_items = array();

        foreach ((array) WC()->cart->get_cart() as $item) {
            $product = $item['data'] ?? null;
            if (!$product || !is_object($product)) {
                continue;
            }

            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $weight = $this->weight_kg($product);
            $length = $this->dimension_cm($product, 'get_length');
            $width = $this->dimension_cm($product, 'get_width');
            $height = $this->dimension_cm($product, 'get_height');

            if ($weight <= 0 || $length <= 0 || $width <= 0 || $height <= 0) {
                return $this->error(
                    'package_dimensions_missing',
                    __('Для расчета Яндекс Доставки у одного из товаров не заполнены вес или габариты.', 'ranau-yandex-delivery-for-woocommerce')
                );
            }

            $weight_grams += (int) round($weight * 1000) * $quantity;
            $length_cm = max($length_cm, (int) ceil($length));
            $width_cm = max($width_cm, (int) ceil($width));
            $height_cm += (int) ceil($height) * $quantity;
            $express_items[] = array(
                'size' => array(
                    'length' => round($length / 100, 4),
                    'width' => round($width / 100, 4),
                    'height' => round($height / 100, 4),
                ),
                'weight' => round($weight, 3),
                'quantity' => $quantity,
                'pickup_point' => 1,
                'dropoff_point' => 2,
                'age_restricted' => false,
            );
        }

        if ($weight_grams <= 0 || $length_cm <= 0 || $width_cm <= 0 || $height_cm <= 0) {
            return $this->error('package_empty', __('Корзина не содержит товаров для доставки.', 'ranau-yandex-delivery-for-woocommerce'));
        }

        $dimensions = array($length_cm, $width_cm, $height_cm);
        rsort($dimensions, SORT_NUMERIC);

        return array(
            'ok' => true,
            'total_weight' => $weight_grams,
            'total_assessed_price' => (int) round((float) WC()->cart->get_cart_contents_total() * 100),
            'express_items' => $express_items,
            'places' => array(
                array(
                    'physical_dims' => array(
                        'weight_gross' => $weight_grams,
                        'dx' => (int) $dimensions[0],
                        'dy' => (int) $dimensions[1],
                        'dz' => (int) $dimensions[2],
                    ),
                ),
            ),
        );
    }

    private function weight_kg(object $product): float
    {
        $raw = method_exists($product, 'get_weight') ? (float) $product->get_weight() : 0.0;
        if ($raw <= 0) {
            return 0.0;
        }

        return function_exists('wc_get_weight') ? (float) wc_get_weight($raw, 'kg') : $raw;
    }

    private function dimension_cm(object $product, string $getter): float
    {
        $raw = method_exists($product, $getter) ? (float) $product->{$getter}() : 0.0;
        if ($raw <= 0) {
            return 0.0;
        }

        return function_exists('wc_get_dimension') ? (float) wc_get_dimension($raw, 'cm') : $raw;
    }

    private function error(string $code, string $message): array
    {
        return array('ok' => false, 'code' => $code, 'message' => $message);
    }
}
