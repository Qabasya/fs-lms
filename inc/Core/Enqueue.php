<?php

    namespace Inc\Core;

    /**
     * Отвечает за подключение ресурсов
     */
    class Enqueue extends BaseController implements Service

    {
        public function register():void {
            // hook: admin_enqueue_scripts https://developer.wordpress.org/reference/hooks/admin_enqueue_scripts/
            // Вешаем хук на функцию enqueue_assets
            // Админские ресурсы
            add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

            // Ресурсы фронтенда
            add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        }

        // Админские стили и скрипты
        public function enqueue_admin_assets(): void {
            // Подключаем стили css
            wp_enqueue_style(
                'fs-lms-admin-style',
                $this->url( 'assets/css/admin.min.css' ),
                array('wp-components'), // WordPress component styles dependency
                $this->plugin_version // Version for cache busting
            );

            // Enqueue admin JavaScript with dependencies
            wp_enqueue_script(
                'fs-lms-admin-script',
                $this->url( 'assets/js/admin.min.js' ),
                array('jquery', 'wp-api', 'wp-i18n'), // jQuery, WordPress API, and i18n
                $this->plugin_version, // Version for cache busting
                true // Load in footer for better performance
            );
        }

        // Пользовательские стили и скрипты
        public function enqueue_frontend_assets(): void {
            // Подключаем стили css
            wp_enqueue_style(
                'fs-lms-frontend-style',
                $this->url( 'assets/css/frontend.min.css' ),
                array(), // No dependencies for frontend CSS
                $this->plugin_version // Version for cache busting
            );

            // Enqueue frontend JavaScript with jQuery dependency
            wp_enqueue_script(
                'fs-lms-frontend-script',
                $this->url( 'assets/js/frontend.min.js' ),
                array('jquery'), // jQuery dependency
                $this->plugin_version, // Version for cache busting
                true // Load in footer
            );
        }

    }