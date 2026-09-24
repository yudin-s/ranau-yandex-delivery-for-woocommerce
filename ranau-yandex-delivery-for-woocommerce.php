<?php
/**
 * Plugin Name: Ranau Yandex Delivery for WooCommerce
 * Plugin URI: https://ranau.uk/wordpress/ranau-yandex-delivery-for-woocommerce/
 * Description: ПВЗ и курьерская доставка Яндекса с живым расчетом тарифов в WooCommerce.
 * Version: 0.2.1
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Author: Ranau
 * Author URI: https://ranau.uk/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ranau-yandex-delivery-for-woocommerce
 * Requires Plugins: woocommerce
 * WC requires at least: 8.9
 * WC tested up to: 10.8
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('RANAU_YANDEX_DELIVERY_VERSION', '0.2.1');
define('RANAU_YANDEX_DELIVERY_FILE', __FILE__);
define('RANAU_YANDEX_DELIVERY_DIR', plugin_dir_path(__FILE__));
define('RANAU_YANDEX_DELIVERY_URL', plugin_dir_url(__FILE__));

require_once RANAU_YANDEX_DELIVERY_DIR . 'includes/class-package-resolver.php';
require_once RANAU_YANDEX_DELIVERY_DIR . 'includes/class-settings.php';
require_once RANAU_YANDEX_DELIVERY_DIR . 'includes/class-free-shipping-policy.php';
require_once RANAU_YANDEX_DELIVERY_DIR . 'includes/class-platform-client.php';
require_once RANAU_YANDEX_DELIVERY_DIR . 'includes/class-express-client.php';
require_once RANAU_YANDEX_DELIVERY_DIR . 'includes/internal/ProviderStateStore.php';
require_once RANAU_YANDEX_DELIVERY_DIR . 'includes/class-plugin.php';

add_action('before_woocommerce_init', static function (): void {
    $features = '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil';
    if (class_exists($features)) {
        $features::declare_compatibility('custom_order_tables', RANAU_YANDEX_DELIVERY_FILE, true);
        $features::declare_compatibility('cart_checkout_blocks', RANAU_YANDEX_DELIVERY_FILE, true);
    }
});

add_action('plugins_loaded', static function (): void {
    if (!class_exists('WooCommerce')) {
        return;
    }

    \Ranau\YandexDelivery\Plugin::instance()->init();
    (new \Ranau\YandexDelivery\Settings())->init();
});
