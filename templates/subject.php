<?php
/**
 * templates/subject.php
 * @var array $args
 */

/** @var \Inc\DTO\SubjectViewDTO $dto */
$dto = $args['data'];
$subject = $dto->subject_data;

// Логика активного таба из URL
$active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'tab-1';

$tabs = [
        'tab-1' => [
                'title' => 'Статистика',
                'file'  => '/components/tabs/subject-tabs/subject-1-stats.php',
        ],
        'tab-2' => [
                'title' => 'Задания',
                'file'  => '/components/tabs/subject-tabs/subject-2-tasks.php',
        ],
        'tab-3' => [
                'title' => 'Статьи',
                'file'  => '/components/tabs/subject-tabs/subject-3-articles.php',
        ],
        'tab-4' => [
                'title' => 'Таксономии',
                'file'  => '/components/tabs/subject-tabs/subject-4-taxonomies.php',
        ],
        'tab-5' => [
                'title' => 'Менеджер заданий',
                'file'  => '/components/tabs/subject-tabs/subject-5-task-manager.php',
        ],
];
?>

    <div class="wrap fs-lms-dashboard">
        <h1>Управление предметом: <?php echo esc_html($subject->name ?? 'Без названия'); ?></h1>

        <h2 class="nav-tab-wrapper">
            <?php foreach ($tabs as $tab_id => $tab) : ?>
                <a href="?page=<?php echo esc_attr($_GET['page'] ?? ''); ?>&tab=<?php echo esc_attr($tab_id); ?>"
                   class="nav-tab <?php echo $active_tab === $tab_id ? 'nav-tab-active' : ''; ?>">
                    <?php echo esc_html($tab['title']); ?>
                </a>
            <?php endforeach; ?>
        </h2>

        <div class="tab-content" style="background:#fff; border:1px solid #ccc; border-top:none; padding: 20px;">
            <?php
            if (isset($tabs[$active_tab])) {
                // rtrim нужен, чтобы не было двойного слэша // между путем и файлом
                $file_path = rtrim(plugin_dir_path(__FILE__), '/') . $tabs[$active_tab]['file'];

                if (file_exists($file_path)) {
                    include $file_path;
                } else {
                    echo "<div class='notice notice-error'><p>Файл не найден: <code>{$file_path}</code></p></div>";
                }
            }
            ?>
        </div>
    </div>

<?php
// Подключение модального окна (путь относительно папки templates/)
$modal_path = rtrim(plugin_dir_path(__FILE__), '/') . '/components/modals/taxonomy-modal.php';
if (file_exists($modal_path)) {
    include $modal_path;
}
?>