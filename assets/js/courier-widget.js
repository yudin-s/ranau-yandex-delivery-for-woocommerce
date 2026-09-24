(function () {
    'use strict';

    var config = window.RanauYandexCourier || {};
    var commitAdapter = window.RanauYandexDeliveryCommitV1.create({
        methodId: 'ranau_yandex_courier',
        namespace: config.storeApiNamespace || 'ranau-yandex-courier',
        commitUrl: config.commitUrl || '',
        nonce: config.restNonce || '',
        packageFingerprint: config.packageFingerprint || ''
    });
    var state = {
        quote: config.quote || null,
        selection: config.selection || null,
        address: config.selectedAddress || '',
        calculating: false,
        committing: false,
        dirty: false,
        clearing: false,
        renderQueued: false
    };

    function escapeHtml(value) {
        var node = document.createElement('div');
        node.textContent = String(value || '');
        return node.innerHTML;
    }

    function cssEscape(value) {
        return window.CSS && typeof window.CSS.escape === 'function'
            ? window.CSS.escape(String(value || ''))
            : String(value || '').replace(/["\\]/g, '\\$&');
    }

    function currentCity() {
        var selectors = [
            '#shipping-city',
            '#shipping_city',
            'input[name="shipping_city"]',
            '.wc-block-components-address-form__city input',
            'input[autocomplete="address-level2"]'
        ];
        for (var i = 0; i < selectors.length; i += 1) {
            var field = document.querySelector(selectors[i]);
            if (field && String(field.value || '').trim()) {
                return String(field.value).trim();
            }
        }
        return config.defaultCity || '';
    }

    function checkoutStreetAddress() {
        var selectors = [
            '#shipping-address_1',
            '#shipping_address_1',
            'input[name="shipping_address_1"]',
            'input[autocomplete="shipping street-address"]:not(.ranau-yandex-courier-address)',
            'input[autocomplete="street-address"]:not(.ranau-yandex-courier-address)'
        ];
        for (var i = 0; i < selectors.length; i += 1) {
            var field = document.querySelector(selectors[i]);
            if (field && String(field.value || '').trim()) {
                return String(field.value).trim();
            }
        }
        return '';
    }

    function isReady() {
        return Boolean(!state.committing && state.selection && state.selection.id && state.selection.address && state.selection.price !== null);
    }

    function money(value) {
        return Number(value || 0).toLocaleString('ru-RU', {minimumFractionDigits: 0, maximumFractionDigits: 2}) + ' ₽';
    }

    function offerTitle(offer) {
        if (offer && offer.title) {
            return String(offer.title);
        }
        return offer && offer.taxi_class === 'courier' ? 'Курьер' : 'Экспресс';
    }

    function intervalText(offer) {
        var from = offer && offer.delivery_from ? new Date(offer.delivery_from) : null;
        var to = offer && offer.delivery_to ? new Date(offer.delivery_to) : null;
        if (!to || Number.isNaN(to.getTime())) {
            return '';
        }
        var timeOptions = {hour: '2-digit', minute: '2-digit'};
        var sameDate = from && !Number.isNaN(from.getTime()) && from.toDateString() === to.toDateString();
        if (sameDate) {
            return 'Доставка ' + from.toLocaleTimeString('ru-RU', timeOptions) + '–' + to.toLocaleTimeString('ru-RU', timeOptions);
        }
        return 'Доставим до ' + to.toLocaleString('ru-RU', {day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit'});
    }

    function selectorHtml(rateValue) {
        return [
            '<div class="ranau-yandex-courier-selector ranau-yandex-courier-blocks-selector" data-ranau-yandex-courier-rate="' + escapeHtml(rateValue) + '" data-ranau-delivery-method="ranau_yandex_courier" data-ranau-delivery-complete="0">',
            '<div class="ranau-yandex-courier-selector__head"><div><strong>ЯНДЕКС КУРЬЕР</strong><span>Укажите адрес, рассчитайте стоимость и выберите доступный тариф.</span></div></div>',
            '<div class="ranau-yandex-courier-editor">',
            '<label><span>Адрес доставки</span><input type="text" class="ranau-yandex-courier-address" autocomplete="shipping street-address" placeholder="Улица и номер дома"></label>',
            '<button type="button" class="ranau-yandex-courier-calculate">Рассчитать доставку</button>',
            '<div class="ranau-yandex-courier-status" aria-live="polite"></div>',
            '<div class="ranau-yandex-courier-offers" role="group" aria-label="Тарифы Яндекс Доставки"></div>',
            '</div></div>'
        ].join('');
    }

    function setText(node, value) {
        value = String(value || '');
        if (node && node.textContent !== value) {
            node.textContent = value;
        }
    }

    function setAttribute(node, name, value) {
        value = String(value);
        if (node && node.getAttribute(name) !== value) {
            node.setAttribute(name, value);
        }
    }

    function removeAttribute(node, name) {
        if (node && node.hasAttribute(name)) {
            node.removeAttribute(name);
        }
    }

    function toggleClass(node, name, enabled) {
        if (node && node.classList.contains(name) !== Boolean(enabled)) {
            node.classList.toggle(name, Boolean(enabled));
        }
    }

    function statusMessage() {
        if (state.calculating) {
            return 'Рассчитываем доступные варианты Яндекс Доставки…';
        }
        if (state.committing) {
            return 'Сохраняем выбранный тариф…';
        }
        if (state.dirty) {
            return 'Адрес изменён. Рассчитайте доставку ещё раз.';
        }
        if (isReady()) {
            return offerTitle(state.selection) + ' · ' + money(state.selection.price) + (intervalText(state.selection) ? ' · ' + intervalText(state.selection) : '');
        }
        if (state.quote && state.quote.ok && Array.isArray(state.quote.offers) && state.quote.offers.length) {
            return 'Выберите подходящий вариант доставки.';
        }
        if (state.quote && state.quote.message) {
            return String(state.quote.message);
        }
        return 'Введите улицу и номер дома, затем рассчитайте доставку.';
    }

    function renderOffers(root) {
        var target = root.querySelector('.ranau-yandex-courier-offers');
        if (!target) {
            return;
        }
        var offers = state.quote && state.quote.ok && Array.isArray(state.quote.offers) ? state.quote.offers : [];
        var activeId = state.selection && state.selection.id ? String(state.selection.id) : '';
        var renderKey = offers.map(function (offer) { return offer.id; }).join('|') + ':' + activeId;
        if (target.getAttribute('data-render-key') === renderKey) {
            return;
        }
        target.setAttribute('data-render-key', renderKey);
        target.innerHTML = '';

        offers.forEach(function (offer) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'ranau-yandex-courier-offer';
            button.setAttribute('data-quote-id', String(offer.id || ''));
            button.setAttribute('aria-pressed', activeId && activeId === String(offer.id || '') ? 'true' : 'false');

            var title = document.createElement('span');
            title.className = 'ranau-yandex-courier-offer__title';
            title.textContent = offerTitle(offer);
            button.appendChild(title);

            var price = document.createElement('span');
            price.className = 'ranau-yandex-courier-offer__price';
            price.textContent = money(offer.price);
            button.appendChild(price);

            var interval = intervalText(offer);
            if (interval) {
                var time = document.createElement('span');
                time.className = 'ranau-yandex-courier-offer__time';
                time.textContent = interval;
                button.appendChild(time);
            }
            target.appendChild(button);
        });
    }

    function updateSelector(root) {
        if (!root) {
            return;
        }
        setAttribute(root, 'data-ranau-delivery-complete', isReady() ? '1' : '0');
        toggleClass(root, 'is-ready', isReady());
        toggleClass(root, 'is-dirty', state.dirty);
        toggleClass(root, 'is-calculating', state.calculating);

        var address = root.querySelector('.ranau-yandex-courier-address');
        if (address && document.activeElement !== address && !address.value) {
            address.value = state.address || checkoutStreetAddress();
        }
        var calculate = root.querySelector('.ranau-yandex-courier-calculate');
        if (calculate) {
            if (calculate.disabled !== state.calculating) {
                calculate.disabled = state.calculating;
            }
            setText(calculate, state.calculating ? 'Рассчитываем…' : 'Рассчитать доставку');
        }
        var status = root.querySelector('.ranau-yandex-courier-status');
        setText(status, statusMessage());
        if (status) {
            toggleClass(status, 'is-error', Boolean((state.quote && !state.quote.ok) || state.dirty));
            toggleClass(status, 'is-ready', isReady());
        }
        renderOffers(root);
    }

    function refreshRatePresentation() {
        document.querySelectorAll('input[type="radio"][value^="ranau_yandex_courier"]').forEach(function (radio) {
            var option = radio.closest('label.wc-block-components-radio-control__option, label');
            var target = option ? option.querySelector('.wc-block-components-radio-control__secondary-label') : null;
            if (!target) {
                return;
            }
            if (isReady()) {
                removeAttribute(target, 'data-ranau-yandex-courier-rate-label');
                removeAttribute(target, 'data-ranau-yandex-courier-rate-ready');
                return;
            }
            setAttribute(target, 'data-ranau-yandex-courier-rate-label', 'Рассчитаем после ввода адреса');
            setAttribute(target, 'data-ranau-yandex-courier-rate-ready', '0');
        });
    }

    function renderSelectors() {
        state.renderQueued = false;
        document.querySelectorAll('.ranau-yandex-courier-blocks-selector').forEach(function (selector) {
            var value = selector.getAttribute('data-ranau-yandex-courier-rate') || '';
            var radio = document.querySelector('input[type="radio"][value="' + cssEscape(value) + '"]');
            if (!radio || !radio.checked) {
                selector.remove();
            }
        });

        document.querySelectorAll('input[type="radio"][value^="ranau_yandex_courier"]').forEach(function (radio) {
            var option = radio.closest('label.wc-block-components-radio-control__option, label');
            if (!option || !radio.checked) {
                return;
            }
            var value = radio.value || '';
            var selector = document.querySelector('.ranau-yandex-courier-blocks-selector[data-ranau-yandex-courier-rate="' + cssEscape(value) + '"]');
            if (!selector) {
                option.insertAdjacentHTML('afterend', selectorHtml(value));
                selector = option.nextElementSibling;
            }
            updateSelector(selector);
        });

        document.querySelectorAll('[data-ranau-yandex-courier-classic]').forEach(function (selector) {
            var row = selector.closest('li, tr') || document;
            var radio = row.querySelector('input.shipping_method[value^="ranau_yandex_courier"]');
            toggleClass(selector, 'is-active', Boolean(radio && radio.checked));
            updateSelector(selector);
        });
        refreshRatePresentation();
    }

    function scheduleRender() {
        if (state.renderQueued) {
            return;
        }
        state.renderQueued = true;
        window.requestAnimationFrame(renderSelectors);
    }

    function post(path, body) {
        return fetch((config.restUrl || '') + path, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': config.restNonce || ''
            },
            body: JSON.stringify(body || {})
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('request_failed');
            }
            return response.json();
        });
    }

    function refreshCart(quoteId) {
        var result = Promise.resolve();
        if (window.wc && window.wc.blocksCheckout && typeof window.wc.blocksCheckout.extensionCartUpdate === 'function') {
            result = window.wc.blocksCheckout.extensionCartUpdate({
                namespace: config.storeApiNamespace || 'ranau-yandex-courier',
                data: {quote_id: quoteId || ''}
            });
        } else if (window.jQuery && document.body) {
            window.jQuery(document.body).trigger('update_checkout');
        }
        return Promise.resolve(result);
    }

    function publishCheckoutAddress() {
        if (!isReady()) {
            return;
        }
        document.dispatchEvent(new CustomEvent('ranauCheckout:checkoutAddressUpdate', {
            detail: {
                source: 'yandex_courier',
                kind: 'courier',
                shipping: {
                    address_1: state.selection.address || '',
                    city: state.selection.city || '',
                    state: state.selection.region || '',
                    postcode: state.selection.postcode || '',
                    country: state.selection.country || 'RU'
                }
            }
        }));
    }

    function calculateOffers(root) {
        var input = root.querySelector('.ranau-yandex-courier-address');
        var address = input ? String(input.value || '').trim() : '';
        var city = currentCity();
        if (!city || !address) {
            state.quote = {ok: false, message: 'Укажите город, улицу и номер дома.'};
            scheduleRender();
            return;
        }

        state.calculating = true;
        state.dirty = false;
        state.address = address;
        state.selection = null;
        scheduleRender();

        post('/quote', {city: city, address: address}).then(function (quote) {
            state.quote = quote || null;
            state.address = quote && quote.address ? quote.address : address;
            state.selection = null;
            state.calculating = false;
            scheduleRender();
        }).catch(function () {
            state.calculating = false;
            state.quote = {ok: false, message: 'Не удалось рассчитать доставку. Попробуйте ещё раз.'};
            scheduleRender();
        });
    }

    function selectOffer(quoteId) {
        var calculation;
        try {
            calculation = commitAdapter.begin({quote_id: quoteId});
        } catch (error) {
            state.quote = {ok: false, message: 'Сначала выберите способ доставки Яндекса.'};
            scheduleRender();
            return;
        }
        state.committing = true;
        scheduleRender();
        post('/selection', {quote_id: quoteId}).then(function (result) {
            if (!result || !result.selection || !result.selection.id) {
                throw new Error('delivery_selection_missing');
            }
            return commitAdapter.commit(calculation, result.commit, result.selection).then(function () {
                state.selection = result.selection;
                state.selection._ranau_committed = true;
                state.quote = result.quote || state.quote;
                state.dirty = false;
            });
        }).then(function () {
            state.committing = false;
            publishCheckoutAddress();
            scheduleRender();
            document.dispatchEvent(new CustomEvent('ranauCheckout:deliveryStateChanged', {
                detail: {method: 'ranau_yandex_courier', complete: isReady()}
            }));
        }).catch(function () {
            state.committing = false;
            state.quote = {ok: false, message: 'Не удалось сохранить выбранный тариф. Рассчитайте доставку ещё раз.'};
            state.selection = null;
            scheduleRender();
        });
    }

    function markAddressDirty(input) {
        state.address = String(input.value || '').trim();
        if (!state.selection && !(state.quote && state.quote.ok)) {
            return;
        }
        state.selection = null;
        state.quote = null;
        state.dirty = true;
        scheduleRender();
        if (state.clearing) {
            return;
        }
        state.clearing = true;
        post('/selection', {quote_id: '', clear_quote: true}).catch(function () { return null; }).then(function () {
            state.clearing = false;
            return refreshCart('');
        }).then(scheduleRender);
    }

    document.addEventListener('click', function (event) {
        var calculate = event.target.closest('.ranau-yandex-courier-calculate');
        if (calculate) {
            event.preventDefault();
            calculateOffers(calculate.closest('.ranau-yandex-courier-selector'));
            return;
        }
        var offer = event.target.closest('.ranau-yandex-courier-offer');
        if (offer) {
            event.preventDefault();
            selectOffer(String(offer.getAttribute('data-quote-id') || ''));
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && event.target && event.target.matches('.ranau-yandex-courier-address')) {
            event.preventDefault();
            calculateOffers(event.target.closest('.ranau-yandex-courier-selector'));
        }
    });

    document.addEventListener('input', function (event) {
        if (event.target && event.target.matches('.ranau-yandex-courier-address')) {
            markAddressDirty(event.target);
        }
    });

    document.addEventListener('change', function (event) {
        if (!event.target) {
            return;
        }
        if (event.target.matches('input[type="radio"], input.shipping_method')) {
            scheduleRender();
        }
        if (event.target.matches('#shipping-city, #shipping_city, input[name="shipping_city"], input[autocomplete="address-level2"]')) {
            state.selection = null;
            state.quote = null;
            state.address = '';
            state.dirty = false;
            post('/selection', {quote_id: '', clear_quote: true}).catch(function () { return null; });
            scheduleRender();
        }
    });

    document.addEventListener('updated_checkout', scheduleRender);
    document.addEventListener('wc-blocks_added_to_cart', scheduleRender);
    document.addEventListener('ranauCheckout:cityContextChanged', function () {
        state.selection = null;
        state.quote = null;
        state.address = '';
        state.dirty = false;
        scheduleRender();
        document.dispatchEvent(new CustomEvent('ranauCheckout:deliveryStateChanged', {
            detail: {method: 'ranau_yandex_courier', complete: false}
        }));
    });
    window.setInterval(scheduleRender, 750);
    scheduleRender();
}());
