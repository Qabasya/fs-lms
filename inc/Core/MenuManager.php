<?php

    namespace Inc\Core;

    /**
     * Работа с визуальным меню
     */
    class MenuManager
    {
        public function register(array $pages, array $subpages): void {
            if (empty($pages)) return;

            add_action('admin_menu', function() use ($pages, $subpages) {
                foreach ($pages as $page) {
                    add_menu_page(
                        $page['page_title'],
                        $page['menu_title'],
                        $page['capability'],
                        $page['menu_slug'],
                        $page['callback'],
                        $page['icon_url'],
                        $page['position']);
                }
                foreach ($subpages as $subpage) {
                    add_submenu_page(
                        $subpage['parent_slug'],
                        $subpage['page_title'],
                        $subpage['menu_title'],
                        $subpage['capability'],
                        $subpage['menu_slug'],
                        $subpage['callback']);
                }
            });
        }
    }