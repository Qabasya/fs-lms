<?php
/**
 * Шаблон страницы настроек с вкладками.
 *
 * @var string $active_tab Текущая активная вкладка
 */

// Определение активной вкладки, по умолчанию tab-1
$active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'tab-1';

// Массив вкладок с заголовками и путями к файлам
$tabs = [
        'tab-1' => [
                'title' => 'Предметы',
                'file'  => '/components/tabs/subjects-manager.php',
        ],
        'tab-2' => [
                'title' => 'Вкладка 2',
                'file'  => '/components/tabs/tab-2.php',
        ],
        'tab-3' => [
                'title' => 'Вкладка 3',
                'file'  => '/components/tabs/tab-3.php',
        ],
];
?>

    <div class="wrap">
        <!-- Навигация по вкладкам -->
        <h2 class="nav-tab-wrapper">
            <?php foreach ($tabs as $tab_id => $tab) : ?>
                <a href="?page=<?php echo esc_attr($_GET['page'] ?? ''); ?>&tab=<?php echo esc_attr($tab_id); ?>"
                   class="nav-tab <?php echo $active_tab === $tab_id ? 'nav-tab-active' : ''; ?>">
                    <?php echo esc_html($tab['title']); ?>
                </a>
            <?php endforeach; ?>
        </h2>

        <!-- Содержимое активной вкладки -->
        <div class="tab-content">
            <?php
            // Подключение файла активной вкладки
            if (isset($tabs[$active_tab])) {
                include plugin_dir_path(__FILE__) . $tabs[$active_tab]['file'];
            }
            ?>
        </div>
    </div>

<?php
// Подключение модального окна для добавления предмета
include plugin_dir_path(__FILE__) . '/components/modals/add-subject-modal.php';
?>