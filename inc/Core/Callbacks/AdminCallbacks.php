<?php

    namespace Inc\Core\Callbacks;

    use Inc\Core\BaseController;
    use Inc\Shared\Traits\TemplateRenderer;
    use Inc\Api\SubjectRepository;

    class AdminCallbacks extends BaseController
    {
        use TemplateRenderer;

        protected SubjectRepository $subjects;

        public function __construct(SubjectRepository $subjects) {
            parent::__construct();
            $this->subjects = $subjects;

            // Регистрируем AJAX обработчик
            add_action('wp_ajax_fs_store_subject', [$this, 'storeSubject']);
        }

        public function storeSubject(): void {
            // Проверка безопасности
            check_ajax_referer('fs_subject_nonce', 'security');

            if (!current_user_can('manage_options')) {
                wp_send_json_error('Нет прав');
            }

            $name = sanitize_text_field($_POST['name']);
            $key = sanitize_title($_POST['key']);

            if (empty($name) || empty($key)) {
                wp_send_json_error('Заполните все поля');
            }

            // Сохраняем в наш репозиторий (который работает с Options)
            $result = $this->subjects->create($name, $key);

            if ($result) {
                // Очищаем правила ссылок, чтобы новые CPT сразу работали
                flush_rewrite_rules();
                wp_send_json_success();
            }

            wp_send_json_error('Не удалось сохранить');
        }

        public function adminDashboard(): void {
            // Получаем данные, чтобы передать их в переменную $subjects в шаблоне
            $all_subjects = $this->subjects->get_all();
            $this->render('admin', ['subjects' => $all_subjects]);
        }

        public function adminImport(): void {
            $this->render('import');
        }

        public function checkboxField(array $args): void {
            $name = $args['label_for'];
            $option_name = $args['option_name'];
            $options = get_option($option_name);

            // Проверяем значение
            $checked = isset($options[$name]) ? ($options[$name] ? true : false) : false;

            echo '<input type="checkbox" id="' . $name . '" name="' . $option_name . '[' . $name . ']" value="1" ' . ($checked ? 'checked' : '') . '>';
        }

        public function subjectsDashboard(): void {
            // Можно просто переиспользовать тот же шаблон со списком
            $all_subjects = $this->subjects->get_all();
            $this->render('admin', ['subjects' => $all_subjects]);
        }

        public function subjectPage(): void {
            // Получаем ключ предмета из текущего URL страницы админки
            $page = $_GET['page'] ?? '';
            $key = str_replace('fs_subject_', '', $page);

            $all_subjects = $this->subjects->get_all();
            $current_subject = $all_subjects[$key] ?? null;

            if (!$current_subject) {
                echo "Предмет не найден";
                return;
            }

            echo '<div class="wrap">';
            echo '<h1>Управление предметом: ' . esc_html($current_subject['name']) . '</h1>';
            echo '<div class="card" style="max-width: 100%; margin-top: 20px; padding: 20px;">';
            echo '<h3>Контент предмета</h3>';

            // Генерируем прямые ссылки на списки CPT, которые мы скрыли из меню
            $tasks_link = admin_url("edit.php?post_type={$key}_tasks");
            $articles_link = admin_url("edit.php?post_type={$key}_articles");

            echo "<a href='{$tasks_link}' class='button button-primary'>Перейти к Заданиям</a> ";
            echo "<a href='{$articles_link}' class='button button-secondary'>Перейти к Статьям</a>";

            echo '</div></div>';
        }
    }