<?php

    namespace Inc\Core;

    /**
     * Класс-адаптер для регистрации страниц и полей настроек
     * Нужен для изоляции от прямого вызова функций WP
     */
    class SettingsApi
    {
        public array $pages = [];
        public array $subpages = [];

        /**
         * Добавление пунктов меню (страниц)
         */
        public function add_pages(array $pages): self {
            $this->pages = $pages;
            return $this;
        }

        /*
 * Добавление подпунктов меню
 */
        public function add_sub_pages(array $subpages): self {
            $this->subpages = $subpages;
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

            add_action('admin_menu', [$this, 'add_admin_menu']);
        }


        /**
         * Добавление основного пункта меню (через функции WP)
         * @return void
         */
        public function add_admin_menu(): void {
            foreach ($this->pages as $page) {
                add_menu_page(
                    $page['page_title'],
                    $page['menu_title'],
                    $page['capability'],
                    $page['menu_slug'],
                    $page['callback'],
                    $page['icon_url'],
                    $page['position']);
            }
            foreach ($this->subpages as $subpage) {
                add_submenu_page(
                    $subpage['parent_slug'],
                    $subpage['page_title'],
                    $subpage['menu_title'],
                    $subpage['capability'],
                    $subpage['menu_slug'],
                    $subpage['callback']);
            }
        }
    }