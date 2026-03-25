<?php

    namespace Inc\Core;

    use Inc\Pages\Admin;



    final class Init
    {
        /**
         * Список всех модулей:
         * 1. Enqueue - подключение css/js
         * 2.
         */
        public static function getServices(): array {
            return [
                Enqueue::class,
                Admin::class,
                CPTManager::class
            ];
        }

        public static function run(): void {
            $container = new Container();
            foreach (self::getServices() as $class) {
                $service = $container->get($class);
                // Чтобы не обосраться проверяем, что есть интерфейс сервиса
                if ($service instanceof Service) {
                    $service->register();
                }
            }
        }
    }