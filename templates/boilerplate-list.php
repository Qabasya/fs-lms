<?php
/**
 * Шаблон списка типовых условий (boilerplate) для конкретного задания.
 *
 * @var string $subject Ключ предмета (например, "math")
 * @var string $term Слаг типа задания (например, "1", "2")
 * @var \Inc\DTO\TaskTypeBoilerplateDTO[] $boilerplates Массив DTO типовых условий
 */

use Inc\Enums\Nonce;

// ============================ ПОДГОТОВКА ДАННЫХ ============================

// Формируем название таксономии для получения объекта термина
$taxonomy = $subject . '_task_number';

// Получаем объект термина по его слагу
$term_object = get_term_by('slug', $term, $taxonomy);

// Определяем отображаемое имя: если описание есть — берем его, иначе оставляем слаг
$display_name = ($term_object && !empty($term_object->description))
        ? $term_object->description
        : $term;

// Получаем массив всех предметов из опций
$all_subjects = get_option('fs_lms_subjects_list', []);

// Поиск названия предмета по ключу
$subject_display_name = $subject; // Значение по умолчанию

if (!empty($all_subjects)) {
    foreach ($all_subjects as $s) {
        if ($s['key'] === $subject) {
            $subject_display_name = $s['name'];
            break;
        }
    }
}
?>

<div class="wrap">
    <!-- Nonce-поле для безопасности (используется в JS при удалении) -->
    <?php wp_nonce_field(Nonce::SaveBoilerplate->value, 'nonce'); ?>

    <h1 class="wp-heading-inline">
        Типовые условия<br>
        <hr>
        <?php echo esc_html($display_name); ?> / <?php echo esc_html($subject_display_name); ?>
    </h1>

    <!-- Кнопка добавления нового типового условия -->
    <a href="<?php echo admin_url("admin.php?page=fs_boilerplate_manager&subject=$subject&term=$term&action=new"); ?>" class="page-title-action">
        Добавить новое
    </a>

    <hr class="wp-header-end">

    <!-- Таблица списка типовых условий -->
    <table class="wp-list-table widefat fixed striped">
        <thead>
        <tr>
            <th class="column-primary">Название шаблона</th>
            <th>Статус</th>
            <th style="width: 150px;">Действия</th>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($boilerplates)) : ?>
            <tr>
                <td colspan="3">Для этого задания еще не создано типовых условий.</td>
            </tr>
        <?php else : ?>
            <?php foreach ($boilerplates as $bp) : ?>
                <tr>
                    <td class="column-primary">
                        <strong>
                            <a href="<?php echo admin_url("admin.php?page=fs_boilerplate_manager&subject=$subject&term=$term&action=edit&uid={$bp->uid}"); ?>">
                                <?php echo esc_html($bp->title); ?>
                            </a>
                        </strong>
                    </td>
                    <td>
                        <?php if ($bp->is_default) : ?>
                            <span class="badge" style="background: #c6e1c6; color: #236b23; padding: 2px 8px; border-radius: 4px;">
                                    По умолчанию
                                </span>
                        <?php else : ?>
                            <span style="color: #999;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?php echo admin_url("admin.php?page=fs_boilerplate_manager&subject=$subject&term=$term&action=edit&uid={$bp->uid}"); ?>">
                            Изменить
                        </a>
                        |
                        <a href="#" class="delete-boilerplate-link" style="color: #a00;" data-uid="<?php echo esc_attr($bp->uid); ?>">
                            Удалить
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <!-- Кнопка возврата в менеджер заданий-->
    <p>
        <?php
        // Формируем URL: страница предмета + таб 5
        $back_url = add_query_arg([
                'page' => 'fs_subject_' . $subject,
                'tab'  => 'tab-5'
        ], admin_url('admin.php'));
        ?>
        <a href="<?php echo esc_url($back_url); ?>" class="button">
            &larr; Назад в Менеджер заданий
        </a>
    </p>
</div>

<script>
    /**
     * Обработчик удаления типового условия.
     * При клике на ссылку удаления показывает подтверждение
     * и отправляет AJAX-запрос.
     */
    jQuery(document).ready(function($) {
        $('.delete-boilerplate-link').on('click', function(e) {
            e.preventDefault();

            const uid = $(this).data('uid');
            const nonce = $('#nonce').val();

            if (confirm('Вы уверены, что хотите удалить это типовое условие?')) {
                $.post(ajaxurl, {
                    action: 'delete_boilerplate',
                    uid: uid,
                    subject_key: '<?php echo esc_js($subject); ?>',
                    term_slug: '<?php echo esc_js($term); ?>',
                    security: nonce
                }, function(response) {
                    if (response.success) {
                        // Перезагрузка страницы после успешного удаления
                        location.reload();
                    } else {
                        alert('Ошибка: ' + response.data.message);
                    }
                });
            }
        });
    });
</script>