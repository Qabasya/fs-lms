/**
 * @module TaxonomyModalManager
 * @description Менеджер для управления таксономиями (категориями/характеристиками) предмета.
 *              Отвечает за:
 *              - Инициализацию только при наличии таблицы на странице (оптимизация)
 *              - Открытие модальных окон для создания и редактирования таксономий
 *              - Делегирование событий для кнопок внутри динамической таблицы
 *              - Валидацию данных и отправку AJAX-запросов на сохранение или удаление
 *              - Плавное визуальное удаление строки из таблицы без полной перезагрузки
 *
 * @requires jQuery
 * @requires TaxonomyModal, ConfirmModal - UI-компоненты модальных окон
 * @requires showNotice, fadeDeleteRow, showModalError - утилиты для UX и анимаций
 */

import '../_types.js';
import { TaxonomyModal } from '../modals/taxonomy-modal.js';
import { ConfirmModal } from '../modals/confirm-modal.js';
import { showNotice, fadeDeleteRow, showModalError } from '../modules/utils.js';

const $ = jQuery;

export const TaxonomyModalManager = {

    /**
     * Инициализация менеджера.
     * Точка входа, вызывается при загрузке страницы.
     */
    init() {
        // ПАТТЕРН: Ранний выход (Early Return) / Guard Clause.
        // Если на текущей странице нет таблицы таксономий, мы немедленно прекращаем выполнение.
        // Это экономит ресурсы браузера и предотвращает ошибки, если скрипт загружается 
        // глобально на страницах, где этот функционал не используется.
        if (!$('.js-taxonomy-table').length) return;

        // Подписка на событие сохранения из модального окна
        TaxonomyModal.onSave((data) => this._handleSave(data));

        this._bindEvents();
    },

    /**
     * Привязка обработчиков событий.
     * @private
     */
    _bindEvents() {
        // Прямая привязка события. Кнопка "Добавить" обычно статична и присутствует в DOM 
        // с момента загрузки страницы, поэтому делегирование здесь не обязательно.
        $('.js-add-taxonomy').on('click', (e) => {
            e.preventDefault();
            TaxonomyModal.open('store'); // 'store' указывает модалке на режим создания новой записи
        });

        // ДЕЛЕГИРОВАНИЕ СОБЫТИЙ: Привязка к родителю (.js-taxonomy-table), а не к документу или самой кнопке.
        // Это оптимальный подход для таблиц. Строки таблицы могут быть добавлены динамически 
        // (например, через AJAX-пагинацию или добавление без перезагрузки). 
        // Делегирование гарантирует, что клик по новой кнопке .js-edit-tax будет обработан корректно.
        $('.js-taxonomy-table').on('click', '.js-edit-tax', (e) => {
            e.preventDefault();
            const $row = $(e.currentTarget).closest('tr');

            // Считываем данные из data-атрибутов строки таблицы для заполнения формы редактирования.
            // Оператор === 1 используется, так как data-атрибуты в HTML всегда строки, 
            // а нам нужно передать в модалку строгий boolean.
            TaxonomyModal.open('update', {
                slug:        $row.data('slug'),
                name:        $row.data('name'),
                display:     $row.data('display'),
                is_required: $row.data('required') === 1,
            });
        });

        // Делегирование события для кнопки удаления
        $('.js-taxonomy-table').on('click', '.js-delete-tax', (e) => {
            e.preventDefault();
            const $row = $(e.currentTarget).closest('tr');
            const slug = $row.data('slug');

            // Получаем ключ предмета из скрытого или видимого input на странице, 
            // так как он нужен серверу для контекста удаления.
            const subject_key = $('#tax-subject-key').val();
            const taxName = $row.data('name');

            // Показываем модальное окно подтверждения перед деструктивным действием
            ConfirmModal.confirm({
                title: 'Удаление таксономии',
                message: `Удалить таксономию «${taxName}»?\nВсе связанные термины будут безвозвратно стёрты.`,
                confirmText: 'Удалить',
                cancelText: 'Отмена',
            }).then(() => {
                // Если пользователь нажал "Удалить", выполняем AJAX-запрос
                this._doDelete(slug, subject_key, $row);
            }).catch(() => {
                // Если пользователь нажал "Отмена" или закрыл модалку, ничего не делаем
            });
        });
    },

    /**
     * Обработка сохранения данных таксономии (создание или обновление).
     * @private
     * @param {Object} data - Данные формы из модального окна.
     */
    _handleSave(data) {
        // Клиентская валидация: блокируем отправку, если обязательное поле пустое.
        // Это дает мгновенную обратную связь пользователю без лишнего запроса к серверу.
        if ( ! data.tax_name ) {
            showNotice('Пожалуйста, заполните название', 'error', TaxonomyModal.$modal.find( '.fs-lms-modal-body' ));
            return;
        }

        // Блокируем кнопку сохранения для предотвращения двойного клика
        TaxonomyModal.setSaveState(true);

        // Определяем тип операции на основе данных, переданных модалкой.
        // Это позволяет использовать одну и ту же функцию для создания и обновления.
        const isStore = data.action === 'store';

        // Формируем базовый объект payload для AJAX-запроса
        const postData = {
            action:       isStore ? fs_lms_vars.ajax_actions.storeTaxonomy : fs_lms_vars.ajax_actions.updateTaxonomy,
            security:     fs_lms_vars.nonces.subject,
            subject_key:  data.subject_key,
            tax_name:     data.tax_name,
            display_type: data.display_type,
            is_required:  data.is_required,
        };

        // УСЛОВНОЕ ДОБАВЛЕНИЕ ПОЛЯ: 
        // При создании новой записи (store) slug генерируется на сервере.
        // При обновлении (update) нам обязательно нужно передать существующий tax_slug, 
        // чтобы сервер знал, какую именно запись обновлять.
        if ( ! isStore ) {
            postData.tax_slug = data.tax_slug;
        }

        $.post(fs_lms_vars.ajaxurl, postData)
            .done((res) => {
                if (res.success) {
                    // Перезагружаем страницу для обновления таблицы и применения изменений
                    location.reload();
                } else {
                    // Показываем ошибку от сервера внутри модального окна
                    showNotice('Ошибка: ' + (res.data?.message || res.data), 'error', TaxonomyModal.$modal.find( '.fs-lms-modal-body' ));
                    TaxonomyModal.setSaveState(false); // Разблокируем кнопку для повторной попытки
                }
            })
            .fail(() => {
                // Обработка сетевых ошибок (например, потеря интернета или ошибка 500)
                showNotice('Системная ошибка сервера', 'error', TaxonomyModal.$modal.find( '.fs-lms-modal-body' ));
                TaxonomyModal.setSaveState(false);
            });
    },

    /**
     * Выполнение AJAX-запроса на удаление таксономии.
     * @private
     * @param {string} slug - Слаг (идентификатор) удаляемой таксономии.
     * @param {string} subject_key - Ключ предмета.
     * @param {jQuery} $row - jQuery-объект строки таблицы, которую нужно удалить.
     */
    _doDelete(slug, subject_key, $row) {
        $.post(fs_lms_vars.ajaxurl, {
            action:      fs_lms_vars.ajax_actions.deleteTaxonomy,
            security:    fs_lms_vars.nonces.subject,
            subject_key: subject_key,
            tax_slug:    slug,
        })
            .done((res) => {
                if (res.success) {
                    // ПАТТЕРН: Graceful Degradation (Мягкая деградация) для UI.
                    // Мы проверяем, существует ли строка в DOM ($row?.length).
                    if ($row?.length) {
                        // Если строка есть, используем утилиту для плавного исчезновения.
                        // Это создает приятный UX, не дергая страницу перезагрузкой.
                        fadeDeleteRow($row, () => {
                            showNotice('Таксономия удалена', 'success', $('.js-taxonomy-table'));
                        });
                    } else {
                        // FALLBACK: Если по какой-то причине строки уже нет в DOM 
                        // (например, рассинхронизация состояния или баг), 
                        // мы просто перезагружаем страницу, чтобы гарантировать актуальность данных.
                        location.reload();
                    }
                } else {
                    showNotice('Ошибка при удалении: ' + res.data, 'error', $('.js-taxonomy-table'));
                }
            })
            .fail(() => {
                showNotice('Системная ошибка при удалении', 'error', $('.js-taxonomy-table'));
            });
    },
};