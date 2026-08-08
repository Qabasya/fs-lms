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

/**
 * Class LearningMenuController
 *
 * Единое меню «Обучение» с сабменю-банками (Курсы / Уроки / Работы / Задания / Статьи)
 * и подсветка активного пункта на нативных экранах CPT (show_in_menu=false).
 *
 * После распила Т14.1 здесь ТОЛЬКО меню и подсветка (общее состояние $bank_slugs);
 * фильтры list-table — BankListTableController, шапка/лендинги банков —
 * BankChromeController (его колбеки регистрируются сабстраницами-фолбэками),
 * row-actions и модалка черновика — BankRowActionsController.
 *
 * @package Inc\Controllers
 */
class LearningMenuController extends BaseController implements ServiceInterface {

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
		private readonly BankChromeController   $chrome,
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
	}

	/**
	 * Строит меню «Обучение» на хуке admin_menu (когда текущий пользователь известен).
	 *
	 * Родитель и все пункты ведут на нативные таблицы первого предмета препода.
	 * Переключение между предметами — таб-баром над таблицей (BankChromeController).
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
	 * Прямой переход на edit.php callback не требует; лендинг-фолбэк рендерит
	 * BankChromeController (колбеки renderCourses()/renderLessons()/…).
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
			'callback'    => $is_direct ? '' : array( $this->chrome, $case->callback() ),
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
}
