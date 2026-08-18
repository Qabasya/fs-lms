<?php

declare( strict_types=1 );

namespace Inc\DTO\Profile;

/**
 * Данные кабинета ученика (вкладки «Главная», «Мои курсы», «Оценки», «Посещаемость»).
 *
 * @package Inc\DTO\Profile
 *
 * Схема ответа `GetLearnerProfile`: `toArray()` — единственное место, где она
 * зафиксирована, фронт (`src/js/profile/learner.js`) читает именно эти ключи.
 */
readonly class LearnerDashboardDTO {

	/**
	 * @param array<string, string>|null       $examLock   Активная контрольная, блокирующая контент
	 * @param array<int, array>                $groups     Группы ученика (без открытых — D17.2)
	 * @param array<int, array>                $courses    Курсы ученика с прогрессом
	 * @param array<int, array>                $upcoming   Ближайшие занятия (топ-6)
	 * @param array<int, array>                $deadlines  Ближайшие дедлайны работ (топ-6)
	 * @param array<int, array>                $recent     Последние оценки (топ-5)
	 * @param array<int, array>                $lessons    Все занятия ученика
	 * @param array<int, array>                $grades     Дневник (сырые баллы)
	 * @param array{rows: array, present: int, total: int, percent: int|null} $attendance Посещаемость
	 */
	public function __construct(
		public ?array $examLock,
		public array  $groups,
		public array  $courses,
		public array  $upcoming,
		public array  $deadlines,
		public array  $recent,
		public array  $lessons,
		public array  $grades,
		public array  $attendance,
	) {}

	/**
	 * Представление для AJAX-ответа.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'exam_lock'  => $this->examLock,
			'groups'     => $this->groups,
			'courses'    => $this->courses,
			'upcoming'   => $this->upcoming,
			'deadlines'  => $this->deadlines,
			'recent'     => $this->recent,
			'lessons'    => $this->lessons,
			'grades'     => $this->grades,
			'attendance' => $this->attendance,
		);
	}
}
