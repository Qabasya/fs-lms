<?php

    namespace Inc\Controllers;

    use Inc\Callbacks\AdminCallbacks;
    use Inc\Core\BaseController;
    use Inc\Registrars\SettingsRegistrar;

    /**
     * Конфигурирует WordPress Settings API.
     * Отвечает за регистрацию настроек, секций и полей.
     */
    class SettingsConfigurator
    {
        private AdminCallbacks $callbacks;

        public function __construct(AdminCallbacks $callbacks)
        {
            $this->callbacks = $callbacks;
        }

        /**
         * Применить всю конфигурацию к Settings API.
         */
        public function configure(SettingsRegistrar $registrar): void
        {
            $registrar->addSettings($this->getSettings())
                ->addSections($this->getSections())
                ->addFields($this->getFields());
        }
        private function getSettings(): array
        {
            return [
                [
                    'option_group' => BaseController::SETTINGS_GROUP,
                    'option_name'  => BaseController::SETTINGS_OPTION,
                    'callback'     => null
                ]
            ];
        }


        // ДАЛЬШЕ НЕ ИСПОЛЬЗУЕТСЯ, НУЖНО ДЛЯ ТЕСТОВ ОТОБРАЖЕНИЯ
        private function getSections(): array {
            return [[
                'id'       => 'fs_tasks_admin_index',
                'title'    => 'Глобальные переключатели',
                'callback' => fn() => print 'Включите необходимые модули:',
                'page'     => BaseController::SETTINGS_PAGE
            ]];
        }
        /**
         * Возвращает конфигурацию полей настроек.
         *
         * TODO: Вынести в отдельный FieldsRegistry, когда полей станет больше.
         */
        private function getFields(): array
        {
            $baseArgs = [
                'class'       => 'ui-toggle',
                'option_name' => BaseController::SETTINGS_OPTION
            ];

            return [
                $this->createCheckboxField('inf_oge', 'ОГЭ Информатика', $baseArgs),
                $this->createCheckboxField('inf_ege', 'ЕГЭ Информатика', $baseArgs),
            ];
        }

        /**
         * Фабричный метод для создания checkbox-полей.
         * (пока не используется)
         */
        private function createCheckboxField(string $id, string $title, array $baseArgs): array
        {
            return [
                'id'       => $id,
                'title'    => $title,
                'callback' => [$this->callbacks, 'checkboxField'],
                'page'     => BaseController::SETTINGS_PAGE,
                'section'  => 'fs_tasks_admin_index',
                'args'     => array_merge($baseArgs, ['label_for' => $id])
            ];
        }

    }