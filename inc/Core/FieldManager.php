<?php

    namespace Inc\Core;
    /**
     * Работа с наполнением меню - полями и секциями
     */
    class FieldManager
    {
        public function register(array $settings, array $sections, array $fields): void {
            if (empty($settings)) return;

            add_action('admin_init', function() use ($settings, $sections, $fields) {
                foreach ($settings as $setting) {
                    register_setting(
                        $setting["option_group"],
                        $setting["option_name"],
                        ($setting["callback"] ?? ''));
                }
                foreach ($sections as $section) {
                    add_settings_section(
                        $section["id"],
                        $section["title"],
                        ($section["callback"] ?? ''),
                        $section["page"]);
                }
                foreach ($fields as $field) {
                    add_settings_field(
                        $field["id"],
                        $field["title"],
                        ($field["callback"] ?? ''),
                        $field["page"],
                        $field["section"],
                        ($field["args"] ?? ''));
                }
            });
        }
    }