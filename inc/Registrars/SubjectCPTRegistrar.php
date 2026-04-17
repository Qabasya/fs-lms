<?php

namespace Inc\Registrars;

use Inc\Managers\CPTManager;

/**
 * Class SubjectCPTRegistrar
 *
 * Фасад для регистрации пользовательских типов записей (CPT) предметов.
 *
 * Предоставляет удобный интерфейс (Builder/Fluent Interface) для
 * накопления конфигураций CPT. Содержит хелпер-методы для быстрого
 * создания стандартных типов записей с предустановленными параметрами.
 *
 * После накопления данных делегирует регистрацию низкоуровневому менеджеру.
 *
 * Паттерны:
 * - Facade — упрощает интерфейс работы с CPTManager
 * - Fluent Interface — позволяет объединять вызовы в цепочку
 * - Builder — накапливает данные перед регистрацией
 *
 * @package Inc\Registrars
 */
class SubjectCPTRegistrar {
	/**
	 * Низкоуровневый менеджер для выполнения регистрации.
	 *
	 * @var CPTManager
	 */
	private CPTManager $manager;

	/**
	 * Массив конфигураций CPT, где ключ — slug типа записи,
	 * значение — аргументы для register_post_type().
	 *
	 * @var array<string, array>
	 */
	private array $post_types = array();

	/**
	 * Конструктор.
	 *
	 * @param CPTManager $manager Менеджер для регистрации CPT
	 */
	public function __construct( CPTManager $manager ) {
		$this->manager = $manager;
	}

	/**
	 * Добавляет тип записи в очередь на регистрацию.
	 *
	 * Поддерживает цепочку вызовов (Fluent Interface).
	 *
	 * @param string $slug Уникальный идентификатор типа записи
	 * @param array  $args Аргументы для register_post_type()
	 *
	 * @return self Для цепочки вызовов
	 */
	public function addPostType( string $slug, array $args ): self {
		$this->post_types[ $slug ] = $args;

		return $this;
	}

	/**
	 * Хелпер для быстрого создания стандартного типа записи.
	 *
	 * Автоматически заполняет стандартные настройки:
	 * - labels (множественное/единственное число, пункты меню)
	 * - public, has_archive, show_in_menu, show_in_rest, supports, rewrite
	 *
	 * @param string $slug     Уникальный идентификатор типа записи
	 * @param string $plural   Множественное название (для меню и заголовков)
	 * @param string $singular Единственное название
	 *
	 * @return self Для цепочки вызовов
	 */
	public function addStandardType( string $slug, string $plural, string $singular, array $args = array() ): self {
		$defaults = array(
			'labels'       => array(
				'name'          => $plural,
				'singular_name' => $singular,
				'menu_name'     => $plural,
				'add_new'       => "Добавить {$singular}",
				'add_new_item'  => "Добавить новый {$singular}",
				'edit_item'     => "Редактировать {$singular}",
				'all_items'     => "Все {$plural}",
			),
			'public'       => true,
			'has_archive'  => true,
			'show_in_menu' => false,
			'show_in_rest' => true,
			'supports'     => array( 'title' ),
			'rewrite'      => array(
				'slug'       => $slug,
				'with_front' => false,
			),
		);

		$final_args = array_replace_recursive( $defaults, $args );

		return $this->addPostType( $slug, $final_args );
	}


	/**
	 * Выполняет регистрацию всех накопленных типов записей.
	 *
	 * Делегирует регистрацию CPTManager.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->manager->register( $this->post_types );
	}
}
