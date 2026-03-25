<?php

    namespace Inc\Core;

    /**
     * Базовый контроллер: хранит общие данные для всех сервисов плагина.
     */
    class BaseController
    {
        public string $plugin_path;
        public string $plugin_url;
        public string $plugin_name;
        public string $plugin_version = '0.0.1';

        // Объект создаётся в Init и там не передаются аргументы в конструктор
        public function __construct() {
            // Путь в корень плагина fs-tasks/
            $root_path = dirname(__FILE__, 2);

            $this->plugin_path = plugin_dir_path($root_path);
            $this->plugin_url = plugin_dir_url($root_path);
            $this->plugin_name = plugin_basename($root_path);
        }

        /**
         * Возвращает полный путь к файлу в папке плагина
         * @param string $path
         * @return string
         */
        public function path(string $path = ''): string {
            return $this->plugin_path . ltrim($path, '/\\');
        }

        /**
         * Возвращает путь до файлов плагина
         * @param string $path
         * @return string
         */
        public function url(string $path = ''): string {
            return $this->plugin_url . ltrim($path, '/');
        }

        /**
         * Возвращает имя плагина
         * @return string
         */
        public function pluginName(): string {
            return $this->plugin_name;
        }


    }