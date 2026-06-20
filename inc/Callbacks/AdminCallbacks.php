<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Controllers\Pages\BoilerplatePageController;
use Inc\Core\BaseController;
use Inc\DTO\Settings\AcademicPeriodDTO;
use Inc\Enums\Log\AuditAction;
use Inc\Enums\Access\Capability;
use Inc\Enums\Access\UserRole;
use Inc\Enums\Course\WeekDay;
use Inc\Repositories\OptionsRepositories\AcademicPeriodRepository;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\OptionsRepositories\UserRepository;
use Inc\Repositories\WPDBRepositories\Log\AuditLogRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\Log\AuthLogRepository;
use Inc\Repositories\WPDBRepositories\Log\EntityAuditLogRepository;
use Inc\Repositories\WPDBRepositories\Log\ConsentChangeLogRepository;
use Inc\Repositories\WPDBRepositories\Log\DataChangeLogRepository;
use Inc\Repositories\WPDBRepositories\Log\EmailLogRepository;
use Inc\Repositories\WPDBRepositories\Log\ExportLogRepository;
use Inc\Repositories\WPDBRepositories\Log\PiiAccessLogRepository;
use Inc\Services\Enrollment\AcademicPeriodService;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Shared\PluginConfig;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;
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

	use Authorizer;
	use Sanitizer;
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
	 * @param StudentRecordRepository   $studentRecordRepository   Репозиторий записей студентов
	 */
	public function __construct(
		private readonly SubjectRepository $subjects,
		private readonly AcademicPeriodRepository $periods,
		private readonly UserRepository $users,
		private readonly BoilerplatePageController $boilerplatePageController,
		private readonly AcademicPeriodService $period_service,
		private readonly GroupsRepository $groupsRepository,
		private readonly StudentRecordRepository $studentRecordRepository,
		private readonly EntityAuditLogRepository  $entity_audit_log,
		private readonly AuditLogRepository        $audit_log,
		private readonly PiiAccessLogRepository    $pii_log,
		private readonly ExportLogRepository       $export_log,
		private readonly DataChangeLogRepository   $data_change_log,
		private readonly ConsentChangeLogRepository $consent_change_log,
		private readonly EmailLogRepository        $email_log,
		private readonly AuthLogRepository         $auth_log,
		private readonly PersonRepository          $person_repo,
		private readonly PluginConfig              $pluginConfig,
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
		$academic_periods = $this->periods->readAll();

		// Карта «id периода → количество групп» для колонки в табе периодов.
		$period_group_counts = array();
		foreach ( $academic_periods as $period ) {
			$period_id = (string) ( $period['id'] ?? '' );
			if ( '' !== $period_id ) {
				$period_group_counts[ $period_id ] = $this->groupsRepository->countByPeriodId( $period_id );
			}
		}

		$this->render(
			'admin/settings',
			array(
				'subjects'            => $this->subjects->readAll(),
				'academic_periods'    => $academic_periods,
				'period_group_counts' => $period_group_counts,
				'config'              => $this->pluginConfig->viewState(),
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

		$selected_period_id = $this->sanitizeGetKey( 'period_filter' );
		if ( '' === $selected_period_id ) {
			$selected_period_id = $current_period['id'] ?? '';
		}

		$filter_subject_key = $this->sanitizeGetKey( 'subject_key' );
		$filter_teacher_id  = $this->sanitizeGetInt( 'teacher_id' );

		$groups_filters = array_filter( array(
			'subject_key' => $filter_subject_key,
			'teacher_id'  => $filter_teacher_id > 0 ? $filter_teacher_id : '',
		) );

		$groups   = '' !== $selected_period_id
			? $this->groupsRepository->findByFilters( $selected_period_id, $filter_subject_key, $filter_teacher_id )
			: array();
		$subjects = $this->subjects->readAll();
		$teachers = $this->users->getByRole( \Inc\Enums\Access\UserRole::FSTeacher );

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
				'teacher_id'   => $g->teacher_id ? (int) $g->teacher_id : null,
				'teacher_name' => $g->teacher_id ? ( $teacher_map[ (int) $g->teacher_id ] ?? "#{$g->teacher_id}" ) : '—',
				'schedule'     => WeekDay::formatScheduleFull( json_decode( $g->meetings ?? '[]', true ) ?: array() ),
				'schedule_raw' => $g->meetings ?? '[]',
				'period_id'    => $g->academic_period_id,
				'subject_key'  => $g->subject_key,
				'active_count' => $this->studentRecordRepository->countActiveByGroup( (int) $g->id ),
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
				'groups_filters'     => $groups_filters,
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
		$this->requireCap( Capability::Admin );

		$active_tab = $this->sanitizeGetKey( 'tab' ) ?: 'tab-0';
		$per_page   = 50;
		$paged      = max( 1, $this->sanitizeGetInt( 'paged' ) );

		$data = compact( 'active_tab', 'per_page' );

		$date_from = $this->sanitizeGetText( 'date_from' );
		$date_to   = $this->sanitizeGetText( 'date_to' );
		$actor_id  = $this->sanitizeGetInt( 'actor_id' ) ?: null;
		$person_id = $this->sanitizeGetInt( 'person_id' ) ?: null;

		$log_orderby_raw = $this->sanitizeGetKey( 'orderby' );
		$log_orderby     = in_array( $log_orderby_raw, array( 'id', 'created_at' ), true ) ? $log_orderby_raw : 'id';
		$log_order       = 'asc' === $this->sanitizeGetKey( 'order' ) ? 'ASC' : 'DESC';

		$data['log_orderby'] = $log_orderby;
		$data['log_order']   = strtolower( $log_order );


		if ( 'tab-0' === $active_tab ) {
			$filters = array_filter( array(
				'operation'     => $this->sanitizeGetKey( 'operation' ),
				'entity_type'   => $this->sanitizeGetKey( 'entity_type' ),
				'actor_user_id' => $actor_id,
				'date_from'     => $date_from,
				'date_to'       => $date_to,
			) );
			$data['entity_audit_filters']   = $filters;
			$data['entity_audit_page']      = $paged;
			$data['entity_audit_total']     = $this->entity_audit_log->countFiltered( $filters );
			$data['entity_audit_rows']      = $this->entity_audit_log->list( $filters, $paged, $per_page, $log_orderby, $log_order );
			$data['entity_audit_operations']  = $this->entity_audit_log->distinctOperations();
			$data['entity_audit_types']       = $this->entity_audit_log->distinctEntityTypes();
			$data['entity_audit_actor_options'] = $this->resolveActorOptions( $this->entity_audit_log->distinctActorUserIds() );

		} elseif ( 'tab-1' === $active_tab ) {
			$audit_filters = array_filter( array(
				'action'        => $this->sanitizeGetKey( 'action' ),
				'actor_user_id' => $actor_id,
				'date_from'     => $date_from,
				'date_to'       => $date_to,
			) );

			$data['audit_filters'] = $audit_filters;
			$data['audit_page'] = $paged;
			$data['audit_total'] = $this->audit_log->countFiltered( $audit_filters );
			$data['audit_rows'] = $this->audit_log->list(
				$audit_filters,
				$paged,
				$per_page,
				$log_orderby,
				$log_order
			);

			$data['audit_actions'] = $this->audit_log->distinctActions();
			$data['audit_actor_options'] = $this->resolveActorOptions(
				$this->audit_log->distinctActorUserIds()
			);

		}elseif ( 'tab-2' === $active_tab ) {
			$pii_filters = array_filter( array(
				'actor_user_id' => $actor_id,
				'person_id'     => $person_id,
				'date_from'     => $date_from,
				'date_to'       => $date_to,
			) );
			$data['pii_filters'] = $pii_filters;
			$data['pii_page']    = $paged;
			$data['pii_total']   = $this->pii_log->countFiltered( $pii_filters );
			$data['pii_rows']    = $this->pii_log->list( $pii_filters, $paged, $per_page, $log_orderby, $log_order );

		} elseif ( 'tab-3' === $active_tab ) {
			$filters = array_filter( array(
				'actor_user_id' => $actor_id,
				'data_type'     => $this->sanitizeGetKey( 'data_type' ),
				'date_from'     => $date_from,
				'date_to'       => $date_to,
			) );
			$data['export_filters']       = $filters;
			$data['export_page']          = $paged;
			$data['export_total']         = $this->export_log->countFiltered( $filters );
			$data['export_rows']          = $this->export_log->list( $filters, $paged, $per_page, $log_orderby, $log_order );
			$data['export_data_types']    = $this->export_log->distinctDataTypes();
			$data['export_actor_options'] = $this->resolveActorOptions( $this->export_log->distinctActorUserIds() );

		} elseif ( 'tab-4' === $active_tab ) {
			$filters = array_filter( array(
				'actor_user_id'    => $actor_id,
				'target_person_id' => $person_id,
				'date_from'        => $date_from,
				'date_to'          => $date_to,
			) );
			$data['data_change_filters']        = $filters;
			$data['data_change_page']           = $paged;
			$data['data_change_total']          = $this->data_change_log->countFiltered( $filters );
			$data['data_change_rows']           = $this->data_change_log->list( $filters, $paged, $per_page, $log_orderby, $log_order );
			$data['data_change_actor_options']  = $this->resolveActorOptions( $this->data_change_log->distinctActorUserIds() );
			$data['data_change_person_options'] = $this->resolvePersonOptions( $this->data_change_log->distinctPersonIds() );

		} elseif ( 'tab-5' === $active_tab ) {
			$filters = array_filter( array(
				'consent_type' => $this->sanitizeGetKey( 'consent_type' ),
				'date_from'    => $date_from,
				'date_to'      => $date_to,
			) );
			$data['consent_filters']       = $filters;
			$data['consent_page']          = $paged;
			$data['consent_total']         = $this->consent_change_log->countFiltered( $filters );
			$data['consent_rows']          = $this->consent_change_log->list( $filters, $paged, $per_page, $log_orderby, $log_order );
			$data['consent_type_options']  = $this->consent_change_log->distinctConsentTypes();

		} elseif ( 'tab-6' === $active_tab ) {
			$filters = array_filter( array(
				'email_type'       => $this->sanitizeGetKey( 'email_type' ),
				'status'           => $this->sanitizeGetKey( 'status' ),
				'target_person_id' => $person_id,
				'date_from'        => $date_from,
				'date_to'          => $date_to,
			) );
			$data['email_filters']        = $filters;
			$data['email_page']           = $paged;
			$data['email_total']          = $this->email_log->countFiltered( $filters );
			$data['email_rows']           = $this->email_log->list( $filters, $paged, $per_page, $log_orderby, $log_order );
			$data['email_type_options']   = $this->email_log->distinctEmailTypes();
			$data['email_person_options'] = $this->resolvePersonOptions( $this->email_log->distinctPersonIds() );

		} elseif ( 'tab-8' === $active_tab ) {
			$filters = array_filter( array(
				'action'    => $this->sanitizeGetKey( 'action' ),
				'result'    => $this->sanitizeGetKey( 'result' ),
				'date_from' => $date_from,
				'date_to'   => $date_to,
			) );
			$data['auth_filters'] = $filters;
			$data['auth_page']    = $paged;
			$data['auth_total']   = $this->auth_log->countFiltered( $filters );
			$data['auth_rows']    = $this->auth_log->list( $filters, $paged, $per_page, $log_orderby, $log_order );
			$data['auth_actions'] = $this->auth_log->distinctActions();

		} else {
			$actor_name = $this->sanitizeGetText( 'actor_name' );
			$actor_ids  = null;
			if ( $actor_name ) {
				$found = get_users( array(
					'search'         => '*' . $actor_name . '*',
					'search_columns' => array( 'display_name', 'user_login' ),
					'number'         => 200,
					'fields'         => 'ID',
				) );
				$actor_ids = ! empty( $found ) ? array_map( 'intval', $found ) : array( -1 );
			}

			$audit_url_filters = array_filter( array(
				'action_filter' => $this->sanitizeGetKey( 'action_filter' ),
				'actor_name'    => $actor_name,
				'date_from'     => $date_from,
				'date_to'       => $date_to,
			) );
			$audit_db_filters = array_filter( array(
				'action'    => $this->sanitizeGetKey( 'action_filter' ),
				'actor_ids' => $actor_ids,
				'date_from' => $date_from,
				'date_to'   => $date_to,
			) );

			$data['audit_filters']    = $audit_url_filters;
			$data['audit_actor_name'] = $actor_name;
			$data['audit_page']       = $paged;
			$data['audit_total']      = $this->audit_log->countFiltered( $audit_db_filters );
			$data['audit_rows']       = $this->audit_log->list( $audit_db_filters, $paged, $per_page, $log_orderby, $log_order );
			$data['audit_actions']    = AuditAction::cases();
		}

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

	/** @param int[] $userIds @return array<int, string> id => display_name */
	private function resolveActorOptions( array $userIds ): array {
		if ( empty( $userIds ) ) {
			return array();
		}
		$options = array();
		foreach ( $userIds as $uid ) {
			$user = get_userdata( $uid );
			$options[ $uid ] = $user ? $user->display_name : "User #{$uid}";
		}
		return $options;
	}

	/** @param int[] $personIds @return array<int, string> id => full_name */
	private function resolvePersonOptions( array $personIds ): array {
		if ( empty( $personIds ) ) {
			return array();
		}
		$persons = $this->person_repo->findByIds( $personIds );
		$options = array();
		foreach ( $personIds as $pid ) {
			$options[ $pid ] = isset( $persons[ $pid ] ) ? $persons[ $pid ]->fullName() : "Person #{$pid}";
		}
		return $options;
	}
}
