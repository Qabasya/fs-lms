/**
 * @fileoverview Модуль управления шаблонами (boilerplates) для плагина FS-LMS.
 *
 * @module Boilerplates
 * @description Менеджер для работы с шаблонами заданий.
 *              Отвечает за:
 *              - Перехват отправки формы создания/редактирования шаблона и отправку через AJAX
 *              - Синхронизацию содержимого визуального редактора (TinyMCE) с формой перед отправкой
 *              - Обновление URL страницы после успешного создания нового шаблона (переход из режима 'new' в 'edit')
 *              - Удаление шаблона с подтверждением и плавной анимацией удаления строки из таблицы
 *
 * @requires jQuery
 * @requires ConfirmModal - модальное окно подтверждения действий
 * @requires showNotice, fadeDeleteRow - утилиты для уведомлений и анимации удаления
 */

import '../_types.js';
import { ConfirmModal } from '../modals/confirm-modal.js';
import { showNotice, fadeDeleteRow } from '../modules/utils.js';

const $ = jQuery;

export const Boilerplates = {

    /**
     * Инициализация модуля шаблонов.
     * Точка входа, вызывается при загрузке страницы управления шаблонами.
     */
    init() {
        this.bindEvents();
    },

    /**
     * Привязка обработчиков событий.
     */
    bindEvents() {
        const $form = $('#fs-lms-boilerplate-form');

        // ПРЯМАЯ ПРИВЯЗКА СОБЫТИЯ:
        // Проверяем наличие формы на странице ($form.length), чтобы избежать ошибок,
        // если скрипт загружается на странице, где формы создания шаблона нет.
        if ($form.length) {
            $form.on('submit', (e) => {
                e.preventDefault(); // Отменяем стандартную перезагрузку страницы
                this.save($form);
            });
        }

        // ДЕЛЕГИРОВАНИЕ СОБЫТИЯ:
        // Используем $('body').on('click', ...), так как ссылки удаления могут быть
        // добавлены в таблицу динамически (например, после AJAX-обновления списка).
        // Прямая привязка $('.delete-boilerplate-link').on(...) не сработала бы для новых элементов.
        $('body').on('click', '.delete-boilerplate-link', (e) => {
            e.preventDefault();

            // Двухэтапное подтверждение деструктивного действия
            ConfirmModal.confirm({
                title: 'Удаление шаблона',
                message: 'Вы уверены, что хотите удалить этот шаблон?',
                size: 'sm',
                isDanger: true, // Красная кнопка для визуального обозначения опасности
                confirmText: 'Удалить',
                cancelText: 'Отмена',
            }).then(() => this.deleteBoilerplate($(e.currentTarget)));
            // .catch(() => {}) намеренно опущен, так как отмена пользователем не является ошибкой
        });
    },

    /**
     * Сохранение шаблона через AJAX.
     * Обрабатывает как создание нового, так и обновление существующего шаблона.
     *
     * @param {jQuery} $form - jQuery-объект формы с данными шаблона.
     */
    save($form) {
        // ВАЖНО: Синхронизация WYSIWYG-редактора.
        // Если в форме используется TinyMCE (визуальный редактор WordPress),
        // он хранит содержимое в своем iframe и не обновляет скрытый <textarea> автоматически.
        // triggerSave() принудительно копирует содержимое из редактора в textarea перед сериализацией формы.
        if (typeof tinyMCE !== 'undefined') {
            tinyMCE.triggerSave();
        }

        const $btn = $form.find('input[type="submit"]');
        const originalText = $btn.val(); // Сохраняем исходный текст кнопки для восстановления

        // serialize() автоматически собирает все поля формы в URL-encoded строку
        // (например, "title=Test&content=Hello&action=save_boilerplate").
        const data = $form.serialize();

        // Блокируем кнопку для предотвращения двойной отправки (защита от race condition)
        $btn.val('Сохранение...').prop('disabled', true);

        $.post(fs_lms_vars.ajaxurl, data)
            .done((response) => {
                if (response.success) {
                    showNotice('Шаблон успешно сохранен!', 'success', $form);

                    // ЛОГИКА ПЕРЕХОДА ОТ СОЗДАНИЯ К РЕДАКТИРОВАНИЮ:
                    // Если мы создавали новый шаблон (action=new), сервер возвращает его новый UID.
                    // Нам нужно обновить URL страницы, чтобы переключить интерфейс в режим редактирования
                    // и чтобы при обновлении страницы (F5) пользователь не создал дубликат.
                    const urlParams = new URLSearchParams(window.location.search);
                    if (urlParams.get('action') === 'new' && response.data.uid) {
                        urlParams.set('action', 'edit');
                        urlParams.set('uid', response.data.uid);

                        // Присвоение window.location.search вызывает перезагрузку страницы
                        // с новыми параметрами URL, что обновляет состояние интерфейса.
                        window.location.search = urlParams.toString();
                    }
                } else {
                    // Fallback-цепочка для извлечения сообщения об ошибке от сервера
                    const msg = response.data || 'Неизвестная ошибка';
                    showNotice(msg, 'error', $form);
                }
            })
            .fail(() => {
                // Обработка сетевых ошибок (таймаут, ошибка 500 и т.д.)
                showNotice('Ошибка сервера. Попробуйте позже.', 'error', $form);
            })
            .always(() => {
                // ГАРАНТИРОВАННАЯ РАЗБЛОКИРОВКА:
                // .always() выполняется независимо от того, завершился запрос успехом или ошибкой.
                // Это гарантирует, что кнопка не останется заблокированной навсегда при сбое сети.
                $btn.val(originalText).prop('disabled', false);
            });
    },

    /**
     * Удаление шаблона через AJAX.
     *
     * @param {jQuery} $el - jQuery-объект ссылки/кнопки удаления, по которой кликнули.
     */
    deleteBoilerplate($el) {
        // Извлекаем текущие параметры URL, чтобы передать контекст (предмет и тип задания) на сервер.
        // Это нужно серверу для корректной очистки кэша или пересчета связанных данных.
        const params = new URLSearchParams(window.location.search);

        const data = {
            action: fs_lms_vars.ajax_actions.deleteBoilerplate,
            // Nonce-токен безопасности, считываемый из скрытого поля формы или страницы
            security: $('#security').val(),
            // UID шаблона, хранящийся в data-атрибуте кнопки удаления
            uid: $el.data('uid'),
            subject_key: params.get('subject'),
            term_slug: params.get('term'),
        };

        $.post(fs_lms_vars.ajaxurl, data, (response) => {
            if (response.success) {
                // ПАТТЕРН: Плавное удаление строки таблицы.
                // Находим родительскую строку <tr> и применяем утилиту анимации.
                // Это дает пользователю визуальную обратную связь о том, что действие выполнено.
                fadeDeleteRow($el.closest('tr'));
            } else {
                // Показываем ошибку в контексте таблицы (в блоке .wrap)
                const msg = response.data?.message || response.data || 'Неизвестная ошибка';
                showNotice('Ошибка: ' + msg, 'error', $el.closest('.wrap'));
            }
        });
    },
};