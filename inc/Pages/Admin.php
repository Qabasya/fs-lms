<?php
// Inc/Pages/Admin.php

    namespace Inc\Pages;

    use Inc\Core\BaseController;
    use Inc\Core\Service;
    use Inc\Core\PluginRegistrar;
    use Inc\Core\PluginConstants;
    use Inc\Core\Callbacks\AdminCallbacks;
    use Inc\Pages\Builders\SubjectsMenuBuilder;

    /**
     * Главный класс административного меню плагина.
     *
     * Ответственность: оркестрация регистрации меню и настроек.
     * Делегирует построение меню и конфигурацию билдерам.
     */
    class Admin extends BaseController implements Service
    {
        private PluginRegistrar         $registrar;
        private AdminCallbacks          $callbacks;
        private SubjectsMenuBuilder     $subjectsMenuBuilder;
        private SettingsConfigurator    $settingsConfigurator;

        public function __construct(
            PluginRegistrar         $registrar,
            AdminCallbacks          $callbacks,
            SubjectsMenuBuilder     $subjectsMenuBuilder,
            SettingsConfigurator    $settingsConfigurator
        ) {
            parent::__construct();

            $this->registrar = $registrar;
            $this->callbacks = $callbacks;
            $this->subjectsMenuBuilder = $subjectsMenuBuilder;
            $this->settingsConfigurator = $settingsConfigurator;
        }

        /**
         * Регистрирует все административные меню и настройки плагина.
         */
        public function register(): void
        {
            $this->settingsConfigurator->configure($this->registrar->settings());
            $pages = $this->buildMainPages();
            $subpages = $this->buildAllSubPages();


            // Регистрируем меню
            $this->registrar->menu()
                ->addPages($pages)
                ->addSubPages($subpages);

            // Убираем дублирующийся пункт меню WordPress
            $this->registrar->register();

            $this->removeAutoSubMenuItem();
        }

        /**
         * Строит конфигурацию главных страниц меню.
         */
        private function buildMainPages(): array
        {
            $pages = [
                [
                    'page_title' => 'FS LMS',
                    'menu_title' => 'FS LMS',
                    'capability' => PluginConstants::ADMIN_CAPABILITY,
                    'menu_slug'  => PluginConstants::MAIN_MENU_SLUG,
                    'callback'   => [$this->callbacks, 'adminDashboard'],
                    'icon_url'   => 'dashicons-welcome-learn-more',
                    'position'   => 4
                ]
            ];

            // Добавляем страницы предметов (если есть)
            return array_merge($pages, $this->subjectsMenuBuilder->buildPages());
        }

        /**
         * Собирает все подстраницы из разных источников.
         */
        private function buildAllSubPages(): array
        {
            $subpages = [];

            // Подстраницы предметов
            $subpages = array_merge($subpages, $this->subjectsMenuBuilder->buildSubPages());

            // Подстраница настроек (всегда последняя)
            $subpages[] = $this->buildSettingsSubPage();

            return $subpages;
        }

        /**
         * Конфигурация подстраницы настроек.
         */
        private function buildSettingsSubPage(): array
        {
            return [
                'parent_slug' => PluginConstants::MAIN_MENU_SLUG,
                'page_title'  => 'Настройки',
                'menu_title'  => 'Настройки',
                'capability'  => PluginConstants::ADMIN_CAPABILITY,
                'menu_slug'   => PluginConstants::MAIN_MENU_SLUG,
                'callback'    => [$this->callbacks, 'adminDashboard'],
            ];
        }

        /**
         * WordPress автоматически создаёт первый подпункт с тем же названием.
         * Удаляем его для чистоты меню.
         */
        private function removeAutoSubMenuItem(): void
        {
            add_action('admin_menu', function () {
                remove_submenu_page(
                    PluginConstants::SUBJECTS_MENU_SLUG,
                    PluginConstants::SUBJECTS_MENU_SLUG
                );
            }, 999);
        }
    }