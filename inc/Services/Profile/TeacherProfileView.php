<?php

declare( strict_types=1 );

namespace Inc\Services\Profile;

use Inc\Contracts\ProfileViewInterface;
use Inc\DTO\Profile\ProfileContext;
use Inc\Enums\Access\UserRole;
use Inc\Enums\Course\AccessMode;
use Inc\Enums\Wp\AjaxHook;
use Inc\Enums\Wp\Nonce;
use Inc\Enums\Wp\PageRoutes;
use Inc\Managers\Course\CourseManager;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Services\Course\SubmissionService;

/**
 * Class TeacherProfileView
 *
 * Витрина преподавателя: его инструменты — Главная, Журнал, КТП.
 * Группы препода рендерятся в сайдбаре отдельным блоком (фронт).
 * Офис (`FSOffice`) дополнительно получает экран «Замены» (Эпики 5+9).
 *
 * @package Inc\Services\Profile
 *
 * Витрина отвечает и за меню (`nav`/`screens`), и за блоки конфига своих
 * экранов: раньше вторая половина жила в {@see ProfileViewResolver::teacherConfig()},
 * из-за чего резолвер знал устройство чужого экрана (аудит §2.3).
 */
final class TeacherProfileView implements ProfileViewInterface {

	/**
	 * @param GroupsRepository   $groups   Группы (препод — свои, офис — все)
	 * @param CourseManager      $courses  Курсы для блока «Мои курсы»
	 * @param SubjectRepository  $subjects Названия предметов
	 */
	public function __construct(
		private readonly GroupsRepository  $groups,
		private readonly CourseManager     $courses,
		private readonly SubjectRepository $subjects,
	) {}

	public function build( ProfileContext $context ): array {
		// T12.7: пункт «Группы» убран из меню — экран остаётся в $screens (маршрут жив),
		// вход теперь только кликом по группе в сайдбарном блоке «Мои группы» (D10, openGroupsFor).
		$nav = array(
			array( 'key' => 'dashboard', 'label' => 'Главная' ),
			array( 'key' => 'journal',   'label' => 'Журнал' ),
			// D3: основной вход в проверку — сразу после «Журнала», до «Сводки».
			array( 'key' => 'works',     'label' => 'Работы' ),
			array( 'key' => 'summary',   'label' => 'Сводка по ученику' ),
			array( 'key' => 'ktp',       'label' => 'КТП и расписание' ),
			array( 'key' => 'activity',  'label' => 'Активность' ),
		);
		$screens = array( 'dashboard', 'groups', 'journal', 'works', 'summary', 'ktp', 'activity' );

		// Замены (кабинет + педагог) — офисный инструмент, препод не видит.
		if ( UserRole::FSOffice === $context->role ) {
			$nav[]     = array( 'key' => 'substitutions', 'label' => 'Замены' );
			$screens[] = 'substitutions';
		}

		return array_merge(
			array( 'nav' => $nav, 'screens' => $screens ),
			$this->teacherConfig( $context )
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
	private function teacherConfig( ProfileContext $context ): array {
		$rows = ( UserRole::FSOffice === $context->role )
			? $this->groups->findAll()
			: $this->groups->findByTeacherId( $context->wpUserId );

		$config = array(
			'groups'   => array_map(
				fn( $g ): array => array(
					'id'          => (int) $g->id,
					'name'        => $g->name,
					'subject'     => $this->subjectName( (string) $g->subject_key ),
					'subject_key' => (string) $g->subject_key, // ключ цвета чипа (chipIndex, utils.js)
					// Эпик 15: открытая группа — фронт скрывает КТП/посещаемость.
					'access_mode' => AccessMode::fromValueOrDefault( (string) ( $g->access_mode ?? '' ) )->value,
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
					'unschedule'    => AjaxHook::UnscheduleGroup->jsAction(),
					'pin'           => AjaxHook::PinLesson->jsAction(),
					'getProgram'    => AjaxHook::GetGroupProgram->jsAction(),
					'publish'       => AjaxHook::PublishProgram->jsAction(),
					'unpublish'     => AjaxHook::UnpublishProgram->jsAction(),
					'getDeadlines'  => AjaxHook::GetWorkDeadlines->jsAction(),
					'saveDeadlines' => AjaxHook::SaveWorkDeadlines->jsAction(),
					'setRecordingUrl' => AjaxHook::SetRecordingUrl->jsAction(),
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
					'getSummary'  => AjaxHook::GetStudentSummary->jsAction(),
					'getStudents' => AjaxHook::GetTeacherStudents->jsAction(),
					'getCourses'  => AjaxHook::GetStudentCourses->jsAction(),
				),
			),
			// Вкладка «Работы» (D3): список работ/экзаменов на проверку по вкладке
			// (На проверке / Ждут подтверждения / Проверенные) + сдачи по каждой.
			// review/attemptGrade ниже переиспользуются переходом в деталь работы (D2).
			'works'    => array(
				'nonce'   => Nonce::GradeWork->create(),
				'actions' => array(
					'getPendingWorks'    => AjaxHook::GetPendingWorks->jsAction(),
					'getWorkSubmissions' => AjaxHook::GetWorkSubmissions->jsAction(),
				),
			),
			// Деталь работы + оценивание (нонс GradeWork) для «Сводки по ученику» (T10.9).
			'review'   => array(
				'nonce'   => Nonce::GradeWork->create(),
				// Tasks.md п. 8: жёсткий лимит сдач работы — счётчик попыток на экране проверки.
				'maxAttempts' => SubmissionService::MAX_WORK_ATTEMPTS,
				'actions' => array(
					'getDetail'         => AjaxHook::GetWorkDetail->jsAction(),
					'saveGrade'         => AjaxHook::SaveGrade->jsAction(),
					'returnSubmission'  => AjaxHook::ReturnSubmission->jsAction(),
					// Tasks.md, п. 6: «Проверка завершена» — перевод сдачи в «Проверенные».
					'completeReview'    => AjaxHook::CompleteReview->jsAction(),
					'resetAttempts'     => AjaxHook::ResetAttempts->jsAction(),
					// «Пройти заново»: история прошлых раундов сдачи (.docs/Tasks.md).
					'getAttemptHistory' => AjaxHook::GetWorkAttemptHistory->jsAction(),
				),
			),
			// Пооответное оценивание попытки экзамена (T11.9) — отдельный нонс GradeAttempt.
			'attemptGrade' => array(
				'nonce'   => Nonce::GradeAttempt->create(),
				'actions' => array(
					'gradeAttempt'   => AjaxHook::GradeAttempt->jsAction(),
					// D18: «Утвердить работу» — открывает ответы ученику для ЕГЭ (без ручной проверки).
					'approveAttempt' => AjaxHook::ApproveAttempt->jsAction(),
				),
			),
			// D4: поштучное оценивание задания «Развёрнутый ответ» внутри submission-работы —
			// переиспользует уже существующий Этап-7 эндпоинт GradeBatchTask (пишет в
			// per-task строку submissions и пересчитывает агрегат), ранее не подключённый
			// ни к одному экрану кабинета.
			'batchGrade' => array(
				'nonce'   => Nonce::GradeBatch->create(),
				'actions' => array(
					'gradeTask' => AjaxHook::GradeBatchTask->jsAction(),
				),
			),
			'dashboard' => array(
				'nonce'   => Nonce::SaveSchedule->create(),
				'actions' => array(
					'getDashboard' => AjaxHook::GetProfileDashboard->jsAction(),
				),
			),
			// Экран «Активность»: лента событий группы и история решений задач.
			// Три под-блока, потому что домены разные и нонс у каждого свой —
			// createApi() принимает ровно пару {nonce, actions}.
			'activity' => array(
				'events'   => array(
					'nonce'   => Nonce::GroupActivity->create(),
					'actions' => array( 'getEvents' => AjaxHook::GetGroupActivity->jsAction() ),
				),
				'program'  => array(
					'nonce'   => Nonce::SaveSchedule->create(),
					'actions' => array( 'getProgram' => AjaxHook::GetGroupProgram->jsAction() ),
				),
				'attempts' => array(
					'nonce'   => Nonce::TaskAttempts->create(),
					'actions' => array( 'getAttempts' => AjaxHook::GetTaskAttempts->jsAction() ),
				),
			),
		);

		// Экран «Замены» — только офис (кабинет + педагог).
		if ( UserRole::FSOffice === $context->role ) {
			$config['substitutions'] = array(
				'nonce'   => Nonce::Substitution->create(),
				'actions' => array(
					'getData'      => AjaxHook::GetSubstitutionsData->jsAction(),
					// Переключение группы — только её замены, без списков преподавателей/кабинетов.
					'getGroupSubs' => AjaxHook::GetGroupSubstitutions->jsAction(),
					'assign'       => AjaxHook::AssignSubstitute->jsAction(),
					'revoke'       => AjaxHook::RevokeSubstitute->jsAction(),
					'setRoom'      => AjaxHook::SetRoomOverride->jsAction(),
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

	/** Человекочитаемое имя предмета (fallback — слаг), как в LearnerService (#12). */
	private function subjectName( string $key ): string {
		return $this->subjects->getByKey( $key )?->name ?? $key;
	}

}
