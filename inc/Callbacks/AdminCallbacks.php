<?php
namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Repositories\SubjectRepository;
use Inc\Shared\Traits\TemplateRenderer;

/**
 * Class AdminCallbacks
 *
 * Обработчики (коллбеки) для административной панели WordPress.
 *
 * Отвечает за:
 * - Рендеринг страниц админ-панели (adminDashboard)
 * - AJAX-обработку CRUD операций с предметами (store, update, delete)
 *
 * @package Inc\Callbacks
 *
 * @method void render(string $template, array $data = []) — трейт TemplateRenderer
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

	/**
	 * Получает и валидирует данные предмета из AJAX-запроса.
	 *
	 * Выполняет:
	 * - Проверку nonce (security)
	 * - Проверку прав доступа (manage_options)
	 * - Санитизацию полей (name, key, tasks_count)
	 * - Валидацию обязательного поля key
	 *
	 * @return array<string, mixed> Массив с данными предмета (name, key, tasks_count)
	 *
	 * @throws void Отправляет JSON-ошибку через wp_send_json_error() при нарушении
	 */
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

	// TODO: тут нарушение DRY надо как-то переписать эти 3 функции мб?

	/**
	 * AJAX-обработчик создания нового предмета.
	 *
	 * Получает валидированные данные, проверяет наличие названия,
	 * сохраняет предмет через репозиторий и сбрасывает правила перезаписи.
	 *
	 * @return void Отправляет JSON-ответ через wp_send_json_*()
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
	 * AJAX-обработчик обновления существующего предмета.
	 *
	 * Получает валидированные данные, проверяет существование предмета,
	 * обновляет его через репозиторий.
	 *
	 * @return void Отправляет JSON-ответ через wp_send_json_*()
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
	 * AJAX-обработчик удаления предмета.
	 *
	 * Получает валидированные данные, проверяет существование предмета,
	 * удаляет его через репозиторий и сбрасывает правила перезаписи.
	 *
	 * @return void Отправляет JSON-ответ через wp_send_json_*()
	 */
	public function deleteSubject(): void {
		$data = $this->get_validated_subject_data();

		// Проверяем существование
		if ( ! $this->subjects->get_by_key( $data['key'] ) ) {
			wp_send_json_error( 'Предмет не найден в базе!' );
		}

		if ( $this->subjects->delete( $data ) ) {
			flush_rewrite_rules();
			wp_send_json_success( sprintf( 'Предмет "%s" удалён.', $data['name'] ) );
		}
		wp_send_json_error( 'Ошибка удаления' );
	}


	/**
	 * Коллбек для главной страницы административной панели.
	 *
	 * Отображает дашборд со списком всех предметов.
	 * Использует шаблон 'settings' для рендеринга.
	 *
	 * @return void
	 */
	public function adminDashboard(): void {
		$all_subjects = $this->subjects->read_all();
		$this->render( 'settings', [ 'subjects' => $all_subjects ] );
	}
}