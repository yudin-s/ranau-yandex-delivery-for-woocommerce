<?php

declare(strict_types=1);

namespace Ranau\YandexDelivery;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use DomainException;
use Ranau\YandexDelivery\Internal\DeliveryState\ProviderStateStore;

defined('ABSPATH') || exit;

final class Plugin
{
    private const METHOD_ID = 'ranau_yandex_pickup';
    private const COURIER_METHOD_ID = 'ranau_yandex_courier';
    private const STORE_API_NAMESPACE = 'ranau-yandex-delivery-for-woocommerce';
    private const COURIER_STORE_API_NAMESPACE = 'ranau-yandex-courier';
    private const SESSION_POINT = 'ranau_yandex_pickup_point';
    private const SESSION_QUOTE = 'ranau_yandex_pickup_quote';
    private const SESSION_COURIER_QUOTE = 'ranau_yandex_courier_quote';
    private const SESSION_COURIER_SELECTION = 'ranau_yandex_courier_selection';

    private static ?Plugin $instance = null;
    private bool $store_api_data_registered = false;
    private bool $store_api_update_registered = false;

    public static function instance(): Plugin
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function init(): void
    {
        add_action('woocommerce_shipping_init', array($this, 'load_shipping_method'));
        add_filter('woocommerce_shipping_methods', array($this, 'register_shipping_method'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('woocommerce_after_shipping_rate', array($this, 'render_classic_selector'), 10, 2);
        add_action('woocommerce_after_checkout_validation', array($this, 'validate_classic_checkout'), 15, 2);
        add_action('woocommerce_checkout_create_order', array($this, 'save_classic_order'), 15, 2);

        add_action('woocommerce_blocks_loaded', array($this, 'register_store_api_data'));
        add_action('woocommerce_blocks_loaded', array($this, 'register_store_api_update'));
        add_action('woocommerce_store_api_checkout_update_order_from_request', array($this, 'validate_blocks_checkout'), 15, 2);
        add_action('woocommerce_store_api_checkout_update_order_from_request', array($this, 'save_blocks_order'), 20, 2);

        if (function_exists('woocommerce_store_api_register_endpoint_data')) {
            $this->register_store_api_data();
        }
        if (function_exists('woocommerce_store_api_register_update_callback')) {
            $this->register_store_api_update();
        }
    }

    public function load_shipping_method(): void
    {
        require_once RANAU_YANDEX_DELIVERY_DIR . 'includes/class-shipping-method.php';
        require_once RANAU_YANDEX_DELIVERY_DIR . 'includes/class-courier-shipping-method.php';
    }

    public function register_shipping_method(array $methods): array
    {
        $methods[self::METHOD_ID] = ShippingMethod::class;
        $methods[self::COURIER_METHOD_ID] = CourierShippingMethod::class;
        return $methods;
    }

    public function register_rest_routes(): void
    {
        register_rest_route('ranau-yandex-delivery-for-woocommerce/v1', '/selection', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array($this, 'update_selection'),
            'permission_callback' => array($this, 'check_nonce'),
        ));
        register_rest_route('ranau-yandex-delivery-for-woocommerce/v1', '/courier/quote', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array($this, 'calculate_courier_quote'),
            'permission_callback' => array($this, 'check_nonce'),
        ));
        register_rest_route('ranau-yandex-delivery-for-woocommerce/v1', '/courier/selection', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array($this, 'update_courier_selection'),
            'permission_callback' => array($this, 'check_nonce'),
        ));
        register_rest_route('ranau-yandex-delivery-for-woocommerce/v1', '/commit', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array($this, 'commit_rest_selection'),
            'permission_callback' => array($this, 'check_nonce'),
        ));
    }

    public function check_nonce(\WP_REST_Request $request): bool
    {
        $nonce = (string) $request->get_header('x_wp_nonce');

        return $nonce !== '' && wp_verify_nonce($nonce, 'wp_rest');
    }

    public function update_selection(\WP_REST_Request $request): \WP_REST_Response
    {
        $point = $this->sanitize_point($this->normalize_array($request->get_json_params()));
        $quote = $this->store_selection($point);
        $commit = $point ? $this->begin_provider_state(self::METHOD_ID, $point, $quote) : array();

        return rest_ensure_response(array(
            'point' => $point,
            'quote' => $quote,
            'commit' => $commit,
        ));
    }

    public function calculate_courier_quote(\WP_REST_Request $request): \WP_REST_Response
    {
        $this->ensure_cart_loaded();
        $data = $this->normalize_array($request->get_json_params());
        $city = sanitize_text_field((string) ($data['city'] ?? ''));
        $address = sanitize_text_field((string) ($data['address'] ?? ''));
        $quote = (new ExpressClient())->quote($city, $address, (new PackageResolver())->resolve_cart());

        if (function_exists('WC') && WC()->session) {
            WC()->session->set(self::SESSION_COURIER_QUOTE, $quote);
            WC()->session->set(self::SESSION_COURIER_SELECTION, array());
            $this->clear_shipping_cache();
            $this->persist_session();
        }

        return rest_ensure_response($quote);
    }

    public function update_courier_selection(\WP_REST_Request $request): \WP_REST_Response
    {
        $this->ensure_cart_loaded();
        $data = $this->normalize_array($request->get_json_params());
        if (!empty($data['clear_quote'])) {
            $selection = $this->clear_courier_quote();
            $commit = array();
        } else {
            $selection = $this->select_courier_offer(sanitize_text_field((string) ($data['quote_id'] ?? '')));
            $commit = $selection ? $this->begin_provider_state(self::COURIER_METHOD_ID, $selection, $selection) : array();
        }

        return rest_ensure_response(array(
            'selection' => $selection,
            'quote' => $this->session_array(self::SESSION_COURIER_QUOTE),
            'commit' => $commit,
        ));
    }

    public function commit_rest_selection(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $committed = $this->commit_provider_state($this->normalize_array($request->get_json_params()));
        } catch (DomainException $exception) {
            return new \WP_REST_Response(array(
                'code' => sanitize_key($exception->getMessage()),
                'message' => __('Выбор доставки устарел. Рассчитайте доставку ещё раз.', 'ranau-yandex-delivery-for-woocommerce'),
            ), 409);
        }

        return rest_ensure_response(array('committed' => $committed));
    }

    public function enqueue_assets(): void
    {
        if ((!function_exists('is_checkout') || !is_checkout()) && (!function_exists('is_cart') || !is_cart())) {
            return;
        }

        wp_enqueue_style(
            'ranau-yandex-delivery-for-woocommerce',
            RANAU_YANDEX_DELIVERY_URL . 'assets/css/pickup-widget.css',
            array(),
            $this->asset_version('assets/css/pickup-widget.css')
        );
        wp_enqueue_script(
            'ranau-yandex-delivery-runtime',
            RANAU_YANDEX_DELIVERY_URL . 'assets/js/delivery-runtime.js',
            array(),
            $this->asset_version('assets/js/delivery-runtime.js'),
            true
        );
        wp_enqueue_script(
            'ranau-yandex-delivery-adapter',
            RANAU_YANDEX_DELIVERY_URL . 'assets/js/delivery-adapter.js',
            array('ranau-yandex-delivery-runtime'),
            $this->asset_version('assets/js/delivery-adapter.js'),
            true
        );
        wp_enqueue_script(
            'ranau-yandex-delivery-for-woocommerce',
            RANAU_YANDEX_DELIVERY_URL . 'assets/js/pickup-widget.js',
            array('ranau-yandex-delivery-adapter', 'wc-blocks-checkout'),
            $this->asset_version('assets/js/pickup-widget.js'),
            true
        );
        wp_enqueue_script(
            'ranau-yandex-courier',
            RANAU_YANDEX_DELIVERY_URL . 'assets/js/courier-widget.js',
            array('ranau-yandex-delivery-adapter', 'wc-blocks-checkout'),
            $this->asset_version('assets/js/courier-widget.js'),
            true
        );

        wp_localize_script('ranau-yandex-delivery-for-woocommerce', 'RanauYandexDelivery', array(
            'restUrl' => esc_url_raw(rest_url('ranau-yandex-delivery-for-woocommerce/v1')),
            'commitUrl' => esc_url_raw(rest_url('ranau-yandex-delivery-for-woocommerce/v1/commit')),
            'restNonce' => wp_create_nonce('wp_rest'),
            'storeApiNamespace' => self::STORE_API_NAMESPACE,
            'selectedPoint' => $this->session_array(self::SESSION_POINT),
            'quote' => $this->current_pickup_quote(),
            'defaultCity' => 'Москва',
            'packageFingerprint' => $this->package_fingerprint(),
        ));

        $courier_quote = $this->session_array(self::SESSION_COURIER_QUOTE);
        wp_localize_script('ranau-yandex-courier', 'RanauYandexCourier', array(
            'restUrl' => esc_url_raw(rest_url('ranau-yandex-delivery-for-woocommerce/v1/courier')),
            'commitUrl' => esc_url_raw(rest_url('ranau-yandex-delivery-for-woocommerce/v1/commit')),
            'restNonce' => wp_create_nonce('wp_rest'),
            'storeApiNamespace' => self::COURIER_STORE_API_NAMESPACE,
            'quote' => $courier_quote,
            'selection' => $this->session_array(self::SESSION_COURIER_SELECTION),
            'selectedAddress' => (string) ($courier_quote['address'] ?? ''),
            'defaultCity' => 'Москва',
            'packageFingerprint' => $this->package_fingerprint(),
        ));
    }

    public function render_classic_selector($rate, int $index): void
    {
        if (!is_object($rate) || !method_exists($rate, 'get_method_id')) {
            return;
        }

        if ($rate->get_method_id() === self::COURIER_METHOD_ID) {
            $this->render_classic_courier_selector();
            return;
        }

        if ($rate->get_method_id() !== self::METHOD_ID) {
            return;
        }

        echo '<div class="ranau-yandex-delivery-for-woocommerce-selector" data-ranau-yandex-delivery-for-woocommerce-classic data-ranau-delivery-method="' . esc_attr(self::METHOD_ID) . '" data-ranau-delivery-complete="0">';
        echo '<div class="ranau-yandex-delivery-for-woocommerce-selector__head"><div><strong>' . esc_html__('ПУНКТ ВЫДАЧИ ЯНДЕКСА', 'ranau-yandex-delivery-for-woocommerce') . '</strong><span>' . esc_html__('Выберите удобный ПВЗ или постамат на карте.', 'ranau-yandex-delivery-for-woocommerce') . '</span></div>';
        echo '<button type="button" class="button ranau-yandex-delivery-for-woocommerce-open">' . esc_html__('Выбрать ПВЗ', 'ranau-yandex-delivery-for-woocommerce') . '</button></div>';
        echo '<div class="ranau-yandex-delivery-for-woocommerce-selected" aria-live="polite">' . esc_html__('Пункт выдачи пока не выбран', 'ranau-yandex-delivery-for-woocommerce') . '</div>';
        echo '<button type="button" class="ranau-yandex-delivery-for-woocommerce-clear" hidden>' . esc_html__('Сбросить выбор', 'ranau-yandex-delivery-for-woocommerce') . '</button>';
        echo '</div>';
    }

    private function render_classic_courier_selector(): void
    {
        echo '<div class="ranau-yandex-courier-selector" data-ranau-yandex-courier-classic data-ranau-delivery-method="' . esc_attr(self::COURIER_METHOD_ID) . '" data-ranau-delivery-complete="0">';
        echo '<div class="ranau-yandex-courier-selector__head"><div><strong>' . esc_html__('ЯНДЕКС КУРЬЕР', 'ranau-yandex-delivery-for-woocommerce') . '</strong><span>' . esc_html__('Укажите адрес, рассчитайте стоимость и выберите доступный тариф.', 'ranau-yandex-delivery-for-woocommerce') . '</span></div></div>';
        echo '<div class="ranau-yandex-courier-editor"><label><span>' . esc_html__('Адрес доставки', 'ranau-yandex-delivery-for-woocommerce') . '</span><input type="text" class="ranau-yandex-courier-address" autocomplete="shipping street-address" placeholder="Улица и номер дома"></label>';
        echo '<button type="button" class="button ranau-yandex-courier-calculate">' . esc_html__('Рассчитать доставку', 'ranau-yandex-delivery-for-woocommerce') . '</button>';
        echo '<div class="ranau-yandex-courier-status" aria-live="polite"></div><div class="ranau-yandex-courier-offers" role="group" aria-label="Тарифы Яндекс Доставки"></div></div>';
        echo '</div>';
    }

    public function register_store_api_data(): void
    {
        if ($this->store_api_data_registered || !function_exists('woocommerce_store_api_register_endpoint_data')) {
            return;
        }

        woocommerce_store_api_register_endpoint_data(array(
            'endpoint' => 'checkout',
            'namespace' => self::STORE_API_NAMESPACE,
            'schema_callback' => static function (): array {
                return array(
                    'point_id' => array(
                        'description' => __('ID выбранного ПВЗ Яндекс Доставки.', 'ranau-yandex-delivery-for-woocommerce'),
                        'type' => 'string',
                        'context' => array('view', 'edit'),
                        'required' => false,
                    ),
                );
            },
        ));
        woocommerce_store_api_register_endpoint_data(array(
            'endpoint' => 'checkout',
            'namespace' => self::COURIER_STORE_API_NAMESPACE,
            'schema_callback' => static function (): array {
                return array(
                    'quote_id' => array(
                        'description' => __('ID выбранного предложения Яндекс Курьера.', 'ranau-yandex-delivery-for-woocommerce'),
                        'type' => 'string',
                        'context' => array('view', 'edit'),
                        'required' => false,
                    ),
                );
            },
        ));

        $this->store_api_data_registered = true;
    }

    public function register_store_api_update(): void
    {
        if ($this->store_api_update_registered || !function_exists('woocommerce_store_api_register_update_callback')) {
            return;
        }

        woocommerce_store_api_register_update_callback(array(
            'namespace' => self::STORE_API_NAMESPACE,
            'callback' => array($this, 'update_cart_from_store_api'),
        ));
        woocommerce_store_api_register_update_callback(array(
            'namespace' => self::COURIER_STORE_API_NAMESPACE,
            'callback' => array($this, 'update_courier_cart_from_store_api'),
        ));

        $this->store_api_update_registered = true;
    }

    public function update_cart_from_store_api($data): void
    {
        $data = $this->normalize_array($data);
        if (empty($data['commit_token'])) {
            $this->store_selection(array());
            return;
        }

        $this->commit_store_api_state($data);
    }

    public function update_courier_cart_from_store_api($data): void
    {
        $data = $this->normalize_array($data);
        if (empty($data['commit_token'])) {
            $this->clear_courier_quote();
            return;
        }

        $this->commit_store_api_state($data);
    }

    public function validate_classic_checkout(array $data, \WP_Error $errors): void
    {
        $method_id = $this->selected_method_id();
        if ($method_id === '') {
            return;
        }

        $message = $this->selection_error();
        if ($message !== '') {
            $errors->add('ranau_yandex_delivery_incomplete', $message);
        }
    }

    public function validate_blocks_checkout(\WC_Order $order, \WP_REST_Request $request): void
    {
        $method_id = $this->selected_method_id($order);
        if ($this->is_totals_request($request) || $method_id === '') {
            return;
        }

        $message = $this->selection_error($method_id, $order);
        if ($message !== '') {
            if (class_exists(RouteException::class)) {
                throw new RouteException('ranau_yandex_delivery_incomplete', esc_html($message), 400);
            }
            throw new \RuntimeException(esc_html($message));
        }
    }

    public function save_classic_order(\WC_Order $order, array $data): void
    {
        $this->save_order_delivery($order);
    }

    public function save_blocks_order(\WC_Order $order, \WP_REST_Request $request): void
    {
        if ($this->is_totals_request($request)) {
            return;
        }
        $this->save_order_delivery($order);
    }

    private function store_selection(array $point): array
    {
        $this->ensure_cart_loaded();
        $carrier_quote = array();
        if ($this->has_complete_pickup_point($point)) {
            $carrier_quote = (new PlatformClient())->quote((string) $point['id'], (new PackageResolver())->resolve_cart());
            $point['_ranau_committed'] = false;
        }

        if (function_exists('WC') && WC()->session) {
            WC()->session->set(self::SESSION_POINT, $point);
            WC()->session->set(self::SESSION_QUOTE, $carrier_quote);
            $this->clear_shipping_cache();
            $this->persist_session();
        }

        return $this->apply_pickup_free_shipping($carrier_quote);
    }

    private function selection_error(string $method_id = '', ?\WC_Order $order = null): string
    {
        $method_id = $method_id ?: $this->selected_method_id($order);
        if ($method_id === self::COURIER_METHOD_ID) {
            return $this->courier_selection_error($order);
        }

        if ($order && !$this->selected_shipping_item_is_ready($order, self::METHOD_ID, 'ranau_yandex_pickup_ready')) {
            return __('Выберите пункт выдачи Яндекс Доставки и дождитесь расчета стоимости.', 'ranau-yandex-delivery-for-woocommerce');
        }

        $point = $this->session_array(self::SESSION_POINT);
        if (!$this->has_complete_pickup_point($point) || empty($point['_ranau_committed'])) {
            return __('Выберите пункт выдачи Яндекс Доставки.', 'ranau-yandex-delivery-for-woocommerce');
        }

        $quote = $this->current_pickup_quote();
        if (empty($quote['ok'])) {
            return (string) ($quote['message'] ?? __('Стоимость доставки в этот ПВЗ пока недоступна.', 'ranau-yandex-delivery-for-woocommerce'));
        }

        return '';
    }

    private function save_order_delivery(\WC_Order $order): void
    {
        $method_id = $this->selected_method_id($order);
        if ($method_id === '' || $this->selection_error($method_id, $order) !== '') {
            return;
        }

        if ($method_id === self::COURIER_METHOD_ID) {
            $this->save_courier_order_selection($order);
            return;
        }

        $point = $this->session_array(self::SESSION_POINT);
        $quote = $this->current_pickup_quote();
        $address = $this->normalize_array($point['address'] ?? array());
        $position = array_values(array_map('strval', (array) ($point['position'] ?? array())));

        $order->update_meta_data('_ranau_delivery_provider', 'yandex_delivery');
        $order->update_meta_data('_ranau_delivery_fulfillment_owner', 'external');
        $order->update_meta_data('_ranau_delivery_mode', 'pickup');
        $order->update_meta_data('_ranau_yandex_no_fulfillment', 'yes');
        $order->update_meta_data('_ranau_yandex_pickup_point_id', sanitize_text_field((string) ($point['id'] ?? '')));
        $order->update_meta_data('_ranau_yandex_pickup_point_type', sanitize_text_field((string) ($point['type'] ?? '')));
        $order->update_meta_data('_ranau_yandex_pickup_address', sanitize_text_field((string) ($address['full_address'] ?? '')));
        $order->update_meta_data('_ranau_yandex_pickup_locality', sanitize_text_field((string) ($address['locality'] ?? '')));
        $order->update_meta_data('_ranau_yandex_pickup_country', sanitize_text_field((string) ($address['country'] ?? '')));
        $order->update_meta_data('_ranau_yandex_pickup_latitude', sanitize_text_field((string) ($position[0] ?? '')));
        $order->update_meta_data('_ranau_yandex_pickup_longitude', sanitize_text_field((string) ($position[1] ?? '')));
        $order->update_meta_data('_ranau_yandex_pickup_payment_methods', implode(',', array_map('sanitize_key', (array) ($point['payment_methods'] ?? array()))));
        $order->update_meta_data('_ranau_yandex_pickup_price', (float) ($quote['price'] ?? 0));
        $order->update_meta_data('_ranau_yandex_pickup_customer_price', (float) ($quote['price'] ?? 0));
        $order->update_meta_data('_ranau_yandex_pickup_free_shipping', !empty($quote['free_shipping']) ? 'yes' : 'no');
        $order->update_meta_data('_ranau_yandex_pickup_carrier_price', isset($quote['carrier_price']) ? (float) $quote['carrier_price'] : '');
        $order->update_meta_data('_ranau_yandex_pickup_subtotal_before_discounts', (float) ($quote['subtotal_before_discounts'] ?? 0));
        $order->update_meta_data('_ranau_yandex_pickup_days', (int) ($quote['days'] ?? 0));

        $order->set_shipping_address_1(sanitize_text_field((string) ($address['full_address'] ?? '')));
        $order->set_shipping_city(sanitize_text_field((string) ($address['locality'] ?? '')));
        if (!empty($address['country'])) {
            $order->set_shipping_country($this->country_code((string) $address['country']));
        }
    }

    private function select_courier_offer(string $quote_id): array
    {
        $selection = array();
        if ($quote_id !== '') {
            $quote = $this->session_array(self::SESSION_COURIER_QUOTE);
            foreach ((array) ($quote['offers'] ?? array()) as $offer) {
                if (!is_array($offer) || !hash_equals((string) ($offer['id'] ?? ''), $quote_id)) {
                    continue;
                }
                if (!(new ExpressClient())->offer_is_fresh($offer)) {
                    break;
                }
                $selection = $offer;
                $selection['address'] = sanitize_text_field((string) ($quote['address'] ?? ''));
                $selection['city'] = sanitize_text_field((string) ($quote['city'] ?? ''));
                $selection['region'] = sanitize_text_field((string) ($quote['region'] ?? ''));
                $selection['postcode'] = sanitize_text_field((string) ($quote['postcode'] ?? ''));
                $selection['country'] = sanitize_key((string) ($quote['country'] ?? 'RU')) ?: 'RU';
                $selection['position'] = array_values(array_map('floatval', (array) ($quote['position'] ?? array())));
                $selection['_ranau_committed'] = false;
                break;
            }
        }

        if (function_exists('WC') && WC()->session) {
            WC()->session->set(self::SESSION_COURIER_SELECTION, $selection);
            $this->clear_shipping_cache();
            $this->persist_session();
        }

        return $selection;
    }

    private function clear_courier_quote(): array
    {
        if (function_exists('WC') && WC()->session) {
            WC()->session->set(self::SESSION_COURIER_QUOTE, array());
            WC()->session->set(self::SESSION_COURIER_SELECTION, array());
            $this->clear_shipping_cache();
            $this->persist_session();
        }

        return array();
    }

    private function courier_selection_error(?\WC_Order $order = null): string
    {
        if ($order && !$this->selected_shipping_item_is_ready($order, self::COURIER_METHOD_ID, 'ranau_yandex_courier_ready')) {
            return __('Укажите адрес, рассчитайте доставку и выберите тариф Яндекса.', 'ranau-yandex-delivery-for-woocommerce');
        }

        $selection = $this->session_array(self::SESSION_COURIER_SELECTION);
        if (!empty($selection['id']) && !empty($selection['address']) && !empty($selection['_ranau_committed'])) {
            if ((new ExpressClient())->offer_is_fresh($selection)) {
                return '';
            }
            return __('Стоимость курьерской доставки устарела. Рассчитайте тариф еще раз.', 'ranau-yandex-delivery-for-woocommerce');
        }

        $quote = $this->session_array(self::SESSION_COURIER_QUOTE);
        if (isset($quote['ok']) && empty($quote['ok']) && !empty($quote['message'])) {
            return (string) $quote['message'];
        }

        return __('Укажите адрес, рассчитайте доставку и выберите тариф Яндекса.', 'ranau-yandex-delivery-for-woocommerce');
    }

    private function save_courier_order_selection(\WC_Order $order): void
    {
        $selection = $this->session_array(self::SESSION_COURIER_SELECTION);
        $position = array_values(array_map('strval', (array) ($selection['position'] ?? array())));

        $order->update_meta_data('_ranau_delivery_provider', 'yandex_delivery');
        $order->update_meta_data('_ranau_delivery_fulfillment_owner', 'external');
        $order->update_meta_data('_ranau_delivery_mode', 'courier');
        $order->update_meta_data('_ranau_yandex_no_fulfillment', 'yes');
        $order->update_meta_data('_ranau_yandex_courier_quote_id', sanitize_text_field((string) ($selection['id'] ?? '')));
        $order->update_meta_data('_ranau_yandex_courier_class', sanitize_key((string) ($selection['taxi_class'] ?? '')));
        $order->update_meta_data('_ranau_yandex_courier_address', sanitize_text_field((string) ($selection['address'] ?? '')));
        $order->update_meta_data('_ranau_yandex_courier_price', (float) ($selection['price'] ?? 0));
        $order->update_meta_data('_ranau_yandex_courier_customer_price', (float) ($selection['price'] ?? 0));
        $order->update_meta_data('_ranau_yandex_courier_carrier_price', (float) ($selection['price'] ?? 0));
        $order->update_meta_data('_ranau_yandex_courier_latitude', sanitize_text_field((string) ($position[0] ?? '')));
        $order->update_meta_data('_ranau_yandex_courier_longitude', sanitize_text_field((string) ($position[1] ?? '')));
        $order->update_meta_data('_ranau_yandex_courier_pickup_from', sanitize_text_field((string) ($selection['pickup_from'] ?? '')));
        $order->update_meta_data('_ranau_yandex_courier_pickup_to', sanitize_text_field((string) ($selection['pickup_to'] ?? '')));
        $order->update_meta_data('_ranau_yandex_courier_delivery_from', sanitize_text_field((string) ($selection['delivery_from'] ?? '')));
        $order->update_meta_data('_ranau_yandex_courier_delivery_to', sanitize_text_field((string) ($selection['delivery_to'] ?? '')));
        $order->set_shipping_address_1(sanitize_text_field((string) ($selection['address'] ?? '')));
        $order->set_shipping_city(sanitize_text_field((string) ($selection['city'] ?? '')));
        $order->set_shipping_state(sanitize_text_field((string) ($selection['region'] ?? '')));
        $order->set_shipping_postcode(sanitize_text_field((string) ($selection['postcode'] ?? '')));
        $order->set_shipping_country(strtoupper(sanitize_key((string) ($selection['country'] ?? 'RU'))) ?: 'RU');
    }

    private function sanitize_point(array $data): array
    {
        $id = sanitize_text_field((string) ($data['id'] ?? ''));
        if ($id === '') {
            return array();
        }

        $raw_address = $this->normalize_array($data['address'] ?? array());
        $address = array();
        foreach (array('country', 'locality', 'street', 'house', 'full_address', 'comment') as $key) {
            $address[$key] = sanitize_text_field((string) ($raw_address[$key] ?? ''));
        }

        $position = array_values(array_filter(array_map('floatval', (array) ($data['position'] ?? array())), static fn(float $value): bool => $value !== 0.0));

        return array(
            'id' => $id,
            'type' => sanitize_key((string) ($data['type'] ?? 'pickup_point')),
            'address' => $address,
            'position' => array_slice($position, 0, 2),
            'payment_methods' => array_values(array_filter(array_map('sanitize_key', (array) ($data['payment_methods'] ?? array())))),
        );
    }

    private function country_code(string $country): string
    {
        $country = trim($country);
        $normalized = function_exists('mb_strtolower') ? mb_strtolower($country) : strtolower($country);
        return in_array($normalized, array('россия', 'russia', 'ru'), true) ? 'RU' : '';
    }

    private function has_complete_pickup_point(array $point): bool
    {
        $address = $this->normalize_array($point['address'] ?? array());
        return !empty($point['id']) && !empty($address['full_address']);
    }

    private function selected_method_id(?\WC_Order $order = null): string
    {
        if ($order) {
            $method_id = $this->selected_method_id_from_order($order);
            if ($method_id !== '') {
                return $method_id;
            }
        }

        if (!function_exists('WC') || !WC()->session) {
            return '';
        }

        foreach ((array) WC()->session->get('chosen_shipping_methods', array()) as $method) {
            $method = (string) $method;
            foreach (array(self::METHOD_ID, self::COURIER_METHOD_ID) as $method_id) {
                if (strpos($method, $method_id) === 0) {
                    return $method_id;
                }
            }
        }

        return '';
    }

    private function selected_method_id_from_order(\WC_Order $order): string
    {
        foreach ((array) $order->get_items('shipping') as $item) {
            if (!is_object($item)) {
                continue;
            }

            $method = method_exists($item, 'get_method_id') ? (string) $item->get_method_id() : '';
            if ($method === '') {
                $method = $this->shipping_item_meta($item, 'method_id');
            }
            if ($method === '') {
                $method = $this->shipping_item_meta($item, '_method_id');
            }

            foreach (array(self::METHOD_ID, self::COURIER_METHOD_ID) as $method_id) {
                if ($method === $method_id || strpos($method, $method_id . ':') === 0) {
                    return $method_id;
                }
            }
        }

        return '';
    }

    private function selected_shipping_item_is_ready(\WC_Order $order, string $method_id, string $ready_meta_key): bool
    {
        foreach ((array) $order->get_items('shipping') as $item) {
            if (!is_object($item)) {
                continue;
            }

            $item_method = method_exists($item, 'get_method_id') ? (string) $item->get_method_id() : '';
            if ($item_method !== $method_id && strpos($item_method, $method_id . ':') !== 0) {
                continue;
            }

            return $this->shipping_item_meta($item, $ready_meta_key) === 'yes';
        }

        return false;
    }

    private function shipping_item_meta(object $item, string $key): string
    {
        if (!method_exists($item, 'get_meta')) {
            return '';
        }

        return sanitize_text_field((string) $item->get_meta($key));
    }

    private function is_totals_request(\WP_REST_Request $request): bool
    {
        $value = $request->get_param('__experimental_calc_totals');
        return in_array($value, array(true, 'true', 1, '1'), true);
    }

    private function current_pickup_quote(): array
    {
        return $this->apply_pickup_free_shipping($this->session_array(self::SESSION_QUOTE));
    }

    private function apply_pickup_free_shipping(array $quote): array
    {
        $policy = new FreeShippingPolicy(self::METHOD_ID);
        $threshold = $policy->threshold();
        if (!$policy->is_eligible(array(), $threshold)) {
            return $quote;
        }

        $carrier_quote = $quote;
        $quote['ok'] = true;
        $quote['price'] = 0.0;
        $quote['free_shipping'] = true;
        $quote['free_shipping_threshold'] = $threshold;
        $quote['subtotal_before_discounts'] = $policy->subtotal_before_discounts();
        $quote['carrier_price'] = !empty($carrier_quote['ok']) && isset($carrier_quote['price'])
            ? (float) $carrier_quote['price']
            : null;
        $quote['carrier_code'] = (string) ($carrier_quote['code'] ?? '');
        $quote['carrier_message'] = (string) ($carrier_quote['message'] ?? '');
        $quote['message'] = __('Бесплатная доставка в ПВЗ по сумме товаров до скидок.', 'ranau-yandex-delivery-for-woocommerce');

        return $quote;
    }

    /** @param array<string, mixed> $selection
     *  @param array<string, mixed> $quote
     *  @return array<string, mixed>
     */
    private function begin_provider_state(string $method_id, array $selection, array $quote): array
    {
        $rate_id = $this->selected_full_rate_id($method_id);
        $fingerprint = $this->package_fingerprint();

        return $this->provider_state_store($rate_id)->beginPending(
            $this->context_key($selection, $fingerprint),
            $fingerprint,
            $selection,
            $quote
        );
    }

    /** @param array<string, mixed> $data
     *  @return array<string, mixed>
     */
    private function commit_provider_state(array $data): array
    {
        $rate_id = sanitize_text_field((string) ($data['rate_id'] ?? ''));
        $method_id = explode(':', $rate_id)[0];
        if (!in_array($method_id, array(self::METHOD_ID, self::COURIER_METHOD_ID), true)) {
            throw new DomainException('invalid_rate');
        }
        $selection = $method_id === self::METHOD_ID
            ? $this->session_array(self::SESSION_POINT)
            : $this->session_array(self::SESSION_COURIER_SELECTION);
        $fingerprint = $this->package_fingerprint();
        $committed = $this->provider_state_store($rate_id)->commit(
            $data,
            $this->selected_full_rate_id($method_id),
            $this->context_key($selection, $fingerprint),
            $fingerprint
        );
        $selection = (array) ($committed['selection'] ?? array());
        $selection['_ranau_committed'] = true;
        if ($method_id === self::METHOD_ID) {
            $this->set_session(self::SESSION_POINT, $selection);
            $this->set_session(self::SESSION_QUOTE, (array) ($committed['quote'] ?? array()));
        } else {
            $this->set_session(self::SESSION_COURIER_SELECTION, $selection);
        }
        $this->clear_shipping_cache();
        $this->persist_session();

        return $committed;
    }

    /** @param array<string, mixed> $data */
    private function commit_store_api_state(array $data): void
    {
        try {
            $this->commit_provider_state($data);
        } catch (DomainException $exception) {
            $message = __('Выбор доставки устарел. Рассчитайте доставку ещё раз.', 'ranau-yandex-delivery-for-woocommerce');
            if (class_exists(RouteException::class)) {
                throw new RouteException(esc_attr($exception->getMessage()), esc_html($message), 409);
            }
            throw new \RuntimeException(esc_html($message));
        }
    }

    private function provider_state_store(string $rate_id): ProviderStateStore
    {
        if ($rate_id === '') {
            throw new DomainException('missing_rate_id');
        }
        $key = 'ranau_yandex_delivery_state_' . md5($rate_id);

        return new ProviderStateStore(
            $rate_id,
            function () use ($key): array {
                return $this->session_array($key);
            },
            function (array $value) use ($key): void {
                $this->set_session($key, $value);
                $this->persist_session();
            },
            function () use ($key): void {
                if (function_exists('WC') && WC()->session) {
                    WC()->session->__unset($key);
                    $this->persist_session();
                }
            }
        );
    }

    private function selected_full_rate_id(string $method_id): string
    {
        if (!function_exists('WC') || !WC()->session) {
            return '';
        }
        foreach ((array) WC()->session->get('chosen_shipping_methods', array()) as $rate_id) {
            $rate_id = sanitize_text_field((string) $rate_id);
            if (explode(':', $rate_id)[0] === $method_id) {
                return $rate_id;
            }
        }

        return '';
    }

    /** @param array<string, mixed> $selection */
    private function context_key(array $selection, string $fingerprint): string
    {
        $address = $this->normalize_array($selection['address'] ?? array());
        $parts = array(
            (string) ($selection['country'] ?? $address['country'] ?? 'RU'),
            (string) ($selection['region'] ?? ''),
            (string) ($selection['city'] ?? $address['locality'] ?? ''),
            (string) ($selection['postcode'] ?? ''),
            $fingerprint,
        );

        return implode('|', array_map(static function ($value): string {
            return strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $value)));
        }, $parts));
    }

    private function package_fingerprint(): string
    {
        return hash('sha256', (string) wp_json_encode((new PackageResolver())->resolve_cart()));
    }

    private function session_array(string $key): array
    {
        if (!function_exists('WC') || !WC()->session) {
            return array();
        }

        return $this->normalize_array(WC()->session->get($key, array()));
    }

    private function normalize_array($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return json_decode(wp_json_encode($value), true) ?: array();
        }
        return array();
    }

    private function set_session(string $key, array $value): void
    {
        if (function_exists('WC') && WC()->session) {
            WC()->session->set($key, $value);
        }
    }

    private function clear_shipping_cache(): void
    {
        if (!function_exists('WC') || !WC()->session) {
            return;
        }

        $packages = WC()->cart ? WC()->cart->get_shipping_packages() : array();
        foreach (array_keys((array) $packages) as $index) {
            WC()->session->__unset('shipping_for_package_' . $index);
        }
    }

    private function ensure_cart_loaded(): void
    {
        if (!function_exists('WC')) {
            return;
        }
        if (!WC()->session && method_exists(WC(), 'initialize_session')) {
            WC()->initialize_session();
        }
        if (!WC()->cart && method_exists(WC(), 'initialize_cart')) {
            WC()->initialize_cart();
        }
    }

    private function persist_session(): void
    {
        if (function_exists('WC') && WC()->session && method_exists(WC()->session, 'save_data')) {
            WC()->session->save_data();
        }
    }

    private function asset_version(string $relative_path): string
    {
        $path = RANAU_YANDEX_DELIVERY_DIR . ltrim($relative_path, '/');
        clearstatcache(true, $path);
        $mtime = is_readable($path) ? filemtime($path) : false;
        return $mtime ? (string) $mtime : RANAU_YANDEX_DELIVERY_VERSION;
    }
}
