=== Ranau Yandex Delivery for WooCommerce ===
Contributors: yudin-s
Tags: woocommerce, yandex delivery, pickup, courier, shipping
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add Yandex pickup-point selection and courier or express delivery quotes to WooCommerce.

== Description ==

Ranau Yandex Delivery for WooCommerce is a standalone shipping integration for stores that fulfil orders in another system.

Features:

* Official Yandex pickup-point widget.
* Pickup pricing from Yandex Delivery.
* Courier and express offer calculation for a configured warehouse.
* Independent API credentials and warehouse settings.
* Checkout Blocks, classic checkout, and HPOS support.
* Provider-local state machine with server-owned selection and price commits.
* No delivery claim, label, courier dispatch, tracking, or fulfilment calls.
* No Ranau account, license key, tracking, or remote Ranau service.

== Installation ==

1. Install and activate WooCommerce.
2. Upload and activate this plugin.
3. Open WooCommerce > Ranau Yandex Delivery and enter your API token, Geocoder key, pickup source station, and warehouse details.
4. Add the Ranau Yandex pickup and/or courier methods to the required shipping zones.
5. Test package dimensions, supported destinations, quote expiry, checkout validation, and every payment method before launch.

== Frequently Asked Questions ==

= Does the plugin create Yandex delivery claims? =

No. It calculates options and records the confirmed choice on the order. Fulfilment remains external.

= Does it require another Yandex or checkout plugin? =

No. Credentials, state handling, Checkout Blocks support, and both shipping methods are included.

== External services ==

The pickup method loads the official Yandex Delivery pickup-point widget from `widget-pvz.dostavka.yandex.net`. The widget can receive the destination city, browser IP address, and device information. After a pickup point is selected, the plugin sends the configured API token, source and destination station identifiers, aggregate parcel weight, dimensions, assessed value, and payment mode to the Yandex Delivery pricing calculator.

The courier method sends the configured API token, warehouse address and coordinates, customer-entered destination address and coordinates, and parcel weight/dimensions to Yandex Delivery's offer calculator. Yandex Geocoder is used to convert configured or customer-entered addresses to coordinates. The plugin calculates offers only; it does not create a delivery claim or dispatch a courier.

Yandex Delivery terms: https://yandex.ru/legal/logistics_terms_of_use/ru/
Yandex Maps API terms: https://yandex.ru/legal/maps_api/ru/
Yandex privacy policy: https://yandex.ru/legal/confidential/

== Privacy ==

The plugin sends parcel data, configured warehouse data, destination addresses, or pickup identifiers to Yandex endpoints when required for widgets and calculations. It sends no checkout data to Ranau and adds no analytics or tracking.

== Support ==

Community issues: https://github.com/yudin-s/ranau-yandex-delivery-for-woocommerce/issues

Optional paid support and custom WooCommerce development are available at https://ranau.uk/ and are not required to use the plugin.

== Changelog ==

= 0.2.1 =

* First independent open-source release candidate.
