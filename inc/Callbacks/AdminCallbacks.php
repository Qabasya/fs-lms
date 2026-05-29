<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Controllers\BoilerplatePageController;
use Inc\Core\BaseController;
use Inc\DTO\AcademicPeriodDTO;
use Inc\DTO\StudentGroupDTO;
use Inc\Enums\UserRole;
use Inc\Repositories\OptionsRepositories\AcademicPeriodRepository;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\OptionsRepositories\UserRepository;
use Inc\Services\AcademicPeriodService;
use Inc\Services\StudentGroupService;
use Inc\Shared\Traits\TemplateRenderer;

/**
 * Класс AdminCallbacks
 *
 * Обработчики (коллбеки) для административной панели WordPress.
 *
 * @package Inc\Callbacks
 *
 * ### Основные обязанности:
 *
 * 1. **Рендеринг Dashboard** — отображение главной страницы плагина (временная заглушка).
 * 2. **Рендеринг страницы настроек** — вывод интерфейса управления предметами.
 * 3. **Прокси для Boilerplate** — делегирование отображения страницы типовых условий.
 *
 * ### Архитектурная роль:
 *
 * Делегирует рендеринг страниц шаблонам, а бизнес-логику — контроллерам и репозиториям.
 *
 * @method void render(string $template, array $data = []) — метод трейта TemplateRenderer
 */
class AdminCallbacks extends BaseController {

	use TemplateRenderer;

	/**
	 * Конструктор.
	 *
	 * @param SubjectRepository         $subjects                  Репозиторий предметов
	 * @param AcademicPeriodRepository  $periods                   Репозиторий учебных периодов
	 * @param UserRepository            $users                     Репозиторий пользователей
	 * @param BoilerplatePageController $boilerplatePageController Контроллер страницы boilerplate
	 * @param AcademicPeriodService     $period_service            Сервис учебных периодов
	 * @param StudentGroupService       $group_service             Сервис групп учеников
	 */
	public function __construct(
		private readonly SubjectRepository $subjects,
		private readonly AcademicPeriodRepository $periods,
		private readonly UserRepository $users,
		private readonly BoilerplatePageController $boilerplatePageController,
		private readonly AcademicPeriodService $period_service,
		private readonly StudentGroupService $group_service,
	) {
		parent::__construct();
	}

	/**
	 * Главная страница Dashboard (временная заглушка).
	 *
	 * @return void
	 */
	public function adminDashboard(): void {
		$this->render(
			'admin/dashboard'
		);
	}

	/**
	 * Страница настроек (управление предметами).
	 *
	 * @return void
	 */
	public function settingsPage(): void {
		$this->render(
			'admin/settings',
			array(
				'subjects'         => $this->subjects->readAll(),
				'academic_periods' => $this->periods->readAll(),
			)
		);
	}

	/**
	 * Страница групп
	 *
	 * @return void
	 */
	public function groupsPage(): void {
		$raw_periods    = $this->periods->readAll();
		$period_dtos    = array_map( fn( array $p ) => AcademicPeriodDTO::fromArray( $p ), $raw_periods );
		$sorted         = $this->period_service->getSortedPeriods( $period_dtos );
		$current_period = $sorted['current'];
		$other_periods  = $sorted['other'];

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$selected_period_id = sanitize_key( wp_unslash( $_GET['period_filter'] ?? '' ) );
		if ( '' === $selected_period_id ) {
			$selected_period_id = $current_period['id'] ?? '';
		}

		$groups   = '' !== $selected_period_id
			? $this->group_service->getGroupsByPeriod( $selected_period_id )
			: array();
		$teachers = $this->users->getByRole( UserRole::FSTeacher );
		$subjects = $this->subjects->readAll();

		$teacher_map = array();
		foreach ( $teachers as $teacher ) {
			$teacher_map[ $teacher->id ] = $teacher->displayName;
		}

		$groups_view = array_map(
			fn( StudentGroupDTO $g ) => array(
				'id'           => $g->id,
				'title'        => $g->title,
				'period_name'  => $raw_periods[ $g->period_id ]['name'] ?? $g->period_id,
				'subject_name' => $subjects[ $g->subject_id ]->name ?? $g->subject_id,
				'teacher_name' => $teacher_map[ $g->teacher_id ] ?? 'Не назначен',
			),
			$groups
		);

		$this->render(
			'admin/groups',
			array(
				'subjects'           => $subjects,
				'academic_periods'   => $raw_periods,
				'current_period'     => $current_period,
				'other_periods'      => $other_periods,
				'selected_period_id' => $selected_period_id,
				'groups_view'        => $groups_view,
				'teachers'           => $teachers,
			)
		);
	}

	/**
	 * Страница пользователей
	 *
	 * @return void
	 */
	public function userlistPage(): void {
		$this->render(
			'admin/userlist',
			array(
				'subjects'         => $this->subjects->readAll(),
				'academic_periods' => $this->periods->readAll(),
			)
		);
	}

	/**
	 * Прокси-метод для страницы управления типовыми условиями (boilerplate).
	 *
	 * @return void
	 */
	public function boilerplatePage(): void {
		// displayPage() — самостоятельно определяет режим (список/редактор) по параметрам PageRoutes
		$this->boilerplatePageController->displayPage();
	}
}
