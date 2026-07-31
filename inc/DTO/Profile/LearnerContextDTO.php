<?php

declare( strict_types=1 );

namespace Inc\DTO\Profile;

use Inc\DTO\Course\GroupLessonDTO;

/**
 * Общий контекст сборки кабинета ученика: то, что читается из БД один раз и
 * нужно сразу нескольким секциям (расписание, дедлайны, посещаемость, курсы).
 *
 * @package Inc\DTO\Profile
 *
 * Живёт в пределах одного вызова {@see \Inc\Services\Profile\LearnerService::build()}.
 */
readonly class LearnerContextDTO {

	/**
	 * @param array<int, array<string, mixed>>   $groups     Группы ученика: id → карточка группы
	 * @param array<int, array<string, mixed>>   $lessonMap  Занятия: group_lesson_id → карточка занятия
	 * @param array<int, array<string, mixed>>   $allLessons Те же занятия списком (свежие сверху)
	 * @param array<int, GroupLessonDTO>         $rawRows    Сырые строки программы: group_lesson_id → DTO
	 * @param array<int, string>                 $roomNames  Кабинеты: id → название
	 * @param string                             $now        Текущее время (mysql)
	 */
	public function __construct(
		public array  $groups,
		public array  $lessonMap,
		public array  $allLessons,
		public array  $rawRows,
		public array  $roomNames,
		public string $now,
	) {}
}
