<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Controllers\BoilerplatePageController;
use Inc\Core\BaseController;
use Inc\DTO\AcademicPeriodDTO;
use Inc\Enums\AuditAction;
use Inc\Enums\Capability;
use Inc\Enums\UserRole;
use Inc\Enums\WeekDay;
use Inc\Repositories\OptionsRepositories\AcademicPeriodRepository;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\OptionsRepositories\UserRepository;
use Inc\Repositories\WPDBRepositories\AuditLogRepository;
use Inc\Repositories\WPDBRepositories\PiiAccessLogRepository;
use Inc\Services\Enrollment\AcademicPeriodService;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
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
	 * @param GroupsRepository          $groupsRepository          Репозиторий групп
	 */
	public function __construct(
		private readonly SubjectRepository $subjects,
		private readonly AcademicPeriodRepository $periods,
		private readonly UserRepository $users,
		private readonly BoilerplatePageController $boilerplatePageController,
		private readonly AcademicPeriodService $period_service,
		private readonly GroupsRepository $groupsRepository,
		private readonly AuditLogRepository $audit_log,
		private readonly PiiAccessLogRepository $pii_log,
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
			? $this->groupsRepository->findByPeriodId( $selected_period_id )
			: array();
		$subjects = $this->subjects->readAll();
		$teachers = $this->users->getByRole( \Inc\Enums\UserRole::FSTeacher );

		$teacher_map = array();
		foreach ( $teachers as $t ) {
			$teacher_map[ $t->id ] = $t->displayName;
		}

		$groups_view = array_map(
			fn( object $g ) => array(
				'id'           => (int) $g->id,
				'title'        => $g->name,
				'period_name'  => $raw_periods[ $g->academic_period_id ]['name'] ?? $g->academic_period_id,
				'subject_name' => $subjects[ $g->subject_key ]->name ?? $g->subject_key,
				'teacher_name' => $g->teacher_id ? ( $teacher_map[ (int) $g->teacher_id ] ?? "#{$g->teacher_id}" ) : '—',
				'schedule'     => WeekDay::formatScheduleFull( json_decode( $g->schedule ?? '[]', true ) ?: array() ),
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
	 * Страница журналов.
	 *
	 * @return void
	 */
	public function logsPage(): void {
		if ( ! current_user_can( Capability::Admin->value ) ) {
			wp_die( 'Недостаточно прав.' );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$active_tab = sanitize_key( wp_unslash( $_GET['tab'] ?? 'tab-1' ) );
		$per_page   = 50;
		$paged      = max( 1, (int) ( $_GET['paged'] ?? 1 ) );

		$data = compact( 'active_tab', 'per_page' );

		if ( 'tab-2' === $active_tab ) {
			$pii_filters = array_filter( array(
				'actor_user_id' => (int) ( $_GET['actor_id'] ?? 0 ) ?: null,
				'person_id'     => (int) ( $_GET['person_id'] ?? 0 ) ?: null,
				'date_from'     => sanitize_text_field( wp_unslash( $_GET['date_from'] ?? '' ) ),
				'date_to'       => sanitize_text_field( wp_unslash( $_GET['date_to'] ?? '' ) ),
			) );

			$data['pii_filters'] = $pii_filters;
			$data['pii_page']    = $paged;
			$data['pii_total']   = $this->pii_log->countFiltered( $pii_filters );
			$data['pii_rows']    = $this->pii_log->list( $pii_filters, $paged, $per_page );
		} else {
			$audit_filters = array_filter( array(
				'action'        => sanitize_key( wp_unslash( $_GET['action_filter'] ?? '' ) ),
				'actor_user_id' => (int) ( $_GET['actor_id'] ?? 0 ) ?: null,
				'date_from'     => sanitize_text_field( wp_unslash( $_GET['date_from'] ?? '' ) ),
				'date_to'       => sanitize_text_field( wp_unslash( $_GET['date_to'] ?? '' ) ),
			) );

			$data['audit_filters']   = $audit_filters;
			$data['audit_page']      = $paged;
			$data['audit_total']     = $this->audit_log->countFiltered( $audit_filters );
			$data['audit_rows']      = $this->audit_log->list( $audit_filters, $paged, $per_page );
			$data['audit_actions']   = AuditAction::cases();
		}
		// phpcs:enable

		$this->render( 'admin/logs', $data );
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
