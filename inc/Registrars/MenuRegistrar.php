<?php

namespace Inc\Registrars;

use Inc\Managers\MenuManager;

/**
 * Class MenuRegistrar
 *
 * Фасад для регистрации страниц административного меню.
 *
 * Предоставляет удобный интерфейс (Builder/Fluent Interface) для
 * накопления конфигураций страниц и подстраниц. После накопления
 * данных делегирует регистрацию низкоуровневому менеджеру.
 *
 * Паттерны:
 * - Facade — упрощает интерфейс работы с MenuManager
 * - Fluent Interface — позволяет объединять вызовы в цепочку
 * - Builder — накапливает данные перед регистрацией
 *
 * @package Inc\Registrars
 */
class MenuRegistrar {
	/**
	 * Низкоуровневый менеджер для выполнения регистрации.
	 *
	 * @var MenuManager
	 */
	private MenuManager $manager;
	
	/**
	 * Массив конфигураций главных страниц меню.
	 *
	 * @var array<int, array{
	 *     page_title: string,
	 *     menu_title: string,
	 *     capability: string,
	 *     menu_slug: string,
	 *     callback: callable,
	 *     icon_url: string,
	 *     position: int
	 * }>
	 */
	private array $pages = [];
	
	/**
	 * Массив конфигураций подстраниц меню.
	 *
	 * @var array<int, array{
	 *     parent_slug: string,
	 *     page_title: string,
	 *     menu_title: string,
	 *     capability: string,
	 *     menu_slug: string,
	 *     callback: callable
	 * }>
	 */
	private array $subpages = [];
	
	/**
	 * Конструктор.
	 *
	 * @param MenuManager $manager Менеджер для регистрации меню
	 */
	public function __construct( MenuManager $manager ) {
		$this->manager = $manager;
	}
	
	/**
	 * Добавляет одну или несколько главных страниц меню.
	 *
	 * Поддерживает цепочку вызовов (Fluent Interface).
	 *
	 * @param array<int, array{
	 *     page_title: string,
	 *     menu_title: string,
	 *     capability: string,
	 *     menu_slug: string,
	 *     callback: callable,
	 *     icon_url: string,
	 *     position: int
	 * }> $pages Конфигурация страниц
	 *
	 * @return self Для цепочки вызовов
	 */
	public function addPages( array $pages ): self {
		$this->pages = array_merge( $this->pages, $pages );
		
		return $this;
	}
	
	/**
	 * Добавляет одну или несколько подстраниц меню.
	 *
	 * Поддерживает цепочку вызовов (Fluent Interface).
	 *
	 * @param array<int, array{
	 *     parent_slug: string,
	 *     page_title: string,
	 *     menu_title: string,
	 *     capability: string,
	 *     menu_slug: string,
	 *     callback: callable
	 * }> $subpages Конфигурация подстраниц
	 *
	 * @return self Для цепочки вызовов
	 */
	public function addSubPages( array $subpages ): self {
		$this->subpages = array_merge( $this->subpages, $subpages );
		
		return $this;
	}
	
	/**
	 * Выполняет регистрацию всех накопленных страниц и подстраниц.
	 *
	 * Если главные страницы отсутствуют, регистрация не выполняется.
	 * Делегирует регистрацию MenuManager.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( empty( $this->pages ) ) {
			return;
		}
		
		$this->manager->register( $this->pages, $this->subpages );
	}
}