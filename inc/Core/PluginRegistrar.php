<?php

    namespace Inc\Core;

    /**
     * Композитный регистратор — объединяет меню и настройки.
     * Паттерн Composite для обратной совместимости.
     */
    class PluginRegistrar
    {
        private MenuRegistrar $menu;
        private SettingsRegistrar $settings;

        public function __construct(MenuRegistrar $menu, SettingsRegistrar $settings)
        {
            $this->menu = $menu;
            $this->settings = $settings;
        }

        public function menu(): MenuRegistrar
        {
            return $this->menu;
        }

        public function settings(): SettingsRegistrar
        {
            return $this->settings;
        }

        /**
         * Зарегистрировать всё.
         */
        public function register(): void
        {
            $this->menu->register();
            $this->settings->register();
        }
    }