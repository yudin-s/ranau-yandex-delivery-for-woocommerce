<?php

declare(strict_types=1);

namespace Ranau\YandexDelivery;

defined('ABSPATH') || exit;

final class PlatformClient
{
    private const PRICING_URL = 'https://b2b-authproxy.taxi.yandex.net/api/b2b/platform/pricing-calculator';
    public function quote(string $destination_station_id, array $package): array
    {
        $token = $this->token();
        if ($token === '') {
            return $this->error('token_missing', __('Токен Яндекс Доставки не настроен.', 'ranau-yandex-delivery-for-woocommerce'));
        }
        $source_station_id = $this->source_station_id();
        if ($source_station_id === '') {
            return $this->error('source_station_missing', __('Укажите ID станции отправления в настройках Ranau Yandex Delivery.', 'ranau-yandex-delivery-for-woocommerce'));
        }

        if (empty($package['ok'])) {
            return $this->error(
                (string) ($package['code'] ?? 'package_invalid'),
                (string) ($package['message'] ?? __('Не удалось подготовить параметры посылки.', 'ranau-yandex-delivery-for-woocommerce'))
            );
        }

        $payload = array(
            'source' => array('platform_station_id' => $source_station_id),
            'destination' => array('platform_station_id' => $destination_station_id),
            'tariff' => 'self_pickup',
            'total_weight' => (int) $package['total_weight'],
            'total_assessed_price' => (int) $package['total_assessed_price'],
            'client_price' => 0,
            'payment_method' => 'already_paid',
            'places' => (array) $package['places'],
        );

        $response = wp_remote_post(self::PRICING_URL, array(
            'timeout' => 25,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'Accept-Language' => 'ru',
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ));

        if (is_wp_error($response)) {
            return $this->error('network_error', __('Яндекс Доставка сейчас не отвечает. Попробуйте еще раз.', 'ranau-yandex-delivery-for-woocommerce'));
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $json = json_decode((string) wp_remote_retrieve_body($response), true);
        $json = is_array($json) ? $json : array();

        if ($status < 200 || $status >= 300 || empty($json['pricing_total'])) {
            $code = sanitize_key((string) ($json['code'] ?? $json['error']['code'] ?? 'pricing_unavailable'));
            return $this->error($code, $this->public_error_message($code));
        }

        $price = $this->parse_price((string) $json['pricing_total']);
        if ($price === null) {
            return $this->error('pricing_invalid', __('Яндекс вернул стоимость в неизвестном формате.', 'ranau-yandex-delivery-for-woocommerce'));
        }

        return array(
            'ok' => true,
            'code' => '',
            'message' => '',
            'price' => $price,
            'days' => max(0, (int) ($json['delivery_days'] ?? 0)),
            'currency' => 'RUB',
        );
    }

    private function token(): string
    {
        $settings = Settings::all();
        return trim((string) ($settings['api_token'] ?? ''));
    }

    private function source_station_id(): string
    {
        $settings = Settings::all();
        return sanitize_text_field((string) ($settings['source_station_id'] ?? ''));
    }

    private function parse_price(string $value): ?float
    {
        if (!preg_match('/-?\d+(?:[.,]\d+)?/', str_replace(' ', '', $value), $match)) {
            return null;
        }

        $price = (float) str_replace(',', '.', $match[0]);
        return $price >= 0 ? $price : null;
    }

    private function public_error_message(string $code): string
    {
        if ($code === 'pickups_not_configured') {
            return __('Стоимость станет доступна после настройки вывоза со склада.', 'ranau-yandex-delivery-for-woocommerce');
        }

        return __('Не удалось рассчитать доставку в выбранный ПВЗ. Выберите другую точку или попробуйте позже.', 'ranau-yandex-delivery-for-woocommerce');
    }

    private function error(string $code, string $message): array
    {
        return array(
            'ok' => false,
            'code' => $code,
            'message' => $message,
            'price' => null,
            'days' => null,
            'currency' => 'RUB',
        );
    }
}
