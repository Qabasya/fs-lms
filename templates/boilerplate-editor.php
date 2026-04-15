<?php
/**
 * Шаблон редактора типового условия (boilerplate).
 *
 * Позволяет создавать и редактировать типовые условия для заданий.
 * Форма содержит:
 * - Название условия (для отображения в списке выбора)
 * - Редакторы для каждого поля условия (из шаблона)
 * - Опцию "Использовать по умолчанию"
 *
 * @var string $subject      Ключ предмета (например, "math")
 * @var string $term         Слаг типа задания (например, "1")
 * @var string|null $template_id ID визуального шаблона
 * @var \Inc\DTO\TaskTypeBoilerplateDTO|null $boilerplate DTO существующего boilerplate (null для нового)
 * @var array<string, array{label: string}> $fields Поля условий для текущего шаблона
 */

// Определение режима (создание или редактирование)
use Inc\Enums\Nonce;

$is_edit = !empty($boilerplate);
$title   = $is_edit ? 'Редактировать условие' : 'Добавить новое типовое условие';
$uid     = $is_edit ? $boilerplate->uid : uniqid('bp_');

// Декодирование содержимого boilerplate для заполнения полей
$raw_content = $is_edit ? $boilerplate->content : '';
$values = [];

if (!empty($raw_content)) {
    $decoded = json_decode($raw_content, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $values = $decoded;
    } else {
        $values['task_condition'] = $raw_content;
    }
}
?>

<div class="wrap">
    <h1 class="wp-heading-inline"><?php echo esc_html($title); ?></h1>
    <hr class="wp-header-end">

    <form id="fs-lms-boilerplate-form" method="post" class="standard-form">
        <!-- Скрытые поля формы -->
        <input type="hidden" name="action" value="save_boilerplate">
        <input type="hidden" name="subject_key" value="<?php echo esc_attr($subject); ?>">
        <input type="hidden" name="term_slug" value="<?php echo esc_attr($term); ?>">
        <input type="hidden" name="uid" value="<?php echo esc_attr($uid); ?>">
        <?php wp_nonce_field( Nonce::SaveBoilerplate->value, 'nonce' ); ?>

        <div id="poststuff">
            <div id="post-body" class="metabox-holder columns-2">

                <!-- ============================ ОСНОВНОЙ КОНТЕНТ ============================ -->
                <div id="post-body-content">
                    <!-- Поле названия условия -->
                    <div class="boilerplate-title-section" style="margin-bottom: 20px;">
                        <label for="bp_title"><strong>Название условия (для списка выбора):</strong></label>
                        <input type="text" name="title" id="bp_title" class="widefat"
                               value="<?php echo esc_attr($is_edit ? $boilerplate->title : ''); ?>"
                               placeholder="Например: одна куча" required>
                    </div>

                    <!-- Редакторы для полей условий -->
                    <div class="boilerplate-editors-section">
                        <?php if (empty($fields)) : ?>
                            <div class="notice notice-warning inline">
                                <p>Поля для шаблона "<?php echo esc_html($template_id); ?>" не определены.</p>
                            </div>
                        <?php else : ?>
                            <?php foreach ($fields as $id => $config) : ?>
                                <div class="boilerplate-field-group" style="margin-bottom: 30px;">
                                    <label><strong><?php echo esc_html($config['label']); ?>:</strong></label>
                                    <?php
                                    $content = $values[$id] ?? '';
                                    $editor_id = 'editor_' . str_replace('-', '_', $id);

                                    // WP Editor с автоматическим сохранением при изменениях
                                    wp_editor($content, $editor_id, [
                                            'textarea_name' => "content[$id]",
                                            'textarea_rows' => 12,
                                            'media_buttons' => true,
                                            'tinymce'       => [
                                                    'setup' => 'function(ed) { ed.on("change", function() { ed.save(); }); }'
                                            ]
                                    ]);
                                    ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ============================ САЙДБАР ============================ -->
                <div id="postbox-container-1" class="postbox-container">
                    <!-- Метабокс сохранения -->
                    <div class="postbox">
                        <h2 class="hndle"><span>Сохранение</span></h2>
                        <div class="inside">
                            <p><strong>Шаблон:</strong> <code><?php echo esc_html($template_id); ?></code></p>

                            <p>
                                <label>
                                    <input type="checkbox" name="is_default" value="1" <?php checked($is_edit && $boilerplate->is_default); ?>>
                                    Использовать по умолчанию
                                </label>
                            </p>

                            <div id="major-publishing-actions">
                                <div id="publishing-action">
                                    <input type="submit" class="button button-primary button-large" value="Сохранить шаблон">
                                </div>
                                <div class="clear"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Кнопка возврата к списку -->
                    <p>
                        <a href="<?php echo admin_url("admin.php?page=fs_boilerplate_manager&subject=$subject&term=$term"); ?>" class="button">
                            &larr; Назад к списку
                        </a>
                    </p>
                </div>

            </div>
        </div>
    </form>
</div>

<style>
    /* Стили для групп полей boilerplate */
    .boilerplate-field-group {
        background: #fff;
        padding: 15px;
        border: 1px solid #ccd0d4;
        box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04);
    }

    .boilerplate-field-group label {
        display: block;
        margin-bottom: 10px;
        font-size: 14px;
    }
</style>