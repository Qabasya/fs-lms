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
class PluginRegistrar {
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
	 * Регистратор пользовательских типов записей для предметов.
	 *
	 * @var SubjectCPTRegistrar
	 */
	private SubjectCPTRegistrar $cpt;

	private SubjectTaxonomyRegistrar $taxonomy;

	/**
	 * Конструктор.
	 *
	 * @param MenuRegistrar $menu Регистратор меню
	 * @param SettingsRegistrar $settings Регистратор настроек
	 * @param SubjectCPTRegistrar $cpt Регистратор CPT предметов
	 */
	public function __construct(
		MenuRegistrar $menu,
		SettingsRegistrar $settings,
		SubjectCPTRegistrar $cpt,
		SubjectTaxonomyRegistrar $taxonomy
	) {
		$this->menu     = $menu;
		$this->settings = $settings;
		$this->cpt      = $cpt;
		$this->taxonomy = $taxonomy;
	}

	/**
	 * Возвращает регистратор меню.
	 *
	 * Позволяет получить доступ к fluent-интерфейсу MenuRegistrar
	 * для добавления страниц и подстраниц.
	 *
	 * @return MenuRegistrar Регистратор меню
	 */
	public function menu(): MenuRegistrar {
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
	public function settings(): SettingsRegistrar {
		return $this->settings;
	}

	/**
	 * Возвращает регистратор пользовательских типов записей.
	 *
	 * Позволяет получить доступ к fluent-интерфейсу SubjectCPTRegistrar
	 * для добавления CPT предметов.
	 *
	 * @return SubjectCPTRegistrar Регистратор CPT
	 */
	public function cpt(): SubjectCPTRegistrar {
		return $this->cpt;
	}

	public function taxonomy(): SubjectTaxonomyRegistrar {
		return $this->taxonomy;
	}

//	/** Пока вообще не нужен */
//	 * Выполняет регистрацию всех компонентов плагина.
//	 *
//	 * Последовательно вызывает регистрацию:
//	 * 1. Административного меню
//	 * 2. Настроек WordPress
//	 * 3. Пользовательских типов записей (CPT)
//	 *
//	 * @return void
//	 */
//	public function register(): void {
//		$this->menu->register();
//		$this->settings->register();
//		$this->cpt->register();
//		$this->taxonomy->register();
//	}

}