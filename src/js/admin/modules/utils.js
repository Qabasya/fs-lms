/**
 * Утилиты для плагина FS-LMS.
 * @requires jQuery
 */
const $ = window.jQuery || jQuery;

/**
 * Экранирует строку для безопасной вставки в HTML.
 * @param {string} str - Исходная строка
 * @returns {string} Экранированная строка
 * @private
 */
export function escapeHtml(str) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    };
    return String(str).replace(/[&<>"']/g, (m) => map[m]);
}

/**
 * Переключает кнопку в режим загрузки/обычный режим.
 * @param {JQuery} $btn - jQuery-объект кнопки
 * @param {boolean} isLoading - true: блокировка и текст загрузки, false: восстановление
 * @param {string} [loadingText='...'] - Текст для режима загрузки
 */
export function toggleButton($btn, isLoading, loadingText = '...') {
    if (!$btn || !$btn.jquery) {
        console.error('[Utils.toggleButton] Invalid jQuery object', $btn);
        return;
    }

    if (isLoading) {
        $btn.data('original-text', $btn.html())
            .prop('disabled', true)
            .text(loadingText);
    } else {
        $btn.prop('disabled', false)
            .html($btn.data('original-text'));
    }
}

/**
 * Логирует ошибку API и показывает уведомление.
 * @param {Error|Object|string} error - Объект или текст ошибки
 * @param {Object} [options] - Настройки
 * @param {boolean} [options.silent=false] - Не показывать уведомление пользователю
 * @param {Function} [options.onNotify] - Кастомная функция для показа ошибки (вместо alert)
 */
export function apiError(error, options = {}) {
    const { silent = false, onNotify = null } = options;

    console.error('FS-LMS API Error:', error);

    if (!silent) {
        const message = 'Произошла ошибка при связи с сервером.';
        if (typeof onNotify === 'function') {
            onNotify(message, 'error');
        } else {
            alert(message);
        }
    }
}

/**
 * Расширенное переключение кнопки с поддержкой коллбеков.
 * @param {JQuery} $btn - jQuery-объект кнопки
 * @param {boolean} isLoading - Флаг режима загрузки
 * @param {Object} [options] - Настройки
 * @param {string} [options.loadingText='...']
 * @param {string} [options.successText] - Временный текст после успеха
 * @param {number} [options.successDuration=2000] - Длительность показа успеха (мс)
 * @param {Function} [options.onComplete] - Коллбек после завершения
 */
export function toggleButtonExtended($btn, isLoading, options = {}) {
    const {
        loadingText = '...',
        successText = null,
        successDuration = 2000,
        onComplete = null
    } = options;

    if (isLoading) {
        $btn.data('original-text', $btn.html())
            .prop('disabled', true)
            .text(loadingText);
    } else {
        $btn.prop('disabled', false)
            .html($btn.data('original-text'));

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
 * Обработка ошибки API с гибким выводом сообщения.
 * @param {Error|Object|string} error - Объект ошибки
 * @param {Object} [options] - Настройки
 * @param {boolean} [options.silent=false] - Не показывать уведомление пользователю
 * @param {Function} [options.onError] - Коллбек с текстом ошибки
 * @param {Function} [options.onNotify] - Кастомная функция для показа ошибки
 * @returns {string} Человекочитаемое сообщение об ошибке
 */
export function apiErrorEnhanced(error, options = {}) {
    const { silent = false, onError = null, onNotify = null } = options;
    let userMessage = 'Произошла ошибка при связи с сервером.';

    if (typeof error === 'string') {
        userMessage = error;
    } else if (error && typeof error === 'object') {
        userMessage = error.message ||
            error.responseJSON?.message ||
            error.statusText ||
            userMessage;

        if (error.responseJSON?.data) {
            console.debug('[API Error Details]:', error.responseJSON.data);
        }
        if (error.code) {
            console.debug('[API Error Code]:', error.code);
        }
    }

    console.error('[FS-LMS API Error]:', error);
    console.debug('[User Message]:', userMessage);

    if (!silent) {
        if (typeof onNotify === 'function') {
            onNotify(userMessage, 'error');
        } else {
            alert(userMessage);
        }
    }

    if (typeof onError === 'function') {
        onError(userMessage);
    }

    return userMessage;
}

/**
 * Показывает уведомление в стиле WordPress Admin Notice.
 * @param {string} message - Текст сообщения
 * @param {'success'|'error'|'warning'|'info'} [type='info'] - Тип уведомления
 * @param {JQuery|string|null} [$container=null] - Контейнер для вставки (по умолчанию $('body'))
 * @param {Object} [options] - Дополнительные настройки
 * @param {boolean} [options.autoDismiss=true] - Автозакрытие для типа 'success'
 * @param {number} [options.autoDismissDelay=1000] - Задержка автозакрытия (мс)
 * @param {boolean} [options.escape=true] - Экранировать сообщение для защиты от XSS
 * @returns {JQuery} Созданный элемент уведомления
 */
export function showNotice(message, type = 'info', $container = null, options = {}) {
    const { autoDismiss = true, autoDismissDelay = 1000, escape = true } = options;

    // Разрешаем $container только после готовности DOM
    if (!$container) {
        $container = $('body');
    } else if (!($container instanceof $)) {
        $container = $($container);
    }
    if (!$container.length) {
        $container = $('body');
    }

    $container.find('.fs-notice').remove();

    const labels = {
        success: 'Готово!',
        error: 'Ошибка:',
        warning: 'Внимание:',
        info: 'Информация:',
    };
    const title = labels[type] || labels.info;
    const safeMessage = escape ? escapeHtml(message) : message;

    const $notice = $(`
        <div class="notice notice-${type} is-dismissible fs-notice" style="margin: 10px 0;">
            <p><strong>${title}</strong> ${safeMessage}</p>
            <button type="button" class="notice-dismiss">
                <span class="screen-reader-text">Закрыть</span>
            </button>
        </div>
    `);

    $notice.on('click', '.notice-dismiss', function () {
        $notice.fadeTo(100, 0, function () {
            $notice.slideUp(100, function () {
                $(this).remove();
            });
        });
    });

    $container.prepend($notice);

    if (type === 'success' && autoDismiss) {
        setTimeout(() => {
            if ($notice.is(':visible')) {
                $notice.find('.notice-dismiss').trigger('click');
            }
        }, autoDismissDelay);
    }

    return $notice;
}

/**
 * Анимирует удаление строки таблицы: подсвечивает красным и скрывает.
 * @param {JQuery} $row - Строка таблицы
 * @param {Function} [onRemoved] - Коллбек после удаления из DOM
 */
export function fadeDeleteRow($row, onRemoved) {
    $row.css('background', '#ff8d8d').fadeOut(400, function () {
        $(this).remove();
        if (typeof onRemoved === 'function') onRemoved();
    });
}