<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\StepDTO;
use Inc\Enums\Course\StepType;
use Inc\Managers\Course\CourseManager;
use Inc\Managers\Course\LessonManager;

/**
 * Class CoursePublishValidator
 *
 * Проверка готовности курса к публикации (#11): нельзя опубликовать курс с
 * пустым шагом. Пустота здесь — про то, что шаг НИКОГДА не наполнится сам:
 * текст без контента и ссылочный шаг (задача/работа/контрольная) без
 * прикреплённой сущности (ref ≤ 0). Инлайновые медиа-шаги (видео, трансляция)
 * наполняются позже — ссылкой или записью занятия, — до тех пор плеер рендерит
 * заглушку, поэтому публикацию они не блокируют.
 *
 * По образцу {@see \Inc\Services\Task\TaskPublishValidator}: сервис возвращает
 * первую ошибку-строку, вызывающий колбэк её показывает.
 *
 * @package Inc\Services\Course
 */
class CoursePublishValidator {

	public function __construct(
		private readonly CourseManager $courses,
		private readonly LessonManager $lessons,
	) {}

	/**
	 * Первый пустой шаг курса (обход модули → уроки → шаги). null — все шаги с контентом.
	 * Ошибка называет конкретный урок и номер/тип шага.
	 */
	public function firstEmptyStepError( int $courseId ): ?string {
		$course = $this->courses->get( $courseId );
		if ( null === $course ) {
			return null; // не курс — забота updateCourseMeta (вернёт false)
		}

		foreach ( $course->modules as $module ) {
			foreach ( $module->lessonIds as $lessonId ) {
				$lesson = $this->lessons->get( (int) $lessonId );
				if ( null === $lesson ) {
					continue;
				}
				foreach ( $lesson->steps as $index => $step ) {
					if ( ! $this->stepHasContent( $step ) ) {
						return sprintf(
							/* translators: 1: lesson topic, 2: step number, 3: step type label */
							__( 'Урок «%1$s»: шаг №%2$d (%3$s) пустой — заполните его или удалите перед публикацией.', 'fs-lms' ),
							$lesson->topic,
							$index + 1,
							$step->type->label()
						);
					}
				}
			}
		}

		return null;
	}

	/** Правило пустоты шага (зеркало клиентского stepHasContent). */
	private function stepHasContent( StepDTO $step ): bool {
		return match ( $step->type ) {
			StepType::Text  => '' !== trim( (string) ( $step->payload['content'] ?? '' ) ),
			// №5 (D17.5): видео-шаг допустим к публикации даже пустым — плеер
			// показывает плейсхолдер «Видео скоро появится», ссылка (в т.ч. S3)
			// добавляется позже. Блок публикации на пустое видео снят.
			StepType::Video => true,
			// Трансляция: `stream_url` — необязательный адрес внешнего эфира, а
			// основное содержимое шага приезжает само — записью занятия через
			// фильтр `fs_lms_recording_url` (в т.ч. s3://-указатель модуля
			// VideoLibrary). До неё шаг рендерит заглушку «Запись занятия ещё не
			// доступна», поэтому публикацию не блокирует — как и видео.
			StepType::Broadcast => true,
			// task / work / assessment — привязана сущность.
			default         => (int) ( $step->payload['ref'] ?? 0 ) > 0,
		};
	}
}
