<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Menu;
use Inc\Registrars\MenuRegistrar;
use Inc\Services\Course\TeacherSubjectsService;
use Inc\Services\PostTypeResolver;
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
		add_action(
			'admin_footer',
			function (): void {
				$screen = get_current_screen();
				if ( ! $screen ) {
					return;
				}
				$pt = $screen->post_type;
				// draft-creator-modal нужна на страницах работ (создание задачи из конструктора),
				// уроков (создание работы) и курсов (создание урока).
				if ( PostTypeResolver::isWorkPostType( $pt )
					|| PostTypeResolver::isLessonPostType( $pt )
					|| PostTypeResolver::isCoursePostType( $pt ) ) {
					include_once $this->plugin_path . 'templates/admin/components/modals/draft-creator-modal.php';
				}
			}
		);
	}

	public function renderCourses(): void {
		$this->renderBank( 'courses', Menu::LearningCourses->value );
	}

	public function renderLessons(): void {
		$this->renderBank( 'lessons', Menu::LearningLessons->value );
	}

	public function renderWorks(): void {
		$this->renderBank( 'works', Menu::LearningWorks->value );
	}

	public function renderTasks(): void {
		$this->renderBank( 'tasks', Menu::LearningTasks->value );
	}

	public function renderArticles(): void {
		$this->renderBank( 'articles', Menu::LearningArticles->value );
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

		// Слаги банков по типу (прямые edit.php при наличии предметов, иначе лендинг-фолбэк).
		foreach ( $this->bankMenuMap() as $type => $menu ) {
			$this->bank_slugs[ $type ] = $this->subjectBankSlug( $type, $subjects, $menu );
		}

		// Родитель «Обучение» ведёт на таблицу курсов первого предмета.
		$this->learning_parent_slug = $this->bank_slugs['courses'];

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
			$this->subjectBankSubpage( Menu::LearningCourses, $this->bank_slugs['courses'], $cap ),
			$this->subjectBankSubpage( Menu::LearningLessons, $this->bank_slugs['lessons'], $cap ),
			$this->subjectBankSubpage( Menu::LearningWorks, $this->bank_slugs['works'], $cap ),
			// «Банк задач» (fs_lms_problems) — глобальный, не зависит от предмета.
			array(
				'parent_slug' => $this->learning_parent_slug,
				'page_title'  => Menu::LearningProblems->page_title(),
				'menu_title'  => Menu::LearningProblems->menu_title(),
				'capability'  => $cap,
				'menu_slug'   => 'edit.php?post_type=' . PostTypeResolver::problems(),
				'callback'    => '',
			),
			$this->subjectBankSubpage( Menu::LearningTasks, $this->bank_slugs['tasks'], $cap ),
			$this->subjectBankSubpage( Menu::LearningArticles, $this->bank_slugs['articles'], $cap ),
		);

		$this->menu_registrar->addPages( $pages )->addSubPages( $subpages )->register();
	}

	/**
	 * Карта тип банка → пункт меню (для построения слагов и фолбэков).
	 *
	 * @return array<string, Menu>
	 */
	private function bankMenuMap(): array {
		return array(
			'courses'  => Menu::LearningCourses,
			'lessons'  => Menu::LearningLessons,
			'works'    => Menu::LearningWorks,
			'tasks'    => Menu::LearningTasks,
			'articles' => Menu::LearningArticles,
		);
	}

	/**
	 * Строит слаг пункта «Задания/Статьи предмета».
	 *
	 * При наличии предметов — прямая ссылка на нативную таблицу первого предмета;
	 * иначе — слаг плагин-страницы (лендинг-фолбэк с предупреждением «нет предметов»).
	 *
	 * @param string                       $type     tasks|articles
	 * @param array<int, object>           $subjects Предметы препода.
	 * @param Menu                         $fallback Пункт меню для пустого случая.
	 */
	private function subjectBankSlug( string $type, array $subjects, Menu $fallback ): string {
		$first = $subjects[0] ?? null;

		return null !== $first
			? 'edit.php?post_type=' . $this->resolveCpt( $type, $first->key )
			: $fallback->value;
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

		$type = $this->bankTypeForPostType( $post_type );

		return '' !== $type ? ( $this->bank_slugs[ $type ] ?? '' ) : '';
	}

	/**
	 * Определяет тип банка (courses|lessons|works|tasks|articles) по CPT или '' .
	 */
	private function bankTypeForPostType( string $post_type ): string {
		return match ( true ) {
			PostTypeResolver::isCoursePostType( $post_type )  => 'courses',
			PostTypeResolver::isLessonPostType( $post_type )  => 'lessons',
			PostTypeResolver::isWorkPostType( $post_type )    => 'works',
			PostTypeResolver::isTaskPostType( $post_type )    => 'tasks',
			PostTypeResolver::isArticlePostType( $post_type ) => 'articles',
			default                                           => '',
		};
	}

	/**
	 * Выводит описание-абзац над таблицей банка (как в «Банке задач»).
	 *
	 * Хук admin_notices штатно переносится JS под заголовок (перед фильтрами-views).
	 */
	public function renderBankDescription(): void {
		$type = $this->currentBankType();
		if ( '' === $type ) {
			return;
		}

		$this->render( 'admin/components/bank-notice', array( 'text' => $this->bankDescription( $type ) ) );
	}

	/**
	 * Выводит таб-бар предметов над таблицей банка (курсы/уроки/работы/задания/статьи).
	 *
	 * Показываем только на списках CPT банков и только при 2+ предметах.
	 */
	public function renderSubjectBankTabs(): void {
		$type = $this->currentBankType();
		if ( '' === $type ) {
			return;
		}

		$subjects = $this->teacher_subjects->subjectsForUser( get_current_user_id() );
		if ( count( $subjects ) < 2 ) {
			return;
		}

		$active = $this->subjectKeyFromPostType( $type, get_current_screen()->post_type );

		$tabs = array();
		foreach ( $subjects as $subject ) {
			$tabs[] = array(
				'name'   => $subject->name,
				'url'    => admin_url( 'edit.php?post_type=' . $this->resolveCpt( $type, $subject->key ) ),
				'active' => $subject->key === $active,
			);
		}

		$this->render( 'admin/components/subject-bank-tabs', array( 'tabs' => $tabs ) );
	}

	/**
	 * Тип банка для текущего экрана списка (или '' — не экран банка).
	 */
	private function currentBankType(): string {
		$screen = get_current_screen();
		if ( ! $screen || 'edit' !== $screen->base ) {
			return '';
		}

		return $this->bankTypeForPostType( $screen->post_type );
	}

	/**
	 * Текст описания банка по типу.
	 */
	private function bankDescription( string $type ): string {
		return match ( $type ) {
			'courses'  => __( 'Банк курсов предмета. Курс состоит из уроков и назначается учебным группам.', 'fs-lms' ),
			'lessons'  => __( 'Банк уроков предмета. Урок состоит из работ и входит в курсы.', 'fs-lms' ),
			'works'    => __( 'Банк работ предмета. Работа собирается из задач в конструкторе и входит в уроки.', 'fs-lms' ),
			'tasks'    => __( 'Банк заданий предмета. Задание — отдельная единица контента, из которых собираются работы.', 'fs-lms' ),
			'articles' => __( 'Банк статей предмета. Справочные материалы для учеников.', 'fs-lms' ),
			default    => '',
		};
	}

	/**
	 * Извлекает ключ предмета из CPT банка указанного типа.
	 */
	private function subjectKeyFromPostType( string $type, string $post_type ): string {
		return match ( $type ) {
			'courses'  => PostTypeResolver::subjectFromCoursePostType( $post_type ),
			'lessons'  => PostTypeResolver::subjectFromLessonPostType( $post_type ),
			'works'    => PostTypeResolver::subjectFromWorkPostType( $post_type ),
			'tasks'    => PostTypeResolver::subjectFromTaskPostType( $post_type ),
			'articles' => PostTypeResolver::subjectFromArticlePostType( $post_type ),
			default    => '',
		};
	}

	/**
	 * Рендерит страницу банка: вкладки-предметы + переход на нативный экран CPT.
	 *
	 * @param string $type      tasks|works|lessons|courses|articles
	 * @param string $page_slug Слаг страницы (для ссылок вкладок).
	 *
	 * @return void
	 */
	private function renderBank( string $type, string $page_slug ): void {
		$user     = get_current_user_id();
		$subjects = $this->teacher_subjects->subjectsForUser( $user );
		$title    = $this->bankTitle( $type );

		$active = sanitize_key( wp_unslash( $_GET['fs_subject'] ?? '' ) );
		$keys   = array_map( static fn( $s ) => $s->key, $subjects );
		if ( '' === $active || ! in_array( $active, $keys, true ) ) {
			$active = $keys[0] ?? '';
		}

		echo '<div class="wrap fs-lms-learning">';
		echo '<h1>' . esc_html( $title ) . '</h1>';

		if ( empty( $subjects ) ) {
			echo '<div class="notice notice-warning"><p>'
				. esc_html__( 'Нет доступных предметов. Сначала создайте предмет в разделе «Предметы».', 'fs-lms' )
				. '</p></div></div>';
			return;
		}

		// Вкладки предметов.
		echo '<nav class="nav-tab-wrapper fs-lms-subject-tabs">';
		foreach ( $subjects as $subject ) {
			$url    = add_query_arg(
				array( 'page' => $page_slug, 'fs_subject' => $subject->key ),
				admin_url( 'admin.php' )
			);
			$class  = 'nav-tab' . ( $subject->key === $active ? ' nav-tab-active' : '' );
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">'
				. esc_html( $subject->name ) . '</a>';
		}
		echo '</nav>';

		$cpt = $this->resolveCpt( $type, $active );

		if ( '' !== $cpt ) {
			$list_url = admin_url( 'edit.php?post_type=' . $cpt );
			$new_url  = admin_url( 'post-new.php?post_type=' . $cpt );

			echo '<div class="fs-lms-bank-actions">';
			echo '<a class="button button-primary" href="' . esc_url( $list_url ) . '">'
				. esc_html__( 'Открыть список', 'fs-lms' ) . '</a> ';
			echo '<a class="button" href="' . esc_url( $new_url ) . '">'
				. esc_html__( 'Добавить', 'fs-lms' ) . '</a>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Резолвит CPT-slug банка по типу и предмету.
	 *
	 * @param string $type tasks|works|lessons|courses|articles
	 * @param string $key  Ключ предмета.
	 *
	 * @return string
	 */
	private function resolveCpt( string $type, string $key ): string {
		return match ( $type ) {
			'tasks'    => PostTypeResolver::tasks( $key ),
			'works'    => PostTypeResolver::works( $key ),
			'lessons'  => PostTypeResolver::lessons( $key ),
			'courses'  => PostTypeResolver::courses( $key ),
			'articles' => PostTypeResolver::articles( $key ),
			default    => '', // 'problems' — прямой пункт меню на edit.php, без банка-обёртки
		};
	}

	private function bankTitle( string $type ): string {
		return match ( $type ) {
			'tasks'    => Menu::LearningTasks->page_title(),
			'works'    => Menu::LearningWorks->page_title(),
			'lessons'  => Menu::LearningLessons->page_title(),
			'courses'  => Menu::LearningCourses->page_title(),
			'articles' => Menu::LearningArticles->page_title(),
			default    => 'Обучение',
		};
	}
}
