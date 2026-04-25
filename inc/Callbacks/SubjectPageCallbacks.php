<?php

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\DTO\SubjectViewDTO;
use Inc\DTO\TaxonomyDataDTO;
use Inc\Managers\PostManager;
use Inc\Repositories\MetaBoxRepository;
use Inc\Repositories\SubjectRepository;
use Inc\Repositories\TaxonomyRepository;
use Inc\Shared\Traits\TemplateRenderer;

/**
 * Class SubjectPageCallbacks
 *
 * Коллбеки для отображения страницы управления предметом.
 *
 * @package Inc\Callbacks
 *
 * ### Основные обязанности:
 *
 * 1. **Подготовка данных** — сбор всей информации о предмете (таксономии, типы заданий, таблицы) в DTO.
 * 2. **Рендеринг страницы** — отображение страницы управления предметом с вкладками.
 * 3. **Вывод уведомлений** — показ ошибок валидации обязательных таксономий.
 */
class SubjectPageCallbacks extends BaseController {
	use TemplateRenderer;

	public function __construct(
		private readonly SubjectRepository $subjects,
		private readonly TaxonomyRepository $taxonomies,
		private readonly MetaBoxRepository $metaboxes,
		private readonly PostManager $posts,
	) {
		parent::__construct();
	}

	// ============================ ПРИВАТНЫЕ МЕТОДЫ ============================ //

	/**
	 * Собирает все данные для страницы управления предметом.
	 *
	 * @param string $key Ключ предмета
	 *
	 * @return SubjectViewDTO|null
	 */
	private function prepareSubjectViewData( string $key ): ?SubjectViewDTO {
		$current_subject = $this->subjects->getByKey( $key );

		if ( ! $current_subject ) {
			return null;
		}

		// DTO для системной таксономии номеров заданий (защищена от удаления)
		$fixed_tax_dto = new TaxonomyDataDTO(
			slug: "{$key}_task_number",
			name: 'Номера заданий',
			subject_key: $key,
			is_protected: true,
			is_required: true
		);

		// Определение активной вкладки из URL
		$page       = sanitize_text_field( wp_unslash( $_GET['page'] ?? '' ) );
		$active_tab = sanitize_text_field( wp_unslash( $_GET['tab'] ?? '' ) );

		$tasks_table    = null;
		$articles_table = null;

		// buildListTable() — создаёт объект WP_ListTable для указанного типа поста
		// Таблица строится только для активной вкладки (ленивая загрузка)
		if ( $active_tab === 'tab-2' ) {
			$tasks_table = $this->posts->buildListTable( "{$key}_tasks", $page, 'tab-2' );
		} elseif ( $active_tab === 'tab-3' ) {
			$articles_table = $this->posts->buildListTable( "{$key}_articles", $page, 'tab-3' );
		}

		return new SubjectViewDTO(
			subject_key: $key,
			subject_data: $current_subject,
			task_types: $this->metaboxes->getTaskTypes( $key ),
			// apply_filters() — позволяет сторонним разработчикам расширять список шаблонов
			all_templates: apply_filters( 'fs_lms_get_templates', array() ),
			// admin_url() — формирует полный URL к административной панели
			tasks_url: admin_url( "edit.php?post_type={$key}_tasks" ),
			articles_url: admin_url( "edit.php?post_type={$key}_articles" ),
			protected_tax: "{$key}_task_number",
			// array_merge() — объединяет системную таксономию с пользовательскими
			taxonomies: array_merge( array( $fixed_tax_dto ), $this->taxonomies->getBySubject( $key ) ),
			tasks_table: $tasks_table,
			articles_table: $articles_table,
		);
	}

	// ============================ КОЛЛБЕКИ СТРАНИЦ ============================ //

	/**
	 * Коллбек страницы управления конкретным предметом в админке.
	 *
	 * @return void
	 */
	public function subjectPage(): void {
		$page = sanitize_text_field( wp_unslash( $_GET['page'] ?? '' ) );
		// str_replace() — удаляет префикс 'fs_subject_' из слага страницы
		$key = str_replace( 'fs_subject_', '', $page );

		$dto = $this->prepareSubjectViewData( $key );

		if ( ! $dto ) {
			echo 'Предмет не найден';
			return;
		}

		// render() — подключает шаблон из папки /templates/admin/
		$this->render( 'subject', $dto );
	}

	/**
	 * Отображает уведомление об ошибке обязательной таксономии.
	 * Вызывается через хук admin_notices.
	 *
	 * @return void
	 */
	public function showRequiredTaxNotice(): void {
		// get_current_user_id() — возвращает ID текущего авторизованного пользователя
		$key = 'fs_lms_required_tax_error_' . get_current_user_id();
		// get_transient() — получает временные данные из кеша (хранятся до 30 секунд)
		$msg = get_transient( $key );

		if ( ! $msg ) {
			return;
		}

		// delete_transient() — удаляет временные данные после отображения
		delete_transient( $key );

		// printf() — выводит отформатированную строку
		// esc_html() — экранирует HTML-символы для безопасного вывода
		printf(
			'<div class="notice notice-error is-dismissible"><p>Обязательная таксономия «%s» не заполнена. Задание сохранено как черновик.</p></div>',
			esc_html( $msg )
		);
	}
}
