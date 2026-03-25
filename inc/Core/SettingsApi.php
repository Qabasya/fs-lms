<?php

    namespace Inc\Core;

    /**
     * Класс-фасад для регистрации страниц и полей настроек
     * Нужен для изоляции от прямого вызова функций WP
     */
    class SettingsApi
    {
        protected MenuManager $menu_manager;
        protected FieldManager $fields_manager;

        private array $pages = [];
        private array $subpages = [];

        private array $settings = [];
        private array $sections = [];
        private array $fields = [];

        public function __construct(MenuManager $menu_manager, FieldManager $fields_manager) {
            $this->menu_manager = $menu_manager;
            $this->fields_manager = $fields_manager;
        }

        /**
         * Добавление пунктов меню (страниц)
         */
        public function add_pages(array $pages): self {
            $this->pages = $pages;
            return $this;
        }

        /**
         * Добавление подпунктов меню
         */
        public function add_sub_pages(array $subpages): self {
            $this->subpages = $subpages;
            return $this;
        }

        /**
         * Добавление настроек
         * @param array $settings
         * @return $this
         */
        public function set_settings(array $settings): self {
            $this->settings = $settings;
            return $this;
        }

        /**
         * Добавление секций
         * @param array $sections
         * @return $this
         */
        public function set_sections(array $sections): self {
            $this->sections = $sections;
            return $this;
        }

        /**
         * Добавление полей
         * @param array $fields
         * @return $this
         */
        public function set_fields(array $fields): self {
            $this->fields = $fields;
            return $this;
        }

        /**
         * Метод-регистратор. Проверяет, добавлены ли страницы, если да,
         * то регистрирует хук admin_menu. WP вызывает add_admin_menu()
         * @return void
         */
        public function register(): void {
            if (empty($this->pages)) return;

            if (!empty($this->subpages)) {
                $parent = $this->pages[0];

                // Проверяем: если первый элемент подменю НЕ совпадает с родителем,
                // только тогда добавляем автоматическую "Главную"
                if ($this->subpages[0]['menu_slug'] !== $parent['menu_slug']) {
                    $main_subpage = [[
                        'parent_slug' => $parent['menu_slug'],
                        'page_title'  => $parent['page_title'],
                        'menu_title'  => 'Главная',
                        'capability'  => $parent['capability'],
                        'menu_slug'   => $parent['menu_slug'],
                        'callback'    => $parent['callback'],
                    ]];
                    $this->subpages = array_merge($main_subpage, $this->subpages);
                }
            }

            // Управление передаётся менеджерам
            $this->menu_manager
                ->register($this->pages, $this->subpages);

            $this->fields_manager
                ->register($this->settings, $this->sections, $this->fields);
        }
    }