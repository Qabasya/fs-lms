<?php

declare( strict_types=1 );

namespace Inc\Controllers\Problems;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\AjaxHook;
use Inc\Enums\Wp\Nonce;
use Inc\Enums\Wp\PostMetaName;
use Inc\Controllers\Builders\ProblemListFilters;
use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\TermManager;
use Inc\Enums\Subject\TaskTemplate;
use Inc\Registrars\ProblemBankRegistrar;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Task\TaskPublishGuard;
use Inc\Services\Task\TaskPublishValidator;
use Inc\Services\Template\TemplateRegistry;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;
use Inc\Shared\Traits\TemplateRenderer;

/**
 * Class ProblemsController
 *
 * Регистрирует глобальный CPT `fs_lms_problems` и таксономию `problem_tag`.
 * Добавляет метабокс выбора шаблона редактора (те же шаблоны, что у заданий).
 *
 * @package Inc\Controllers
 */
class ProblemsController extends BaseController implements ServiceInterface {

	use Authorizer;
	use Sanitizer;
	use TemplateRenderer;

	public function __construct(
		private readonly TemplateRegistry      $registry,
		private readonly PostManager           $posts,
		private readonly TaskPublishValidator  $validator,
		private readonly TaskPublishGuard      $guard,
		private readonly ProblemBankRegistrar  $bank,
		private readonly ProblemListFilters    $filters,
		private readonly SubjectRepository     $subjects,
		private readonly TermManager           $terms,
	) {
		parent::__construct();
	}

	/**
	 * WP filter: доп. номера позиции для метабокса «Предмет и номер задания»
	 * банковской задачи — вне таксономии `{subject}_task_number` (напр. ОГЭ
	 * №13-16, ручная проверка: термов для них нет, см. докблок
	 * `Inc\Modules\EgeComputer\Config\OgeCriteriaConfig`). Та же идея, что
	 * `EgeCompletenessChecker::EXTRA_POSITIONS_FILTER`, но без контекста
	 * конкретной контрольной — только по предмету:
	 *   apply_filters( self::NUMBER_OPTIONS_FILTER, [], $subjectKey )
	 */
	public const NUMBER_OPTIONS_FILTER = 'fs_lms_bank_task_number_options';

	public function register(): void {
		$cpt = PostTypeResolver::problems();

		add_action( 'init', array( $this->bank, 'registerCpt' ) );
		add_action( 'init', array( $this->bank, 'registerTaxonomy' ) );
		add_action( 'add_meta_boxes', array( $this, 'addTemplateMetabox' ) );
		add_action( 'add_meta_boxes', array( $this, 'addSubjectMetabox' ) );
		add_action( 'add_meta_boxes', array( $this, 'addSourceMetabox' ) );
		add_action( 'add_meta_boxes_' . $cpt, array( $this, 'removeAuthorMetabox' ), 20 );
		add_action( 'save_post_' . $cpt, array( $this, 'saveTemplateType' ) );
		add_action( 'save_post_' . $cpt, array( $this, 'saveSubjectFields' ) );
		add_action( 'save_post_' . $cpt, array( $this, 'saveSourceField' ) );
		add_action( 'save_post_' . $cpt, array( $this, 'prefillDraftFromQuery' ), 10, 2 );
		add_action( AjaxHook::SetTaskTemplateType->action(), array( $this, 'ajaxSetTemplateType' ) );

		add_filter( "manage_{$cpt}_posts_columns", array( $this, 'addColumns' ) );
		add_action( "manage_{$cpt}_posts_custom_column", array( $this, 'renderColumn' ), 10, 2 );
		add_filter( "manage_edit-{$cpt}_sortable_columns", array( $this, 'sortableColumns' ) );
		add_action( 'pre_get_posts', array( $this, 'applyColumnSort' ) );
		add_action( 'restrict_manage_posts', array( $this, 'renderProblemsFilters' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'renderBankDescription' ) );
		add_filter( 'wp_insert_post_data', array( $this, 'validateBeforePublish' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'showPublishError' ) );
		add_filter( 'default_title', array( $this, 'prefillTitleFromQuery' ), 10, 2 );
	}

	/**
	 * Предзаполняет заголовок нового черновика банка задач подсказкой из
	 * query-параметра `fs_lms_suggested_title` — билдер работы формирует её из
	 * названия работы и порядкового номера задачи (см. `work-builder.js`
	 * suggestTitle), автор может принять её как есть или отредактировать перед
	 * публикацией. Срабатывает только на `post-new.php` — у существующего поста
	 * заголовок уже задан ядром раньше вызова этого фильтра.
	 */
	public function prefillTitleFromQuery( string $title, \WP_Post $post ): string {
		if ( PostTypeResolver::problems() !== $post->post_type ) {
			return $title;
		}

		$suggested = $this->sanitizeGetText( 'fs_lms_suggested_title' );

		return '' !== $suggested ? $suggested : $title;
	}

	/**
	 * Наполняет автосозданный черновик (`post-new.php?fs_lms_subject=…`) предметом
	 * и дефолтным типом шаблона СРАЗУ, на первом `wp_insert_post()` авто-черновика
	 * (WP создаёт его до рендера метабоксов) — а не только визуальным дефолтом в
	 * селекте. Без этого `TemplateResolver::resolveId()` при первом открытии видит
	 * пустую мету и резолвит `Standard`, поэтому поля условия рендерились не под
	 * нужный шаблон до первого сохранения формы.
	 */
	public function prefillDraftFromQuery( int $post_id, \WP_Post $post ): void {
		if ( 'auto-draft' !== $post->post_status ) {
			return;
		}
		if ( '' !== (string) $this->posts->getMeta( $post_id, PostMetaName::BankTaskSubject->value ) ) {
			return;
		}

		$subject = $this->sanitizeGetKey( 'fs_lms_subject' );
		if ( null === $this->subjects->getByKey( $subject ) ) {
			return;
		}

		$this->posts->updateMeta( $post_id, PostMetaName::BankTaskSubject->value, $subject );

		$default = $this->defaultTemplateFor( $subject );
		if ( null !== $default ) {
			$this->posts->updateMeta( $post_id, PostMetaName::TemplateType->value, $default->value );
		}
	}

	/**
	 * Выводит описание над таблицей на экране списка задач.
	 *
	 * Хук admin_notices срабатывает на всех экранах — ограничиваем выводом
	 * только на нативном списке `edit.php?post_type=fs_lms_problems`.
	 */
	public function renderBankDescription(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-' . PostTypeResolver::problems() !== $screen->id ) {
			return;
		}

		$this->render( 'admin/components/problems-bank-notice' );
	}



	public function addTemplateMetabox(): void {
		add_meta_box(
			'fs_lms_problem_template',
			'Тип шаблона',
			array( $this, 'renderTemplateMetabox' ),
			PostTypeResolver::problems(),
			'side',
		);
	}

	public function renderTemplateMetabox( \WP_Post $post ): void {
		$current = (string) $this->posts->getMeta( $post->ID, PostMetaName::TemplateType->value );
		if ( '' === $current ) {
			// Новый черновик из билдера работы/урока/контрольной (post-new.php?fs_lms_subject=…) —
			// подставляем дефолтный тип шаблона предмета, чтобы сразу открылись нужные поля.
			$subjectForDefault = $this->queryOrMetaSubject( $post );
			$default           = $this->defaultTemplateFor( $subjectForDefault );
			if ( null !== $default ) {
				$current = $default->value;
			}
		}
		wp_nonce_field( Nonce::SaveMeta->value, 'fs_lms_meta_nonce' );
		$this->render( 'admin/metaboxes/template-select', array(
			'name'      => PostMetaName::TemplateType->value,
			'current'   => $current,
			'templates' => $this->registry->getAll(),
		) );
	}

	/**
	 * Дефолтный тип шаблона по предмету (хардкод по решению пользователя,
	 * 2026-08-28 — настройка «тип шаблона по умолчанию» на уровне предмета пока
	 * не заведена в SubjectDTO). Расширять по мере появления новых предметов.
	 */
	private function defaultTemplateFor( string $subjectKey ): ?TaskTemplate {
		return 'python' === $subjectKey ? TaskTemplate::Code : null;
	}

	/**
	 * Предмет для дефолтов нового черновика: уже сохранённая мета (правка) либо
	 * query-параметр `fs_lms_subject` (создание из билдера работы/урока/контрольной).
	 */
	private function queryOrMetaSubject( \WP_Post $post ): string {
		$subject = (string) $this->posts->getMeta( $post->ID, PostMetaName::BankTaskSubject->value );
		if ( '' !== $subject ) {
			return $subject;
		}

		$fromQuery = $this->sanitizeGetKey( 'fs_lms_subject' );

		return null !== $this->subjects->getByKey( $fromQuery ) ? $fromQuery : '';
	}

	public function saveTemplateType( int $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! $this->isFormPostId( $post_id ) ) {
			return;
		}
		if ( ! $this->authorizePostSave( Nonce::SaveMeta, $post_id ) ) {
			return;
		}
		$template_id = $this->sanitizeKey( PostMetaName::TemplateType->value );
		if ( '' !== $template_id ) {
			$this->posts->updateMeta( $post_id, PostMetaName::TemplateType->value, $template_id );
		}
	}

	/**
	 * Метабокс «Предмет и номер задания» — необязательная пометка банковской
	 * задачи (T: убрать ручной номер из конструктора контрольной). Канонический
	 * источник номера позиции экзамена теперь сам пост банка, а не мета
	 * контрольной ({@see \Inc\Services\Assessment\EgeCompletenessChecker}).
	 */
	public function addSubjectMetabox(): void {
		add_meta_box(
			'fs_lms_problem_subject',
			'Предмет и номер задания',
			array( $this, 'renderSubjectMetabox' ),
			PostTypeResolver::problems(),
			'side',
		);
	}

	public function renderSubjectMetabox( \WP_Post $post ): void {
		$subject     = $this->queryOrMetaSubject( $post );
		$number      = (string) $this->posts->getMeta( $post->ID, PostMetaName::BankTaskNumber->value );
		$allSubjects = $this->subjects->readAll();

		wp_nonce_field( Nonce::SaveMeta->value, 'fs_lms_meta_nonce' );
		$this->render( 'admin/problems/subject-number-select', array(
			'subjects'         => $allSubjects,
			'subject'          => $subject,
			'number'           => $number,
			'numbersBySubject' => $this->numberOptionsBySubject( $allSubjects ),
		) );
	}

	/**
	 * Защита от реэнтерабельного `save_post_fs_lms_problems`: хук может сработать
	 * для ЧУЖОГО `$post_id` в рамках того же запроса (напр. `TaskBundleService::
	 * upsertChild()` создаёт детей связки внутри сохранения родителя) — тогда
	 * `$_POST` всё ещё содержит форму родителя и не имеет отношения к `$post_id`.
	 * WP-редактор всегда шлёт `post_ID` формы текущего поста — сверяем с ним.
	 */
	private function isFormPostId( int $post_id ): bool {
		return $post_id === (int) ( $_POST['post_ID'] ?? 0 );
	}

	/**
	 * Номер имеет смысл только вместе с предметом — без выбранного предмета
	 * поле в UI скрыто, а значение при сохранении отбрасывается. Значение
	 * сверяется с актуальным набором опций ({@see numberOptionsFor()}), а не
	 * просто санитайзится — присланный извне номер, не входящий в таксономию
	 * предмета (и не добавленный NUMBER_OPTIONS_FILTER), отбрасывается.
	 */
	public function saveSubjectFields( int $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! $this->isFormPostId( $post_id ) ) {
			return;
		}
		if ( ! $this->authorizePostSave( Nonce::SaveMeta, $post_id ) ) {
			return;
		}

		$subject = $this->sanitizeKey( PostMetaName::BankTaskSubject->value );
		if ( null === $this->subjects->getByKey( $subject ) ) {
			$subject = '';
		}
		$this->posts->updateMeta( $post_id, PostMetaName::BankTaskSubject->value, $subject );

		$number = '';
		if ( '' !== $subject ) {
			$candidate = $this->sanitizeText( PostMetaName::BankTaskNumber->value );
			if ( in_array( $candidate, $this->numberOptionsFor( $subject ), true ) ) {
				$number = $candidate;
			}
		}
		$this->posts->updateMeta( $post_id, PostMetaName::BankTaskNumber->value, $number );
	}

	/**
	 * Допустимые номера позиции для каждого предмета (для JS-переключателя
	 * в метабоксе — при смене предмета список номеров перестраивается без AJAX).
	 *
	 * @param \Inc\DTO\Subject\SubjectDTO[] $subjects
	 *
	 * @return array<string, string[]> subjectKey => номера
	 */
	private function numberOptionsBySubject( array $subjects ): array {
		$map = array();
		foreach ( $subjects as $s ) {
			$map[ $s->key ] = $this->numberOptionsFor( $s->key );
		}

		return $map;
	}

	/**
	 * Номера позиции предмета: термы таксономии `{subject}_task_number`
	 * (численно отсортированные — `get_terms()` по умолчанию сортирует по
	 * имени, т.е. алфавитно) плюс расширения через NUMBER_OPTIONS_FILTER.
	 *
	 * @return string[]
	 */
	private function numberOptionsFor( string $subjectKey ): array {
		$names = array_map(
			static fn( \WP_Term $t ): string => $t->name,
			$this->terms->getAll( PostTypeResolver::getTaskTaxonomy( $subjectKey ) )
		);

		$extra = (array) apply_filters( self::NUMBER_OPTIONS_FILTER, array(), $subjectKey );
		$names = array_values( array_unique( array_merge( $names, array_map( 'strval', $extra ) ) ) );

		usort( $names, static fn( string $a, string $b ): int => (int) $a - (int) $b );

		return $names;
	}

	/**
	 * Снимает нативный метабокс «Автор» — вместо него в сайдбаре отображается
	 * метабокс «Источник» ({@see addSourceMetabox()}).
	 */
	public function removeAuthorMetabox(): void {
		remove_meta_box( 'authordiv', PostTypeResolver::problems(), 'normal' );
	}

	/**
	 * Метабокс «Источник» — исходный номер задания из бумажного сборника
	 * (свободный текст, вручную вводит автор).
	 */
	public function addSourceMetabox(): void {
		add_meta_box(
			'fs_lms_problem_source',
			'Источник',
			array( $this, 'renderSourceMetabox' ),
			PostTypeResolver::problems(),
			'side',
		);
	}

	public function renderSourceMetabox( \WP_Post $post ): void {
		$value = (string) $this->posts->getMeta( $post->ID, PostMetaName::BankTaskSource->value );

		$this->render( 'admin/problems/source-input', array(
			'field_name' => PostMetaName::BankTaskSource->value,
			'value'      => $value,
		) );
	}

	public function saveSourceField( int $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! $this->isFormPostId( $post_id ) ) {
			return;
		}
		if ( ! $this->authorizePostSave( Nonce::SaveMeta, $post_id ) ) {
			return;
		}

		$this->posts->updateMeta( $post_id, PostMetaName::BankTaskSource->value, $this->sanitizeText( PostMetaName::BankTaskSource->value ) );
	}

	/**
	 * AJAX: авто-сохранение типа шаблона при смене в селекторе.
	 * JS после успеха перезагружает экран редактирования — метабокс полей
	 * перерисовывается под новый тип (`MetaBoxController` через `TemplateResolver`).
	 */
	public function ajaxSetTemplateType(): void {
		$this->authorize( Nonce::SaveMeta, Capability::AuthorLmsCourses );

		$post_id     = $this->requireInt( 'post_id' );
		$template_id = $this->sanitizeKey( 'template_type' );

		if ( '' === $template_id || null === $this->registry->get( $template_id ) ) {
			$this->error( 'Неизвестный тип шаблона.' );
		}
		if ( ! get_post( $post_id ) ) {
			$this->error( 'Пост не найден.' );
		}

		$this->posts->updateMeta( $post_id, PostMetaName::TemplateType->value, $template_id );
		$this->success();
	}

	/**
	 * Добавляет колонки «Предмет» (после названия) и «Тип шаблона» (перед датой).
	 *
	 * Колонки «Тематика» (таксономия `problem_tag`) и «Автор» добавляются
	 * ядром WP автоматически (`show_admin_column` и `supports => author`).
	 *
	 * @param array<string, string> $columns
	 *
	 * @return array<string, string>
	 */
	public function addColumns( array $columns ): array {
		$order = array( 'cb', 'title', 'subject' );

		// Таксономии (добавляются WP автоматически через show_admin_column).
		foreach ( array_keys( $columns ) as $key ) {
			if ( str_starts_with( $key, 'taxonomy-' ) ) {
				$order[] = $key;
			}
		}

		$order = array_merge( $order, array( 'template_type', 'author', 'fs_lms_usage', 'date' ) );

		$result = array();
		foreach ( $order as $key ) {
			if ( 'subject' === $key ) {
				$result['subject'] = 'Предмет';
			} elseif ( 'template_type' === $key ) {
				$result['template_type'] = 'Тип шаблона';
			} elseif ( isset( $columns[ $key ] ) ) {
				$result[ $key ] = $columns[ $key ];
			}
		}

		return $result;
	}

	/**
	 * Отрисовывает значения кастомных колонок «Предмет» и «Тип шаблона».
	 */
	public function renderColumn( string $column, int $post_id ): void {
		if ( 'subject' === $column ) {
			$key     = (string) $this->posts->getMeta( $post_id, PostMetaName::BankTaskSubject->value );
			$subject = '' !== $key ? $this->subjects->getByKey( $key ) : null;

			echo esc_html( null !== $subject ? $subject->name : '—' );

			return;
		}

		if ( 'template_type' !== $column ) {
			return;
		}

		$template_id = (string) $this->posts->getMeta( $post_id, PostMetaName::TemplateType->value );
		$template    = '' !== $template_id ? $this->registry->get( $template_id ) : null;

		echo esc_html( null !== $template ? $template->get_name() : '—' );
	}

	/**
	 * Делает колонки «Предмет» и «Тип шаблона» сортируемыми.
	 *
	 * @param array<string, string> $columns
	 *
	 * @return array<string, string>
	 */
	public function sortableColumns( array $columns ): array {
		$columns['subject']              = 'subject';
		$columns['template_type']        = 'template_type';
		$columns['taxonomy-problem_tag'] = 'taxonomy-problem_tag';
		$columns['fs_lms_usage']         = 'fs_lms_usage';

		return $columns;
	}

	/**
	 * Применяет сортировку и фильтры списка задач.
	 */
	/**
	 * Сортировка и фильтры списка задач (хук pre_get_posts).
	 *
	 * @param \WP_Query $query Запрос экрана
	 *
	 * @return void
	 */
	public function applyColumnSort( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( PostTypeResolver::problems() !== $query->get( 'post_type' ) ) {
			return;
		}

		// Сортировка по типу шаблона/предмету — обычная мета-сортировка, остальное — в фильтрах банка.
		if ( 'template_type' === $query->get( 'orderby' ) ) {
			$query->set( 'meta_key', PostMetaName::TemplateType->value );
			$query->set( 'orderby', 'meta_value' );
		}
		if ( 'subject' === $query->get( 'orderby' ) ) {
			$query->set( 'meta_key', PostMetaName::BankTaskSubject->value );
			$query->set( 'orderby', 'meta_value' );
		}

		$this->filters->apply( $query );
	}

	/**
	 * Фильтры над таблицей банка задач (хук restrict_manage_posts).
	 *
	 * @param string $post_type CPT экрана
	 * @param string $which     Позиция панели: top|bottom
	 *
	 * @return void
	 */
	public function renderProblemsFilters( string $post_type, string $which = 'top' ): void {
		if ( PostTypeResolver::problems() !== $post_type || 'top' !== $which ) {
			return;
		}

		$this->render( 'admin/problems/problem-filters', $this->filters->data() );
	}

	/**
	 * Хук wp_insert_post_data: блокирует публикацию задачи из банка,
	 * если не заполнены название, условие или ответ.
	 *
	 * @param array $data    Очищенные данные поста
	 * @param array $postarr Неочищенные данные из $_POST
	 *
	 * @return array
	 */
	public function validateBeforePublish( array $data, array $postarr ): array {
		if ( PostTypeResolver::problems() !== ( $data['post_type'] ?? '' ) ) {
			return $data;
		}

		$postId = (int) ( $postarr['ID'] ?? 0 );

		return $this->guard->enforce(
			$data,
			'fs_lms_problem_publish_error_',
			'Название задачи обязательно для заполнения.',
			function () use ( $postId ) {
				$hasMetaForm = $this->hasParam( PostMetaName::Meta->value );

				// Программная вставка (импорт пакета, рестор банка): формы нет, поста
				// ещё нет — мета запишется сразу после insert, валидировать нечего.
				if ( $postId <= 0 && ! $hasMetaForm ) {
					return null;
				}

				// Быстрое/массовое редактирование и программный wp_update_post форму
				// метабокса не шлют — берём сохранённое состояние, иначе валидатор
				// видел пустую мету, сваливался на «Стандартный» шаблон и откатывал
				// опубликованную задачу в черновик.
				$postMeta = $hasMetaForm
					? $this->unslashArray( PostMetaName::Meta->value )
					: $this->storedMeta( $postId );

				$templateId = $this->sanitizeKey( PostMetaName::TemplateType->value );
				if ( '' === $templateId && $postId > 0 ) {
					$stored     = $this->posts->getMeta( $postId, PostMetaName::TemplateType->value );
					$templateId = is_string( $stored ) ? $stored : '';
				}

				return $this->validator->getSoftError( $postMeta, $templateId );
			}
		);
	}

	/**
	 * Сохранённая мета задачи (пустой массив, если меты ещё нет).
	 *
	 * @param int $postId ID задачи банка.
	 *
	 * @return array<string, mixed>
	 */
	private function storedMeta( int $postId ): array {
		$meta = $postId > 0 ? $this->posts->getMeta( $postId, PostMetaName::Meta->value ) : null;

		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * Хук admin_notices: показывает ошибку валидации после неудачной публикации.
	 */
	public function showPublishError(): void {
		$screen = get_current_screen();
		if ( ! $screen || PostTypeResolver::problems() !== $screen->post_type ) {
			return;
		}

		$this->guard->renderDeferredError( 'fs_lms_problem_publish_error_', __( 'Невозможно опубликовать задачу', 'fs-lms' ) );
	}
}
