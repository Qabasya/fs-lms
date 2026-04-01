<?php
// ========= ЗАГЛУШКА =========
/**
 * @var array $args Данные из SubjectController
 * [
 * 'subject_key'   => string,
 * 'subject'       => array,
 * 'protected_tax' => string,
 * 'taxonomies'    => array
 * ]
 */
$subject = $args['subject'];
$key     = $args['subject_key'];
?>

<div class="wrap fs-lms-dashboard">
    <h1>Управление предметом: <?php echo esc_html( $subject['name'] ); ?></h1>

    <div class="fs-tabs">
        <input type="radio" name="fs_tabs" id="tab1" checked="checked">
        <label for="tab1">Задания</label>
        <div class="tab-content">
            <div class="card">
                <a href="<?php echo esc_url( $args['tasks_url'] ); ?>" class="button button-primary">Перейти к Заданиям</a>
            </div>
        </div>

        <input type="radio" name="fs_tabs" id="tab2">
        <label for="tab2">Статьи</label>
        <div class="tab-content">
            <div class="card">
                <a href="<?php echo esc_url( $args['articles_url'] ); ?>" class="button button-secondary">Перейти к Статьям</a>
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
                <?php foreach ( $args['taxonomies'] as $tax_slug => $tax_data ) :
                    $is_protected = ( $tax_slug === $args['protected_tax'] );
                    $display_name = $tax_data['name'] ?? $tax_slug;
                    ?>
                    <tr data-slug="<?php echo esc_attr( $tax_slug ); ?>" data-name="<?php echo esc_attr( $display_name ); ?>">
                        <td class="column-name">
                            <strong><?php echo esc_html( $display_name ); ?></strong>
                            <?php if ( $is_protected ) : ?>
                                <span class="dashicons dashicons-lock" title="Системная"></span>
                            <?php endif; ?>
                        </td>
                        <td><code><?php echo esc_html( $tax_slug ); ?></code></td>
                        <td>
                            <a href="<?php echo admin_url( "edit-tags.php?taxonomy=$tax_slug" ); ?>" class="button button-small">Термины</a>
                            <?php if ( ! $is_protected ) : ?>
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
            <h3>Управление шаблонами заданий</h3>
            <table class="widefat fixed striped js-task-manager-table">
                <thead>
                <tr>
                    <th>Задание</th>
                    <th style="width: 250px;">Шаблон</th>
                    <th style="width: 40px;"></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ( $args['tasks'] as $task ) :
                    $current_tpl = get_post_meta( $task->ID, '_fs_lms_template_type', true ) ?: 'standard_task';
                    ?>
                    <tr data-task-id="<?php echo $task->ID; ?>">
                        <td><strong><?php echo esc_html( $task->post_title ); ?></strong></td>
                        <td>
                            <select class="js-change-task-template" style="width:100%">
                                <?php foreach ( $args['all_templates'] as $id => $name ) : ?>
                                    <option value="<?php echo $id; ?>" <?php selected($current_tpl, $id); ?>><?php echo $name; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td class="status-cell">
                            <span class="spinner" style="float:none; margin:0;"></span>
                            <span class="dashicons dashicons-yes js-success-icon" style="display:none; color:green;"></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="fs-taxonomy-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999;">
    <div style="background:#fff; width:400px; margin:100px auto; padding:20px; border-radius:5px;">
        <h3 id="modal-title">Новая таксономия</h3>
        <input type="hidden" id="tax-subject-key" value="<?php echo esc_attr( $key ); ?>">
        <input type="hidden" id="tax-action" value="store">

        <p>
            <label>Название (например, "Тема"):</label><br>
            <input type="text" id="tax-name" class="regular-text" style="width:100%">
        </p>
        <p id="slug-container">
            <label>Slug (только латиница, например, "topic"):</label><br>
            <input type="text" id="tax-slug" class="regular-text" style="width:100%">
        </p>

        <div style="text-align:right; margin-top:20px;">
            <button class="button js-modal-close">Отмена</button>
            <button class="button button-primary js-modal-save">Сохранить</button>
        </div>
    </div>
</div>

<style>
    .fs-tabs { display: flex; flex-wrap: wrap; margin-top: 20px; }
    .fs-tabs label { padding: 10px 20px; background: #e0e0e0; cursor: pointer; border: 1px solid #ccc; border-bottom: none; margin-right: 5px; }
    .fs-tabs input[type="radio"] { display: none; }
    .fs-tabs .tab-content { width: 100%; order: 1; display: none; padding: 20px; background: #fff; border: 1px solid #ccc; }
    .fs-tabs input[type="radio"]:checked + label { background: #fff; border-bottom: 1px solid #fff; margin-bottom: -1px; }
    .fs-tabs input[type="radio"]:checked + label + .tab-content { display: block; }
    .dashicons-lock { font-size: 14px; color: #999; vertical-align: middle; }
</style>