<?php

declare( strict_types=1 );

namespace Inc\Services\Profile;

use Inc\DTO\Profile\LearnerDashboardDTO;
use Inc\Services\Profile\Learner\LearnerContextBuilder;
use Inc\Services\Profile\Learner\LearnerCoursesSection;
use Inc\Services\Profile\Learner\LearnerPerformanceSection;
use Inc\Services\Profile\Learner\LearnerScheduleSection;

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
		private readonly LearnerContextBuilder     $contextBuilder,
		private readonly LearnerScheduleSection    $schedule,
		private readonly LearnerPerformanceSection $performance,
		private readonly LearnerCoursesSection     $coursesSection,
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
		$visibleGroups = array_values( array_filter(
			$ctx->groups,
			static fn( $g ) => 'open' !== ( $g['access_mode'] ?? 'scheduled' )
		) );

		$grades = $this->performance->grades( $ctx, $personId );

		return new LearnerDashboardDTO(
			examLock:   $this->coursesSection->examLock( $personId ),
			groups:     $visibleGroups,
			courses:    $this->coursesSection->buildCourses( $ctx->groups, $ctx->rawRows, $ctx->lessonMap, $ctx->roomNames, $personId ),
			upcoming:   array_slice( $this->schedule->upcoming( $ctx ), 0, 6 ),
			deadlines:  array_slice( $this->schedule->deadlines( $ctx, $personId ), 0, 6 ),
			recent:     array_slice( $this->performance->recentGrades( $grades ), 0, 5 ),
			lessons:    $ctx->allLessons,
			grades:     $grades,
			attendance: $this->performance->attendance( $ctx, $personId ),
		);
	}
}
