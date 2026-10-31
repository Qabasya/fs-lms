<?php

declare( strict_types=1 );

namespace Inc\Services\Profile;

use Inc\DTO\Profile\LearnerDashboardDTO;
use Inc\Enums\Exam\ExamSubject;
use Inc\Services\Profile\Learner\ExamCountdownSection;
use Inc\Services\Profile\Learner\LearnerContextBuilder;
use Inc\Services\Profile\Learner\LearnerCoursesSection;
use Inc\Services\Profile\Learner\LearnerHighlightsSection;
use Inc\Services\Profile\Learner\LearnerPerformanceSection;
use Inc\Services\Profile\Learner\LearnerScheduleSection;
use Inc\Services\Profile\Learner\LearnerRankingSection;
use Inc\Services\Exam\LearnerExamsService;

/**
 * Read-модель профиля учащегося (Эпик 7). Собирает по одному `student_person_id`
 * (ученик — свой, родитель — ребёнка): группы, расписание/дедлайны, дневник
 * (сырые баллы, D4) и посещаемость (бинарно + %). Только чтение.
 *
 * После распила Т14.3 — тонкий фасад над четырьмя секциями
 * (Services/Profile/Learner/): контекст, расписание, успеваемость, курсы.
 * Внешний контракт (`build()` → LearnerDashboardDTO) не менялся.
 *
 * @package Inc\Services\Profile
 */
class LearnerService {

	public function __construct(
		private readonly LearnerContextBuilder $contextBuilder,
		private readonly LearnerScheduleSection $schedule,
		private readonly LearnerPerformanceSection $performance,
		private readonly LearnerCoursesSection $coursesSection,
		private readonly LearnerExamsService $examsService,
		private readonly ExamCountdownSection $examCountdown,
		private readonly LearnerHighlightsSection $highlights,
		private readonly LearnerRankingSection $ranking,
	) {}

	/**
	 * Собирает кабинет ученика: группы, расписание, дедлайны, оценки, посещаемость.
	 *
	 * Контекст (группы + занятия + сырые строки программы) читается один раз и
	 * переиспользуется всеми секциями — см. {@see \Inc\DTO\Profile\LearnerContextDTO}.
	 *
	 * @param int $personId Физлицо ученика
	 */
	public function build( int $personId ): LearnerDashboardDTO {
		$ctx = $this->contextBuilder->build( $personId );

		// №2 (D17.2): открытые группы существуют только как курсы в «Мои курсы» —
		// как ГРУППЫ в блоке приветствия/статистике ученика их не показываем
		// (видны лишь в админке). Полный список групп нужен курсам и оценкам.
		$visibleGroups = array_values(
			array_filter(
				$ctx->groups,
				static fn( $g ) => 'open' !== ( $g['access_mode'] ?? 'scheduled' )
			)
		);

		$grades = $this->performance->grades( $ctx, $personId );

		// События экзаменов сливаются с занятиями и сортируются по дате и времени до обрезки до 6.
		$upcomingEvents = array_merge( $this->schedule->upcoming( $ctx ), $this->examsService->upcomingEvents( $personId ) );
		usort(
			$upcomingEvents,
			static fn ( array $a, array $b ): int => strcmp( ( $a['date'] ?? '' ) . ' ' . ( $a['start'] ?? '' ), ( $b['date'] ?? '' ) . ' ' . ( $b['start'] ?? '' ) )
		);

		$deadlines  = $this->schedule->deadlines( $ctx, $personId );
		$attendance = $this->performance->attendance( $ctx, $personId, $grades );

		return new LearnerDashboardDTO(
			examLock:   $this->coursesSection->examLock( $personId ),
			groups:     $visibleGroups,
			courses:    $this->coursesSection->buildCourses( $ctx->groups, $ctx->rawRows, $ctx->lessonMap, $ctx->roomNames, $personId ),
			upcoming:   array_slice( $upcomingEvents, 0, 6 ),
			deadlines:  array_slice( $deadlines, 0, 6 ),
			recent:     array_slice( $this->performance->recentGrades( $grades ), 0, 5 ),
			lessons:    $ctx->allLessons,
			grades:     $grades,
			attendance: $attendance,
			examCountdown: $this->examCountdown->forGroups( $ctx->groups, $ctx->now ),
			highlights: $this->highlightsFor( $personId, $attendance, $deadlines ),
			rankings: $this->ranking->forGroups( $personId, $ctx->groups, $ctx->now ),
			toFix: $this->highlights->toFix( $deadlines ),
			homeSubjects: $this->homeSubjects( $ctx->groups ),
		);
	}

	/**
	 * Предметы всех групп, включая открытые, без повторов.
	 *
	 * @return list<array{key: string, name: string, exam: bool}>
	 */
	private function homeSubjects( array $groups ): array {
		$subjects = array();
		foreach ( $groups as $group ) {
			$key = (string) ( $group['subject_key'] ?? '' );
			if ( '' !== $key && ! isset( $subjects[ $key ] ) ) {
				$subjects[ $key ] = array(
					'key'  => $key,
					'name' => (string) ( $group['subject'] ?? $key ),
					'exam' => ExamSubject::supports( $key ),
				);
			}
		}
		return array_values( $subjects );
	}

	/**
	 * Дополнительные плитки главной — пилот: только смотрящему из списка тестовых логинов, у остальных `null`.
	 *
	 * @param array{rows: array} $attendance Посещаемость
	 * @param array<int, array>  $deadlines  Дедлайны целиком (до обрезки)
	 *
	 * @return array{streak: int, to_fix: int, last_result: array{value: string, note: string}|null}|null
	 */
	private function highlightsFor( int $personId, array $attendance, array $deadlines ): ?array {
		$viewer = get_userdata( get_current_user_id() );
		if ( ! $this->highlights->enabledFor( $viewer ? (string) $viewer->user_login : '' ) ) {
			return null;
		}

		return array(
			'streak'      => $this->highlights->streak( $attendance['rows'] ),
			'to_fix'      => $this->highlights->toFix( $deadlines ),
			'last_result' => $this->highlights->lastResult( $this->examsService->build( $personId )['exams'] ),
		);
	}
}
