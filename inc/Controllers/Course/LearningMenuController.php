<?php

declare( strict_types=1 );

namespace Inc\Controllers\Course;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Course\BankType;
use Inc\Enums\Wp\Menu;
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

		// Над таблицей банка: описание (всегда) + таб-бар предметов (при 2+ предметах).
		add_action( 'admin_notices', array( $this, 'renderBankDescription' ) );
		add_action( 'admin_notices', array( $this, 'renderSubjectBankTabs' ) );

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
			|| PostTypeResolver::isCoursePostType( $pt ) ) {
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

	/**
	 * Строит меню «Обучение» на хуке admin_menu (когда текущий пользователь известен).
	 *
	 * Родитель и все пункты ведут на нативные таблицы первого предмета препода.
	 * Переключение между предметами — таб-баром над таблицей (renderSubjectBankTabs).
	 * Если предметов в системе нет — лендинг-фолбэки с предупреждением.
	 */
	public function registerLearningMenu(): void {
		$cap      = Capability::ManageLMSAssignments->value;
		$subjects = $this->teacher_subjects->subjectsForUser( get_current_user_id() );

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
				// Прямой edit.php callback не требует; для пустого случая — лендинг.
				'callback'   => empty( $subjects ) ? array( $this, 'renderCourses' ) : '',
				'icon_url'   => 'dashicons-welcome-learn-more',
				'position'   => 4,
			),
		);

		// Порядок: Курсы · Уроки · Работы · Банк задач · Задания · Статьи.
		// «Курсы» переиспользует слаг родителя (переименование автодубля top-level).
		$subpages = array(
			$this->subjectBankSubpage( Menu::LearningCourses, $this->bank_slugs[ BankType::Courses->value ], $cap ),
			$this->subjectBankSubpage( Menu::LearningLessons, $this->bank_slugs[ BankType::Lessons->value ], $cap ),
			$this->subjectBankSubpage( Menu::LearningWorks, $this->bank_slugs[ BankType::Works->value ], $cap ),
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
			$this->subjectBankSubpage( Menu::LearningArticles, $this->bank_slugs[ BankType::Articles->value ], $cap ),
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
	 * Выводит описание-абзац над таблицей банка (как в «Банке задач»).
	 *
	 * Хук admin_notices штатно переносится JS под заголовок (перед фильтрами-views).
	 */
	public function renderBankDescription(): void {
		$bankType = $this->currentBankType();
		if ( null === $bankType ) {
			return;
		}

		$this->render( 'admin/components/bank-notice', array( 'text' => $bankType->description() ) );
	}

	/**
	 * Выводит таб-бар предметов над таблицей банка (курсы/уроки/работы/задания/статьи).
	 *
	 * Показываем только на списках CPT банков и только при 2+ предметах.
	 */
	public function renderSubjectBankTabs(): void {
		$bankType = $this->currentBankType();
		if ( null === $bankType ) {
			return;
		}

		$subjects = $this->teacher_subjects->subjectsForUser( get_current_user_id() );
		if ( count( $subjects ) < 2 ) {
			return;
		}

		$active = $bankType->subjectFromPostType( get_current_screen()->post_type );

		$tabs = array();
		foreach ( $subjects as $subject ) {
			$tabs[] = array(
				'name'   => $subject->name,
				'url'    => admin_url( 'edit.php?post_type=' . $bankType->cpt( $subject->key ) ),
				'active' => $subject->key === $active,
			);
		}

		$this->render( 'admin/components/subject-bank-tabs', array( 'tabs' => $tabs ) );
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
