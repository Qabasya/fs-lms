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

	/**
	 * Собирает все данные для страницы управления предметом и упаковывает в SubjectViewDTO.
	 *
	 * @param string $key Ключ предмета, например: "math"
	 *
	 * @return SubjectViewDTO|null DTO для шаблона или null, если предмет не найден
	 */
	private function prepareSubjectViewData( string $key ): ?SubjectViewDTO {
		$current_subject = $this->subjects->getByKey( $key );

		if ( ! $current_subject ) {
			return null;
		}

		// Фиксированная таксономия номеров — не хранится в БД пользовательских таксономий,
		// поэтому собираем вручную. Флаг is_protected запрещает удаление в интерфейсе.
		$fixed_tax_dto = new TaxonomyDataDTO(
			slug: "{$key}_task_number",
			name: 'Номера заданий',
			subject_key: $key,
			is_protected: true,
			is_required: true
		);

		// Получение текущей вкладки для определения необходимости построения таблиц
		$page       = sanitize_text_field( wp_unslash( $_GET['page'] ?? '' ) );
		$active_tab = sanitize_text_field( wp_unslash( $_GET['tab'] ?? '' ) );

		$tasks_table    = null;
		$articles_table = null;

		// Построение таблицы заданий только если активна соответствующая вкладка
		if ( $active_tab === 'tab-2' ) {
			$tasks_table = $this->posts->buildListTable( "{$key}_tasks", $page, 'tab-2' );
		} elseif ( $active_tab === 'tab-3' ) {
			$articles_table = $this->posts->buildListTable( "{$key}_articles", $page, 'tab-3' );
		}

		// Создаём DTO для передачи всех данных в шаблон
		return new SubjectViewDTO(
			subject_key: $key,
			subject_data: $current_subject,
			task_types: $this->metaboxes->getTaskTypes( $key ),
			all_templates: apply_filters( 'fs_lms_get_templates', array() ),
			tasks_url: admin_url( "edit.php?post_type={$key}_tasks" ),
			articles_url: admin_url( "edit.php?post_type={$key}_articles" ),
			protected_tax: "{$key}_task_number",
			taxonomies: array_merge( array( $fixed_tax_dto ), $this->taxonomies->getBySubject( $key ) ),
			tasks_table: $tasks_table,
			articles_table: $articles_table,
		);
	}

	/**
	 * Коллбек страницы управления конкретным предметом в админке.
	 *
	 * Вызывается WordPress при открытии /wp-admin/admin.php?page=fs_subject_{key}.
	 *
	 * @return void
	 */
	public function subjectPage(): void {
		$page = sanitize_text_field( wp_unslash( $_GET['page'] ?? '' ) );
		$key  = str_replace( 'fs_subject_', '', $page );

		$dto = $this->prepareSubjectViewData( $key );

		if ( ! $dto ) {
			echo 'Предмет не найден';
			return;
		}

		$this->render( 'subject', $dto );
	}
	
	/**
	 * Отображает уведомление об ошибке обязательной таксономии.
	 *
	 * @return void
	 */
	public function showRequiredTaxNotice(): void {
		$key = 'fs_lms_required_tax_error_' . get_current_user_id();
		$msg = get_transient( $key );
		
		if ( ! $msg ) {
			return;
		}
		
		delete_transient( $key );
		
		printf(
			'<div class="notice notice-error is-dismissible"><p>Обязательная таксономия «%s» не заполнена. Задание сохранено как черновик.</p></div>',
			esc_html( $msg )
		);
	}
}
