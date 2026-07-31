<?php

declare( strict_types=1 );

namespace Inc\Controllers\Course;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Course\BankType;
use Inc\Enums\Wp\Menu;
use Inc\Controllers\Builders\BankListFilters;
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
		private readonly BankListFilters        $filters,
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

		// «Дублировать» в строке таблицы банка — точка входа к AjaxHook::Clone*
		// (Эпик: допиливание UI недостижимых эндпоинтов).
		add_filter( 'post_row_actions', array( $this, 'addCloneRowAction' ), 10, 2 );

		// draft-creator-modal: рендерится на страницах уроков и курсов
		// (создание работы из урока / урока из курса без перезагрузки).
		add_action( 'admin_footer', array( $this, 'renderDraftCreatorModal' ) );
	}

	/**
	 * Добавляет действие «Дублировать» в строку таблицы банка контента.
	 *
	 * Кнопка ничего не делает сама: JS (`admin/services/content-clone.js`) читает
	 * `data-clone-*` и зовёт соответствующий AJAX-хук клонирования.
	 *
	 * @param array<string, string> $actions Действия строки
	 * @param \WP_Post              $post    Запись банка
	 *
	 * @return array<string, string>
	 */
	public function addCloneRowAction( array $actions, \WP_Post $post ): array {
		$type = match ( true ) {
			PostTypeResolver::isLessonPostType( $post->post_type )     => 'lesson',
			PostTypeResolver::isWorkPostType( $post->post_type )       => 'work',
			PostTypeResolver::isAssessmentPostType( $post->post_type ) => 'assessment',
			PostTypeResolver::isCoursePostType( $post->post_type )     => 'course',
			default                                                    => '',
		};

		if ( '' === $type || ! current_user_can( Capability::Admin->value ) ) {
			return $actions;
		}

		$actions['fs_lms_clone'] = sprintf(
			'<a href="#" class="js-fs-clone" data-clone-type="%s" data-clone-id="%d">%s</a>',
			esc_attr( $type ),
			$post->ID,
			esc_html__( 'Дублировать', 'fs-lms' )
		);

		return $actions;
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

	/**
	 * Фильтры банка над нативной таблицей (хук restrict_manage_posts).
	 *
	 * @param string $post_type CPT экрана
	 * @param string $which     Позиция панели: top|bottom
	 *
	 * @return void
	 */
	public function renderTypeFilter( string $post_type, string $which = 'top' ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$selects = $this->filters->selectsFor( $post_type );
		if ( empty( $selects ) ) {
			return;
		}

		$this->render( 'admin/learning/bank-filters', compact( 'selects' ) );
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

	/**
	 * Применяет фильтры банка к списку (хук pre_get_posts).
	 *
	 * @param \WP_Query $query Запрос экрана
	 *
	 * @return void
	 */
	public function applyTypeFilter( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$this->filters->apply( $query );
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
	 * Прямая ссылка на нативную таблицу первого предмета, у которого для этого банка
	 * реально зарегистрирован CPT (Эпик 18: безбанковый предмет не имеет tasks/articles
	 * CPT — пропускаем его при выборе «первого доступного», см. T18.4). Если ни у кого
	 * из предметов нужного CPT нет — слаг плагин-страницы (лендинг-фолбэк).
	 */
	private function subjectBankSlug( BankType $bankType, array $subjects ): string {
		foreach ( $subjects as $subject ) {
			$cpt = $bankType->cpt( $subject->key );
			if ( post_type_exists( $cpt ) ) {
				return 'edit.php?post_type=' . $cpt;
			}
		}

		return $bankType->menu()->value;
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
		// Эпик 18: не предлагаем переключиться на предмет без CPT этого банка (T18.4).
		$subjects = array_filter(
			$this->teacher_subjects->subjectsForUser( get_current_user_id() ),
			static fn( $s ) => post_type_exists( $bankType->cpt( $s->key ) )
		);
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
		// Эпик 18: только предметы, у которых реально зарегистрирован CPT этого банка
		// (T18.4) — иначе список/добавление вели бы на несуществующий post_type.
		$subjects = array_values( array_filter(
			$this->teacher_subjects->subjectsForUser( $user ),
			static fn( $s ) => post_type_exists( $bankType->cpt( $s->key ) )
		) );

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
