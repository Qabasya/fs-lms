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
	}

	/**
	 * AJAX-обработчик сохранения нового предмета.
	 *
	 * Проверяет nonce и права доступа, затем сохраняет предмет через репозиторий.
	 * После сохранения сбрасывает правила перезаписи для активации новых CPT.
	 *
	 * @return void Отправляет JSON-ответ через wp_send_json_*()
	 */
	public function storeSubject(): void {
		// Проверка безопасности
		check_ajax_referer( 'fs_subject_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Нет прав' );
		}

		if ( empty( $_POST['name'] ) || empty( $_POST['key'] ) ) {
			wp_send_json_error( 'Заполните все поля' );
		}

		$new_subject = [
			'name' => sanitize_text_field( $_POST['name'] ),
			'key'  => sanitize_title( $_POST['key'] )
		];

		// Сохраняем в наш репозиторий (который работает с Options)
		$result = $this->subjects->update( $new_subject );

		if ( $result ) {
			// Очищаем правила ссылок, чтобы новые CPT сразу работали
			flush_rewrite_rules();
			wp_send_json_success();
		}

		wp_send_json_error( 'Не удалось сохранить' );
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
		$this->render( 'test', [ 'subjects' => $all_subjects ] );
	}

	/**
	 * Коллбек для поля настроек типа checkbox.
	 *
	 * Рендерит HTML-разметку checkbox-поля с сохранённым значением.
	 *
	 * @param array $args Аргументы поля:
	 *                    - label_for: идентификатор поля
	 *                    - option_name: имя опции в WordPress
	 *
	 * @return void
	 */
	public function checkboxField( array $args ): void {
		$name        = $args['label_for'];
		$option_name = $args['option_name'];
		$options     = get_option( $option_name );

		$checked = isset( $options[ $name ] ) ? (bool) $options[ $name ] : false;

		echo '<input type="checkbox" 
                    id="' . esc_attr( $name ) . '" 
                    name="' . esc_attr( $option_name ) . '[' . esc_attr( $name ) . ']" 
                    value="1" 
                    ' . checked( $checked, true, false ) . '>';
	}

	/**
	 * Коллбек для дашборда предметов.
	 *
	 * Переиспользует тот же шаблон, что и adminDashboard().
	 *
	 * @return void
	 */
	public function subjectsDashboard(): void {
		$all_subjects = $this->subjects->read_all();
		$this->render( 'test', [ 'subjects' => $all_subjects ] );
	}

	/**
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