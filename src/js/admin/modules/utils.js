/**
 * @fileoverview Утилиты для работы с плагином FS-LMS.
 * @description Набор вспомогательных функций для управления состоянием кнопок,
 *              обработки ошибок API и других общих задач.
 * @requires jQuery - глобальная зависимость WordPress.
 */
const $ = window.jQuery || jQuery;
/**
 * Объект с вспомогательными утилитами для плагина.
 * @namespace Utils
 * @typedef {Object} Utils
 */
export const Utils = {
    /**
     * Переключает состояние кнопки между загрузкой и обычным режимом.
     * При включении режима загрузки кнопка блокируется, текст меняется на указанный,
     * а оригинальный текст сохраняется в data-атрибуте для последующего восстановления.
     *
     * @memberof Utils
     * @instance
     * @param {jQuery} $btn - jQuery-объект кнопки, состояние которой нужно переключить.
     * @param {boolean} isLoading - Флаг состояния: true - включить режим загрузки,
     *                               false - выключить режим загрузки и восстановить оригинальный текст.
     * @param {string} [loadingText='...'] - Текст, отображаемый на кнопке в режиме загрузки.
     *                                        По умолчанию используется '...'.
     * @returns {void}
     *
     * @example
     * // Включение режима загрузки
     * Utils.toggleButton($('#submit-btn'), true, 'Сохранение...');
     *
     * @example
     * // Выключение режима загрузки (восстановление исходного состояния)
     * Utils.toggleButton($('#submit-btn'), false);
     *
     * @example
     * // Использование с AJAX-запросом
     * const $btn = $('#save-settings');
     * Utils.toggleButton($btn, true, 'Отправка...');
     * $.ajax({
     *     url: '/api/save',
     *     complete: () => Utils.toggleButton($btn, false)
     * });
     */
    toggleButton($btn, isLoading, loadingText = '...') {
        // Проверяем, что передан корректный jQuery-объект
        if (!$btn || !$btn.jquery) {
            console.error('[Utils.toggleButton] Ошибка: передан некорректный jQuery-объект', $btn);
            return;
        }

        if (isLoading) {
            /**
             * Включение режима загрузки:
             * 1. Сохраняем оригинальный HTML-контент кнопки в data-атрибут 'original-text'
             * 2. Блокируем кнопку, чтобы предотвратить повторные клики
             * 3. Устанавливаем текст загрузки
             */
            $btn.data('original-text', $btn.html())
                .prop('disabled', true)
                .text(loadingText);
        } else {
            /**
             * Выключение режима загрузки:
             * 1. Разблокируем кнопку
             * 2. Восстанавливаем оригинальный HTML-контент из data-атрибута
             * Примечание: используем .html() вместо .text(), чтобы восстановить возможные HTML-теги
             */
            $btn.prop('disabled', false)
                .html($btn.data('original-text'));
        }
    },

    /**
     * Обрабатывает ошибки API-запросов.
     * Выводит детальную информацию об ошибке в консоль браузера и показывает
     * понятное пользователю сообщение через alert.
     *
     * @memberof Utils
     * @instance
     * @param {Error|Object|string} error - Объект ошибки, полученный от API.
     *                                       Может быть экземпляром Error, объектом ответа сервера
     *                                       или строковым сообщением.
     * @returns {void}
     *
     * @example
     * // Обработка ошибки в catch блоке
     * try {
     *     const response = await fetch('/api/data');
     *     if (!response.ok) throw new Error('Сервер вернул ошибку');
     * } catch (error) {
     *     Utils.apiError(error);
     * }
     *
     * @example
     * // Использование с jQuery.ajax
     * $.ajax({
     *     url: '/api/save',
     *     error: (xhr, status, error) => Utils.apiError(error)
     * });
     *
     * @example
     * // Обработка ошибки с дополнительной информацией
     * $.ajax({
     *     url: '/api/submit',
     *     error: (xhr) => {
     *         const message = xhr.responseJSON?.message || xhr.statusText;
     *         Utils.apiError(message);
     *     }
     * });
     */
    apiError(error) {
        /**
         * Вывод детальной информации об ошибке в консоль для разработчиков.
         * Используется префикс 'FS-LMS API Error:' для удобной фильтрации логов.
         *
         * @type {Error|Object|string}
         */
        console.error('FS-LMS API Error:', error);

        /**
         * Показываем простое и понятное сообщение пользователю через системный alert.
         * В production-версии рекомендуется заменить на более дружелюбное уведомление
         * (например, всплывающий toast или сообщение внутри интерфейса).
         *
         * @type {string}
         */
        alert('Произошла ошибка при связи с сервером.');
    },
};

/**
 * @typedef {Object} ButtonState
 * @property {string} originalText - Оригинальный текст кнопки, сохранённый в data-атрибуте
 * @property {boolean} isDisabled - Флаг блокировки кнопки
 * @property {string} currentText - Текущий текст кнопки
 */

/**
 * @typedef {Object} ApiErrorObject
 * @property {string} message - Человекочитаемое сообщение об ошибке
 * @property {number} [code] - Код ошибки HTTP или внутренний код ошибки
 * @property {Object} [data] - Дополнительные данные об ошибке
 * @property {string} [type] - Тип ошибки (например, 'validation', 'server', 'network')
 */

/**
 * Расширенная версия утилиты для работы с кнопками с дополнительными возможностями.
 * @function toggleButtonExtended
 * @description Альтернативная реализация с поддержкой callback-функций и Promise.
 * @param {jQuery} $btn - jQuery-объект кнопки.
 * @param {boolean} isLoading - Флаг загрузки.
 * @param {Object} [options] - Дополнительные опции.
 * @param {string} [options.loadingText='...'] - Текст в режиме загрузки.
 * @param {string} [options.successText] - Текст при успешном завершении (временный).
 * @param {number} [options.successDuration=2000] - Длительность отображения successText в мс.
 * @param {Function} [options.onComplete] - Callback после завершения загрузки.
 * @returns {void}
 *
 * @example
 * Utils.toggleButtonExtended($('#submit'), true, {
 *     loadingText: 'Отправка...',
 *     successText: 'Готово!',
 *     successDuration: 1500,
 *     onComplete: () => console.log('Готово')
 * });
 */
export function toggleButtonExtended($btn, isLoading, options = {}) {
    const {
        loadingText = '...',
        successText = null,
        successDuration = 2000,
        onComplete = null
    } = options;

    if (isLoading) {
        // Сохраняем оригинальный текст и переключаем в режим загрузки
        $btn.data('original-text', $btn.html())
            .prop('disabled', true)
            .text(loadingText);
    } else {
        // Выходим из режима загрузки
        $btn.prop('disabled', false)
            .html($btn.data('original-text'));

        // Если указан текст успеха, временно показываем его
        if (successText) {
            const originalText = $btn.html();
            $btn.text(successText);
            setTimeout(() => {
                $btn.html(originalText);
                if (typeof onComplete === 'function') onComplete();
            }, successDuration);
        } else if (typeof onComplete === 'function') {
            onComplete();
        }
    }
}

/**
 * Улучшенная обработка ошибок API с поддержкой различных форматов ответа.
 * @function apiErrorEnhanced
 * @param {Error|Object|string} error - Объект ошибки.
 * @param {Object} [options] - Дополнительные опции.
 * @param {boolean} [options.silent=false] - Если true, не показывать alert пользователю.
 * @param {Function} [options.onError] - Callback при ошибке.
 * @returns {string} - Человекочитаемое сообщение об ошибке.
 *
 * @example
 * const errorMessage = Utils.apiErrorEnhanced(error, {
 *     silent: true,
 *     onError: (msg) => showToast(msg)
 * });
 */
export function apiErrorEnhanced(error, options = {}) {
    const { silent = false, onError = null } = options;

    let userMessage = 'Произошла ошибка при связи с сервером.';

    // Пытаемся извлечь понятное сообщение из различных форматов ошибки
    if (typeof error === 'string') {
        userMessage = error;
    } else if (error && typeof error === 'object') {
        // Обработка разных форматов ошибок
        userMessage = error.message ||
            error.responseJSON?.message ||
            error.statusText ||
            userMessage;

        // Логируем дополнительные детали для разработчиков
        if (error.responseJSON?.data) {
            console.debug('[API Error Details]:', error.responseJSON.data);
        }
        if (error.code) {
            console.debug('[API Error Code]:', error.code);
        }
    }

    // Выводим в консоль для разработчиков
    console.error('[FS-LMS API Error]:', error);
    console.debug('[User Message]:', userMessage);

    // Показываем пользователю, если не запрошен silent режим
    if (!silent) {
        alert(userMessage);
    }

    // Вызываем колбэк, если он предоставлен
    if (typeof onError === 'function') {
        onError(userMessage);
    }

    return userMessage;
}

/**
 * Показывает уведомление в стиле WordPress Admin Notice.
 * Автоматически удаляет предыдущие уведомления в том же контейнере.
 * @param {string} message Текст сообщения
 * @param {'success'|'error'|'warning'|'info'} [type='info'] Тип уведомления
 * @param {JQuery|string} [$container] Контейнер для вставки (по умолчанию $('body'))
 * @param {Object} [options] Дополнительные настройки
 * @param {boolean} [options.autoDismiss=true] Автозакрытие для успешных сообщений
 * @param {number} [options.autoDismissDelay=1000] Задержка перед автозакрытием (мс)
 * @returns {JQuery} Созданный элемент уведомления (для цепочек или ручного управления)
 */
export function showNotice(message, type = 'info', $container = $('body'), options = {}) {
    const { autoDismiss = true, autoDismissDelay = 1000 } = options;

    // Нормализуем контейнер
    $container = $container instanceof $ ? $container : $($container);
    if (!$container.length) $container = $('body');

    // Удаляем старые уведомления в этом контейнере
    $container.find('.fs-notice').remove();

    const labels = {
        success: 'Готово!',
        error: 'Ошибка:',
        warning: 'Внимание:',
        info: 'Информация:',
    };
    const title = labels[type] || labels.info;

    const $notice = $(`
        <div class="notice notice-${type} is-dismissible fs-notice" style="margin: 10px 0;">
            <p><strong>${title}</strong> ${message}</p>
            <button type="button" class="notice-dismiss">
                <span class="screen-reader-text">Закрыть</span>
            </button>
        </div>
    `);

    // Обработчик закрытия с анимацией
    $notice.on('click', '.notice-dismiss', function () {
        $notice.fadeTo(100, 0, function () {
            $notice.slideUp(100, function () {
                $(this).remove();
            });
        });
    });

    $container.prepend($notice);

    // Автозакрытие для успеха (если не отключено)
    if (type === 'success' && autoDismiss) {
        setTimeout(() => {
            if ($notice.is(':visible')) {
                $notice.find('.notice-dismiss').trigger('click');
            }
        }, autoDismissDelay);
    }

    return $notice;
}
