<?php
// ========= ЗАГЛУШКА =========
/**
 * @var array $args Данные из SubjectController
 * [
 * 'data' => \Inc\DTO\SubjectViewDTO
 * ]
 */

/**
 * Шаблон страницы управления предметом.
 * * @var array $args Содержит объект DTO под ключом 'data'
 */

/** @var \Inc\DTO\SubjectViewDTO $dto */
$dto = $args['data'];

// Для удобства и чтобы не переписывать весь HTML,
// можно оставить эти локальные переменные, достав их из DTO
$subject = $dto->subject_data;
$key     = $dto->subject_key;
?>

<div class="wrap fs-lms-dashboard">
    <h1>Управление предметом: <?php echo esc_html( $subject->name ); ?></h1>

    <div class="fs-tabs">
        <input type="radio" name="fs_tabs" id="tab1" checked="checked">
        <label for="tab1">Задания</label>
        <div class="tab-content">
            <div class="card">
                <a href="<?php echo esc_url( $dto->tasks_url ); ?>" class="button button-primary">Перейти к Заданиям</a>
            </div>
        </div>

        <input type="radio" name="fs_tabs" id="tab2">
        <label for="tab2">Статьи</label>
        <div class="tab-content">
            <div class="card">
                <a href="<?php echo esc_url( $dto->articles_url ); ?>" class="button button-secondary">Перейти к Статьям</a>
            </div>
        </div>

        <input type="radio" name="fs_tabs" id="tab3">
        <label for="tab3">Таксономии</label>
        <div class="tab-content">
            <div class="tab-header" style="display: flex; justify-content: space-between; margin-bottom: 15px;">
                <h3>Дополнительные классификаторы</h3>
                <button type="button" class="button button-primary js-add-taxonomy">Добавить таксономию</button>
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
                <?php foreach ( $dto->taxonomies as $tax ) : ?>
                    <tr data-slug="<?php echo esc_attr( $tax->slug ); ?>" data-name="<?php echo esc_attr( $tax->name ); ?>">
                        <td class="column-name">
                            <strong><?php echo esc_html( $tax->name ); ?></strong>
                            <?php if ( $tax->slug === $dto->protected_tax ) : ?>
                                <span class="dashicons dashicons-lock" title="Системная"></span>
                            <?php endif; ?>
                        </td>
                        <td><code><?php echo esc_html( $tax->slug ); ?></code></td>
                        <td>
                            <a href="<?php echo admin_url( "edit-tags.php?taxonomy={$tax->slug}" ); ?>" class="button button-small">Термины</a>
                            <?php if ( $tax->slug !== $dto->protected_tax ) : ?>
                                | <a href="#" class="js-edit-tax">Изменить</a>
                                | <a href="#" class="js-delete-tax" style="color: #d63638;">Удалить</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <input type="radio" name="fs_tabs" id="tab4">
        <label for="tab4">Менеджер заданий</label>



        <div class="tab-content">
            <h3>Управление шаблонами и условиями типов заданий</h3>
            <p class="description">Настройте визуальный шаблон и типовое условие, которое будет автоматически подставляться при создании нового задания этого типа.</p>

            <table class="widefat fixed striped js-task-manager-table" data-subject="<?php echo esc_attr( $dto->subject_key ); ?>">
                <thead>
                <tr>
                    <th>Тип задания (Номер)</th>
                    <th style="width: 200px;">Визуальный шаблон</th>
                    <th style="width: 120px;">Настройка</th>
                    <th style="width: 40px;"></th>
                </tr>
                </thead>
                <tbody>
                <?php if ( ! empty( $dto->task_types ) ) : ?>
                    <?php foreach ( $dto->task_types as $type ) : ?>
                        <tr data-term-id="<?php echo $type->id; ?>"
                            data-task-slug="<?php echo esc_attr($type->slug); ?>"
                            data-task-name="<?php echo esc_attr($type->name); ?>">
                            <td>
                                <strong><?php echo esc_html( $type->name ); ?></strong>
                            </td>
                            <td>
                                <select class="js-change-term-template" style="width:100%">
                                    <?php foreach ( $dto->all_templates as $tpl ) : ?>
                                        <option value="<?php echo esc_attr( $tpl->id ); ?>" <?php selected( $type->current_template, $tpl->id ); ?>>
                                            <?php echo esc_html( $tpl->title ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <button type="button" class="button js-open-boilerplate-modal" title="Настроить текст условия">
                                    <span class="dashicons dashicons-admin-appearance" style="margin-top:4px;"></span> Текст
                                </button>
                            </td>
                            <td class="status-cell">
                                <span class="spinner" style="float:none; margin:0;"></span>
                                <span class="dashicons dashicons-yes js-success-icon" style="display:none; color:green; margin-top:4px;"></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr><td colspan="4">Типы заданий не найдены.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="fs-taxonomy-modal" class="fs-lms-modal" style="display:none;">
    <div class="fs-lms-modal-content">
        <h3 id="modal-title">Новая таксономия</h3>
        <input type="hidden" id="tax-subject-key" value="<?php echo esc_attr( $dto->subject_key ); ?>">
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

<div id="fs-boilerplate-modal" class="fs-lms-modal" style="display:none;">
    <div class="fs-lms-modal-content" style="width: 600px;">
        <h3>Настройка условия: <span id="boilerplate-task-name"></span></h3>
        <input type="hidden" id="boilerplate-subject-key" value="<?php echo esc_attr( $dto->subject_key ); ?>">
        <input type="hidden" id="boilerplate-term-slug" value="">

        <div id="boilerplate-fields-container">
        </div>

        <p class="description">Этот текст будет автоматически подставлен в поле «Условие» при создании нового задания данного типа.</p>

        <div style="text-align:right; margin-top:20px;">
            <span class="spinner js-boilerplate-spinner" style="float:none; margin: 0 10px 0 0;"></span>
            <button class="button js-boilerplate-modal-close">Отмена</button>
            <button class="button button-primary js-boilerplate-modal-save">Сохранить</button>
        </div>
    </div>
</div>

<?php
wp_enqueue_editor();
wp_enqueue_media();
// Обязательно вне вкладок, перед закрывающим тегом или скриптами
echo '<div style="display:none;">';
wp_editor( '', 'fs_lms_init_editor', [
        'tinymce'       => true,
        'quicktags'     => true,
        'media_buttons' => true
] );
echo '</div>';
?>


<style>
    /* Базовые стили модалок */
    .fs-lms-modal {
        position:fixed; top:0; left:0; width:100%; height:100%;
        background:rgba(0,0,0,0.5); z-index:9999;
    }
    .fs-lms-modal-content {
        background:#fff; width:400px; margin:100px auto;
        padding:20px; border-radius:5px; box-shadow: 0 5px 15px rgba(0,0,0,0.3);
    }

    .fs-tabs { display: flex; flex-wrap: wrap; margin-top: 20px; }
    .fs-tabs label { padding: 10px 20px; background: #e0e0e0; cursor: pointer; border: 1px solid #ccc; border-bottom: none; margin-right: 5px; }
    .fs-tabs input[type="radio"] { display: none; }
    .fs-tabs .tab-content { width: 100%; order: 1; display: none; padding: 20px; background: #fff; border: 1px solid #ccc; min-height: 200px; }
    .fs-tabs input[type="radio"]:checked + label { background: #fff; border-bottom: 1px solid #fff; margin-bottom: -1px; font-weight: bold; }
    .fs-tabs input[type="radio"]:checked + label + .tab-content { display: block; }
    .dashicons-lock { font-size: 14px; color: #999; vertical-align: middle; }
    .column-name { vertical-align: middle !important; }
</style>