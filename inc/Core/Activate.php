<?php

    namespace Inc\Core;

    use Inc\Api\Database;

    class Activate
    {
        public static function activate(): void {
            flush_rewrite_rules();

            // Создаём БД с предметами
            $db = new Database();
            $db->create_tables();
        }
    }