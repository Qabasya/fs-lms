<?php

namespace Inc\Registrars;

use Inc\Managers\SubjectCPTManager;
class SubjectCPTRegistrar {
	private SubjectCPTManager $manager;
	private array $post_types = [];

	public function __construct( SubjectCPTManager $manager ) {
		$this->manager = $manager;
	}

	/**
	 * Добавляет тип записи в очередь на регистрацию.
	 */
	public function addPostType( string $slug, array $args ): self {
		$this->post_types[$slug] = $args;
		return $this;
	}

	/**
	 * Хелпер для быстрой генерации стандартных конфигов (как в твоем примере)
	 */
	public function addStandardType( string $slug, string $plural, string $singular ): self {
		return $this->addPostType( $slug, [
			'labels'       => [
				'name'               => $plural,
				'singular_name'      => $singular,
				'menu_name'          => $plural,
				'add_new'            => "Добавить {$singular}",
				'add_new_item'       => "Добавить новый {$singular}",
				'edit_item'          => "Редактировать {$singular}",
				'all_items'          => "Все {$plural}",
			],
			'public'       => true,
			'has_archive'  => true,
			'show_in_menu' => false,
			'show_in_rest' => true,
			'supports'     => [ 'title', 'editor', 'thumbnail', 'excerpt' ],
			'rewrite'      => [ 'slug' => $slug, 'with_front' => false ]
		] );
	}

	public function register(): void {
		$this->manager->register( $this->post_types );
	}

}