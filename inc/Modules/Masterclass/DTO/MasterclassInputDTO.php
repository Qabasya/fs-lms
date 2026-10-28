<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\DTO;

/**
 * Данные формы создания/правки мастер-класса.
 *
 * Окно приходит в МЕСТНОМ времени (`Y-m-d H:i:s`, как вводит администратор и как лежит `group_lessons`);
 * в UTC для таблицы мастер-классов его переводит сервис через `ExamTime`.
 *
 * @package Inc\Modules\Masterclass\DTO
 */
readonly class MasterclassInputDTO {

	/**
	 * @param string $schoolName    Школа
	 * @param string $subjectKey    Предмет
	 * @param int    $lessonId      Урок банка
	 * @param int    $teacherUserId Преподаватель
	 * @param string $startsAtLocal Начало окна (местное, `Y-m-d H:i:s`)
	 * @param string $endsAtLocal   Конец окна (местное, `Y-m-d H:i:s`)
	 */
	public function __construct(
		public string $schoolName,
		public string $subjectKey,
		public int $lessonId,
		public int $teacherUserId,
		public string $startsAtLocal,
		public string $endsAtLocal,
	) {}
}
