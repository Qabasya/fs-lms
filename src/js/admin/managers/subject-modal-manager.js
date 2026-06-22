/**
 * @module SubjectModalManager
 * @description Менеджер для управления учебными предметами.
 *              Отвечает за:
 *              - Создание новых предметов через модальное окно
 *              - Быстрое inline-редактирование прямо в строке таблицы (Quick Edit)
 *              - Удаление предметов с двухэтапным подтверждением и предложением экспорта
 *              - Экспорт предмета в JSON-файл на стороне клиента (Blob API)
 *              - Импорт предмета из JSON-файла (FileReader API)
 *
 * @requires jQuery
 * @requires SubjectModal, ConfirmModal - UI-компоненты модальных окон
 * @requires toggleButton, apiError, showNotice, escapeHtml - утилиты для UX и безопасности
 */

import '../_types.js';
import { SubjectModal } from '../modals/subject-modal.js';
import { ConfirmModal } from '../modals/confirm-modal.js';
import {
    toggleButton,
    apiError,
    showNotice,
    escapeHtml,
} from '../modules/utils.js';

const $ = jQuery;

/**
 * Основной объект-менеджер.
 * Методы с префиксом `_` — внутренние, не предназначены для вызова извне.
 */
export const SubjectModalManager = {

    /**
     * Инициализация менеджера.
     * Точка входа, вызывается при загрузке страницы.
     */
    init() {
        SubjectModal.init();

        // Подписка на событие сохранения в основном модальном окне создания предмета
        SubjectModal.onSave((formData) => this._handleSave(formData));

        this._bindEvents();
    },

    /**
     * Привязка обработчиков событий.
     * @private
     */
    _bindEvents() {
        // Quick Edit — inline-редактирование строки таблицы
        $(document).on('click', '.open-quick-edit', (e) => this._handleQuickEdit(e));

        // Удаление предмета
        $(document).on('click', '.delete-subject', (e) => this._handleDelete(e));

        // Экспорт предмета в JSON-файл
        $(document).on('click', '.js-export-subject', (e) => this._handleExport(e));

        $(document).on('click', '.js-unarchive-subject', (e) => this._handleUnarchive(e));

        // Безвозвратное удаление прямо из «Архива» (минуя предложение архива).
        $(document).on('click', '.js-force-delete-subject', (e) => this._handleForceDelete(e));

        // Импорт предмета: кнопка-триггер открывает стандартный file input
        $('#fs-import-trigger').on('click', () => $('#fs-import-file').trigger('click'));

        // Обработка выбора файла для импорта
        $('#fs-import-file').on('change', (e) => this._handleImport(e));
    },

    /**
     * Сохранение нового предмета через модальное окно.
     * Обрабатывает специфичную ошибку дублирования ключа (duplicate_key)
     * с отображением ошибки прямо в поле ввода.
     * @private
     * @param {Object} formData - Данные формы модального окна.
     */
    _handleSave(formData) {
        SubjectModal.setSaveState(true);

        $.post(fs_lms_vars.ajaxurl, {
            action:      fs_lms_vars.ajax_actions.storeSubject,
            name:        formData.name,
            key:         formData.key,
            tasks_count: formData.tasks_count,
            security:    formData.security,
        })
            .done((res) => {
                if (res.success) {
                    location.reload();
                } else if (res.data?.error_code === 'duplicate_key') {
                    // СПЕЦИАЛЬНАЯ ОБРАБОТКА ОШИБКИ: Если ключ предмета уже существует,
                    // показываем ошибку не в общем уведомлении, а прямо в поле ввода ключа.
                    // Это улучшает UX: пользователь сразу видит, какое поле некорректно.
                    SubjectModal.setKeyError(res.data.message);
                    SubjectModal.setSaveState(false);
                } else {
                    showNotice(res.data?.message || res.data || 'Ошибка сохранения', 'error', SubjectModal.$modal);
                    SubjectModal.setSaveState(false);
                }
            })
            .fail(() => {
                apiError('Failed to save subject');
                SubjectModal.setSaveState(false);
            });
    },

    /**
     * Quick Edit — inline-редактирование предмета прямо в строке таблицы.
     * Реализует паттерн "раскрывающейся строки": скрывает текущую строку
     * и вставляет после неё клонированную строку с формой редактирования.
     * @private
     * @param {jQuery.Event} e - Событие клика.
     */
    _handleQuickEdit(e) {
        e.preventDefault();
        const $btn = $(e.target);

        // Считываем данные из data-атрибутов кнопки.
        // jQuery .data() возвращает объект со всеми data-* атрибутами элемента.
        const data = $btn.data();
        const $row = $btn.closest('tr');

        // КЛЮЧЕВОЙ ПАТТЕРН: Клонирование скрытой формы-шаблона.
        // В HTML существует скрытая строка #fs-quick-edit-row, которая служит шаблоном.
        // Мы клонируем её, показываем и заполняем данными текущего предмета.
        // Это позволяет не создавать форму динамически через JS и легко поддерживать её в HTML.
        const $editRow = $('#fs-quick-edit-row').clone().show();

        // Заполняем поля формы клонированной строки данными из data-атрибутов
        $editRow.find('input[name="name"]').val(data.name);
        $editRow.find('input[name="tasks_count"]').val(data.count);
        $editRow.find('input[name="key"]').val(data.key);

        // Скрываем исходную строку и вставляем клонированную форму после неё
        $row.hide().after($editRow);

        // Обработчик кнопки "Отмена" — удаляем форму редактирования и показываем исходную строку
        $editRow.find('.cancel').on('click', () => {
            $editRow.remove();
            $row.show();
        });

        // Обработчик отправки формы редактирования
        $editRow.find('#fs-quick-edit-form').on('submit', (event) => {
            event.preventDefault();
            const $saveBtn = $editRow.find('.save');
            toggleButton($saveBtn, true, '...');

            // СЕРИАЛИЗАЦИЯ ФОРМЫ: Метод .serialize() автоматически собирает все поля формы
            // в строку URL-encoded формата (name=value&name2=value2).
            // Это избавляет от необходимости вручную собирать каждое поле.
            // Затем добавляем параметр action для WordPress AJAX.
            $.post(fs_lms_vars.ajaxurl, $(event.target).serialize() + '&action=' + fs_lms_vars.ajax_actions.updateSubject)
                .done((res) => {
                    if (res.success) {
                        location.reload();
                    } else {
                        showNotice('Ошибка обновления', 'error', $editRow);
                        toggleButton($saveBtn, false);
                    }
                })
                .fail(() => {
                    apiError('Failed to update subject');
                    toggleButton($saveBtn, false);
                });
        });
    },

    /**
     * Обработчик удаления предмета.
     * Сначала запрашивает у сервера информацию о связанных учениках и группах,
     * затем показывает предупреждение с предложением экспорта.
     * @private
     * @param {jQuery.Event} e - Событие клика.
     */
    _handleDelete(e) {
        e.preventDefault();
        const $btn     = $(e.target);
        const key      = $btn.data('key');
        const $row     = $btn.closest('tr');

        // Извлекаем название предмета из DOM-структуры строки таблицы
        const name     = $row.find('strong a').text().trim();
        const security = this._getNonce();

        toggleButton($btn, true, '...');

        // Этап 1: Проверка возможности удаления и получение метрик
        $.post(fs_lms_vars.ajaxurl, {
            action:      fs_lms_vars.ajax_actions.checkSubjectDeletion,
            security:    fs_lms_vars.nonces.subject,
            subject_key: key,
        })
            .done((res) => {
                toggleButton($btn, false);
                const studentCount     = res.data?.student_count     ?? 0;
                const groupCount       = res.data?.group_count       ?? 0;
                const activeGroupCount = res.data?.active_group_count ?? 0;
                this._showWarningModal(name, key, security, $btn, $row, studentCount, groupCount, activeGroupCount);
            })
            .fail(() => {
                // FALLBACK: Если запрос проверки упал, всё равно показываем предупреждение,
                // но без информации о количестве учеников (0, 0).
                // Это предотвращает блокировку действия пользователя из-за временных проблем с сетью.
                toggleButton($btn, false);
                this._showWarningModal(name, key, security, $btn, $row, 0, 0, 0);
            });
    },

    /**
     * Обработчик «Удалить навсегда» для архивных предметов.
     * Сразу ведёт к усиленному подтверждению force-удаления (архив = корзина).
     * @private
     * @param {jQuery.Event} e - Событие клика.
     */
    _handleForceDelete(e) {
        e.preventDefault();
        const $btn     = $(e.target);
        const key      = $btn.data('key');
        const $row     = $btn.closest('tr');
        const name     = $btn.data('name') || $row.find('strong a').text().trim();
        const security = this._getNonce();

        toggleButton($btn, true, '...');

        $.post(fs_lms_vars.ajaxurl, {
            action:      fs_lms_vars.ajax_actions.checkSubjectDeletion,
            security:    fs_lms_vars.nonces.subject,
            subject_key: key,
        })
            .done((res) => {
                toggleButton($btn, false);
                const studentCount = res.data?.student_count ?? 0;
                const groupCount   = res.data?.group_count   ?? 0;
                this._forceDeleteConfirm(name, key, security, $btn, $row, groupCount, studentCount);
            })
            .fail(() => {
                toggleButton($btn, false);
                this._forceDeleteConfirm(name, key, security, $btn, $row, 0, 0);
            });
    },

    /**
     * Обработчик экспорта предмета.
     * @private
     * @param {jQuery.Event} e - Событие клика.
     */
    _handleExport(e) {
        e.preventDefault();
        const key = $(e.target).data('key');
        const security = this._getNonce();
        this._exportSubject(key, security, $(e.target));
    },

    /**
     * Обработчик импорта предмета из JSON-файла.
     * Использует FileReader API для чтения содержимого файла на стороне клиента.
     * @private
     * @param {Event} e - Событие change элемента input[type="file"].
     */
    _handleImport(e) {
        const file = e.target.files[0];
        if (!file) return;

        // Очищаем значение input, чтобы событие change сработало повторно,
        // если пользователь выберет тот же файл снова.
        // Без этого браузер не вызовет событие change при повторном выборе того же файла.
        e.target.value = '';

        // FileReader API — встроенный в браузер механизм для чтения содержимого файлов.
        // Позволяет читать файлы без отправки их на сервер.
        const reader = new FileReader();

        // Обработчик успешного чтения файла
        reader.onload = (ev) => {
            let data;

            // Безопасный парсинг JSON. Если файл поврежден или не является JSON,
            // показываем понятную ошибку пользователю вместо падения всего скрипта.
            try {
                data = JSON.parse(ev.target.result);
            } catch {
                showNotice('Не удалось прочитать файл. Убедитесь, что это корректный JSON.', 'error', $('#fs-import-trigger').parent());
                return;
            }

            // Извлекаем название предмета для отображения в подтверждении.
            // Fallback-цепочка: сначала пытаемся взять name, затем key, затем общее слово.
            const name = data?.subject?.name || data?.subject?.key || 'предмет';
            const safeName = escapeHtml(name);

            // Показываем модальное окно подтверждения импорта
            ConfirmModal.confirm({
                title: 'Импорт предмета',
                message: `Импортировать «${safeName}»?\nБудут восстановлены: таксономии, термины, шаблоны, boilerplates и записи.`,
                confirmText: 'Импортировать',
                cancelText: 'Отмена',
            })
                .then(() => {
                    // Отправляем ВЕСЬ JSON-контент файла на сервер.
                    // Сервер сам распарсит его и восстановит все связанные данные.
                    $.post(fs_lms_vars.ajaxurl, {
                        action:   fs_lms_vars.ajax_actions.importSubject,
                        json:     ev.target.result,
                        security: this._getNonce(),
                    })
                        .done((res) => {
                            if (res.success) {
                                location.reload();
                            } else {
                                showNotice(res.data || 'Ошибка импорта', 'error', $('#fs-import-trigger').parent());
                            }
                        })
                        .fail(() => {
                            apiError('Failed to import subject');
                        });
                })
                .catch(() => {});
        };

        // Обработчик ошибки чтения файла (например, нет прав доступа)
        reader.onerror = () => {
            showNotice('Ошибка чтения файла', 'error', $('#fs-import-trigger').parent());
        };

        // Запускаем чтение файла как текст (UTF-8).
        // Результат будет доступен в reader.onload как ev.target.result (строка).
        reader.readAsText(file);
    },

    /**
     * Показ первого модального окна предупреждения перед удалением.
     * Предлагает пользователю выбор: сразу удалить ИЛИ экспортировать данные перед удалением.
     * Это UX-паттерн "предложить альтернативу деструктивному действию".
     * @private
     * @param {string} name - Название предмета.
     * @param {string} key - Ключ предмета.
     * @param {string} security - Nonce-токен.
     * @param {jQuery} $btn - jQuery-объект кнопки удаления.
     * @param {jQuery} $row - jQuery-объект строки таблицы.
     * @param {number} studentCount - Количество учеников.
     * @param {number} groupCount - Количество групп.
     * @param {number} activeGroupCount - Количество активных групп (текущий период).
     */
    _showWarningModal(name, key, security, $btn, $row, studentCount = 0, groupCount = 0, activeGroupCount = 0) {
        const safeName    = escapeHtml(name);

        // Активные группы (текущий период): архивировать нельзя, пока идёт обучение.
        // Группы не переносятся — выход либо завершить период, либо удалить безвозвратно.
        if (activeGroupCount > 0) {
            ConfirmModal.confirm({
                title: 'Архивация недоступна',
                message: `По предмету «${safeName}» идёт обучение: активные группы в текущем периоде (${activeGroupCount}).\n\nЧтобы архивировать — завершите текущий период (или сделайте текущим другой).\nЛибо удалите предмет безвозвратно вместе с группами и историей.`,
                size: 'lg',
                isDanger: true,
                confirmText: 'Удалить навсегда',
                cancelText:  'Отмена',
            })
                .then(() => this._forceDeleteConfirm(name, key, security, $btn, $row, groupCount, studentCount))
                .catch(() => {});
            return;
        }

        // Предмет с группами прошлых периодов: рекомендуем архив (данные сохранятся),
        // но даём явный путь к безвозвратному удалению (тестовые предметы).
        if (groupCount > 0) {
            const studentTail = studentCount > 0 ? `, учеников: ${studentCount}` : '';
            ConfirmModal.confirm({
                title: 'Удаление с группами',
                message: `К предмету «${safeName}» привязаны группы (${groupCount})${studentTail}. Безвозвратное удаление стёрло бы когорты и историю обучения.\n\nРекомендуем архивировать — данные и группы сохранятся.\nЛибо удалить навсегда.`,
                size: 'lg',
                isDanger: false,
                confirmText: 'Архивировать',
                cancelText:  'Удалить навсегда',
            })
                .then(() => this._archiveSubject(key, security, $btn))
                .catch((reason) => {
                    if (reason === 'cancel') {
                        this._forceDeleteConfirm(name, key, security, $btn, $row, groupCount, studentCount);
                    }
                });
            return;
        }

        // Формируем дополнительное предупреждение, если в предмете есть ученики
        const studentNote = studentCount > 0
            ? `\nПредмет содержит ${groupCount} гр. и ${studentCount} уч. Ученики без других зачислений будут удалены безвозвратно.\n`
            : '';

        const message =
            `Вы собираетесь удалить предмет «${safeName}».${studentNote}\n` +
            `Будут безвозвратно удалены все связанные таксономии, термины, привязки шаблонов, типовые условия и записи.\n` +
            `Рекомендуем экспортировать данные перед удалением.\n\n` +
            `Для выхода нажмите клавишу Esc или знак Х справа вверху`;

        // ДВУХКНОПОЧНОЕ ПОДТВЕРЖДЕНИЕ: Обе кнопки ведут к удалению, 
        // но одна из них сначала выполняет экспорт.
        // Это нестандартный UX-паттерн: обычно кнопки "Подтвердить/Отмена", 
        // но здесь "Перейти к удалению" (сразу) и "Экспортировать и удалить" (с экспортом).
        ConfirmModal.confirm({
            title: 'Предупреждение',
            message: message,
            size: 'lg',
            isDanger: true,
            confirmText: 'Перейти к удалению',
            cancelText:  'Экспортировать и удалить', // Кнопка "Отмена" фактически запускает экспорт
        })
            .then(() => {
                // Пользователь нажал "Перейти к удалению" — сразу показываем финальное подтверждение.
                // setTimeout нужен для визуального разделения модалок: 
                // чтобы пользователь увидел, что одна модалка закрылась и открылась другая.
                setTimeout(() => {
                    this._showFinalConfirm(name, key, security, $btn, $row);
                }, 250);
            })
            .catch((reason) => {
                // Пользователь нажал "Экспортировать и удалить" (кнопка cancelText).
                // Сначала экспортируем данные, затем показываем финальное подтверждение.
                if (reason === 'cancel') {
                    this._exportSubject(key, security, $btn, () => {
                        // Колбэк onComplete вызывается после завершения экспорта.
                        // Снова используем setTimeout для визуального разделения модалок.
                        setTimeout(() => {
                            this._showFinalConfirm(name, key, security, $btn, $row);
                        }, 250);
                    });
                }
            });
    },

    /**
     * Показ финального модального окна подтверждения удаления.
     * Это последний барьер перед необратимым действием.
     * @private
     * @param {string} name - Название предмета.
     * @param {string} key - Ключ предмета.
     * @param {string} security - Nonce-токен.
     * @param {jQuery} $btn - jQuery-объект кнопки удаления.
     * @param {jQuery} $row - jQuery-объект строки таблицы.
     */
    _showFinalConfirm(name, key, security, $btn, $row) {
        const safeName = escapeHtml(name);

        ConfirmModal.confirm({
            title: 'Подтвердите удаление',
            message: `Точно удалить предмет «${safeName}»?\nЭто действие необратимо.`,
            size: 'sm',
            isDanger: true,
            confirmText: 'Да, удалить',
            cancelText:  'Отмена',
        })
            .then(() => {
                this._doDelete(key, security, $btn, $row);
            })
            .catch(() => {});
    },

    /**
     * Усиленное подтверждение БЕЗВОЗВРАТНОГО удаления предмета вместе с группами/учениками.
     * Это «выход» для тестовых предметов: запускает полный каскад (force=1).
     * @private
     * @param {string} name - Название предмета.
     * @param {string} key - Ключ предмета.
     * @param {string} security - Nonce-токен.
     * @param {jQuery} $btn - jQuery-объект кнопки.
     * @param {jQuery} $row - jQuery-объект строки таблицы.
     * @param {number} groupCount - Количество групп.
     * @param {number} studentCount - Количество учеников.
     */
    _forceDeleteConfirm(name, key, security, $btn, $row, groupCount = 0, studentCount = 0) {
        const safeName = escapeHtml(name);

        const lost = [];
        if (groupCount > 0)   lost.push(`групп: ${groupCount}`);
        if (studentCount > 0) lost.push(`учеников: ${studentCount} (без других зачислений)`);
        const lostNote = lost.length
            ? `\nБудут безвозвратно удалены: ${lost.join(', ')}, вся история, контент и записи.`
            : '\nБудут безвозвратно удалены весь контент и записи предмета.';

        ConfirmModal.confirm({
            title: 'Удалить навсегда?',
            message: `Предмет «${safeName}» и все связанные данные будут стёрты НАВСЕГДА.${lostNote}\n\nДействие необратимо.`,
            size: 'lg',
            isDanger: true,
            confirmText: 'Да, удалить навсегда',
            cancelText:  'Отмена',
        })
            .then(() => this._doDelete(key, security, $btn, $row, true))
            .catch(() => {});
    },

    /**
     * Архивирование / возврат предмета из архива (toggle, server-side флаг `archived`).
     * @private
     */
    _archiveSubject(key, security, $btn) {
        toggleButton($btn, true, '...');

        $.post(fs_lms_vars.ajaxurl, {
            action:   fs_lms_vars.ajax_actions.toggleSubjectArchive,
            key:      key,
            security: security,
        })
            .done((res) => {
                if (res.success) {
                    location.reload();
                } else {
                    showNotice(res.data || 'Не удалось изменить статус', 'error', $btn.closest('td'));
                    toggleButton($btn, false);
                }
            })
            .fail(() => {
                apiError('Failed to toggle subject archive');
                toggleButton($btn, false);
            });
    },

    /**
     * Обработчик «Вернуть из архива».
     * @private
     */
    _handleUnarchive(e) {
        e.preventDefault();
        const $btn = $(e.target);
        const key  = $btn.data('key');
        const name = $btn.data('name') || key;

        ConfirmModal.confirm({
            title: 'Вернуть из архива',
            message: `Вернуть предмет «${escapeHtml(String(name))}» из архива?`,
            confirmText: 'Вернуть',
            cancelText:  'Отмена',
        })
            .then(() => this._archiveSubject(key, this._getNonce(), $btn))
            .catch(() => {});
    },

    /**
     * Экспорт предмета в JSON-файл на стороне клиента.
     * Использует Blob API для создания файла в браузере без участия сервера в скачивании.
     * @private
     * @param {string} key - Ключ предмета.
     * @param {string} security - Nonce-токен.
     * @param {jQuery} $btn - jQuery-объект кнопки.
     * @param {Function|null} onComplete - Колбэк, вызываемый после завершения экспорта.
     */
    _exportSubject(key, security, $btn, onComplete = null) {
        toggleButton($btn, true, 'Экспорт...');

        $.post(fs_lms_vars.ajaxurl, {
            action:   fs_lms_vars.ajax_actions.exportSubject,
            key:      key,
            security: security,
        })
            .done((res) => {
                if (res.success) {
                    // BLOB API: Создаем файл прямо в браузере из JSON-данных.
                    // 1. JSON.stringify с параметрами (null, 2) форматирует JSON с отступами для читаемости.
                    // 2. new Blob создает бинарный объект из строки.
                    // 3. URL.createObjectURL генерирует временную ссылку на этот blob.
                    // 4. Создаем невидимую ссылку <a>, кликаем по ней для скачивания, затем удаляем.
                    const blob = new Blob([JSON.stringify(res.data, null, 2)], { type: 'application/json' });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = `subject_${key}_export.json`; // Имя скачиваемого файла
                    a.click();

                    // Освобождаем память: удаляем временную ссылку на blob.
                    // Это важно, так как blob хранится в памяти браузера.
                    URL.revokeObjectURL(url);
                } else {
                    showNotice(res.data || 'Ошибка экспорта', 'error', $btn.closest('td'));
                }
            })
            .fail(() => apiError('Failed to export subject'))
            .always(() => {
                // .always() срабатывает независимо от успеха/ошибки.
                // Гарантируем, что кнопка разблокируется и вызывается колбэк (если есть).
                toggleButton($btn, false);
                if (typeof onComplete === 'function') {
                    onComplete();
                }
            });
    },

    /**
     * Непосредственное выполнение удаления предмета после всех подтверждений.
     * @private
     * @param {string} key - Ключ предмета.
     * @param {string} security - Nonce-токен.
     * @param {jQuery} $btn - jQuery-объект кнопки удаления.
     * @param {jQuery} $row - jQuery-объект строки таблицы.
     */
    _doDelete(key, security, $btn, $row, force = false) {
        toggleButton($btn, true, '...');

        $.post(fs_lms_vars.ajaxurl, {
            action:   fs_lms_vars.ajax_actions.deleteSubject,
            key:      key,
            security: security,
            force:    force ? 1 : 0,
        })
            .done((res) => {
                if (res.success) {
                    // Плавное визуальное удаление строки перед физическим удалением из DOM
                    $row.fadeOut(400, () => {
                        $row.remove();

                        // Проверка на пустую таблицу.
                        // Если это была последняя строка в конкретной вкладке (#tab-1),
                        // перезагружаем страницу, чтобы отобразить состояние пустого списка.
                        if ($('#tab-1 table.wp-list-table tbody tr').length === 0) {
                            location.reload();
                        }
                    });
                } else {
                    toggleButton($btn, false);
                    showNotice(res.data || 'Ошибка удаления', 'error', $btn.closest('td'));
                }
            })
            .fail(() => {
                toggleButton($btn, false);
                apiError('Failed to delete subject');
            });
    },

    /**
     * Получение nonce-токена безопасности.
     * Ищет nonce в одной из форм на странице.
     * Используется fallback-цепочка: если nonce нет в одной форме, пробуем другую.
     * @private
     * @returns {string} Nonce-токен или пустая строка, если не найден.
     */
    _getNonce() {
        return $('#fs-add-subject-form [name="security"]').val()
            || $('#fs-quick-edit-form [name="security"]').val()
            || '';
    },
};