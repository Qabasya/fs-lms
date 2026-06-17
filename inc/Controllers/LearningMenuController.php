<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\Capability;
use Inc\Enums\Menu;
use Inc\Registrars\MenuRegistrar;
use Inc\Services\Course\TeacherSubjectsService;
use Inc\Services\PostTypeResolver;

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

	public function __construct(
		private readonly MenuRegistrar          $menu_registrar,
		private readonly TeacherSubjectsService $teacher_subjects,
	) {
		parent::__construct();
	}

	public function register(): void {
		$cap = Capability::ManageLMSAssignments->value;

		$pages = array(
			array(
				'page_title' => Menu::Learning->page_title(),
				'menu_title' => Menu::Learning->menu_title(),
				'capability' => $cap,
				'menu_slug'  => Menu::Learning->value,
				'callback'   => array( $this, Menu::Learning->callback() ),
				'icon_url'   => 'dashicons-welcome-learn-more',
				'position'   => 4,
			),
		);

		// Первый сабменю-пункт «Курсы» переиспользует slug top-level (переименование автодубля).
		// Задания и Статьи живут в разделе «Предметы» (доступен преподавателю), не дублируются здесь.
		// «Задачи» (fs_lms_problems) — глобальный банк, отдельный пункт в этом же меню.
		$submenus = array(
			Menu::LearningCourses->value  => Menu::Learning->value,
			Menu::LearningLessons->value  => Menu::LearningLessons->value,
			Menu::LearningWorks->value    => Menu::LearningWorks->value,
			Menu::LearningProblems->value => Menu::LearningProblems->value,
			Menu::LearningTasks->value    => Menu::LearningTasks->value,
			Menu::LearningArticles->value => Menu::LearningArticles->value,

		);

		$cases = array(
			Menu::LearningCourses,
			Menu::LearningLessons,
			Menu::LearningWorks,
			Menu::LearningProblems,
			Menu::LearningTasks,
			Menu::LearningArticles,

		);

		$subpages = array();
		foreach ( $cases as $case ) {
			$subpages[] = array(
				'parent_slug' => Menu::Learning->value,
				'page_title'  => $case->page_title(),
				'menu_title'  => $case->menu_title(),
				'capability'  => $cap,
				'menu_slug'   => $submenus[ $case->value ],
				'callback'    => array( $this, $case->callback() ),
			);
		}

		$this->menu_registrar->addPages( $pages )->addSubPages( $subpages )->register();

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

	public function renderProblems(): void {
		echo '<div class="wrap fs-lms-learning">';
		echo '<h1>' . esc_html__( 'Задачи', 'fs-lms' ) . '</h1>';
		echo '<p>' . esc_html__( 'Глобальный банк приватных задач. Не привязаны к предмету и не отображаются на сайте.', 'fs-lms' ) . '</p>';
		echo '<div class="fs-lms-bank-actions">';
		echo '<a class="button button-primary" href="' . esc_url( admin_url( 'edit.php?post_type=' . PostTypeResolver::problems() ) ) . '">'
			. esc_html__( 'Все задачи', 'fs-lms' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( admin_url( 'post-new.php?post_type=' . PostTypeResolver::problems() ) ) . '">'
			. esc_html__( 'Добавить задачу', 'fs-lms' ) . '</a>';
		echo '</div></div>';
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
			default    => '', // 'problems' handled separately in renderProblems()
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
