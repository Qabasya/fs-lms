<?php

namespace Inc\Managers;

use Inc\Contracts\Service;
use Inc\Repositories\SubjectRepository;

/**
 * Class CPTManager
 *
 * Менеджер регистрации пользовательских типов записей (CPT).
 *
 * Реализует низкоуровневую регистрацию CPT через WordPress API.
 * Для каждого предмета из репозитория создаёт два типа записей:
 * - {key}_tasks — для заданий
 * - {key}_articles — для статей
 *
 * CPT скрыты из административного меню (show_in_menu => false),
 * доступ к ним осуществляется через отдельные страницы.
 *
 * @package Inc\Managers
 * @implements Service
 */
class CPTManager implements Service {
	/**
	 * Репозиторий предметов.
	 *
	 * @var SubjectRepository
	 */
	protected SubjectRepository $subjects;

	/**
	 * Конструктор.
	 *
	 * @param SubjectRepository $subjects Репозиторий предметов
	 */
	public function __construct( SubjectRepository $subjects ) {
		$this->subjects = $subjects;
	}

	/**
	 * Регистрирует хук для создания CPT.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'register_dynamic_cpt' ] );
	}

	/**
	 * Динамически создаёт CPT для всех предметов.
	 *
	 * Для каждого предмета из репозитория создаёт:
	 * - CPT для заданий (суффикс _tasks)
	 * - CPT для статей (суффикс _articles)
	 *
	 * Если список предметов пуст, регистрация не выполняется.
	 *
	 * @return void
	 */
	public function register_dynamic_cpt(): void {
		$list = $this->subjects->read_all();

		if ( empty( $list ) ) {
			return;
		}

		foreach ( $list as $key => $data ) {
			$name = $data['name'];

			// Регистрируем тип для ЗАДАНИЙ
			$this->register_type( $key . '_tasks', "Задания ($name)", "Задание" );

			// Регистрируем тип для СТАТЕЙ
			$this->register_type( $key . '_articles', "Статьи ($name)", "Статья" );
		}
	}

	/**
	 * Регистрирует один тип записи.
	 *
	 * @param string $post_type Идентификатор типа записи (slug)
	 * @param string $plural Множественное название (для меню и заголовков)
	 * @param string $singular Единственное название
	 *
	 * @return void
	 */
	private function register_type( string $post_type, string $plural, string $singular ): void {
		register_post_type( $post_type, [
			'labels'       => [
				'name'               => $plural,
				'singular_name'      => $singular,
				'menu_name'          => $plural,
				'add_new'            => "Добавить {$singular}",
				'add_new_item'       => "Добавить новый {$singular}",
				'edit_item'          => "Редактировать {$singular}",
				'new_item'           => "Новый {$singular}",
				'view_item'          => "Просмотреть {$singular}",
				'search_items'       => "Найти {$singular}",
				'not_found'          => "{$plural} не найдены",
				'not_found_in_trash' => "В корзине нет {$plural}",
				'all_items'          => "Все {$plural}",
			],
			'public'       => true,
			'has_archive'  => true,
			'show_in_menu' => false, // Скрываем из боковой панели
			'show_in_rest' => true,  // Включаем поддержку Gutenberg
			'supports'     => [ 'title', 'editor', 'thumbnail', 'excerpt' ],
			'rewrite'      => [
				'slug'       => $post_type,
				'with_front' => false
			]
		] );
	}
}