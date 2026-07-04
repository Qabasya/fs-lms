<?php

declare( strict_types=1 );

namespace Inc\Services\Profile;

use Inc\Contracts\ProfileViewInterface;
use Inc\DTO\Profile\ProfileContext;
use Inc\Enums\Access\UserRole;
use Inc\Enums\Wp\AjaxHook;
use Inc\Enums\Wp\Nonce;
use Inc\Enums\Wp\PageRoutes;
use Inc\Managers\Course\CourseManager;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;

/**
 * Class ProfileViewResolver
 *
 * Резолвер личного кабинета: по вошедшему пользователю строит {@see ProfileContext},
 * выбирает витрину ({@see ProfileViewInterface}) и собирает JS-конфиг `window.fsProfile`,
 * которым SPA рендерит сайдбар, экраны и режим доступа.
 *
 * Маппинг роль → витрина:
 *  - FSTeacher                       → TeacherProfileView
 *  - FSStudent / Student / FSParent  → LearnerProfileView
 *  - офисные роли (FSOffice/…)       → null (их кабинет — в админке WP, см. ProfileController)
 *
 * @package Inc\Services\Profile
 */
class ProfileViewResolver {

	public function __construct(
		private readonly PersonRepository        $persons,
		private readonly StudentRecordRepository $records,
		private readonly GroupsRepository        $groups,
		private readonly TeacherProfileView      $teacherView,
		private readonly LearnerProfileView      $learnerView,
		private readonly CourseManager           $courses,
		private readonly SubjectRepository       $subjects,
	) {}

	/** Человекочитаемое имя предмета (fallback — слаг), как в LearnerService (#12). */
	private function subjectName( string $key ): string {
		return $this->subjects->getByKey( $key )?->name ?? $key;
	}

	/**
	 * Собирает контекст кабинета для WP-пользователя.
	 */
	public function context( int $wpUserId ): ProfileContext {
		$user = get_userdata( $wpUserId );
		$role = UserRole::primaryForCabinet( $user ? (array) $user->roles : array() );

		$person   = $this->persons->findByWpUserId( $wpUserId );
		$personId = $person?->id;

		$readOnly        = ( UserRole::FSParent === $role );
		$subjectPersonId = $personId;
		$children        = array();

		// Родитель: данные ребёнка + переключатель детей + только чтение.
		if ( UserRole::FSParent === $role && null !== $personId ) {
			foreach ( $this->records->findActiveByParent( $personId ) as $rec ) {
				$children[] = array(
					'personId' => $rec->studentPersonId,
					'name'     => trim( $rec->snapshotLastName . ' ' . $rec->snapshotFirstName ),
				);
			}
			$subjectPersonId = $children[0]['personId'] ?? null;
		}

		return new ProfileContext( $wpUserId, $personId, $role, $subjectPersonId, $readOnly, $children );
	}

	/**
	 * Возвращает витрину для роли, либо null для ролей без фронт-кабинета (офисные).
	 */
	public function viewFor( UserRole $role ): ?ProfileViewInterface {
		// FSOffice использует ту же витрину с группами, но видит ВСЕ группы (см. jsConfig).
		if ( UserRole::FSTeacher === $role || UserRole::FSOffice === $role ) {
			return $this->teacherView;
		}
		if ( in_array( $role, array( UserRole::FSStudent, UserRole::FSParent, UserRole::Student ), true ) ) {
			return $this->learnerView;
		}
		return null;
	}

	/**
	 * Собирает `window.fsProfile` для локализации в Enqueue.
	 *
	 * @return array<string, mixed>
	 */
	public function jsConfig( int $wpUserId ): array {
		$ctx    = $this->context( $wpUserId );
		$config = $this->baseConfig( $wpUserId, $ctx );

		// Препод и офис работают с группами (КТП/журнал).
		if ( UserRole::FSTeacher === $ctx->role || UserRole::FSOffice === $ctx->role ) {
			$config = array_merge( $config, $this->teacherConfig( $wpUserId, $ctx ) );
		}

		// Учащийся/родитель (Эпик 7): один endpoint профиля (read-only).
		if ( in_array( $ctx->role, array( UserRole::FSStudent, UserRole::FSParent, UserRole::Student ), true ) ) {
			$config['learner'] = array(
				'nonce'   => Nonce::LearnerProfile->create(),
				'actions' => array(
					'getProfile' => AjaxHook::GetLearnerProfile->jsAction(),
				),
			);
		}

		return $config;
	}

	/**
	 * Общая часть конфига: роль, пользователь, витрина (nav/screens), URL-ы.
	 *
	 * @return array<string, mixed>
	 */
	private function baseConfig( int $wpUserId, ProfileContext $ctx ): array {
		$view  = $this->viewFor( $ctx->role );
		$built = $view ? $view->build( $ctx ) : array( 'nav' => array(), 'screens' => array() );

		$user = get_userdata( $wpUserId );
		$name = $user ? ( $user->display_name ?: $user->user_login ) : '';

		return array(
			'role'            => $ctx->role->value,
			'readOnly'        => $ctx->readOnly,
			'user'            => array(
				'name'     => $name,
				'initials' => $this->initials( $name ),
			),
			'subjectPersonId' => $ctx->subjectPersonId,
			'children'        => $ctx->children,
			'nav'             => $built['nav'],
			'screens'         => $built['screens'],
			'ajax'            => array( 'url' => admin_url( 'admin-ajax.php' ) ),
			'homeUrl'         => home_url( '/' ),
			'logoutUrl'       => wp_logout_url( home_url( '/' ) ),
		);
	}

	/**
	 * Блоки экранов препода/офиса: группы, расписание, журнал, ростер,
	 * сводка, оценивание, дашборд; офису дополнительно — «Замены».
	 *
	 * Препод видит свои группы; FSOffice — ВСЕ группы (доступ к любой
	 * и так разрешён `canManage` по `ManageLmsPlatform`).
	 *
	 * @return array<string, mixed>
	 */
	private function teacherConfig( int $wpUserId, ProfileContext $ctx ): array {
		$rows = ( UserRole::FSOffice === $ctx->role )
			? $this->groups->findAll()
			: $this->groups->findByTeacherId( $wpUserId );

		$config = array(
			'groups'   => array_map(
				fn( $g ): array => array(
					'id'          => (int) $g->id,
					'name'        => $g->name,
					'subject'     => $this->subjectName( (string) $g->subject_key ),
					'subject_key' => (string) $g->subject_key, // ключ цвета чипа (chipIndex, utils.js)
				),
				$rows
			),
			// «Мои курсы» (#15-B): курсы, назначенные хотя бы одной из групп выше —
			// дедуп по course_id, т.к. несколько групп могут вести один курс.
			'coursesTaught'    => $this->coursesTaught( $rows ),
			'coursePreviewUrl' => PageRoutes::CoursePreview->url(),
			'schedule' => array(
				'nonce'   => Nonce::SaveSchedule->create(),
				'actions' => array(
					'getCalendar'   => AjaxHook::GetGroupCalendar->jsAction(),
					'reflow'        => AjaxHook::ReflowSchedule->jsAction(),
					'pin'           => AjaxHook::PinLesson->jsAction(),
					'getProgram'    => AjaxHook::GetGroupProgram->jsAction(),
					'publish'       => AjaxHook::PublishProgram->jsAction(),
					'unpublish'     => AjaxHook::UnpublishProgram->jsAction(),
					'getDeadlines'  => AjaxHook::GetWorkDeadlines->jsAction(),
					'saveDeadlines' => AjaxHook::SaveWorkDeadlines->jsAction(),
					'continue'      => AjaxHook::ContinueProgramLesson->jsAction(),
					'getIndividual'    => AjaxHook::GetIndividualSlots->jsAction(),
					'lessonCandidates' => AjaxHook::GetLessonCandidates->jsAction(),
					'assignLesson'     => AjaxHook::AssignIndividualLesson->jsAction(),
					'createIndividual' => AjaxHook::CreateIndividualLesson->jsAction(),
					'getFreeRooms'     => AjaxHook::GetFreeRooms->jsAction(),
					'updateIndividual' => AjaxHook::UpdateIndividualLesson->jsAction(),
					'getRoster'        => AjaxHook::GetGroupRoster->jsAction(),
				),
			),
			// Курс-пикер КТП (T11.1) — отдельный блок: `assign_course` требует Nonce::AssignCourse.
			'courses'  => array(
				'nonce'   => Nonce::AssignCourse->create(),
				'actions' => array(
					'getCourses'   => AjaxHook::GetSubjectCourses->jsAction(),
					'assignCourse' => AjaxHook::AssignCourse->jsAction(),
				),
			),
			'journal'  => array(
				'nonce'   => Nonce::SaveSchedule->create(),
				'actions' => array(
					'getJournal'     => AjaxHook::GetGroupJournal->jsAction(),
					'saveAttendance' => AjaxHook::SaveAttendance->jsAction(),
					'bulkAttendance' => AjaxHook::BulkAttendance->jsAction(),
				),
			),
			// Экран «Группы» (ростер + создание индивидуальных занятий, T10.7).
			// Блок назван `roster`, т.к. ключ `groups` занят списком групп сайдбара.
			'roster'   => array(
				'nonce'   => Nonce::SaveSchedule->create(),
				'actions' => array(
					'getRoster'        => AjaxHook::GetGroupRoster->jsAction(),
					'createIndividual' => AjaxHook::CreateIndividualLesson->jsAction(),
					'getFreeRooms'     => AjaxHook::GetFreeRooms->jsAction(),
					'lessonCandidates' => AjaxHook::GetLessonCandidates->jsAction(),
					'updateIndividual' => AjaxHook::UpdateIndividualLesson->jsAction(),
				),
			),
			// «Сводка по ученику» (T10.8, D8) — ростер для выбора + занятия ученика.
			'summary'  => array(
				'nonce'   => Nonce::SaveSchedule->create(),
				'actions' => array(
					'getRoster'  => AjaxHook::GetGroupRoster->jsAction(),
					'getSummary' => AjaxHook::GetStudentSummary->jsAction(),
				),
			),
			// Деталь работы + оценивание (нонс GradeWork) для «Сводки по ученику» (T10.9).
			'review'   => array(
				'nonce'   => Nonce::GradeWork->create(),
				'actions' => array(
					'getDetail'        => AjaxHook::GetWorkDetail->jsAction(),
					'saveGrade'        => AjaxHook::SaveGrade->jsAction(),
					'returnSubmission' => AjaxHook::ReturnSubmission->jsAction(),
				),
			),
			// Пооответное оценивание попытки экзамена (T11.9) — отдельный нонс GradeAttempt.
			'attemptGrade' => array(
				'nonce'   => Nonce::GradeAttempt->create(),
				'actions' => array(
					'gradeAttempt' => AjaxHook::GradeAttempt->jsAction(),
				),
			),
			'dashboard' => array(
				'nonce'   => Nonce::SaveSchedule->create(),
				'actions' => array(
					'getDashboard' => AjaxHook::GetProfileDashboard->jsAction(),
				),
			),
		);

		// Экран «Замены» — только офис (кабинет + педагог).
		if ( UserRole::FSOffice === $ctx->role ) {
			$config['substitutions'] = array(
				'nonce'   => Nonce::Substitution->create(),
				'actions' => array(
					'getData' => AjaxHook::GetSubstitutionsData->jsAction(),
					'assign'  => AjaxHook::AssignSubstitute->jsAction(),
					'revoke'  => AjaxHook::RevokeSubstitute->jsAction(),
					'setRoom' => AjaxHook::SetRoomOverride->jsAction(),
				),
			);
		}

		return $config;
	}

	/**
	 * Дедуп курсов по `course_id` из сырых строк групп (#15-B): несколько групп
	 * могут вести один курс — в сайдбаре он должен быть одной строкой.
	 *
	 * @param object[] $rows Строки групп (raw stdClass, `GroupsRepository`).
	 * @return array<int, array{id:int, title:string, subject_key:string, group_ids:int[], first_lesson_id:int}>
	 */
	private function coursesTaught( array $rows ): array {
		$courses = array();
		foreach ( $rows as $g ) {
			$courseId = (int) ( $g->course_id ?? 0 );
			if ( $courseId <= 0 ) {
				continue;
			}
			if ( ! isset( $courses[ $courseId ] ) ) {
				$course = $this->courses->get( $courseId );
				if ( null === $course ) {
					continue;
				}
				$courses[ $courseId ] = array(
					'id'              => $courseId,
					'title'           => $course->title,
					'subject_key'     => (string) $g->subject_key,
					'group_ids'       => array(),
					'first_lesson_id' => $course->lessonIds()[0] ?? 0,
				);
			}
			$courses[ $courseId ]['group_ids'][] = (int) $g->id;
		}

		return array_values( $courses );
	}

	private function initials( string $name ): string {
		$parts = array_filter( explode( ' ', $name ) );
		$ini   = '';
		foreach ( $parts as $p ) {
			$ini .= mb_strtoupper( mb_substr( $p, 0, 1 ) );
		}
		return mb_substr( $ini, 0, 2 );
	}
}
