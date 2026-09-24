<?php

declare(strict_types=1);

namespace Ranau\YandexDelivery;

defined('ABSPATH') || exit;

final class ExpressClient
{
    private const OFFERS_URL = 'https://b2b.taxi.yandex.net/b2b/cargo/integration/v2/offers/calculate';
    private const GEOCODE_URL = 'https://geocode-maps.yandex.ru/1.x/';
    public function destination_may_be_supported(array $destination): bool
    {
        $country = strtoupper(trim((string) ($destination['country'] ?? 'RU')));
        if ($country !== '' && $country !== 'RU') {
            return false;
        }

        $city = sanitize_text_field((string) ($destination['city'] ?? ''));
        $state = sanitize_text_field((string) ($destination['state'] ?? ''));
        $query = trim(implode(', ', array_filter(array('Россия', $state, $city))));
        if ($city === '') {
            return false;
        }

        if ($this->is_moscow_region_text($state . ' ' . $city)) {
            return true;
        }

        $cache_key = 'ranau_yandex_courier_region_' . md5($query);
        $cached = get_transient($cache_key);
        if ($cached === 'yes' || $cached === 'no') {
            return $cached === 'yes';
        }

        $point = $this->geocode($query);
        $supported = !empty($point['ok']) && !empty($point['moscow_region']);
        set_transient($cache_key, $supported ? 'yes' : 'no', DAY_IN_SECONDS);

        return $supported;
    }

    public function quote(string $city, string $address, array $package): array
    {
        $token = $this->delivery_token();
        if ($token === '') {
            return $this->error('token_missing', __('Токен Яндекс Доставки не настроен.', 'ranau-yandex-delivery-for-woocommerce'));
        }
        $warehouse = $this->warehouse();
        if (empty($warehouse['ok'])) {
            return $this->error('warehouse_missing', __('Заполните адрес и координаты склада в настройках Ranau Yandex Delivery.', 'ranau-yandex-delivery-for-woocommerce'));
        }
        if (empty($package['ok']) || empty($package['express_items'])) {
            return $this->error(
                (string) ($package['code'] ?? 'package_invalid'),
                (string) ($package['message'] ?? __('Не удалось подготовить параметры посылки.', 'ranau-yandex-delivery-for-woocommerce'))
            );
        }

        $city = sanitize_text_field($city);
        $address = sanitize_text_field($address);
        if ($city === '' || $address === '') {
            return $this->error('address_required', __('Укажите город, улицу и номер дома.', 'ranau-yandex-delivery-for-woocommerce'));
        }

        $full_address = $this->full_destination_address($city, $address);
        $destination = $this->geocode($full_address);
        if (empty($destination['ok'])) {
            return $this->error('geocode_not_found', __('Яндекс не смог найти этот адрес. Укажите улицу и номер дома точнее.', 'ranau-yandex-delivery-for-woocommerce'));
        }
        if (empty($destination['moscow_region'])) {
            return $this->error('region_unavailable', __('Курьерская доставка Яндекса доступна только для поддерживаемых адресов Москвы и Московской области.', 'ranau-yandex-delivery-for-woocommerce'));
        }

        $payload = array(
            'items' => array_values((array) $package['express_items']),
            'route_points' => array(
                array(
                    'id' => 1,
                    'coordinates' => array($warehouse['lon'], $warehouse['lat']),
                    'fullname' => $warehouse['address'],
                    'country' => 'Россия',
                    'city' => $warehouse['city'],
                ),
                array(
                    'id' => 2,
                    'coordinates' => array((float) $destination['lon'], (float) $destination['lat']),
                    'fullname' => (string) $destination['formatted'],
                    'country' => 'Россия',
                    'city' => (string) ($destination['locality'] ?: $city),
                ),
            ),
            'requirements' => array(
                'taxi_classes' => array('courier', 'express'),
                'pro_courier' => false,
                'skip_door_to_door' => false,
            ),
        );

        $response = wp_remote_post(self::OFFERS_URL, array(
            'timeout' => 30,
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
        if ($status < 200 || $status >= 300) {
            $raw_code = (string) ($json['code'] ?? $json['error']['code'] ?? 'offers_unavailable');
            $code = sanitize_key(str_replace(array('.', '-'), '_', $raw_code));
            return $this->error($code, $this->public_error_message($code));
        }

        $offers = array();
        foreach ((array) ($json['offers'] ?? array()) as $index => $raw_offer) {
            if (!is_array($raw_offer)) {
                continue;
            }
            $taxi_class = sanitize_key((string) ($raw_offer['taxi_class'] ?? ''));
            if (!in_array($taxi_class, array('courier', 'express'), true)) {
                continue;
            }
            $price = $this->parse_price((string) ($raw_offer['price']['total_price_with_vat'] ?? $raw_offer['price']['total_price'] ?? ''));
            if ($price === null) {
                continue;
            }

            $offer = array(
                'id' => hash('sha256', $taxi_class . '|' . $price . '|' . (string) ($raw_offer['delivery_interval']['to'] ?? '') . '|' . $index),
                'taxi_class' => $taxi_class,
                'title' => $taxi_class === 'courier' ? __('Курьер', 'ranau-yandex-delivery-for-woocommerce') : __('Экспресс', 'ranau-yandex-delivery-for-woocommerce'),
                'description' => sanitize_key((string) ($raw_offer['description'] ?? '')),
                'price' => $price,
                'currency' => sanitize_key((string) ($raw_offer['price']['currency'] ?? 'RUB')),
                'pickup_from' => sanitize_text_field((string) ($raw_offer['pickup_interval']['from'] ?? '')),
                'pickup_to' => sanitize_text_field((string) ($raw_offer['pickup_interval']['to'] ?? '')),
                'delivery_from' => sanitize_text_field((string) ($raw_offer['delivery_interval']['from'] ?? '')),
                'delivery_to' => sanitize_text_field((string) ($raw_offer['delivery_interval']['to'] ?? '')),
                'expires_at' => sanitize_text_field((string) ($raw_offer['offer_ttl'] ?? '')),
            );
            $offers[] = $offer;
        }

        if (!$offers) {
            return $this->error('suitable_offer_not_found', $this->public_error_message('suitable_offer_not_found'), array(
                'address' => (string) $destination['formatted'],
                'city' => (string) ($destination['locality'] ?: $city),
            ));
        }

        usort($offers, static fn(array $left, array $right): int => ($left['price'] <=> $right['price']));

        return array(
            'ok' => true,
            'code' => '',
            'message' => '',
            'address' => (string) $destination['formatted'],
            'city' => (string) ($destination['locality'] ?: $city),
            'region' => (string) ($destination['region'] ?? ''),
            'postcode' => (string) ($destination['postcode'] ?? ''),
            'country' => 'RU',
            'position' => array((float) $destination['lon'], (float) $destination['lat']),
            'offers' => $offers,
        );
    }

    public function offer_is_fresh(array $offer): bool
    {
        $expires_at = trim((string) ($offer['expires_at'] ?? ''));
        if ($expires_at === '') {
            return true;
        }
        $timestamp = strtotime($expires_at);
        return $timestamp !== false && $timestamp > time() + 15;
    }

    private function geocode(string $address): array
    {
        $api_key = $this->geocode_token();
        if ($api_key === '') {
            return array('ok' => false, 'code' => 'geocode_token_missing');
        }

        $url = add_query_arg(array(
            'apikey' => $api_key,
            'geocode' => $address,
            'format' => 'json',
            'lang' => 'ru_RU',
            'results' => 1,
        ), self::GEOCODE_URL);
        $response = wp_remote_get($url, array('timeout' => 20));
        if (is_wp_error($response)) {
            return array('ok' => false, 'code' => 'geocode_network_error');
        }

        $json = json_decode((string) wp_remote_retrieve_body($response), true);
        $geo_object = $json['response']['GeoObjectCollection']['featureMember'][0]['GeoObject'] ?? null;
        if (!is_array($geo_object)) {
            return array('ok' => false, 'code' => 'geocode_not_found');
        }

        $position = preg_split('/\s+/', trim((string) ($geo_object['Point']['pos'] ?? '')));
        if (!is_array($position) || count($position) !== 2) {
            return array('ok' => false, 'code' => 'geocode_not_found');
        }

        $meta = (array) ($geo_object['metaDataProperty']['GeocoderMetaData'] ?? array());
        $formatted = sanitize_text_field((string) ($meta['text'] ?? $address));
        $locality = '';
        $region = '';
        $postcode = sanitize_text_field((string) ($meta['Address']['postal_code'] ?? ''));
        $region_text = $formatted;
        foreach ((array) ($meta['Address']['Components'] ?? array()) as $component) {
            if (!is_array($component)) {
                continue;
            }
            $name = sanitize_text_field((string) ($component['name'] ?? ''));
            $kind = sanitize_key((string) ($component['kind'] ?? ''));
            $region_text .= ' ' . $name;
            if (in_array($kind, array('locality', 'province', 'area'), true) && $locality === '') {
                $locality = $name;
            }
            if ($kind === 'locality') {
                $locality = $name;
            }
            if ($kind === 'province') {
                // Yandex returns components from broad to specific. Keeping the
                // last province gives WooCommerce the actual subject (Москва or
                // Московская область), not the federal district.
                $region = $name;
            }
        }

        return array(
            'ok' => true,
            'lon' => (float) $position[0],
            'lat' => (float) $position[1],
            'formatted' => $formatted,
            'locality' => $locality,
            'region' => $region,
            'postcode' => $postcode,
            'moscow_region' => $this->is_moscow_region_text($region_text),
        );
    }

    private function full_destination_address(string $city, string $address): string
    {
        $normalized_address = function_exists('mb_strtolower') ? mb_strtolower($address) : strtolower($address);
        $normalized_city = function_exists('mb_strtolower') ? mb_strtolower($city) : strtolower($city);
        if ($normalized_city !== '' && strpos($normalized_address, $normalized_city) !== false) {
            return 'Россия, ' . $address;
        }
        return 'Россия, ' . $city . ', ' . $address;
    }

    private function is_moscow_region_text(string $value): bool
    {
        return (bool) preg_match('/(?:^|[\s,])москва(?:$|[\s,])|московск(?:ая|ой|ую|ое|ом)\s+област/ui', $value);
    }

    private function delivery_token(): string
    {
        $settings = Settings::all();
        return trim((string) ($settings['api_token'] ?? ''));
    }

    private function geocode_token(): string
    {
        $settings = Settings::all();
        return trim((string) ($settings['geocoder_api_key'] ?? ''));
    }

    /** @return array<string, mixed> */
    private function warehouse(): array
    {
        $settings = Settings::all();
        $address = sanitize_text_field((string) ($settings['warehouse_address'] ?? ''));
        $city = sanitize_text_field((string) ($settings['warehouse_city'] ?? ''));
        $lon = (float) ($settings['warehouse_longitude'] ?? 0);
        $lat = (float) ($settings['warehouse_latitude'] ?? 0);

        return array(
            'ok' => $address !== '' && $city !== '' && $lon >= -180 && $lon <= 180 && $lat >= -90 && $lat <= 90 && ($lon !== 0.0 || $lat !== 0.0),
            'address' => $address,
            'city' => $city,
            'lon' => $lon,
            'lat' => $lat,
        );
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
        if (in_array($code, array('suitable_offer_not_found', 'errors_suitable_offer_not_found'), true)) {
            return __('Сейчас нет доступных тарифов «Курьер» или «Экспресс». Склад работает ежедневно с 09:00 до 19:00 по Москве. Повторите расчёт в это время — доступность зависит от адреса и наличия курьеров. Или выберите доставку в ПВЗ.', 'ranau-yandex-delivery-for-woocommerce');
        }
        return __('Не удалось рассчитать курьерскую доставку Яндекса. Проверьте адрес или попробуйте позже.', 'ranau-yandex-delivery-for-woocommerce');
    }

    private function error(string $code, string $message, array $extra = array()): array
    {
        return array_merge(array(
            'ok' => false,
            'code' => sanitize_key($code),
            'message' => $message,
            'address' => '',
            'city' => '',
            'position' => array(),
            'offers' => array(),
        ), $extra);
    }
}
