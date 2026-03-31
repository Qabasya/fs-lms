<?php

namespace Inc\Registrars;

use Inc\Managers\TaxonomyManager;

/**
 * Class SubjectTaxonomyRegistrar
 *
 * Фасад для формирования конфигураций таксономий.
 * Предоставляет Fluent Interface для накопления данных перед регистрацией.
 *
 * @package Inc\Registrars
 */
class SubjectTaxonomyRegistrar {
	/**
	 * Низкоуровневый менеджер для выполнения регистрации.
	 *
	 * @var TaxonomyManager
	 */
	private TaxonomyManager $manager;

	/**
	 * Очередь таксономий на регистрацию.
	 *
	 * Структура:
	 * [
	 *     'taxonomy_slug' => [
	 *         'post_types' => array|string,  // К каким CPT привязать
	 *         'args'       => array          // Аргументы register_taxonomy()
	 *     ]
	 * ]
	 *
	 * @var array<string, array{post_types: array|string, args: array}>
	 */
	private array $taxonomies = [];

	/**
	 * Конструктор.
	 *
	 * @param TaxonomyManager $manager Менеджер для регистрации таксономий
	 */
	public function __construct( TaxonomyManager $manager ) {
		$this->manager = $manager;
	}

	/**
	 * Базовый метод добавления таксономии.
	 *
	 * Позволяет добавить таксономию с полным контролем над аргументами.
	 *
	 * @param string $slug Слаг таксономии (уникальный идентификатор)
	 * @param array|string $post_types К какому CPT привязать (массив или строка)
	 * @param array $args Аргументы для register_taxonomy()
	 *
	 * @return self Для цепочки вызовов (Fluent Interface)
	 */
	public function addTaxonomy( string $slug, array|string $post_types, array $args ): self {
		$this->taxonomies[ $slug ] = [
			'post_types' => $post_types,
			'args'       => $args
		];

		return $this;
	}

	/**
	 * Хелпер для создания стандартной иерархической таксономии.
	 *
	 * Создаёт таксономию с полным набором меток (как категории).
	 * Подходит для: Год, Автор, Тема, Раздел и т.п.
	 *
	 * @param string $slug Слаг таксономии
	 * @param array|string $post_types К какому CPT привязать
	 * @param string $plural Множественное название (например, "Года")
	 * @param string $singular Единственное название (например, "Год")
	 *
	 * @return self Для цепочки вызовов
	 */
	public function addStandardTaxonomy( string $slug, array|string $post_types, string $plural, string $singular ): self {
		return $this->addTaxonomy( $slug, $post_types, [
			'labels'            => [
				'name'              => $plural,
				'singular_name'     => $singular,
				'search_items'      => "Найти $plural",
				'all_items'         => "Все $plural",
				'view_item '        => "Просмотр  $singular",
				'parent_item'       => "Родитель $singular",
				'parent_item_colon' => "Родитель $singular:",
				'edit_item'         => "Изменить $singular",
				'update_item'       => "Обновить $singular",
				'add_new_item'      => "Добавить новый $singular",
				'new_item_name'     => "Название нового $singular",
				'menu_name'         => $plural,
				'back_to_items'     => '← Назад к $singular',
			],
			'hierarchical'      => false,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => true, // Для поддержки Gutenberg
			'rewrite'           => [ 'slug' => $slug ],
		] );
	}

	/**
	 * Хелпер для "фиксированных" таксономий с ограниченными правами.
	 *
	 * Создаёт плоскую (неиерархическую) таксономию, где термины нельзя редактировать
	 * обычным пользователям. Подходит для: Номера заданий, Типы и т.п.
	 *
	 * @param string $slug Слаг таксономии
	 * @param array $object_types К какому CPT привязать (только массив)
	 * @param string $name Множественное название
	 * @param string $singular_name Единственное название
	 * @param array $extra_args Дополнительные аргументы (переопределяют стандартные)
	 *
	 * @return self Для цепочки вызовов
	 */
	public function addFixedTaxonomy( string $slug, array $object_types, string $name, string $singular_name, array $extra_args = [] ): self {
		$args = array_merge( [
			'hierarchical'      => false,
			'labels'            => [
				'name'              => $name,
				'singular_name'     => $singular_name,
				'menu_name'         => $name,
				'all_items'         => "Все $name",
				'edit_item'         => "Изменить",
				'view_item'         => "Просмотреть",
				'update_item'       => "Обновить",
				'add_new_item'      => "Добавить",
				'new_item_name'     => "Новое название",
				'search_items'      => "Найти",
				'not_found'         => "Не найдено",
			],
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_nav_menus' => true,
			'show_tagcloud'     => false,
			'query_var'         => true,
			'rewrite'           => [ 'slug' => $slug ],
			'show_in_rest'      => true, // Важно для корректной работы современных запросов
		], $extra_args );

		$this->taxonomies[ $slug ] = [
			'post_types' => $object_types,
			'args'       => $args
		];

		return $this;
	}

	/**
	 * Передаёт накопленные данные менеджеру для регистрации.
	 *
	 * Вызывает низкоуровневый TaxonomyManager, который зарегистрирует
	 * все накопленные таксономии через WordPress API.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->manager->register( $this->taxonomies );
	}

}