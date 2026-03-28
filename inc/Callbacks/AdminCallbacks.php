<?php
/* TODO: REFACTOR */

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Repositories\SubjectRepository;
use Inc\Shared\Traits\TemplateRenderer;

/**
 * Class AdminCallbacks
 *
 * Обработчики для административной панели WordPress.
 *
 * @package Inc\Callbacks
 *
 * @method void render( string $template, array $data = [] ) — трейт TemplateRenderer
 */
class AdminCallbacks extends BaseController {
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
	 * Инициализирует репозиторий предметов и регистрирует AJAX-обработчики.
	 *
	 * @param SubjectRepository $subjects Репозиторий предметов
	 */
	public function __construct( SubjectRepository $subjects ) {
		parent::__construct();
		$this->subjects = $subjects;

		// Регистрируем AJAX обработчик для сохранения предмета
		add_action( 'wp_ajax_fs_store_subject', [ $this, 'storeSubject' ] );
		// Регистрируем AJAX обработчик для редактирования предмета
		add_action( 'wp_ajax_fs_update_subject', [ $this, 'updateSubject' ] );
		// Регистрируем AJAX обработчик для удаления предмета
		add_action( 'wp_ajax_fs_delete_subject', [ $this, 'deleteSubject' ] );
	}


	protected function get_validated_subject_data(): array {
		// Проверка прав
		check_ajax_referer( 'fs_subject_nonce', 'security' );
		if ( ! current_user_can( self::ADMIN_CAPABILITY ) ) {
			wp_send_json_error( 'Нет прав' );
		}

		// Получение полей. Если нужно будет новое поле — добавлять здесь! (и в view)
		$name  = isset( $_POST['name'] ) ? sanitize_text_field( $_POST['name'] ) : '';
		$key   = isset( $_POST['key'] ) ? sanitize_title( $_POST['key'] ) : '';
		$count = isset( $_POST['tasks_count'] ) ? (int) $_POST['tasks_count'] : self::MIN_TASKS_COUNT;

		// Проверка ID
		if ( empty( $key ) ) {
			wp_send_json_error( 'ID обязателен!' );
		}

		// Возвращаем объект "Предмет"
		return [
			'name'        => $name,
			'key'         => $key,
			'tasks_count' => $count
		];

	}

	/**
	 * AJAX: Сохранение (Create)
	 */
	public function storeSubject(): void {
		$data = $this->get_validated_subject_data();

		// Дополнительная валидация для создания
		if ( empty( $data['name'] ) ) {
			wp_send_json_error( 'Название обязательно для заполнения!' );
		}

		if ( $this->subjects->update( $data ) ) {
			flush_rewrite_rules();
			wp_send_json_success( sprintf( 'Предмет «%s» создан!', $data['name'] ) );
		}
		wp_send_json_error( 'Не удалось сохранить' );
	}

	/**
	 * AJAX: Обновление (Update)
	 * get_by_key - получение предмета по ключа. Находится в SubjetRepository
	 */
	public function updateSubject(): void {
		$data = $this->get_validated_subject_data();

		// Проверяем существование
		if ( ! $this->subjects->get_by_key( $data['key'] ) ) {
			wp_send_json_error( 'Предмет не найден в базе!' );
		}

		if ( $this->subjects->update( $data ) ) {
			wp_send_json_success( sprintf( 'Предмет "%s" обновлен!', $data['name'] ) );
		}
		wp_send_json_error( 'Ошибка обновления' );
	}
	/**
	 * AJAX: Удаление (Delete)
	 */
	public function deleteSubject(): void {
		$data = $this->get_validated_subject_data();

		// Получаем текущие данные, чтобы знать имя для сообщения
		$current = $this->subjects->get_by_key( $data['key'] );
		if ( ! $current ) {
			wp_send_json_error( 'Предмет не найден!' );
		}

		if ( $this->subjects->delete( $data  ) ) {
			flush_rewrite_rules();
			wp_send_json_success( sprintf( 'Предмет "%s" удалён.', $current['name'] ) );
		}
		wp_send_json_error( 'Ошибка удаления' );
	}



	/**
	 * Коллбек для главной страницы административной панели.
	 *
	 * Отображает дашборд со списком всех предметов.
	 *
	 * @return void
	 */
	public function adminDashboard(): void {
		$all_subjects = $this->subjects->read_all();
		$this->render( 'settings', [ 'subjects' => $all_subjects ] );
	}


	/**
	 *  ИСПРАВИТЬ
	 * Коллбек для страницы управления конкретным предметом.
	 *
	 * Извлекает ключ предмета из URL-параметра page,
	 * отображает информацию о предмете и ссылки на связанные CPT.
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