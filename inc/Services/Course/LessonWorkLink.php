<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\Enums\Wp\PageRoutes;
use Inc\Managers\Course\LessonManager;

/**
 * Class LessonWorkLink
 *
 * Единственное место, где собирается ссылка «в плеер урока, сразу к шагу работы»
 * (`?step=<ключ шага>`). Её берут дедлайны и оценки ученика и уведомления (колокольчик):
 * общая сборка нужна, чтобы все три вели в одно и то же место.
 *
 * @package Inc\Services\Course
 */
class LessonWorkLink {

	public function __construct(
		private readonly LessonManager $lessons,
	) {}

	/**
	 * @param GroupLessonDTO $row     Занятие группы
	 * @param int            $workId  Работа
	 * @param string|null    $baseUrl Готовый адрес плеера урока; `null` — собрать по группе и занятию,
	 *                                пустая строка — урок без плеера, ссылки нет
	 */
	public function forRow( GroupLessonDTO $row, int $workId, ?string $baseUrl = null ): string {
		$baseUrl ??= PageRoutes::LessonPlayer->lessonUrl( $row->groupId, $row->id );
		if ( '' === $baseUrl ) {
			return '';
		}

		$lesson  = $row->lessonId ? $this->lessons->get( $row->lessonId ) : null;
		$stepKey = $lesson?->stepKeyForWork( $workId );

		return $stepKey ? (string) add_query_arg( array( 'step' => $stepKey ), $baseUrl ) : $baseUrl;
	}
}
