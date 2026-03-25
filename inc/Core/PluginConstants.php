<?php

    namespace Inc\Core;

    /**
     * Централизованное хранение констант плагина.
     * Упрощает поддержку и исключает магические строки.
     */
    final class PluginConstants
    {
        // Slugs
        public const MAIN_MENU_SLUG = 'fs_lms';
        public const SUBJECTS_MENU_SLUG = 'fs_subjects';

        // Capabilities
        public const ADMIN_CAPABILITY = 'manage_options';

        // Option groups
        public const SETTINGS_GROUP = 'fs_tasks_settings_group';
        public const SETTINGS_OPTION = 'fs_tasks_settings';

        // Pages
        public const SETTINGS_PAGE = 'fs_tasks';
    }