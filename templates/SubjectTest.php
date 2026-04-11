<?php
/**
 * Шаблон страницы управления предметом.
 *
 * Отображает вкладки:
 * - Статистика (заглушка)
 * - Задания (ссылка на CPT заданий)
 * - Статьи (ссылка на CPT статей)
 * - Таксономии (управление дополнительными таксономиями предмета)
 * - Менеджер заданий (настройка шаблонов и типовых условий)
 *
 * @var array $args Данные из SubjectController
 * @var \Inc\DTO\SubjectViewDTO $dto
 */

/** @var \Inc\DTO\SubjectViewDTO $dto */
$dto = $args['data'];

// Для удобства извлекаем часто используемые значения из DTO
$subject = $dto->subject_data;
$key     = $dto->subject_key;
?>

<div class="wrap fs-lms-dashboard">
    <h1>Управление предметом: <?php echo esc_html($subject->name ?? 'Без названия'); ?></h1>

    <!-- ============================ ВКЛАДКИ ============================ -->
    <div class="fs-tabs">
        <!-- Вкладка 0: Статистика -->
        <input type="radio" name="fs_tabs" id="tab0" checked="checked">
        <label for="tab0">Статистика</label>
        <div class="tab-content">
            <h3>Потом здесь будет дашборд</h3>
        </div>

        <!-- Вкладка 1: Задания -->
        <input type="radio" name="fs_tabs" id="tab1">
        <label for="tab1">Задания</label>
        <div class="tab-content">
            <div class="card">
                <a href="<?php echo esc_url($dto->tasks_url); ?>" class="button button-primary">
                    Перейти к Заданиям
                </a>
            </div>
        </div>

        <!-- Вкладка 2: Статьи -->
        <input type="radio" name="fs_tabs" id="tab2">
        <label for="tab2">Статьи</label>
        <div class="tab-content">
            <div class="card">
                <a href="<?php echo esc_url($dto->articles_url); ?>" class="button button-secondary">
                    Перейти к Статьям
                </a>
            </div>
        </div>

        <!-- Вкладка 3: Таксономии -->
        <input type="radio" name="fs_tabs" id="tab3">
        <label for="tab3">Таксономии</label>
        <div class="tab-content">
            <div class="tab-header" style="display: flex; justify-content: space-between; margin-bottom: 15px;">
                <h3>Дополнительные классификаторы</h3>
                <button type="button" class="button button-primary js-add-taxonomy">
                    Добавить таксономию
                </button>
            </div>

            <table class="widefat fixed striped js-taxonomy-table">
                <thead>
                <tr>
                    <th>Название</th>
                    <th>Ярлык (Slug)</th>
                    <th>Действия</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($dto->taxonomies as $tax) : ?>
                    <tr data-slug="<?php echo esc_attr($tax->slug); ?>" data-name="<?php echo esc_attr($tax->name); ?>">
                        <td class="column-name">
                            <strong><?php echo esc_html($tax->name); ?></strong>
                            <?php if ($tax->slug === $dto->protected_tax) : ?>
                                <span class="dashicons dashicons-lock" title="Системная таксономия"></span>
                            <?php endif; ?>
                        </td>
                        <td><code><?php echo esc_html($tax->slug); ?></code></td>
                        <td>
                            <a href="<?php echo admin_url("edit-tags.php?taxonomy={$tax->slug}"); ?>" class="button button-small">
                                Термины
                            </a>
                            <?php if ($tax->slug !== $dto->protected_tax) : ?>
                                | <a href="#" class="js-edit-tax">Изменить</a>
                                | <a href="#" class="js-delete-tax" style="color: #d63638;">Удалить</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Вкладка 4: Менеджер заданий -->
        <input type="radio" name="fs_tabs" id="tab4">
        <label for="tab4">Менеджер заданий</label>
        <div class="tab-content">
            <h3>Управление шаблонами и условиями типов заданий</h3>
            <p class="description">
                Настройте визуальный шаблон и типовое условие, которое будет автоматически
                подставляться при создании нового задания этого типа.
            </p>

            <table class="widefat fixed striped js-task-manager-table" data-subject="<?php echo esc_attr($dto->subject_key); ?>">
                <thead>
                <tr>
                    <th>Тип задания (Номер)</th>
                    <th style="width: 200px;">Визуальный шаблон</th>
                    <th style="width: 120px;">Типовые условия</th>
                    <th style="width: 40px;"></th>
                </tr>
                </thead>
                <tbody>
                <?php if (!empty($dto->task_types)) : ?>
                    <?php foreach ($dto->task_types as $type) : ?>
                        <tr data-term-id="<?php echo $type->id; ?>"
                            data-task-slug="<?php echo esc_attr($type->slug); ?>"
                            data-task-name="<?php echo esc_attr($type->description); ?>">
                            <td>
                                <strong><?php echo esc_html($type->description); ?></strong>
                            </td>
                            <td>
                                <select class="js-change-term-template" style="width:100%">
                                    <?php foreach ($dto->all_templates as $tpl) : ?>
                                        <option value="<?php echo esc_attr($tpl->id); ?>" <?php selected($type->current_template->value, (string) $tpl->id); ?>>
                                            <?php echo esc_html($tpl->title); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <a href="<?php echo admin_url("admin.php?page=fs_boilerplate_manager&subject=" . esc_attr($dto->subject_key) . "&term=" . esc_attr($type->slug)); ?>"
                                   class="button"
                                   title="Настроить типовые условия">
                                    <span class="dashicons dashicons-editor-textcolor" style="margin-top:4px;"></span>
                                    Настроить
                                </a>
                            </td>
                            <td class="status-cell">
                                <span class="spinner" style="float:none; margin:0;"></span>
                                <span class="dashicons dashicons-yes js-success-icon" style="display:none; color:green; margin-top:4px;"></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="4">Типы заданий не найдены.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ============================ МОДАЛЬНОЕ ОКНО ДЛЯ ТАКСОНОМИЙ ============================ -->
<div id="fs-taxonomy-modal" class="fs-lms-modal" style="display:none;">
    <div class="fs-lms-modal-content">
        <h3 id="modal-title">Новая таксономия</h3>
        <input type="hidden" id="tax-subject-key" value="<?php echo esc_attr($dto->subject_key); ?>">
        <input type="hidden" id="tax-action" value="store">

        <p>
            <label>Название:</label><br>
            <input type="text" id="tax-name" style="width:100%">
        </p>

        <p id="slug-container">
            <label>Slug:</label><br>
            <input type="text" id="tax-slug" style="width:100%">
        </p>

        <div style="text-align:right; margin-top:20px;">
            <button class="button js-modal-close">Отмена</button>
            <button class="button button-primary js-modal-save">Сохранить</button>
        </div>
    </div>
</div>

<!-- ============================ СТИЛИ ============================ -->
<style>
    /* Базовые стили модальных окон */
    .fs-lms-modal {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        z-index: 9999;
    }

    .fs-lms-modal-content {
        background: #fff;
        width: 400px;
        margin: 100px auto;
        padding: 20px;
        border-radius: 5px;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
    }

    /* Стили вкладок */
    .fs-tabs {
        display: flex;
        flex-wrap: wrap;
        margin-top: 20px;
    }

    .fs-tabs label {
        padding: 10px 20px;
        background: #e0e0e0;
        cursor: pointer;
        border: 1px solid #ccc;
        border-bottom: none;
        margin-right: 5px;
    }

    .fs-tabs input[type="radio"] {
        display: none;
    }

    .fs-tabs .tab-content {
        width: 100%;
        order: 1;
        display: none;
        padding: 20px;
        background: #fff;
        border: 1px solid #ccc;
        min-height: 200px;
    }

    .fs-tabs input[type="radio"]:checked + label {
        background: #fff;
        border-bottom: 1px solid #fff;
        margin-bottom: -1px;
        font-weight: bold;
    }

    .fs-tabs input[type="radio"]:checked + label + .tab-content {
        display: block;
    }

    /* Дополнительные стили */
    .dashicons-lock {
        font-size: 14px;
        color: #999;
        vertical-align: middle;
    }

    .column-name {
        vertical-align: middle !important;
    }
</style>

<script>
    /**
     * JavaScript для страницы управления предметом.
     *
     * Функционал:
     * - Управление таксономиями (CRUD через модальное окно)
     * - Обновление визуального шаблона для типа задания
     */
    jQuery(document).ready(function($) {
        // ==================== ТАКСОНОМИИ ====================

        /**
         * Открытие модального окна для добавления/редактирования таксономии
         */
        function openTaxonomyModal(action, slug = '', name = '') {
            const modal = $('#fs-taxonomy-modal');
            const title = action === 'edit' ? 'Редактировать таксономию' : 'Новая таксономия';

            $('#modal-title').text(title);
            $('#tax-action').val(action === 'edit' ? 'update' : 'store');

            if (action === 'edit') {
                $('#tax-slug').prop('readonly', true).val(slug);
                $('#tax-name').val(name);
            } else {
                $('#tax-slug').prop('readonly', false).val('');
                $('#tax-name').val('');
            }

            modal.show();
        }

        // Добавление таксономии
        $('.js-add-taxonomy').on('click', function() {
            openTaxonomyModal('new');
        });

        // Редактирование таксономии
        $('.js-edit-tax').on('click', function(e) {
            e.preventDefault();
            const row = $(this).closest('tr');
            openTaxonomyModal('edit', row.data('slug'), row.data('name'));
        });

        // Закрытие модального окна
        $('.js-modal-close').on('click', function() {
            $('#fs-taxonomy-modal').hide();
        });

        // Сохранение таксономии
        $('.js-modal-save').on('click', function() {
            const modal = $('#fs-taxonomy-modal');
            const action = $('#tax-action').val();
            const subjectKey = $('#tax-subject-key').val();
            const taxSlug = $('#tax-slug').val();
            const taxName = $('#tax-name').val();

            if (!taxName) {
                alert('Название обязательно');
                return;
            }

            const data = {
                action: action === 'store' ? 'fs_store_taxonomy' : 'fs_update_taxonomy',
                security: fs_lms_vars?.security || '',
                subject_key: subjectKey,
                tax_slug: taxSlug,
                tax_name: taxName
            };

            $.post(ajaxurl, data)
                .done(function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data);
                    }
                })
                .fail(function() {
                    alert('Ошибка при сохранении');
                });
        });

        // Удаление таксономии
        $('.js-delete-tax').on('click', function(e) {
            e.preventDefault();
            if (!confirm('Удалить эту таксономию? Все термины также будут удалены.')) return;

            const row = $(this).closest('tr');
            const data = {
                action: 'fs_delete_taxonomy',
                security: fs_lms_vars?.security || '',
                subject_key: $('#tax-subject-key').val(),
                tax_slug: row.data('slug')
            };

            $.post(ajaxurl, data)
                .done(function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data);
                    }
                });
        });

        // ==================== МЕНЕДЖЕР ЗАДАНИЙ ====================

        /**
         * Обновление шаблона для типа задания
         */
        $('.js-change-term-template').on('change', function() {
            const select = $(this);
            const row = select.closest('tr');
            const termId = row.data('term-id');
            const templateId = select.val();
            const statusCell = row.find('.status-cell');
            const spinner = statusCell.find('.spinner');
            const successIcon = statusCell.find('.js-success-icon');

            spinner.addClass('is-active');
            successIcon.hide();

            $.post(ajaxurl, {
                action: 'update_term_template',
                security: fs_lms_vars?.security || '',
                term_id: termId,
                template: templateId
            })
                .done(function(response) {
                    if (response.success) {
                        successIcon.show().delay(2000).fadeOut();
                    } else {
                        alert(response.data);
                    }
                })
                .fail(function() {
                    alert('Ошибка при сохранении шаблона');
                })
                .always(function() {
                    spinner.removeClass('is-active');
                });
        });
    });
</script>