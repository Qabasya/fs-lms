<?php

namespace Inc\Controllers;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Registrars\PluginRegistrar;
use Inc\Repositories\SubjectRepository;
use Inc\Shared\Traits\TemplateRenderer;


/**
 * Class SubjectController
 *
 * Контроллер для управления предметами и связанными с ними CPT.
 *
 * Отвечает за:
 * - Динамическую регистрацию CPT (задания и статьи) для каждого предмета
 * - Отображение страницы управления конкретным предметом с навигацией
 *   к связанным типам записей
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 *
 * @method void render(string $template, array $data = []) — трейт TemplateRenderer
 */

class SubjectController extends BaseController implements ServiceInterface{
	use TemplateRenderer;

	/**
	 * Репозиторий для работы с предметами.
	 *
	 * @var SubjectRepository
	 */
	protected SubjectRepository $subjects;

	/**
	 * Композитный регистратор плагина.
	 *
	 * @var PluginRegistrar
	 */
	private PluginRegistrar $registrar;

	/**
	 * Конструктор.
	 *
	 * @param SubjectRepository $subjects  Репозиторий предметов
	 * @param PluginRegistrar   $registrar Композитный регистратор плагина
	 */
	public function __construct( SubjectRepository $subjects, PluginRegistrar $registrar ) {
		parent::__construct();
		$this->subjects  = $subjects;
		$this->registrar = $registrar;
	}

	/**
	 * Регистрирует все компоненты контроллера.
	 *
	 * Вызывается один раз при инициализации плагина (в Init.php).
	 * Для каждого предмета из репозитория создаёт два типа записей:
	 * - {key}_tasks — задания
	 * - {key}_articles — статьи
	 *
	 * @return void
	 */
	public function register(): void {
		$all_subjects = $this->subjects->read_all();

		if ( empty( $all_subjects ) ) {
			return;
		}

		foreach ( $all_subjects as $key => $data ) {
			$name = $data['name'];
			$this->registrar->cpt()
			                ->addStandardType( "{$key}_tasks", "Задания ($name)", "Задание" )
			                ->addStandardType( "{$key}_articles", "Статьи ($name)", "Статья" );
		}

		$this->registrar->cpt()->register();
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

		$tasks_link    = admin_url( "edit.php?post_type={$key}_tasks" );
		$articles_link = admin_url( "edit.php?post_type={$key}_articles" );

		echo "<a href='" . esc_url( $tasks_link ) . "' class='button button-primary'>Перейти к Заданиям</a> ";
		echo "<a href='" . esc_url( $articles_link ) . "' class='button button-secondary'>Перейти к Статьям</a>";

		echo '</div></div>';
	}
}