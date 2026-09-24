(function () {
    'use strict';

    var config = window.RanauYandexDelivery || {};
    var commitAdapter = window.RanauYandexDeliveryCommitV1.create({
        methodId: 'ranau_yandex_pickup',
        namespace: config.storeApiNamespace || 'ranau-yandex-delivery-for-woocommerce',
        commitUrl: config.commitUrl || ((config.restUrl || '') + '/commit'),
        nonce: config.restNonce || '',
        packageFingerprint: config.packageFingerprint || ''
    });
    var state = {
        point: config.selectedPoint || null,
        quote: config.quote || null,
        modal: null,
        widgetStarted: false,
        renderQueued: false,
        saving: false,
        widgetObserver: null,
        widgetIdleTimer: null,
        widgetSlowTimer: null,
        widgetResizeTimer: null,
        widgetHeight: 0,
        loadingStartedAt: 0,
        loadingMinimumUntil: 0
    };

    function escapeHtml(value) {
        var node = document.createElement('div');
        node.textContent = String(value || '');
        return node.innerHTML;
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

    function toggleClass(node, name, enabled) {
        if (node && node.classList.contains(name) !== Boolean(enabled)) {
            node.classList.toggle(name, Boolean(enabled));
        }
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
        return config.defaultCity || 'Москва';
    }

    function isReady() {
        return Boolean(state.point && state.point.id && state.quote && state.quote.ok && state.quote.price !== null);
    }

    function pointAddress(point) {
        var address = point && point.address ? point.address : {};
        return address.full_address || [address.locality, address.street, address.house].filter(Boolean).join(', ');
    }

    function quoteSummary() {
        if (!state.point || !state.point.id) {
            return 'Пункт выдачи пока не выбран';
        }
        if (isReady()) {
            var price = state.quote.free_shipping
                ? 'Бесплатно'
                : Number(state.quote.price).toLocaleString('ru-RU', {minimumFractionDigits: 0, maximumFractionDigits: 2}) + ' ₽';
            var days = Number(state.quote.days || 0);
            return pointAddress(state.point) + ' · ' + price + (days ? ' · ' + days + ' дн.' : '');
        }
        return pointAddress(state.point) + ' · ' + ((state.quote && state.quote.message) || 'Стоимость пока недоступна');
    }

    function selectorHtml(rateValue) {
        return [
            '<div class="ranau-yandex-delivery-for-woocommerce-selector ranau-yandex-delivery-for-woocommerce-blocks-selector" data-ranau-yandex-delivery-for-woocommerce-rate="' + escapeHtml(rateValue) + '" data-ranau-delivery-method="ranau_yandex_pickup" data-ranau-delivery-complete="0">',
            '<div class="ranau-yandex-delivery-for-woocommerce-selector__head">',
            '<div><strong>ПУНКТ ВЫДАЧИ ЯНДЕКСА</strong><span>Выберите удобный ПВЗ или постамат на карте.</span></div>',
            '<button type="button" class="ranau-yandex-delivery-for-woocommerce-open">Выбрать ПВЗ</button>',
            '</div>',
            '<div class="ranau-yandex-delivery-for-woocommerce-selected" aria-live="polite">Пункт выдачи пока не выбран</div>',
            '<button type="button" class="ranau-yandex-delivery-for-woocommerce-clear" hidden>Сбросить выбор</button>',
            '</div>'
        ].join('');
    }

    function updateSelector(root) {
        if (!root) {
            return;
        }
        setAttribute(root, 'data-ranau-delivery-complete', isReady() ? '1' : '0');
        var summary = root.querySelector('.ranau-yandex-delivery-for-woocommerce-selected');
        var button = root.querySelector('.ranau-yandex-delivery-for-woocommerce-open');
        var clear = root.querySelector('.ranau-yandex-delivery-for-woocommerce-clear');
        if (summary) {
            setText(summary, quoteSummary());
            toggleClass(summary, 'is-error', Boolean(state.point && !isReady()));
            toggleClass(summary, 'is-ready', isReady());
        }
        if (button) {
            setText(button, state.point && state.point.id ? 'Изменить ПВЗ' : 'Выбрать ПВЗ');
        }
        if (clear) {
            var hidden = !(state.point && state.point.id);
            if (clear.hidden !== hidden) {
                clear.hidden = hidden;
            }
        }
    }

    function refreshRatePresentation() {
        document.querySelectorAll('input[type="radio"][value^="ranau_yandex_pickup"]').forEach(function (radio) {
            var option = radio.closest('label.wc-block-components-radio-control__option, label');
            var target = option ? option.querySelector('.wc-block-components-radio-control__secondary-label') : null;
            if (!target) {
                return;
            }
            var value = isReady()
                ? (state.quote.free_shipping ? 'Бесплатно' : Number(state.quote.price).toLocaleString('ru-RU', {maximumFractionDigits: 2}) + ' ₽')
                : (state.point && state.point.id ? 'Стоимость пока недоступна' : 'Рассчитаем после выбора ПВЗ');
            setAttribute(target, 'data-ranau-yandex-delivery-for-woocommerce-rate-label', value);
            setAttribute(target, 'data-ranau-yandex-delivery-for-woocommerce-rate-ready', isReady() ? '1' : '0');
            setAttribute(target, 'aria-label', value);
        });
    }

    function renderSelectors() {
        state.renderQueued = false;
        document.querySelectorAll('.ranau-yandex-delivery-for-woocommerce-blocks-selector').forEach(function (selector) {
            var value = selector.getAttribute('data-ranau-yandex-delivery-for-woocommerce-rate') || '';
            var radio = document.querySelector('input[type="radio"][value="' + cssEscape(value) + '"]');
            if (!radio || !radio.checked) {
                selector.remove();
            }
        });

        document.querySelectorAll('input[type="radio"][value^="ranau_yandex_pickup"]').forEach(function (radio) {
            var option = radio.closest('label.wc-block-components-radio-control__option, label');
            if (!option || !radio.checked) {
                return;
            }
            var value = radio.value || '';
            var selector = document.querySelector('.ranau-yandex-delivery-for-woocommerce-blocks-selector[data-ranau-yandex-delivery-for-woocommerce-rate="' + cssEscape(value) + '"]');
            if (!selector) {
                option.insertAdjacentHTML('afterend', selectorHtml(value));
                selector = option.nextElementSibling;
            }
            updateSelector(selector);
        });

        document.querySelectorAll('[data-ranau-yandex-delivery-for-woocommerce-classic]').forEach(function (selector) {
            var row = selector.closest('li, tr') || document;
            var radio = row.querySelector('input.shipping_method[value^="ranau_yandex_pickup"]');
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

    function cssEscape(value) {
        return window.CSS && typeof window.CSS.escape === 'function'
            ? window.CSS.escape(String(value || ''))
            : String(value || '').replace(/["\\]/g, '\\$&');
    }

    function ensureModal() {
        if (state.modal) {
            return state.modal;
        }
        var modal = document.createElement('div');
        modal.className = 'ranau-yandex-delivery-for-woocommerce-modal';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-label', 'Выберите пункт выдачи Яндекс Доставки');
        modal.innerHTML = [
            '<div class="ranau-yandex-delivery-for-woocommerce-dialog">',
            '<div class="ranau-yandex-delivery-for-woocommerce-toolbar"><strong>Выберите пункт выдачи Яндекса</strong><button type="button" class="ranau-yandex-delivery-for-woocommerce-close" aria-label="Закрыть">×</button></div>',
            '<div class="ranau-yandex-delivery-for-woocommerce-widget-shell">',
            '<div id="ranau-yandex-delivery-for-woocommerce-widget" class="ranau-yandex-delivery-for-woocommerce-widget"></div>',
            '<div class="ranau-yandex-delivery-for-woocommerce-loading" role="status" aria-live="polite" aria-hidden="true">',
            '<span class="ranau-yandex-delivery-for-woocommerce-spinner" aria-hidden="true"></span>',
            '<strong class="ranau-yandex-delivery-for-woocommerce-loading__title">Загружаем пункты выдачи</strong>',
            '<span class="ranau-yandex-delivery-for-woocommerce-loading__hint">Обычно это занимает несколько секунд</span>',
            '</div>',
            '</div>',
            '<div class="ranau-yandex-delivery-for-woocommerce-modal-status" aria-live="polite"></div>',
            '</div>'
        ].join('');
        document.body.appendChild(modal);
        state.modal = modal;
        observeWidgetLoading();
        return modal;
    }

    function setModalStatus(message) {
        var status = state.modal ? state.modal.querySelector('.ranau-yandex-delivery-for-woocommerce-modal-status') : null;
        if (status) {
            status.textContent = message || '';
        }
    }

    function widgetElement() {
        return state.modal ? state.modal.querySelector('#ranau-yandex-delivery-for-woocommerce-widget') : null;
    }

    function setWidgetLoading(loading, title, hint) {
        var overlay = state.modal ? state.modal.querySelector('.ranau-yandex-delivery-for-woocommerce-loading') : null;
        if (!overlay) {
            return;
        }

        window.clearTimeout(state.widgetSlowTimer);
        if (loading) {
            state.loadingStartedAt = Date.now();
            state.loadingMinimumUntil = state.loadingStartedAt + 1800;
            overlay.classList.add('is-active');
            overlay.setAttribute('aria-hidden', 'false');
            overlay.querySelector('.ranau-yandex-delivery-for-woocommerce-loading__title').textContent = title || 'Загружаем пункты выдачи';
            overlay.querySelector('.ranau-yandex-delivery-for-woocommerce-loading__hint').textContent = hint || 'Обычно это занимает несколько секунд';
            state.widgetSlowTimer = window.setTimeout(function () {
                if (!overlay.classList.contains('is-active')) {
                    return;
                }
                overlay.querySelector('.ranau-yandex-delivery-for-woocommerce-loading__title').textContent = 'Пункты выдачи еще загружаются';
                overlay.querySelector('.ranau-yandex-delivery-for-woocommerce-loading__hint').textContent = 'Соединение медленнее обычного — подождите еще немного';
            }, 7000);
            return;
        }

        overlay.classList.remove('is-active');
        overlay.setAttribute('aria-hidden', 'true');
        setModalStatus('');
    }

    function scheduleWidgetSettled(minimumDelay) {
        window.clearTimeout(state.widgetIdleTimer);
        var elapsed = state.loadingStartedAt ? Date.now() - state.loadingStartedAt : 0;
        var minimumVisibleDelay = Math.max(0, state.loadingMinimumUntil - Date.now());
        var delay = Math.max(Number(minimumDelay || 700), 1100 - elapsed, minimumVisibleDelay);

        state.widgetIdleTimer = window.setTimeout(function () {
            var widget = widgetElement();
            if (!widget || widget.childElementCount === 0) {
                return;
            }
            setWidgetLoading(false);
        }, delay);
    }

    function observeWidgetLoading() {
        var widget = widgetElement();
        if (!widget || state.widgetObserver || typeof MutationObserver !== 'function') {
            return;
        }

        state.widgetObserver = new MutationObserver(function () {
            scheduleWidgetSettled(800);
        });
        state.widgetObserver.observe(widget, {childList: true, subtree: true});

        widget.addEventListener('input', function () {
            setWidgetLoading(true, 'Ищем пункты выдачи', 'Обновляем точки рядом с новым адресом');
            scheduleWidgetSettled(1600);
        }, true);
        widget.addEventListener('change', function () {
            setWidgetLoading(true, 'Обновляем пункты выдачи', 'Проверяем доступные точки на карте');
            scheduleWidgetSettled(1600);
        }, true);
    }

    function availableWidgetHeight() {
        var viewportHeight = window.visualViewport && window.visualViewport.height
            ? window.visualViewport.height
            : window.innerHeight;
        var toolbar = state.modal ? state.modal.querySelector('.ranau-yandex-delivery-for-woocommerce-toolbar') : null;
        var status = state.modal ? state.modal.querySelector('.ranau-yandex-delivery-for-woocommerce-modal-status') : null;
        var chromeHeight = (toolbar ? toolbar.getBoundingClientRect().height : 64) +
            (status ? status.getBoundingClientRect().height : 42);

        return Math.max(320, Math.min(540, Math.floor(viewportHeight - chromeHeight)));
    }

    function startWidget() {
        if (!window.YaDelivery || typeof window.YaDelivery.createWidget !== 'function') {
            return;
        }
        state.widgetHeight = availableWidgetHeight();
        setWidgetLoading(true);
        window.YaDelivery.createWidget({
            containerId: 'ranau-yandex-delivery-for-woocommerce-widget',
            params: {
                city: currentCity(),
                size: {height: state.widgetHeight + 'px', width: '100%'},
                show_select_button: true,
                filter: {
                    type: ['pickup_point', 'terminal'],
                    operator_ids: ['market_l4g'],
                    payment_methods: ['postpay', 'already_paid'],
                    payment_methods_filter: 'or'
                }
            }
        });
        state.widgetStarted = true;
        scheduleWidgetSettled(1800);
    }

    function resizeOpenWidget() {
        if (!state.modal || !state.modal.classList.contains('is-open') || !state.widgetStarted) {
            return;
        }

        window.clearTimeout(state.widgetResizeTimer);
        state.widgetResizeTimer = window.setTimeout(function () {
            var nextHeight = availableWidgetHeight();
            if (Math.abs(nextHeight - state.widgetHeight) < 24) {
                return;
            }
            startWidget();
        }, 180);
    }

    function loadWidget() {
        if (window.YaDelivery) {
            startWidget();
            return;
        }
        if (!document.querySelector('script[data-ranau-yandex-delivery-for-woocommerce-widget]')) {
            var script = document.createElement('script');
            script.async = true;
            script.src = 'https://widget-pvz.dostavka.yandex.net/widget.js?v=2';
            script.setAttribute('data-ranau-yandex-delivery-for-woocommerce-widget', '1');
            script.onerror = function () {
                setWidgetLoading(false);
                setModalStatus('Не удалось загрузить карту ПВЗ. Проверьте соединение и попробуйте снова.');
            };
            document.head.appendChild(script);
        }
        setWidgetLoading(true);
        setModalStatus('Загружаем карту пунктов выдачи…');
    }

    function openModal() {
        var modal = ensureModal();
        modal.classList.add('is-open');
        document.body.classList.add('ranau-yandex-delivery-for-woocommerce-modal-open');
        if (state.widgetStarted && window.YaDelivery && typeof window.YaDelivery.setParams === 'function') {
            setWidgetLoading(true, 'Обновляем пункты выдачи', 'Проверяем доступные точки для выбранного города');
            window.YaDelivery.setParams({city: currentCity()});
            scheduleWidgetSettled(1600);
        } else {
            loadWidget();
        }
    }

    function closeModal() {
        if (!state.modal) {
            return;
        }
        state.modal.classList.remove('is-open');
        document.body.classList.remove('ranau-yandex-delivery-for-woocommerce-modal-open');
    }

    function normalizePoint(detail) {
        detail = detail || {};
        return {
            id: String(detail.id || ''),
            type: String(detail.type || 'pickup_point'),
            address: detail.address || {},
            position: Array.isArray(detail.position) ? detail.position : [],
            payment_methods: Array.isArray(detail.payment_methods) ? detail.payment_methods : []
        };
    }

    function persistPoint(point) {
        state.saving = true;
        var calculation = null;
        if (point && point.id) {
            try {
                calculation = commitAdapter.begin(point);
            } catch (error) {
                state.saving = false;
                setModalStatus('Сначала выберите способ доставки Яндекса.');
                return Promise.reject(error);
            }
        }
        setModalStatus(point && point.id ? 'Рассчитываем стоимость доставки…' : '');
        return fetch((config.restUrl || '') + '/selection', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': config.restNonce || ''
            },
            body: JSON.stringify(point || {})
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('selection_save_failed');
            }
            return response.json();
        }).then(function (result) {
            if (!result.point || !result.point.id) {
                state.point = null;
                state.quote = null;
                return refreshCart(null);
            }
            return commitAdapter.commit(calculation, result.commit, result.quote).then(function () {
                state.point = result.point;
                state.point._ranau_committed = true;
                state.quote = result.quote || null;
            });
        }).then(function () {
            state.saving = false;
            closeModal();
            scheduleRender();
            document.dispatchEvent(new CustomEvent('ranauCheckout:deliveryStateChanged', {
                detail: {method: 'ranau_yandex_pickup', complete: isReady()}
            }));
        }).catch(function () {
            state.saving = false;
            setModalStatus('Не удалось сохранить выбранный ПВЗ. Попробуйте еще раз.');
        });
    }

    function refreshCart(point) {
        var result = Promise.resolve();
        if (window.wc && window.wc.blocksCheckout && typeof window.wc.blocksCheckout.extensionCartUpdate === 'function') {
            result = window.wc.blocksCheckout.extensionCartUpdate({
                namespace: config.storeApiNamespace || 'ranau-yandex-delivery-for-woocommerce',
                data: {point_id: point && point.id ? point.id : ''}
            });
        } else if (window.jQuery && document.body) {
            window.jQuery(document.body).trigger('update_checkout');
        }

        return Promise.resolve(result).catch(function () {
            return null;
        });
    }

    document.addEventListener('YaNddWidgetLoad', startWidget);
    document.addEventListener('YaNddWidgetPointSelected', function (event) {
        if (!state.modal || !state.modal.classList.contains('is-open') || state.saving) {
            return;
        }
        var point = normalizePoint(event.detail);
        if (point.id) {
            persistPoint(point);
        }
    });

    document.addEventListener('click', function (event) {
        var open = event.target.closest('.ranau-yandex-delivery-for-woocommerce-open');
        if (open) {
            event.preventDefault();
            openModal();
            return;
        }
        if (event.target.closest('.ranau-yandex-delivery-for-woocommerce-close') || (state.modal && event.target === state.modal)) {
            event.preventDefault();
            closeModal();
            return;
        }
        if (event.target.closest('.ranau-yandex-delivery-for-woocommerce-clear')) {
            event.preventDefault();
            persistPoint(null);
        }
    });

    document.addEventListener('change', function (event) {
        if (event.target && event.target.matches('input[type="radio"], input.shipping_method, #shipping_city, input[name="shipping_city"]')) {
            scheduleRender();
        }
    });
    document.addEventListener('updated_checkout', scheduleRender);
    document.addEventListener('wc-blocks_added_to_cart', scheduleRender);
    document.addEventListener('ranauCheckout:cityContextChanged', function () {
        state.point = null;
        state.quote = null;
        closeModal();
        scheduleRender();
        document.dispatchEvent(new CustomEvent('ranauCheckout:deliveryStateChanged', {
            detail: {method: 'ranau_yandex_pickup', complete: false}
        }));
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeModal();
        }
    });

    window.addEventListener('resize', resizeOpenWidget);
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', resizeOpenWidget);
    }

    window.setInterval(scheduleRender, 750);
    scheduleRender();
}());
