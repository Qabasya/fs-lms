<?php

    namespace Inc\Pages;

    use Inc\Core\BaseController;
    use Inc\Core\Service;
    use Inc\Core\SettingsApi;
    use Inc\Core\Callbacks\AdminCallbacks;

    /**
     * Этот класс отвечает только за конфигурацию меню.
     */
    class Admin extends BaseController implements Service
    {
        public SettingsApi $settings;
        public AdminCallbacks $callbacks;

        public function __construct(SettingsApi $settings, AdminCallbacks $callbacks) {
            parent::__construct();
            $this->settings = $settings;
            $this->callbacks = $callbacks;
        }

        public function register(): void {
            $pages = [[
                'page_title' => 'Настройки FS Tasks',
                'menu_title' => 'Задания',
                'capability' => 'manage_options',
                'menu_slug'  => 'fs_tasks',
                'callback'   => [$this->callbacks, 'adminDashboard'],
                'icon_url'   => 'dashicons-admin-generic',
                'position'   => 110
            ]];

            $subpages = [[
                'parent_slug' => 'fs_tasks',
                'page_title'  => 'Импорт заданий',
                'menu_title'  => 'Импорт',
                'capability'  => 'manage_options',
                'menu_slug'   => 'fs_tasks_import',
                'callback'    => [$this->callbacks, 'adminImport'],
            ]];

            $this->settings
                ->add_pages($pages)
                ->add_sub_pages($subpages)
                ->register();
        }
    }