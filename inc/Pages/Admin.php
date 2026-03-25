<?php

    namespace Inc\Pages;

    use Inc\Core\BaseController;
    use Inc\Core\Service;
    use Inc\Core\SettingsApi;
    use Inc\Core\Callbacks\AdminCallbacks;
    use Inc\Api\SubjectRepository;

    /**
     * Этот класс отвечает только за конфигурацию меню.
     */
    class Admin extends BaseController implements Service
    {
        public SettingsApi $settings;
        public AdminCallbacks $callbacks;
        public SubjectRepository $subjects;

        public function __construct(SettingsApi $settings, AdminCallbacks $callbacks, SubjectRepository $subjects) {
            parent::__construct();
            $this->settings = $settings;
            $this->callbacks = $callbacks;
            $this->subjects = $subjects;
        }

        public function register(): void {
            $pages = [
                // Это основной пункт FS LMS
                [
                    'page_title' => 'FS LMS Dashboard',
                    'menu_title' => 'FS LMS',
                    'capability' => 'manage_options',
                    'menu_slug'  => 'fs_lms', // Это ключ всей группы
                    'callback'   => [$this->callbacks, 'adminDashboard'], // Это страница по умолчанию
                    'icon_url'   => 'dashicons-welcome-learn-more',
                    'position'   => 4
            ]];

            $subpages = [
                // Изменяем этот пункт:
                [
                    'parent_slug' => 'fs_lms',
                    'page_title'  => 'Управление предметами',
                    'menu_title'  => 'FS Tasks', // Теперь это будет ПЕРВЫЙ пункт (вместо "Главная")
                    'capability'  => 'manage_options',
                    'menu_slug'   => 'fs_lms', // ВАЖНО: слаг совпадает с родителем!
                    'callback'    => [$this->callbacks, 'adminDashboard'],
                ],
                [
                    'parent_slug' => 'fs_lms',
                    'page_title'  => 'Импорт заданий',
                    'menu_title'  => 'Импорт',
                    'capability'  => 'manage_options',
                    'menu_slug'   => 'fs_lms_import',
                    'callback'    => [$this->callbacks, 'adminImport'],
                ]
            ];

            // Получаем предметы из базы и добавляем их в подменю
            $all_subjects = $this->subjects->get_all();
            foreach ($all_subjects as $subject) {
                $subpages[] = [
                    'parent_slug' => 'fs_lms',
                    'page_title'  => $subject['name'],
                    'menu_title'  => '— ' . $subject['name'],
                    'capability'  => 'manage_options',
                    'menu_slug'   => 'fs_subject_' . $subject['id'],
                    'callback'    => [$this->callbacks, 'subjectPage'],
                ];
            }

            $this->setSettings();
            $this->setSections();
            $this->setFields();

            $this->settings
                ->add_pages($pages)
                ->add_sub_pages($subpages)
                ->register();
        }

        private function setSettings(): void {
            $args = [[
                'option_group' => 'fs_tasks_settings_group',
                'option_name'  => 'fs_tasks_settings',
                'callback'     => null
            ]];
            $this->settings->set_settings($args);
        }

        private function setSections(): void {
            $args = [[
                'id'       => 'fs_tasks_admin_index',
                'title'    => 'Глобальные переключатели', // Поменяли заголовок секции
                'callback' => function() { echo 'Включите необходимые модули:'; },
                'page'     => 'fs_tasks'
            ]];
            $this->settings->set_sections($args);
        }

        private function setFields(): void {
            $args = [
                [
                    'id'       => 'python_course',
                    'title'    => 'Курс Python (для школьников)',
                    'callback' => [$this->callbacks, 'checkboxField'],
                    'page'     => 'fs_tasks',
                    'section'  => 'fs_tasks_admin_index',
                    'args'     => [
                        'label_for'   => 'python_course',
                        'class'       => 'ui-toggle',
                        'option_name' => 'fs_tasks_settings'
                    ]
                ],
                [
                    'id'       => 'ege_cs',
                    'title'    => 'ЕГЭ Информатика',
                    'callback' => [$this->callbacks, 'checkboxField'],
                    'page'     => 'fs_tasks',
                    'section'  => 'fs_tasks_admin_index',
                    'args'     => [
                        'label_for'   => 'ege_cs',
                        'class'       => 'ui-toggle',
                        'option_name' => 'fs_tasks_settings'
                    ]
                ]
            ];
            $this->settings->set_fields($args);
        }
    }