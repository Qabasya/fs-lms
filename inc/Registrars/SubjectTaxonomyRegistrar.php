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
	 * Низкоуровневый менеджер.
	 * @var TaxonomyManager
	 */
	private TaxonomyManager $manager;

	/**
	 * Очередь таксономий на регистрацию.
	 * @var array<string, array{post_types: array|string, args: array}>
	 */
	private array $taxonomies = [];

	public function __construct( TaxonomyManager $manager ) {
		$this->manager = $manager;
	}

	/**
	 * Базовый метод добавления таксономии.
	 */
	public function addTaxonomy( string $slug, array|string $post_types, array $args ): self {
		$this->taxonomies[ $slug ] = [
			'post_types' => $post_types,
			'args'       => $args
		];

		return $this;
	}

	/**
	 * Хелпер для создания стандартной таксономии (например, Год или Автор).
	 */
	public function addStandardTaxonomy( string $slug, array|string $post_types, string $plural, string $singular ): self {
		return $this->addTaxonomy( $slug, $post_types, [
			'labels'            => [
				'name'          => $plural,
				'singular_name' => $singular,
				'search_items'  => "Найти $plural",
				'all_items'     => "Все $plural",
				'edit_item'     => "Изменить $singular",
				'update_item'   => "Обновить $singular",
				'add_new_item'  => "Добавить новый $singular",
				'new_item_name' => "Название нового $singular",
				'menu_name'     => $plural,
			],
			'hierarchical'      => true, // Как категории
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => true, // Для поддержки Gutenberg
			'rewrite'           => [ 'slug' => $slug ],
		] );
	}

	/**
	 * Хелпер для "жестких" таксономий (например, Номера заданий).
	 * Ограничивает права на редактирование терминов для всех, кроме админов.
	 */
	public function addFixedTaxonomy( string $slug, array|string $post_types, string $plural, string $singular ): self {
		return $this->addTaxonomy( $slug, $post_types, [
			'labels'            => [ 'name' => $plural, 'singular_name' => $singular ],
			'hierarchical'      => true,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'capabilities'      => [
				'assign_terms' => 'edit_posts',
				'edit_terms'   => 'manage_options', // Только админ
				'manage_terms' => 'manage_options',
				'delete_terms' => 'manage_options',
			],
		] );
	}
	/**
	 * Передает накопленные данные менеджеру для регистрации.
	 */
	public function register(): void {
		$this->manager->register( $this->taxonomies );
	}

}