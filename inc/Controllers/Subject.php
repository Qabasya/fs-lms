<?php

namespace Inc\Controllers;

use Inc\Core\BaseController;
use Inc\Repositories\SubjectRepository;
use Inc\Shared\Traits\TemplateRenderer;


/**
 * Class Subject
 *
 * Обработчики (коллбеки) для страниц управления предметами.
 *
 * Отвечает за отображение страницы конкретного предмета с информацией
 * о нём и ссылками на связанные CPT (задания и статьи).
 *
 * @package Inc\Callbacks
 *
 * @method void render( string $template, array $data = [] ) — трейт TemplateRenderer
 */
class Subject extends BaseController {
	use TemplateRenderer;

	/**
	 * Репозиторий для работы с предметами.
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
		parent::__construct();
		$this->subjects = $subjects;
	}

	/**
	 * Коллбек для страницы управления конкретным предметом.
	 *
	 * Извлекает ключ предмета из URL-параметра page,
	 * отображает информацию о предмете и ссылки на связанные CPT
	 * (задания и статьи).
	 *
	 * Формат URL: /wp-admin/admin.php?page=fs_subject_{key}
	 *
	 * @return void
	 */
	public function subjectPage(): void {
		$page = $_GET['page'] ?? '';
		$key  = str_replace( 'fs_subject_', '', $page );

		$all_subjects    = $this->subjects->read_all();
		$current_subject = $all_subjects[ $key ] ?? null;

		if ( ! $current_subject ) {
			echo "Предмет не найден";

			return;
		}

		echo '<div class="wrap">';
		echo '<h1>Управление предметом: ' . esc_html( $current_subject['name'] ) . '</h1>';
		echo '<div class="card" style="max-width: 100%; margin-top: 20px; padding: 20px;">';
		echo '<h3>Контент предмета</h3>';

		// Генерируем прямые ссылки на списки CPT, которые мы скрыли из меню
		$tasks_link    = admin_url( "edit.php?post_type={$key}_tasks" );
		$articles_link = admin_url( "edit.php?post_type={$key}_articles" );

		echo "<a href='" . esc_url( $tasks_link ) . "' class='button button-primary'>Перейти к Заданиям</a> ";
		echo "<a href='" . esc_url( $articles_link ) . "' class='button button-secondary'>Перейти к Статьям</a>";

		echo '</div></div>';
	}
}