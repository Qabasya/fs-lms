/**
 * @module ArchiveViewModalManager
 * @description Менеджер для управления процессом восстановления заявок из архива.
 *              Отвечает за:
 *              - Инициализацию модальных окон просмотра и восстановления
 *              - Обработку клика по кнопке восстановления с учетом наличия родительской заявки
 *              - Выполнение AJAX-запроса на восстановление
 *              - Копирование ссылки на присоединение в буфер обмена (при наличии)
 *              - Отображение уведомлений об успехе/ошибке и перезагрузку страницы
 *
 * @requires jQuery
 * @requires ArchiveViewModal - UI-компонент модального окна просмотра архива
 * @requires RestoreArchiveModal - UI-компонент модального окна выбора способа восстановления
 * @requires toggleButton, showNotice - утилиты для управления состоянием кнопок и показа уведомлений
 */

import { ArchiveViewModal } from '../../modals/enrollment/archive-view-modal.js';
import { RestoreArchiveModal } from '../../modals/enrollment/restore-archive-modal.js';
import { toggleButton, showNotice } from '../../modules/utils.js';

const $ = jQuery;

export const ArchiveViewModalManager = {

    /**
     * Инициализация менеджера.
     * Точка входа, вызывается при загрузке страницы.
     */
    init() {
        // Инициализируем оба модальных окна, так как они могут взаимодействовать друг с другом
        ArchiveViewModal.init();
        RestoreArchiveModal.init();

        this._bindEvents();
    },

    /**
     * Привязка обработчиков событий.
     */
    _bindEvents() {
        // Неймспейсинг событий: 'click.arc-restore'
        // Добавление суффикса '.arc-restore' позволяет в будущем точечно удалить 
        // именно этот обработчик через $(document).off('click.arc-restore'), 
        // не затрагивая другие обработчики клика, которые могут быть навешаны на document.
        $( document ).on( 'click.arc-restore', '.js-restore-from-archive', ( e ) => {
            e.preventDefault();

            const $btn      = $( e.currentTarget );
            const archiveId = $btn.data( 'archive-id' );

            // Оператор ?? (nullish coalescing) подставляет '0', если data-атрибут равен null или undefined.
            // Это предотвращает ошибки парсинга, если атрибут забыли добавить в HTML.
            const hasParent = parseInt( $btn.data( 'has-parent' ) ?? '0', 10 ) === 1;

            // Ранний выход, если ID отсутствует (защита от некорректных данных)
            if ( ! archiveId ) { return; }

            // RestoreArchiveModal.choose возвращает Promise.
            // Если пользователь подтверждает действие, разрешаем Promise объектом { withParent: boolean }.
            // Если отменяет, отклоняем (reject) со строкой 'cancel'.
            RestoreArchiveModal.choose( hasParent )
                .then( ( { withParent } ) => this._doRestore( archiveId, withParent, $btn ) )
                .catch( ( err ) => {
                    // Игнорируем штатную отмену действия пользователем.
                    // Любые другие ошибки (например, сбои в самой модалке) показываем как уведомление.
                    if ( err !== 'cancel' ) {
                        showNotice( String( err ), 'error', $( '.fs-lms-archive' ) );
                    }
                } );
        } );
    },

    /**
     * Выполнение AJAX-запроса на восстановление заявки из архива.
     *
     * @param {string|number} archiveId - ID записи в архиве.
     * @param {boolean} withParent - Флаг, указывающий, нужно ли восстанавливать вместе с родительской заявкой.
     * @param {jQuery|null} [$triggerBtn=null] - jQuery-объект кнопки, на которую нажали (для блокировки во время запроса).
     */
    _doRestore( archiveId, withParent, $triggerBtn = null ) {
        // Блокируем кнопку, если она была передана, чтобы предотвратить повторные клики
        if ( $triggerBtn ) { toggleButton( $triggerBtn, true, '...' ); }

        // Безопасное получение глобальных переменных. 
        // Если объект window.fs_lms_applications_vars по какой-то причине не определен, 
        // будет использован пустой объект {}, что предотвратит падение скрипта при обращении к vars.nonces.
        const vars = window.fs_lms_applications_vars ?? {};

        $.ajax( {
            url:    fs_lms_vars.ajaxurl,
            method: 'POST',
            data:   {
                action:      fs_lms_vars.ajax_actions.restoreFromArchive,
                archive_id:  archiveId,
                with_parent: withParent ? 1 : 0,
                // Безопасное получение nonce с fallback на пустую строку, если объект или свойство отсутствуют
                security:    vars.nonces?.restoreFromArchive ?? '',
            },
            success: ( res ) => {
                // Снимаем блокировку с кнопки по завершении запроса
                if ( $triggerBtn ) { toggleButton( $triggerBtn, false ); }

                if ( ! res.success ) {
                    showNotice( res.data || 'Ошибка восстановления.', 'error', $( '.fs-lms-archive' ) );
                    return;
                }

                // Извлекаем данные из успешного ответа с использованием fallback-значений
                const appId      = res.data?.id         ?? '';
                const joinUrl    = res.data?.join_url    ?? '';
                const parentName = res.data?.parent_name ?? '';

                // Формируем текст уведомления
                let msg = `Заявка #${ appId } создана.`;
                if ( parentName ) {
                    msg += ` Родитель: ${ parentName }.`;
                }

                // Попытка скопировать ссылку в буфер обмена.
                // Используется опциональная цепочка ?.writeText, так как API буфера обмена 
                // может быть недоступно (например, страница открыта не по HTTPS или заблокирована браузером).
                // Метод .catch( () => {} ) подавляет ошибку, если копирование не удалось, чтобы не ломать UX.
                if ( joinUrl ) {
                    navigator.clipboard?.writeText( joinUrl ).catch( () => {} );
                }

                // Показываем уведомление об успехе.
                // Параметры autoDismiss и autoDismissDelay заставляют уведомление исчезнуть автоматически через 2 секунды.
                showNotice( msg, 'success', $( '.fs-lms-archive' ), { autoDismiss: true, autoDismissDelay: 2000 } );

                // Перезагружаем страницу через 2 секунды, давая пользователю время прочитать уведомление 
                // и (неявно) воспользоваться скопированной ссылкой.
                setTimeout( () => location.reload(), 2000 );
            },
            error: () => {
                // Обработка сетевых ошибок (таймаут, ошибка 500 и т.д.)
                if ( $triggerBtn ) { toggleButton( $triggerBtn, false ); }
                showNotice( 'Сетевая ошибка.', 'error', $( '.fs-lms-archive' ) );
            },
        } );
    },
};