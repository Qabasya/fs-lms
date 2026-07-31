<?php

declare( strict_types=1 );

namespace Inc\Registrars;

use Inc\Services\Subject\PostTypeResolver;

/**
 * Class ProblemBankRegistrar
 *
 * Регистрирует глобальный банк задач: CPT `fs_lms_problems` и таксономию
 * `problem_tag` («Тематика»).
 *
 * @package Inc\Registrars
 *
 * Банк общий для всех предметов (в отличие от {@see SubjectContentRegistrar},
 * который создаёт CPT под каждый предмет), поэтому регистрация одна на сайт.
 * Хуки вешает вызывающий контроллер.
 */
class ProblemBankRegistrar {

	/**
	 * Регистрирует CPT банка задач.
	 *
	 * @return void
	 */
	public function registerCpt(): void {
		register_post_type( PostTypeResolver::problems(), array(
			'labels'              => array(
				'name'          => 'Задачи',
				'singular_name' => 'Задача',
				'add_new_item'  => 'Добавить задачу',
				'edit_item'     => 'Редактировать задачу',
				'search_items'  => 'Найти задачу',
				'not_found'     => 'Задачи не найдены',
			),
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => false,
			'show_in_rest'        => false,
			'exclude_from_search' => true,
			'capability_type'     => 'fs_lms_content',
			'map_meta_cap'        => true,
			'supports'            => array( 'title', 'author' ),
			'rewrite'             => false,
		) );
	}

	/**
	 * Регистрирует таксономию тематики задач.
	 *
	 * @return void
	 */
	public function registerTaxonomy(): void {
		register_taxonomy( 'problem_tag', array( PostTypeResolver::problems() ), array(
			'labels'            => array(
				'name'          => 'Тематика',
				'singular_name' => 'Тема',
				'add_new_item'  => 'Добавить тему',
				'all_items'     => 'Все темы',
			),
			'hierarchical'      => false,
			'show_ui'           => true,
			'show_in_rest'      => false,
			'show_admin_column' => true,
			'rewrite'           => false,
		) );
	}
}
