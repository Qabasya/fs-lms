<!-- Нейронное говно, потом поменять!-->


<?php
/**
 * Главный шаблон админки FS LMS
 * Переменная $subjects доступна благодаря TemplateRenderer
 */
?>

<div class="wrap">
    <h1>FS LMS Dashboard</h1>
    <?php settings_errors(); ?>

    <h2 class="nav-tab-wrapper">
        <a href="#tab-1" class="nav-tab nav-tab-active">Предметы</a>
        <a href="#tab-2" class="nav-tab">Управление предметами</a>
    </h2>

    <div class="tab-content">
        <div id="tab-1" class="tab-pane active" style="margin-top: 20px;">

            <h3>Список активных курсов</h3>

            <table class="wp-list-table widefat fixed striped" style="margin-bottom: 20px;">
                <thead>
                <tr>
                    <th>Название предмета</th>
                    <th>ID предмета</th>
                    <th>Кол-во заданий</th>
                </tr>
                </thead>
                <tbody>
                <?php if ( empty( $subjects ) ): ?>
                    <tr>
                        <td colspan="3">Предметов пока не создано. Нажмите кнопку ниже, чтобы добавить первый предмет.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ( $subjects as $key => $data ): ?>
                        <tr>
                            <td><strong><?php echo esc_html( $data['name'] ); ?></strong></td>
                            <td><code><?php echo esc_html( $key ); ?></code></td>
                            <td><code><?php echo esc_html( $data['tasks_count'] ); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <button type="button" class="button button-primary" id="open-subject-modal">
                + Добавить новый предмет
            </button>
        </div>

        <!-- Тут функционал работы с предметами: редактирование и удаление-->
        <div id="tab-2" class="tab-pane" style="display:none; margin-top: 20px;">
            <h3>Управление предметами</h3>
            <table class="wp-list-table widefat fixed striped posts">
                <thead>
                <tr>
                    <th>Название предмета</th>
                    <th>ID предмета</th>
                    <th>Кол-во заданий</th>
                    <th style="text-align: right;">Действия</th>
                </tr>
                </thead>
                <tbody id="the-list">
                <?php foreach ( $subjects as $key => $data ): ?>
                    <tr id="subject-row-<?php echo esc_attr( $key ); ?>">
                        <td>
                            <strong><?php echo esc_html( $data['name'] ); ?></strong>
                        </td>
                        <td><code><?php echo esc_html( $key ); ?></code></td>
                        <td><?php echo esc_html( $data['tasks_count'] ?? 1 ); ?></td>
                        <td style="text-align: right;">
                            <button type="button" class="button-link editinline open-quick-edit"
                                    data-key="<?php echo esc_attr( $key ); ?>"
                                    data-name="<?php echo esc_attr( $data['name'] ); ?>"
                                    data-count="<?php echo esc_attr( $data['tasks_count'] ?? 1 ); ?>">
                                Редактировать
                            </button>
                            |
                            <button type="button" class="button-link delete-subject" style="color: #a00;"
                                    data-key="<?php echo esc_attr( $key ); ?>">Удалить
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- Тут функционал быстрого редактирования Quick edit -->
        <table style="display: none;">
            <tr id="fs-quick-edit-row" class="inline-edit-row" style="display: none;">
                <td colspan="4" class="colspanchange">
                    <form id="fs-quick-edit-form">
                        <fieldset class="inline-edit-col-left">
                            <legend class="inline-edit-legend">Быстрое редактирование</legend>
                            <div class="inline-edit-col">
                                <label>
                                    <span class="title">Название</span>
                                    <span class="input-text-wrap"><input type="text" name="name" value=""></span>
                                </label>
                                <label>
                                    <span class="title">Кол-во заданий</span>
                                    <span class="input-text-wrap">
                                <input type="number" name="tasks_count" min="1" max="100">
                            </span>
                                </label>
                                <input type="hidden" name="key" value="">
                                <?php wp_nonce_field( 'fs_subject_nonce', 'security' ); ?>
                            </div>
                        </fieldset>
                        <p class="submit inline-edit-save">
                            <button type="button" class="button cancel alignleft">Отмена</button>
                            <button type="submit" class="button button-primary save alignright">Обновить</button>
                            <span class="spinner"></span>
                            <br class="clear">
                        </p>
                    </form>
                </td>
            </tr>
        </table>
    </div>
</div>

<div id="fs-subject-modal" class="fs-modal" style="display:none;">
    <div class="fs-modal-content">
        <span class="fs-close">&times;</span>
        <h2>Создать новый предмет</h2>

        <form id="fs-add-subject-form">
            <div class="fs-form-group">
                <label for="subj_name">Название (например: Информатика ЕГЭ)</label>
                <input type="text" id="subj_name" name="name" placeholder="Введите название..." required>
            </div>

            <div class="fs-form-group">
                <label for="subj_key">Технический ключ (например: inf_ege)</label>
                <input type="text" id="subj_key" name="key" placeholder="Только латиница и подчеркивания..." required
                       pattern="[a-z0-9_]+">
                <p class="description">Используется для названия типов записей в базе.</p>
            </div>

            <div class="fs-form-group">
                <label for="subj_tasks_count">Количество типов заданий
                    (от <?php echo Inc\Core\BaseController::MIN_TASKS_COUNT; ?>
                    до <?php echo Inc\Core\BaseController::MAX_TASKS_COUNT; ?>)</label>
                <input type="number" id="subj_tasks_count" name="tasks_count"
                       min="<?php echo Inc\Core\BaseController::MIN_TASKS_COUNT; ?>"
                       max="<?php echo Inc\Core\BaseController::MAX_TASKS_COUNT; ?>"
                       value="1" required>
                <p class="description">Сколько уникальных номеров заданий будет в курсе.</p>
            </div>

            <?php wp_nonce_field( 'fs_subject_nonce', 'security' ); ?>
            <button type="submit" class="button button-primary">Создать предмет и CPT</button>
        </form>
    </div>
</div>

<style>
    /* Базовая верстка табов */
    .tab-pane {
        animation: fadeIn 0.3s;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
        }
        to {
            opacity: 1;
        }
    }

    /* Стили модального окна */
    .fs-modal {
        position: fixed;
        z-index: 99999;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.6);
    }

    .fs-modal-content {
        background-color: #fff;
        margin: 10% auto;
        padding: 25px;
        border-radius: 4px;
        width: 400px;
        position: relative;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
    }

    .fs-close {
        position: absolute;
        right: 15px;
        top: 10px;
        font-size: 24px;
        cursor: pointer;
        color: #666;
    }

    .fs-close:hover {
        color: #000;
    }

    .fs-form-group {
        margin-bottom: 20px;
    }

    .fs-form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        font-size: 14px;
    }

    .fs-form-group input {
        width: 100%;
        padding: 10px;
        border: 1px solid #ccc;
        border-radius: 4px;
        box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.07);
    }
</style>

<script>
    jQuery(document).ready(function ($) {
        // 1. Переключение табов
        $('.nav-tab').on('click', function (e) {
            e.preventDefault();
            $('.nav-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');
            $('.tab-pane').hide();
            $($(this).attr('href')).show();
        });

        // 2. Управление модальным окном
        var $modal = $('#fs-subject-modal');

        $('#open-subject-modal').on('click', function () {
            $modal.fadeIn(200);
        });

        $('.fs-close').on('click', function () {
            $modal.fadeOut(200);
        });

        $(window).on('click', function (event) {
            if ($(event.target).is($modal)) {
                $modal.fadeOut(200);
            }
        });

        // 3. AJAX сохранение
        $('#fs-add-subject-form').on('submit', function (e) {
            e.preventDefault();
            var $form = $(this);
            var $btn = $form.find('.button-primary');

            $btn.attr('disabled', true).text('Сохранение...');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: $form.serialize() + '&action=fs_store_subject',
                success: function (response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Ошибка: ' + (response.data || 'Неизвестная ошибка'));
                        $btn.attr('disabled', false).text('Создать предмет и CPT');
                    }
                },
                error: function () {
                    alert('Сбой сервера. Проверьте консоль браузера.');
                    $btn.attr('disabled', false).text('Создать предмет и CPT');
                }
            });
        });
    });

    <!-- Тут функционал быстрого редактирования Quick edit -->
    (function ($) {
        $(document).ready(function () {
            var $quickEditRow = $('#fs-quick-edit-row');

            // Открытие Quick Edit
            $('.open-quick-edit').on('click', function () {
                var $btn = $(this);
                var key = $btn.data('key');
                var name = $btn.data('name');
                var count = $btn.data('count');

                $('#subject-row-' + key).hide().after($quickEditRow);

                $quickEditRow.find('input[name="name"]').val(name);
                $quickEditRow.find('input[name="tasks_count"]').val(count);
                $quickEditRow.find('input[name="key"]').val(key);

                $quickEditRow.show();
            });

            // Кнопка Отмена
            $quickEditRow.find('.cancel').on('click', function () {
                var key = $quickEditRow.find('input[name="key"]').val();
                $quickEditRow.hide();
                $('#subject-row-' + key).show();
            });


            $('#fs-quick-edit-form').on('submit', function (e) {
                e.preventDefault();
                var $form = $(this);
                var $btn = $form.find('.save');
                var $spinner = $form.find('.spinner');

                $btn.attr('disabled', true);
                $spinner.addClass('is-active');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: $form.serialize() + '&action=fs_update_subject',
                    success: function (response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('Ошибка: ' + (response.data || 'Не удалось обновить'));
                            $btn.attr('disabled', false);
                            $spinner.removeClass('is-active');
                        }
                    },
                    error: function () {
                        alert('Сбой сервера');
                        $btn.attr('disabled', false);
                        $spinner.removeClass('is-active');
                    }
                });
            });
        });

        // Удаление предмета
        $('.delete-subject').on('click', function (e) {
            e.preventDefault();

            var $btn = $(this);
            var key = $btn.data('key');
            var name = $btn.closest('tr').find('td:first').text().trim();

            if (!confirm('Вы уверены, что хотите удалить предмет "' + name + '"? Это также отключит связанные типы записей.')) {
                return;
            }

            $btn.text('Удаление...').css('pointer-events', 'none');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'fs_delete_subject',
                    key: key,
                    security: $('#fs-quick-edit-form [name="security"]').val()
                },
                success: function (response) {
                    if (response.success) {
                        $('#subject-row-' + key).fadeOut(400, function () {
                            location.reload();
                        });
                    } else {
                        alert(response.data || 'Ошибка удаления');
                        $btn.text('Удалить').css('pointer-events', 'auto');
                    }
                },
                error: function () {
                    alert('Сбой сервера при удалении');
                    $btn.text('Удалить').css('pointer-events', 'auto');
                }
            });
        });

    })(jQuery); // Передаем глобальный jQuery в нашу обертку
</script>