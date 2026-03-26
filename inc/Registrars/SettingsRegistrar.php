<?php

	namespace Inc\Registrars;

	use Inc\Managers\SettingsManager;

	/**
	 * Class SettingsRegistrar
	 *
	 * Фасад для регистрации настроек через WordPress Settings API.
	 *
	 * Предоставляет удобный интерфейс (Builder/Fluent Interface) для
	 * накопления конфигураций настроек, секций и полей. После накопления
	 * данных делегирует регистрацию низкоуровневому менеджеру.
	 *
	 * Паттерны:
	 * - Facade — упрощает интерфейс работы с SettingsManager
	 * - Fluent Interface — позволяет объединять вызовы в цепочку
	 * - Builder — накапливает данные перед регистрацией
	 *
	 * @package Inc\Registrars
	 */
	class SettingsRegistrar
	{
		/**
		 * Низкоуровневый менеджер для выполнения регистрации.
		 *
		 * @var SettingsManager
		 */
		private SettingsManager $manager;

		/**
		 * Массив конфигураций опций настроек.
		 *
		 * @var array<int, array{
		 *     option_group: string,
		 *     option_name: string,
		 *     callback?: callable|null
		 * }>
		 */
		private array $settings = [];

		/**
		 * Массив конфигураций секций настроек.
		 *
		 * @var array<int, array{
		 *     id: string,
		 *     title: string,
		 *     callback?: callable|string,
		 *     page: string
		 * }>
		 */
		private array $sections = [];

		/**
		 * Массив конфигураций полей настроек.
		 *
		 * @var array<int, array{
		 *     id: string,
		 *     title: string,
		 *     callback?: callable|string,
		 *     page: string,
		 *     section: string,
		 *     args?: array<string, mixed>|string
		 * }>
		 */
		private array $fields = [];

		/**
		 * Конструктор.
		 *
		 * @param SettingsManager $manager Менеджер для регистрации настроек
		 */
		public function __construct(SettingsManager $manager)
		{
			$this->manager = $manager;
		}

		/**
		 * Добавляет одну или несколько опций настроек.
		 *
		 * Поддерживает цепочку вызовов (Fluent Interface).
		 *
		 * @param array<int, array{
		 *     option_group: string,
		 *     option_name: string,
		 *     callback?: callable|null
		 * }> $settings Конфигурация опций
		 *
		 * @return self Для цепочки вызовов
		 */
		public function addSettings(array $settings): self
		{
			$this->settings = array_merge($this->settings, $settings);
			return $this;
		}

		/**
		 * Добавляет одну или несколько секций настроек.
		 *
		 * Поддерживает цепочку вызовов (Fluent Interface).
		 *
		 * @param array<int, array{
		 *     id: string,
		 *     title: string,
		 *     callback?: callable|string,
		 *     page: string
		 * }> $sections Конфигурация секций
		 *
		 * @return self Для цепочки вызовов
		 */
		public function addSections(array $sections): self
		{
			$this->sections = array_merge($this->sections, $sections);
			return $this;
		}

		/**
		 * Добавляет одно или несколько полей настроек.
		 *
		 * Поддерживает цепочку вызовов (Fluent Interface).
		 *
		 * @param array<int, array{
		 *     id: string,
		 *     title: string,
		 *     callback?: callable|string,
		 *     page: string,
		 *     section: string,
		 *     args?: array<string, mixed>|string
		 * }> $fields Конфигурация полей
		 *
		 * @return self Для цепочки вызовов
		 */
		public function addFields(array $fields): self
		{
			$this->fields = array_merge($this->fields, $fields);
			return $this;
		}

		/**
		 * Выполняет регистрацию всех накопленных настроек.
		 *
		 * Если опции настроек отсутствуют, регистрация не выполняется.
		 * Делегирует регистрацию SettingsManager.
		 *
		 * @return void
		 */
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