<?php

    namespace Inc\Shared\Traits;

    trait TemplateRenderer
    {
        /*
         * Универсальный метод для загрузки шаблонов с передачей данных.
         * @param string $template_name Имя файла без .php
         * @param array $args Данные, которые станут переменными в шаблоне
         */
        protected function render(string $template_name, array $args = []): void {
            $file = $this->path("templates/{$template_name}.php");

            if (!file_exists($file)) {
                echo "";
                return;
            }

            if (!empty($args)) {
                extract($args);
            }

            require_once $file;

        }
    }