<?php

declare(strict_types=1);

namespace Ranau\YandexDelivery;

defined('ABSPATH') || exit;

final class Settings
{
    public const OPTION = 'ranau_yandex_delivery_settings';

    public function init(): void
    {
        add_action('admin_menu', array($this, 'add_page'));
        add_action('admin_init', array($this, 'register'));
    }

    public function add_page(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Ranau Yandex Delivery', 'ranau-yandex-delivery-for-woocommerce'),
            __('Ranau Yandex Delivery', 'ranau-yandex-delivery-for-woocommerce'),
            'manage_woocommerce',
            'ranau-yandex-delivery',
            array($this, 'render')
        );
    }

    public function register(): void
    {
        register_setting('ranau_yandex_delivery', self::OPTION, array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize'),
            'default' => array(),
        ));
        add_settings_section(
            'ranau_yandex_delivery_api',
            __('Yandex Delivery API', 'ranau-yandex-delivery-for-woocommerce'),
            '__return_false',
            'ranau_yandex_delivery'
        );
        foreach ($this->fields() as $key => $field) {
            add_settings_field(
                $key,
                $field['label'],
                array($this, 'render_field'),
                'ranau_yandex_delivery',
                'ranau_yandex_delivery_api',
                array('key' => $key, 'type' => $field['type'])
            );
        }
    }

    /** @param mixed $value
     *  @return array<string, string>
     */
    public function sanitize($value): array
    {
        $value = is_array($value) ? $value : array();
        $clean = array();
        foreach ($this->fields() as $key => $field) {
            $raw = (string) ($value[$key] ?? '');
            $clean[$key] = $field['type'] === 'number'
                ? (string) (float) $raw
                : sanitize_text_field($raw);
        }

        return $clean;
    }

    /** @param array<string, string> $args */
    public function render_field(array $args): void
    {
        $key = sanitize_key((string) ($args['key'] ?? ''));
        $type = (string) ($args['type'] ?? 'text');
        $settings = self::all();
        printf(
            '<input class="regular-text" type="%1$s" step="any" name="%2$s[%3$s]" value="%4$s" autocomplete="off">',
            esc_attr($type),
            esc_attr(self::OPTION),
            esc_attr($key),
            esc_attr((string) ($settings[$key] ?? ''))
        );
    }

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        echo '<div class="wrap"><h1>' . esc_html__('Ranau Yandex Delivery', 'ranau-yandex-delivery-for-woocommerce') . '</h1>';
        echo '<p>' . esc_html__('Credentials stay in this WordPress installation and are sent only to Yandex API endpoints.', 'ranau-yandex-delivery-for-woocommerce') . '</p>';
        echo '<form method="post" action="options.php">';
        settings_fields('ranau_yandex_delivery');
        do_settings_sections('ranau_yandex_delivery');
        submit_button();
        echo '</form></div>';
    }

    /** @return array<string, string> */
    public static function all(): array
    {
        $settings = get_option(self::OPTION, array());

        return is_array($settings) ? array_map('strval', $settings) : array();
    }

    /** @return array<string, array{label:string,type:string}> */
    private function fields(): array
    {
        return array(
            'api_token' => array('label' => __('API token', 'ranau-yandex-delivery-for-woocommerce'), 'type' => 'password'),
            'geocoder_api_key' => array('label' => __('Geocoder API key', 'ranau-yandex-delivery-for-woocommerce'), 'type' => 'password'),
            'source_station_id' => array('label' => __('Pickup source station ID', 'ranau-yandex-delivery-for-woocommerce'), 'type' => 'text'),
            'warehouse_address' => array('label' => __('Warehouse address', 'ranau-yandex-delivery-for-woocommerce'), 'type' => 'text'),
            'warehouse_city' => array('label' => __('Warehouse city', 'ranau-yandex-delivery-for-woocommerce'), 'type' => 'text'),
            'warehouse_longitude' => array('label' => __('Warehouse longitude', 'ranau-yandex-delivery-for-woocommerce'), 'type' => 'number'),
            'warehouse_latitude' => array('label' => __('Warehouse latitude', 'ranau-yandex-delivery-for-woocommerce'), 'type' => 'number'),
        );
    }
}
