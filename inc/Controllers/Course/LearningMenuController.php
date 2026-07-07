<?php

declare( strict_types=1 );

namespace Inc\Controllers\Course;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\DTO\Course\ModuleDTO;
use Inc\Enums\Access\Capability;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Course\BankType;
use Inc\Enums\Course\StepType;
use Inc\Enums\Course\WorkType;
use Inc\Enums\Wp\Menu;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Registrars\MenuRegistrar;
use Inc\Services\Course\TeacherSubjectsService;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Shared\Traits\TemplateRenderer;

/**
 * Class LearningMenuController
 *
 * Единое меню «Обучение» с сабменю-банками (Курсы / Уроки / Работы / Задания / Статьи).
 * Каждая страница — переключатель предметов (мягкий скоуп под предмет препода) + переход
 * на нативный экран соответствующего CPT. Сами CPT скрыты из top-level (show_in_menu=false).
 *
 * @package Inc\Controllers
 */
class LearningMenuController extends BaseController implements ServiceInterface {

	use TemplateRenderer;

	/**
	 * Слаги банков по типу (courses|lessons|works|tasks|articles): нативная таблица
	 * первого предмета препода или плагин-страница-фолбэк, если предметов в системе нет.
	 * Нужны для подсветки меню (в т.ч. при переключении на другой предмет).
	 *
	 * @var array<string, string>
	 */
	private array $bank_slugs = array();

	/**
	 * Слаг top-level пункта «Обучение» = слаг банка курсов (родитель ведёт на курсы).
	 */
	private string $learning_parent_slug = '';

	public function __construct(
		private readonly MenuRegistrar          $menu_registrar,
		private readonly TeacherSubjectsService $teacher_subjects,
		private readonly PostManager            $posts,
	) {
		parent::__construct();
	}

	public function register(): void {
		// Всё меню зависит от предметов текущего препода, а пользователь на момент
		// загрузки плагина ещё не определён — строим меню на admin_menu.
		add_action( 'admin_menu', array( $this, 'registerLearningMenu' ) );

		// Подсветка раздела «Обучение» на нативных экранах банков (CPT скрыты из меню).
		add_filter( 'parent_file', array( $this, 'highlightLearningParent' ) );
		add_filter( 'submenu_file', array( $this, 'highlightLearningSubmenu' ) );

		// Над таблицей банка: описание + таб-бар предметов ОДНИМ блоком-нотисом.
		// НБ-1: единый `.notice` штатный JS WP переносит под заголовок целиком, поэтому
		// табы не «прыгают» отдельно от описания при загрузке страницы.
		add_action( 'admin_notices', array( $this, 'renderBankChrome' ) );

		// Фильтры по типу работы / виду контрольной / использованию / автору в list table.
		add_action( 'restrict_manage_posts', array( $this, 'renderTypeFilter' ), 10, 2 );
		add_action( 'pre_get_posts', array( $this, 'applyTypeFilter' ) );

		// «Незавершённая» вместо стандартного «Черновик» для задач банка.
		add_filter( 'display_post_states', array( $this, 'filterTaskDraftState' ), 10, 2 );

		// draft-creator-modal: рендерится на страницах уроков и курсов
		// (создание работы из урока / урока из курса без перезагрузки).
		add_action( 'admin_footer', array( $this, 'renderDraftCreatorModal' ) );
	}

	/** Подключает модаль создания черновика на страницах курсов, уроков, работ. */
	public function renderDraftCreatorModal(): void {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}
		$pt = $screen->post_type;
		if ( PostTypeResolver::isWorkPostType( $pt )
			|| PostTypeResolver::isLessonPostType( $pt )
			|| PostTypeResolver::isCoursePostType( $pt )
			|| PostTypeResolver::isAssessmentPostType( $pt ) ) {
			include_once $this->plugin_path . 'templates/admin/components/modals/draft-creator-modal.php';
		}
	}

	public function renderCourses(): void {
		$this->renderBank( BankType::Courses );
	}

	public function renderLessons(): void {
		$this->renderBank( BankType::Lessons );
	}

	public function renderWorks(): void {
		$this->renderBank( BankType::Works );
	}

	public function renderTasks(): void {
		$this->renderBank( BankType::Tasks );
	}

	public function renderArticles(): void {
		$this->renderBank( BankType::Articles );
	}

	public function renderAssessments(): void {
		$this->renderBank( BankType::Assessments );
	}

	public function renderTypeFilter( string $post_type, string $which = 'top' ): void {
		if ( 'top' !== $which ) {
			return;
		}

		require_once $this->plugin_path . 'templates/admin/components/UI/ui_renderers.php';

		if ( PostTypeResolver::isWorkPostType( $post_type ) ) {
			$subject = PostTypeResolver::subjectFromWorkPostType( $post_type );
			render_fs_select( [
				'name'      => 'fs_work_type',
				'options'   => WorkType::options(),
				'selected'  => sanitize_key( $_GET['fs_work_type'] ?? '' ),
				'all_label' => 'Все типы',
			] );
			$this->renderUsageFilter( 'fs_work_usage', $this->lessonWorkIndex( $subject ), 'Все работы' );
			$this->renderAuthorFilter( $post_type );
			return;
		}

		if ( PostTypeResolver::isAssessmentPostType( $post_type ) ) {
			$subject      = PostTypeResolver::subjectFromAssessmentPostType( $post_type );
			$kind_options = array_column( AssessmentKind::options(), 'label', 'value' );
			render_fs_select( [
				'name'      => 'fs_assessment_kind',
				'options'   => $kind_options,
				'selected'  => sanitize_key( $_GET['fs_assessment_kind'] ?? '' ),
				'all_label' => 'Все виды',
			] );
			$this->renderUsageFilter( 'fs_assessment_usage', $this->lessonAssessmentIndex( $subject ), 'Все контрольные' );
			$this->renderAuthorFilter( $post_type );
			return;
		}

		if ( PostTypeResolver::isLessonPostType( $post_type ) ) {
			$subject = PostTypeResolver::subjectFromLessonPostType( $post_type );
			$this->renderUsageFilter( 'fs_lesson_usage', $this->courseLessonIndex( $subject ), 'Все уроки' );
			$this->renderAuthorFilter( $post_type );
		}
	}

	/**
	 * @param array<string,string> $states
	 */
	public function filterTaskDraftState( array $states, \WP_Post $post ): array {
		if ( PostTypeResolver::isTaskPostType( $post->post_type ) && isset( $states['draft'] ) ) {
			$states['draft'] = __( 'Незавершённая', 'fs-lms' );
		}
		return $states;
	}

	public function applyTypeFilter( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$post_type = $query->get( 'post_type' );

		if ( PostTypeResolver::isWorkPostType( $post_type ) ) {
			$type = sanitize_key( $_GET['fs_work_type'] ?? '' );
			if ( '' !== $type ) {
				$query->set( 'meta_query', array(
					array( 'key' => PostMetaName::WorkType->value, 'value' => $type ),
				) );
			}
			$usage = sanitize_key( $_GET['fs_work_usage'] ?? '' );
			if ( '' !== $usage ) {
				$subject = PostTypeResolver::subjectFromWorkPostType( $post_type );
				$this->applyUsageFilter( $query, $usage, $this->lessonWorkIndex( $subject ) );
			}
			return;
		}

		if ( PostTypeResolver::isAssessmentPostType( $post_type ) ) {
			$kind = sanitize_key( $_GET['fs_assessment_kind'] ?? '' );
			if ( '' !== $kind ) {
				$query->set( 'meta_query', array(
					array( 'key' => PostMetaName::AssessmentKind->value, 'value' => $kind ),
				) );
			}
			$usage = sanitize_key( $_GET['fs_assessment_usage'] ?? '' );
			if ( '' !== $usage ) {
				$subject = PostTypeResolver::subjectFromAssessmentPostType( $post_type );
				$this->applyUsageFilter( $query, $usage, $this->lessonAssessmentIndex( $subject ) );
			}
			return;
		}

		if ( PostTypeResolver::isLessonPostType( $post_type ) ) {
			$usage = sanitize_key( $_GET['fs_lesson_usage'] ?? '' );
			if ( '' !== $usage ) {
				$subject = PostTypeResolver::subjectFromLessonPostType( $post_type );
				$this->applyUsageFilter( $query, $usage, $this->courseLessonIndex( $subject ) );
			}
		}
	}

	/**
	 * Строит меню «Обучение» на хуке admin_menu (когда текущий пользователь известен).
	 *
	 * Родитель и все пункты ведут на нативные таблицы первого предмета препода.
	 * Переключение между предметами — таб-баром над таблицей (renderSubjectBankTabs).
	 * Если предметов в системе нет — лендинг-фолбэки с предупреждением.
	 */
	public function registerLearningMenu(): void {
		$cap      = Capability::AuthorLmsCourses->value;
		$subjects = $this->teacher_subjects->subjectsForUser( get_current_user_id() );

		// Нет ни одного предмета — пункт «Обучение» не регистрируем вовсе
		// (как «Предметы», см. SubjectsMenuBuilder::buildPages()). Лендинг-заглушка не нужна.
		if ( empty( $subjects ) ) {
			return;
		}

		foreach ( BankType::cases() as $bankType ) {
			$this->bank_slugs[ $bankType->value ] = $this->subjectBankSlug( $bankType, $subjects );
		}

		// Родитель «Обучение» ведёт на таблицу курсов первого предмета.
		$this->learning_parent_slug = $this->bank_slugs[ BankType::Courses->value ];

		$pages = array(
			array(
				'page_title' => Menu::Learning->page_title(),
				'menu_title' => Menu::Learning->menu_title(),
				'capability' => $cap,
				'menu_slug'  => $this->learning_parent_slug,
				// Предметы гарантированно есть (пустой случай отсеян выше) — слаг ведёт
				// на нативную таблицу курсов первого предмета, свой callback не нужен.
				'callback'   => '',
				'icon_url'   => 'dashicons-welcome-learn-more',
				'position'   => 4,
			),
		);

		// Порядок: Курсы · Уроки · Работы · Контрольные · Банк задач · Задания · Статьи.
		// «Курсы» переиспользует слаг родителя (переименование автодубля top-level).
		$subpages = array(
			$this->subjectBankSubpage( Menu::LearningCourses, $this->bank_slugs[ BankType::Courses->value ], $cap ),
			$this->subjectBankSubpage( Menu::LearningLessons, $this->bank_slugs[ BankType::Lessons->value ], $cap ),
			$this->subjectBankSubpage( Menu::LearningWorks, $this->bank_slugs[ BankType::Works->value ], $cap ),
			$this->subjectBankSubpage( Menu::LearningAssessments, $this->bank_slugs[ BankType::Assessments->value ], $cap ),
			// «Банк задач» (fs_lms_problems) — глобальный, не зависит от предмета.
			array(
				'parent_slug' => $this->learning_parent_slug,
				'page_title'  => Menu::LearningProblems->page_title(),
				'menu_title'  => Menu::LearningProblems->menu_title(),
				'capability'  => $cap,
				'menu_slug'   => 'edit.php?post_type=' . PostTypeResolver::problems(),
				'callback'    => '',
			),
			$this->subjectBankSubpage( Menu::LearningTasks, $this->bank_slugs[ BankType::Tasks->value ], $cap ),
			$this->subjectBankSubpage( Menu::LearningArticles, $this->bank_slugs[ BankType::Articles->value ], Capability::ManageLmsArticles->value ),
		);

		$this->menu_registrar->addPages( $pages )->addSubPages( $subpages )->register();
	}

	/**
	 * Строит слаг пункта банка предмета.
	 *
	 * При наличии предметов — прямая ссылка на нативную таблицу первого предмета;
	 * иначе — слаг плагин-страницы (лендинг-фолбэк с предупреждением «нет предметов»).
	 */
	private function subjectBankSlug( BankType $bankType, array $subjects ): string {
		$first = $subjects[0] ?? null;

		return null !== $first
			? 'edit.php?post_type=' . $bankType->cpt( $first->key )
			: $bankType->menu()->value;
	}

	/**
	 * Конфиг сабстраницы банка предмета.
	 *
	 * Прямой переход на edit.php callback не требует; лендинг-фолбэк — требует.
	 *
	 * @return array<string, mixed>
	 */
	private function subjectBankSubpage( Menu $case, string $slug, string $cap ): array {
		$is_direct = str_contains( $slug, 'edit.php' );

		return array(
			'parent_slug' => $this->learning_parent_slug,
			'page_title'  => $case->page_title(),
			'menu_title'  => $case->menu_title(),
			'capability'  => $cap,
			'menu_slug'   => $slug,
			'callback'    => $is_direct ? '' : array( $this, $case->callback() ),
		);
	}

	/**
	 * Делает «Обучение» активным родителем на нативных экранах банков.
	 */
	public function highlightLearningParent( string $parent_file ): string {
		return '' !== $this->learningSubmenuFor( $GLOBALS['typenow'] ?? '' )
			? $this->learning_parent_slug
			: $parent_file;
	}

	/**
	 * Подсвечивает соответствующий пункт сабменю на нативных экранах банков.
	 */
	public function highlightLearningSubmenu( ?string $submenu_file ): ?string {
		$match = $this->learningSubmenuFor( $GLOBALS['typenow'] ?? '' );

		return '' !== $match ? $match : $submenu_file;
	}

	/**
	 * Возвращает слаг пункта сабменю «Обучения» для текущего CPT (или '').
	 */
	private function learningSubmenuFor( string $post_type ): string {
		if ( PostTypeResolver::isProblemPostType( $post_type ) ) {
			return 'edit.php?post_type=' . PostTypeResolver::problems();
		}

		$bankType = BankType::fromPostType( $post_type );

		return null !== $bankType ? ( $this->bank_slugs[ $bankType->value ] ?? '' ) : '';
	}

	// ── Фильтры использования / автора ──────────────────────────────────────────

	/**
	 * @param array<int, array{int, string, int[]}> $index [[id, title, ref_ids[]]]
	 */
	private function renderUsageFilter( string $param, array $index, string $all_label ): void {
		$options = [ 'orphan' => 'Не используется' ];
		foreach ( $index as [ $id, $title, $ids ] ) {
			if ( ! empty( $ids ) ) {
				$options[ $id ] = $title;
			}
		}
		render_fs_select( [
			'name'      => $param,
			'options'   => $options,
			'selected'  => sanitize_key( $_GET[ $param ] ?? '' ),
			'all_label' => $all_label,
		] );
	}

	private function renderAuthorFilter( string $post_type ): void {
		$posts      = $this->posts->search( $post_type, [
			'status' => array( 'publish', 'draft', 'pending', 'private', 'fs_archived' ),
		] );
		$author_ids = array_unique( array_map( static fn( $p ) => (int) $p->post_author, $posts ) );
		if ( count( $author_ids ) < 2 ) {
			return;
		}
		$options = [];
		foreach ( $author_ids as $uid ) {
			$user = get_user_by( 'id', $uid );
			if ( false !== $user ) {
				$options[ $uid ] = $user->display_name;
			}
		}
		render_fs_select( [
			'name'      => 'author',
			'options'   => $options,
			'selected'  => (string) (int) ( $_GET['author'] ?? 0 ),
			'all_label' => 'Все авторы',
		] );
	}

	/**
	 * @param array<int, array{int, string, int[]}> $index [[id, title, ref_ids[]]]
	 */
	private function applyUsageFilter( \WP_Query $query, string $usage, array $index ): void {
		$all_used = [];
		$by_id    = [];
		foreach ( $index as [ $id, , $ids ] ) {
			$by_id[ $id ] = $ids;
			foreach ( $ids as $rid ) {
				$all_used[] = $rid;
			}
		}

		if ( 'orphan' === $usage ) {
			$query->set( 'post__not_in', array_values( array_unique( $all_used ) ) );
			return;
		}

		if ( is_numeric( $usage ) ) {
			$ids = $by_id[ (int) $usage ] ?? [];
			$query->set( 'post__in', empty( $ids ) ? [ 0 ] : array_values( array_unique( $ids ) ) );
		}
	}

	// ── Индексы потребителей контента ────────────────────────────────────────────

	/**
	 * Строит индекс: [[consumer_id, consumer_title, ref_ids[]]].
	 *
	 * @param callable(array): int[] $extract
	 * @return array<int, array{int, string, int[]}>
	 */
	private function usageIndex( string $consumer_cpt, callable $extract ): array {
		$consumers = $this->posts->search( $consumer_cpt, [
			'status'  => array( 'publish', 'draft', 'pending', 'private', 'future', 'fs_archived' ),
			'orderby' => 'title',
		] );
		$result = [];
		foreach ( $consumers as $consumer ) {
			$meta     = $this->posts->getMeta( $consumer->ID, PostMetaName::Meta->value );
			$meta     = is_array( $meta ) ? $meta : [];
			$result[] = [ $consumer->ID, $consumer->post_title, $extract( $meta ) ];
		}
		return $result;
	}

	/** Уроки → курсы: индекс курс → lesson_ids. */
	private function courseLessonIndex( string $subject ): array {
		return $this->usageIndex(
			PostTypeResolver::courses( $subject ),
			static function ( array $meta ): array {
				$modules = ModuleDTO::fromList( is_array( $meta['modules'] ?? null ) ? $meta['modules'] : [] );
				$ids     = [];
				foreach ( $modules as $module ) {
					foreach ( $module->lessonIds as $lid ) {
						$ids[] = $lid;
					}
				}
				return $ids;
			}
		);
	}

	/** Работы → уроки: индекс урок → work_ids из steps. */
	private function lessonWorkIndex( string $subject ): array {
		return $this->usageIndex(
			PostTypeResolver::lessons( $subject ),
			static fn( array $meta ): array => self::stepRefIds( $meta, StepType::Work )
		);
	}

	/** Контрольные → уроки: индекс урок → assessment_ids из steps. */
	private function lessonAssessmentIndex( string $subject ): array {
		return $this->usageIndex(
			PostTypeResolver::lessons( $subject ),
			static fn( array $meta ): array => self::stepRefIds( $meta, StepType::Assessment )
		);
	}

	/** Извлекает ref-идентификаторы шагов указанного типа из meta урока. */
	private static function stepRefIds( array $meta, StepType $type ): array {
		$steps = is_array( $meta['steps'] ?? null ) ? $meta['steps'] : [];
		$ids   = [];
		foreach ( $steps as $step ) {
			if ( ! is_array( $step ) || ( $step['type'] ?? '' ) !== $type->value ) {
				continue;
			}
			$ref = (int) ( $step['payload']['ref'] ?? 0 );
			if ( $ref > 0 ) {
				$ids[] = $ref;
			}
		}
		return $ids;
	}

	/**
	 * Тип банка для текущего экрана списка (или null — не экран банка).
	 */
	private function currentBankType(): ?BankType {
		$screen = get_current_screen();
		if ( ! $screen || 'edit' !== $screen->base ) {
			return null;
		}

		return BankType::fromPostType( $screen->post_type );
	}

	/**
	 * Выводит «шапку» банка над нативной таблицей ОДНИМ блоком: описание-абзац +
	 * таб-бар предметов (при 2+ предметах, курсы/уроки/работы/задания/статьи).
	 *
	 * НБ-1: и описание, и табы лежат в одном `.notice`, который штатный JS WP
	 * целиком переносит под заголовок (перед `subsubsub`-views), поэтому табы не
	 * «прыгают» отдельно от описания при загрузке. Хук admin_notices.
	 */
	public function renderBankChrome(): void {
		$bankType = $this->currentBankType();
		if ( null === $bankType ) {
			return;
		}

		$tabs     = array();
		$subjects = $this->teacher_subjects->subjectsForUser( get_current_user_id() );
		if ( count( $subjects ) >= 2 ) {
			$active = $bankType->subjectFromPostType( get_current_screen()->post_type );
			foreach ( $subjects as $subject ) {
				$tabs[] = array(
					'name'   => $subject->name,
					'url'    => admin_url( 'edit.php?post_type=' . $bankType->cpt( $subject->key ) ),
					'active' => $subject->key === $active,
				);
			}
		}

		$this->render(
			'admin/components/bank-notice',
			array(
				'text' => $bankType->description(),
				'tabs' => $tabs,
			)
		);
	}

	/**
	 * Рендерит лендинг-фолбэк банка: вкладки-предметы + переход на нативный экран CPT.
	 * Вызывается только когда у меню нет прямого edit.php-слага (нет предметов в системе).
	 */
	private function renderBank( BankType $bankType ): void {
		$user     = get_current_user_id();
		$subjects = $this->teacher_subjects->subjectsForUser( $user );

		$active = sanitize_key( wp_unslash( $_GET['fs_subject'] ?? '' ) );
		$keys   = array_map( static fn( $s ) => $s->key, $subjects );
		if ( '' === $active || ! in_array( $active, $keys, true ) ) {
			$active = $keys[0] ?? '';
		}

		$page_slug = $bankType->menu()->value;
		$tabs      = array();
		foreach ( $subjects as $subject ) {
			$tabs[] = array(
				'name'   => $subject->name,
				'url'    => add_query_arg(
					array( 'page' => $page_slug, 'fs_subject' => $subject->key ),
					admin_url( 'admin.php' )
				),
				'active' => $subject->key === $active,
			);
		}

		$cpt      = '' !== $active ? $bankType->cpt( $active ) : '';
		$list_url = '' !== $cpt ? admin_url( 'edit.php?post_type=' . $cpt ) : '';
		$new_url  = '' !== $cpt ? admin_url( 'post-new.php?post_type=' . $cpt ) : '';

		$this->render( 'admin/learning/bank-landing', compact( 'subjects', 'tabs', 'list_url', 'new_url' ) + array(
			'title' => $bankType->title(),
		) );
	}
}
