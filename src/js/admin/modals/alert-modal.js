/**
 * @module AlertModal
 * @description Простое модальное окно для отображения предупреждений и ошибок.
 *              Реализует промисифицированный API (возвращает Promise),
 *              что позволяет использовать его в async/await конструкциях.
 *              Имеет fallback на стандартный alert() браузера,
 *              если HTML-разметка модалки отсутствует на странице.
 *
 * @requires jQuery
 */

const $ = jQuery;

const AlertModal = {
    /** @type {jQuery|null} Ссылка на DOM-элемент модального окна */
    $modal: null,

    /**
     * Инициализация компонента.
     * Находит и кэширует ссылку на модальное окно в DOM.
     */
    init() {
        this.$modal = $( '#fs-lms-alert-modal' );
    },

    /**
     * Отображение модального окна с сообщением.
     * Возвращает Promise, который разрешается при закрытии окна.
     * Это позволяет использовать модальное окно в асинхронном коде:
     * await AlertModal.show('Ошибка сохранения');
     *
     * @param {string} message - Текст сообщения для отображения.
     * @param {string} [title='Ошибка'] - Заголовок модального окна (по умолчанию 'Ошибка').
     * @returns {Promise<void>} Промис, разрешающийся при закрытии модалки.
     */
    show( message, title = 'Ошибка' ) {
        // FALLBACK: Если модалка не найдена в DOM (например, скрипт загружен,
        // но HTML-разметка отсутствует), используем стандартный alert браузера.
        // Это гарантирует, что сообщение всё равно будет показано пользователю.
        if ( ! this.$modal?.length ) {
            // eslint-disable-next-line no-alert
            alert( message );
            return Promise.resolve(); // Возвращаем разрешенный промис для совместимости API
        }

        // Заполняем заголовок и текст сообщения
        this.$modal.find( '.fs-lms-modal-title' ).text( title );
        this.$modal.find( '.fs-lms-modal-message' ).text( message );

        // ПАТТЕРН: Анимация появления через CSS-классы.
        // 1. Удаляем класс 'hidden' (display: none), чтобы элемент стал видимым в DOM
        this.$modal.removeClass( 'hidden' );

        // 2. Принудительный reflow: чтение offsetHeight заставляет браузер пересчитать layout.
        // Это необходимо для того, чтобы CSS-переход (transition) сработал корректно.
        // Без этой строки класс 'active' добавится синхронно с удалением 'hidden',
        // и браузер не увидит промежуточного состояния для анимации.
        // void используется, чтобы явно указать, что значение не используется (защита от ESLint).
        void this.$modal[ 0 ].offsetHeight;

        // 3. Добавляем класс 'active', который триггерит CSS-анимацию появления (opacity, transform и т.д.)
        this.$modal.addClass( 'active' );

        // Возвращаем Promise, который разрешится при закрытии модалки.
        // Это позволяет использовать модальное окно в async/await:
        // await AlertModal.show('Ошибка'); console.log('Модалка закрыта');
        return new Promise( ( resolve ) => {
            /**
             * Функция закрытия модального окна.
             * Очищает обработчики событий, запускает анимацию закрытия
             * и разрешает промис после завершения анимации.
             */
            const close = () => {
                // Удаляем обработчики событий с неймспейсами, чтобы избежать утечек памяти
                $( document ).off( 'keydown.alert_modal' );
                this.$modal.find( '.fs-lms-alert-modal-ok' ).off( 'click.alert' );

                // Запускаем анимацию закрытия (удаление класса 'active')
                this.$modal.removeClass( 'active' );

                // После завершения CSS-анимации (200мс) скрываем модалку через класс 'hidden'
                setTimeout( () => this.$modal.addClass( 'hidden' ), 200 );

                // Разрешаем промис, сигнализируя вызывающему коду, что модалка закрыта
                resolve();
            };

            // Обработчик клика по кнопке "OK"
            // Сначала удаляем старые обработчики (.off), затем добавляем новый (.on).
            // Это предотвращает дублирование обработчиков при повторных вызовах show().
            this.$modal.find( '.fs-lms-alert-modal-ok' )
                .off( 'click.alert' )
                .on( 'click.alert', close );

            // Обработчик клавиш Escape и Enter для закрытия модалки
            // Неймспейсинг 'keydown.alert_modal' позволяет точечно удалить этот обработчик
            $( document ).off( 'keydown.alert_modal' ).on( 'keydown.alert_modal', ( e ) => {
                if ( e.key === 'Escape' || e.key === 'Enter' ) {
                    close();
                }
            } );
        } );
    },
};

export { AlertModal };