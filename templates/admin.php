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
        <a href="#tab-2" class="nav-tab">Настройки системы</a>
        <a href="#tab-3" class="nav-tab">О плагине</a>
    </h2>

    <div class="tab-content">
        <div id="tab-1" class="tab-pane active" style="margin-top: 20px;">
            <h3>Список активных курсов</h3>

            <table class="wp-list-table widefat fixed striped" style="margin-bottom: 20px;">
                <thead>
                <tr>
                    <th>Название предмета</th>
                    <th>Технический ключ (ID)</th>
                    <th>Статус</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($subjects)): ?>
                    <tr>
                        <td colspan="3">Предметов пока не создано. Нажмите кнопку ниже, чтобы добавить первый предмет.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($subjects as $key => $data): ?>
                        <tr>
                            <td><strong><?php echo esc_html($data['name']); ?></strong></td>
                            <td><code><?php echo esc_html($key); ?></code></td>
                            <td><span style="color: green; font-weight: bold;">● Активен</span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <button type="button" class="button button-primary" id="open-subject-modal">
                + Добавить новый предмет
            </button>
        </div>

        <div id="tab-2" class="tab-pane" style="display:none; margin-top: 20px;">
            <form method="post" action="options.php">
                <?php
                    settings_fields('fs_tasks_settings_group');
                    do_settings_sections('fs_tasks');
                    submit_button();
                ?>
            </form>
        </div>

        <div id="tab-3" class="tab-pane" style="display:none; margin-top: 20px;">
            <h3>FS Tasks v0.0.1</h3>
            <p>Система автоматической генерации курсов и управления учебным контентом.</p>
        </div>
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
                <input type="text" id="subj_key" name="key" placeholder="Только латиница и подчеркивания..." required pattern="[a-z0-9_]+">
                <p class="description">Используется для названия типов записей в базе.</p>
            </div>
            <?php wp_nonce_field('fs_subject_nonce', 'security'); ?>
            <button type="submit" class="button button-primary">Создать предмет и CPT</button>
        </form>
    </div>
</div>

<style>
    /* Базовая верстка табов */
    .tab-pane { animation: fadeIn 0.3s; }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

    /* Стили модального окна */
    .fs-modal { position: fixed; z-index: 99999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.6); }
    .fs-modal-content { background-color: #fff; margin: 10% auto; padding: 25px; border-radius: 4px; width: 400px; position: relative; box-shadow: 0 5px 15px rgba(0,0,0,0.3); }
    .fs-close { position: absolute; right: 15px; top: 10px; font-size: 24px; cursor: pointer; color: #666; }
    .fs-close:hover { color: #000; }
    .fs-form-group { margin-bottom: 20px; }
    .fs-form-group label { display: block; margin-bottom: 8px; font-weight: 600; font-size: 14px; }
    .fs-form-group input { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-shadow: inset 0 1px 2px rgba(0,0,0,0.07); }
</style>

<script>
    jQuery(document).ready(function($) {
        // 1. Переключение табов
        $('.nav-tab').on('click', function(e) {
            e.preventDefault();
            $('.nav-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');
            $('.tab-pane').hide();
            $($(this).attr('href')).show();
        });

        // 2. Управление модальным окном
        var $modal = $('#fs-subject-modal');

        $('#open-subject-modal').on('click', function() {
            $modal.fadeIn(200);
        });

        $('.fs-close').on('click', function() {
            $modal.fadeOut(200);
        });

        $(window).on('click', function(event) {
            if ($(event.target).is($modal)) {
                $modal.fadeOut(200);
            }
        });

        // 3. AJAX сохранение
        $('#fs-add-subject-form').on('submit', function(e) {
            e.preventDefault();
            var $form = $(this);
            var $btn = $form.find('.button-primary');

            $btn.attr('disabled', true).text('Сохранение...');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: $form.serialize() + '&action=fs_store_subject',
                success: function(response) {
                    if(response.success) {
                        location.reload();
                    } else {
                        alert('Ошибка: ' + (response.data || 'Неизвестная ошибка'));
                        $btn.attr('disabled', false).text('Создать предмет и CPT');
                    }
                },
                error: function() {
                    alert('Сбой сервера. Проверьте консоль браузера.');
                    $btn.attr('disabled', false).text('Создать предмет и CPT');
                }
            });
        });
    });
</script>