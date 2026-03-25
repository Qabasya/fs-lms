<?php

    namespace Inc\Core;

    /**
     * Фасад для регистрации настроек через WordPress Settings API.
     */
    class SettingsRegistrar
    {
        private FieldManager $manager;
        private array $settings = [];
        private array $sections = [];
        private array $fields = [];

        public function __construct(FieldManager $manager)
        {
            $this->manager = $manager;
        }

        public function addSettings(array $settings): self
        {
            $this->settings = array_merge($this->settings, $settings);
            return $this;
        }

        public function addSections(array $sections): self
        {
            $this->sections = array_merge($this->sections, $sections);
            return $this;
        }

        public function addFields(array $fields): self
        {
            $this->fields = array_merge($this->fields, $fields);
            return $this;
        }

        public function register(): void
        {
            if (empty($this->settings)) {
                return;
            }

            $this->manager->register(
                $this->settings,
                $this->sections,
                $this->fields
            );
        }
    }