<?php

declare( strict_types=1 );

namespace Inc\Callbacks\System;

use Inc\Controllers\Pages\BoilerplatePageController;
use Inc\Core\BaseController;
use Inc\DTO\Settings\AcademicPeriodDTO;
use Inc\DTO\Log\LogPageQueryDTO;
use Inc\Enums\Log\LogChannel;
use Inc\Enums\Access\Capability;
use Inc\Enums\Access\UserRole;
use Inc\Enums\Course\WeekDay;
use Inc\Repositories\OptionsRepositories\AcademicPeriodRepository;
use Inc\Repositories\OptionsRepositories\ConsentDefinitionsRepository;
use Inc\Repositories\OptionsRepositories\EmailTemplatesRepository;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\OptionsRepositories\UserRepository;
use Inc\Services\Enrollment\AcademicPeriodService;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Services\Log\Pages\LogPageRegistry;
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
	 * @param LogPageRegistry           $logPages                  Реестр провайдеров вкладок «Журналы»
	 */
	public function __construct(
		private readonly SubjectRepository $subjects,
		private readonly AcademicPeriodRepository $periods,
		private readonly UserRepository $users,
		private readonly BoilerplatePageController $boilerplatePageController,
		private readonly AcademicPeriodService $period_service,
		private readonly GroupsRepository $groupsRepository,
		private readonly StudentRecordRepository $studentRecordRepository,
		private readonly LogPageRegistry $logPages,
		private readonly PluginConfig    $pluginConfig,
		private readonly RoomRepository  $rooms,
		private readonly EmailTemplatesRepository $emailTemplates,
		private readonly ConsentDefinitionsRepository $consentDefinitions,
	) {
		parent::__construct();
	}

	/**
	 * Главная страница Dashboard.
	 *
	 * @return void
	 */
	public function adminDashboard(): void {
		$this->render(
			'admin/dashboard',
			array(
				'modules' => apply_filters( 'fs_lms_dashboard_modules', array() ),
			)
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

		// Кабинеты (Эпик 9/10): карта «id кабинета → группы» — по строкам расписания.
		// Кабинет привязан к дню (meetings[].room), поэтому группа попадает к КАЖДОМУ
		// своему кабинету, и каждый показывает ТОЛЬКО свои дни.
		$rooms        = $this->rooms->findAll();
		$rooms_groups = array();
		foreach ( $this->groupsRepository->findAll() as $g ) {
			$meetings   = json_decode( $g->meetings ?? '[]', true ) ?: array();
			$by_room    = array(); // room_id => занятия только этого кабинета
			foreach ( $meetings as $mt ) {
				if ( ! is_array( $mt ) ) {
					continue;
				}
				$room_id = (int) ( $mt['room'] ?? 0 );
				if ( $room_id > 0 ) {
					$by_room[ $room_id ][] = $mt;
				}
			}
			foreach ( $by_room as $room_id => $room_meetings ) {
				$rooms_groups[ $room_id ][] = array(
					'name'     => $g->name,
					'schedule' => WeekDay::formatSchedule( $room_meetings ),
				);
			}
		}

		$this->render(
			'admin/settings',
			array(
				'subjects'            => $this->subjects->readAll(),
				'active_subjects'     => $this->subjects->readActive(),
				'academic_periods'    => $academic_periods,
				'period_group_counts' => $period_group_counts,
				'rooms'               => $rooms,
				'rooms_groups'        => $rooms_groups,
				'config'              => $this->pluginConfig->viewState(),
				// Данные вкладок настроек — из репозиториев: партиалы не читают опции сами.
				'saved_templates'     => $this->emailTemplates->readAll(),
				'consent_definitions' => $this->consentDefinitions->readAll(),
			)
		);
	}

	/**
	 * Форматирует расписание группы построчно с кабинетом каждого дня (Эпик 10):
	 * «Вторник 09:25 - 10:10 · Каб. 315». Порядок строк — как в `meetings`.
	 *
	 * @param array<int,mixed>  $meetings
	 * @param array<int,string> $roomNames id кабинета → название
	 */
	private function formatScheduleWithRooms( array $meetings, array $roomNames ): string {
		$lines = array();
		foreach ( $meetings as $mt ) {
			if ( ! is_array( $mt ) ) {
				continue;
			}
			$day = WeekDay::tryFrom( (string) ( $mt['day'] ?? '' ) );
			if ( null === $day ) {
				continue;
			}
			$start  = (string) ( $mt['start'] ?? '' );
			$end    = (string) ( $mt['end'] ?? '' );
			$time   = ( '' !== $start && '' !== $end ) ? " {$start} - {$end}" : '';
			$roomId = (int) ( $mt['room'] ?? 0 );
			$room   = $roomId && isset( $roomNames[ $roomId ] ) ? ' · ' . $roomNames[ $roomId ] : '';
			$lines[] = $day->fullLabel() . $time . $room;
		}
		return implode( "\n", $lines );
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
		$filter_room_id     = $this->sanitizeGetInt( 'room_id' );

		$groups_filters = array_filter( array(
			'subject_key' => $filter_subject_key,
			'teacher_id'  => $filter_teacher_id > 0 ? $filter_teacher_id : '',
			'room_id'     => $filter_room_id > 0 ? $filter_room_id : '',
		) );

		$groups   = '' !== $selected_period_id
			? $this->groupsRepository->findByFilters( $selected_period_id, $filter_subject_key, $filter_teacher_id, $filter_room_id )
			: array();
		$subjects        = $this->subjects->readAll();    // карта имён для подписи существующих групп
		$active_subjects = $this->subjects->readActive(); // без архивных — для фильтра и скрытия групп
		$teachers        = $this->users->getByRole( \Inc\Enums\Access\UserRole::FSTeacher );

		// Группы архивных предметов не показываем в активном списке (предмет «в корзине» целиком).
		// Данные не удаляются — вернутся при разархивации предмета.
		$groups = array_filter(
			$groups,
			static fn( object $g ): bool => isset( $active_subjects[ $g->subject_key ] )
		);

		$teacher_map = array();
		foreach ( $teachers as $t ) {
			$teacher_map[ $t->id ] = $t->displayName;
		}

		// Карта «id кабинета → название» для колонки «Кабинет» (Эпик 9).
		$room_names = array();
		foreach ( $this->rooms->findAll() as $room ) {
			$room_names[ $room->id ] = $room->name;
		}

		$groups_view = array_map(
			fn( object $g ) => array(
				'id'           => (int) $g->id,
				'title'        => $g->name,
				'period_name'  => $raw_periods[ $g->academic_period_id ]['name'] ?? $g->academic_period_id,
				'subject_name' => $subjects[ $g->subject_key ]->name ?? $g->subject_key,
				'teacher_id'   => $g->teacher_id ? (int) $g->teacher_id : null,
				'teacher_name' => $g->teacher_id ? ( $teacher_map[ (int) $g->teacher_id ] ?? "#{$g->teacher_id}" ) : '—',
				'room_id'      => isset( $g->room_id ) && $g->room_id ? (int) $g->room_id : null,
				'room_name'    => isset( $g->room_id ) && $g->room_id ? ( $room_names[ (int) $g->room_id ] ?? '' ) : '',
				'schedule'     => $this->formatScheduleWithRooms( json_decode( $g->meetings ?? '[]', true ) ?: array(), $room_names ),
				'schedule_raw' => $g->meetings ?? '[]',
				'period_id'    => $g->academic_period_id,
				'subject_key'  => $g->subject_key,
				'access_mode'  => (string) ( $g->access_mode ?? 'scheduled' ),
				'active_count' => $this->studentRecordRepository->countActiveByGroup( (int) $g->id ),
			),
			$groups
		);

		$this->render(
			'admin/groups',
			array(
				'subjects'           => $active_subjects, // фильтр-дропдаун — без архивных
				'active_subjects'    => $active_subjects, // дропдаун создания группы — без архивных
				'academic_periods'   => $raw_periods,
				'current_period'     => $current_period,
				'other_periods'      => $other_periods,
				'selected_period_id' => $selected_period_id,
				'groups_filters'     => $groups_filters,
				'groups_view'        => $groups_view,
				'teachers'           => $teachers,
				'rooms'              => $this->rooms->findAll( true ),
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
	 * Разбирает общий контекст запроса (вкладка, пагинация, сортировка, период,
	 * актор/физлицо) и отдаёт его провайдеру канала — данные конкретной вкладки
	 * собирает LogPageRegistry, а не эта страница.
	 *
	 * @return void
	 */
	public function logsPage(): void {
		$this->requireCap( Capability::Admin );

		$active_tab = $this->sanitizeGetKey( 'tab' ) ?: 'tab-0';
		$channel    = LogChannel::fromAdminTabId( $active_tab ); // null — вкладка неизвестна, данных нет
		$per_page   = 50;

		$orderby_raw = $this->sanitizeGetKey( 'orderby' );
		$orderby     = in_array( $orderby_raw, array( 'id', 'created_at' ), true ) ? $orderby_raw : 'id';
		$order       = 'asc' === $this->sanitizeGetKey( 'order' ) ? 'ASC' : 'DESC';

		$query = new LogPageQueryDTO(
			page:     max( 1, $this->sanitizeGetInt( 'paged' ) ),
			perPage:  $per_page,
			orderby:  $orderby,
			order:    $order,
			dateFrom: $this->sanitizeGetText( 'date_from' ),
			dateTo:   $this->sanitizeGetText( 'date_to' ),
			actorId:  $this->sanitizeGetInt( 'actor_id' ) ?: null,
			personId: $this->sanitizeGetInt( 'person_id' ) ?: null,
		);

		$data = array(
			'active_tab'  => $active_tab,
			'per_page'    => $per_page,
			'log_orderby' => $orderby,
			'log_order'   => strtolower( $order ),
		);

		$this->render( 'admin/logs', array_merge( $data, $this->logPages->data( $channel, $query ) ) );
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
