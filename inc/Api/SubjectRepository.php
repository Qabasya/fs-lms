<?php

    namespace Inc\Api;


    /**
     * Репозиторий для работы с опциями WP
     */
    class SubjectRepository
    {
        private string $option_name = 'fs_lms_subjects_list';

        /**
         * Получить список всех созданных предметов (ключей)
         */
        public function get_all(): array {
            return get_option($this->option_name, []);
        }

        /**
         * Добавить новый предмет в список
         */
        public function create(string $label, string $key): bool {
            $subjects = $this->get_all();

            $subjects[$key] = [
                'name' => sanitize_text_field($label),
                'key'  => sanitize_title($key)
            ];

            return update_option($this->option_name, $subjects);
        }

    }