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
                [
                    'page_title' => 'FS LMS',
                    'menu_title' => 'FS LMS',
                    'capability' => 'manage_options',
                    'menu_slug'  => 'fs_lms',
                    'callback'   => [$this->callbacks, 'adminDashboard'],
                    'icon_url'   => 'dashicons-welcome-learn-more',
                    'position'   => 4
                ]
            ];

            $subjects = $this->subjects->get_all();
            $subpages = [];

            /**
             * Если предметы есть — создаём меню "Предметы"
             */
            if (!empty($subjects)) {

                $pages[] = [
                    'page_title' => 'Предметы',
                    'menu_title' => 'Предметы',
                    'capability' => 'manage_options',
                    'menu_slug'  => 'fs_subjects',
                    'callback'   => [$this->callbacks, 'subjectsRoot'],
                    'icon_url'   => 'dashicons-category',
                    'position'   => 5
                ];

                foreach ($subjects as $key => $subject) {
                    $subpages[] = [
                        'parent_slug' => 'fs_subjects',
                        'page_title'  => $subject['name'],
                        'menu_title'  => $subject['name'],
                        'capability'  => 'manage_options',
                        'menu_slug'   => 'fs_subject_' . $key,
                        'callback'    => [$this->callbacks, 'subjectPage'],
                    ];
                }
            }

            /**
             * Подпункт настроек
             */
            $subpages[] = [
                'parent_slug' => 'fs_lms',
                'page_title'  => 'Настройки',
                'menu_title'  => 'Настройки',
                'capability'  => 'manage_options',
                'menu_slug'   => 'fs_lms',
                'callback'    => [$this->callbacks, 'adminDashboard'],
            ];

            $this->setSettings();
            $this->setSections();
            $this->setFields();

            $this->settings
                ->add_pages($pages)
                ->add_sub_pages($subpages)
                ->register();

            /**
             * Удаляем автоподпункт WordPress
             */
            add_action('admin_menu', function () {
                remove_submenu_page('fs_subjects', 'fs_subjects');
            }, 999);

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
                'title'    => 'Глобальные переключатели',
                'callback' => function() { echo 'Включите необходимые модули:'; },
                'page'     => 'fs_tasks'
            ]];
            $this->settings->set_sections($args);
        }

        // Хз зачем это тут, надо убрать потом
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