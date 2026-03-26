<?php

	namespace Inc\Registrars;

	/**
	 * Class PluginRegistrar
	 *
	 * Композитный регистратор плагина.
	 *
	 * Объединяет регистраторы меню и настроек в единый интерфейс.
	 * Реализует паттерн Компоновщик (Composite), позволяя работать
	 * с группой регистраторов как с единым объектом.
	 *
	 * Паттерны:
	 * - Composite — объединяет несколько регистраторов в один
	 * - Facade — предоставляет упрощённый интерфейс для регистрации
	 * - Aggregator — агрегирует результаты работы дочерних регистраторов
	 *
	 * @package Inc\Registrars
	 */
	class PluginRegistrar
	{
		/**
		 * Регистратор административного меню.
		 *
		 * @var MenuRegistrar
		 */
		private MenuRegistrar $menu;

		/**
		 * Регистратор настроек WordPress.
		 *
		 * @var SettingsRegistrar
		 */
		private SettingsRegistrar $settings;

		/**
		 * Конструктор.
		 *
		 * @param MenuRegistrar     $menu     Регистратор меню
		 * @param SettingsRegistrar $settings Регистратор настроек
		 */
		public function __construct(MenuRegistrar $menu, SettingsRegistrar $settings)
		{
			$this->menu = $menu;
			$this->settings = $settings;
		}

		/**
		 * Возвращает регистратор меню.
		 *
		 * Позволяет получить доступ к fluent-интерфейсу MenuRegistrar
		 * для добавления страниц и подстраниц.
		 *
		 * @return MenuRegistrar Регистратор меню
		 */
		public function menu(): MenuRegistrar
		{
			return $this->menu;
		}

		/**
		 * Возвращает регистратор настроек.
		 *
		 * Позволяет получить доступ к fluent-интерфейсу SettingsRegistrar
		 * для добавления настроек, секций и полей.
		 *
		 * @return SettingsRegistrar Регистратор настроек
		 */
		public function settings(): SettingsRegistrar
		{
			return $this->settings;
		}

		/**
		 * Выполняет регистрацию всех компонентов плагина.
		 *
		 * Последовательно вызывает регистрацию:
		 * 1. Административного меню
		 * 2. Настроек WordPress
		 *
		 * @return void
		 */
		public function register(): void
		{
			$this->menu->register();
			$this->settings->register();
		}
	}