(function (window, document) {
    'use strict';

    var runtime = window.RanauYandexDeliveryRuntimeV1;
    var machines = {};

    function fieldValue(selectors) {
        for (var index = 0; index < selectors.length; index += 1) {
            var field = document.querySelector(selectors[index]);
            if (field && String(field.value || '').trim()) {
                return String(field.value).trim();
            }
        }
        return '';
    }

    function create(options) {
        var settings = options || {};

        function selectedRateId() {
            var field = document.querySelector('input[type="radio"][value^="' + settings.methodId + '"]:checked, input.shipping_method[value^="' + settings.methodId + '"]:checked');
            return field ? String(field.value || '') : '';
        }

        function begin(selection) {
            var rateId = selectedRateId();
            if (!runtime || !rateId) {
                throw new Error('delivery_state_unavailable');
            }
            if (!machines[rateId]) {
                machines[rateId] = runtime.createDeliveryStateMachine({rateId: rateId});
            }
            var machine = machines[rateId];
            machine.setAvailable(true, {
                country: fieldValue(['#shipping-country', '#shipping_country', 'select[name="shipping_country"]']) || 'RU',
                region: fieldValue(['#shipping-state', '#shipping_state', 'input[name="shipping_state"]']),
                city: fieldValue(['#shipping-city', '#shipping_city', 'input[name="shipping_city"]', 'input[autocomplete="address-level2"]']),
                postcode: fieldValue(['#shipping-postcode', '#shipping_postcode', 'input[name="shipping_postcode"]']),
                packageFingerprint: String(settings.packageFingerprint || '')
            });
            return {
                rateId: rateId,
                machine: machine,
                token: machine.beginCalculation(selection),
                bridge: runtime.createCheckoutBridge({machine: machine, namespace: settings.namespace, environment: window})
            };
        }

        function commit(calculation, serverCommit, quote) {
            if (!calculation || !calculation.token || !serverCommit || !serverCommit.commit_token) {
                return Promise.reject(new Error('delivery_commit_missing'));
            }
            if (!(window.wc && window.wc.blocksCheckout && typeof window.wc.blocksCheckout.extensionCartUpdate === 'function')) {
                var accepted = calculation.machine.commit(calculation.token, quote);
                if (!accepted.accepted || selectedRateId() !== calculation.rateId) {
                    return Promise.reject(new Error('delivery_response_stale'));
                }
                return fetch(settings.commitUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json', 'X-WP-Nonce': settings.nonce || ''},
                    body: JSON.stringify(serverCommit)
                }).then(function (response) {
                    if (!response.ok) {
                        throw new Error('delivery_commit_failed');
                    }
                    if (window.jQuery) {
                        window.jQuery(document.body).trigger('update_checkout');
                    }
                    return {accepted: true, checkoutUpdated: true, transport: 'classic'};
                });
            }

            return calculation.bridge.commitCalculation({
                selectedRateId: selectedRateId(),
                token: calculation.token,
                quote: quote,
                packageFingerprint: String(serverCommit.package_fingerprint || settings.packageFingerprint || ''),
                commitToken: String(serverCommit.commit_token || ''),
                data: serverCommit
            }).then(function (result) {
                if (!result.accepted || !result.checkoutUpdated) {
                    throw new Error(result.reason || 'delivery_commit_failed');
                }
                return result;
            });
        }

        return Object.freeze({begin: begin, commit: commit});
    }

    Object.defineProperty(window, 'RanauYandexDeliveryCommitV1', {
        configurable: false,
        enumerable: false,
        writable: false,
        value: Object.freeze({create: create})
    });
}(window, document));
