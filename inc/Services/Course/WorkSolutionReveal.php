<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\WorkDTO;
use Inc\Enums\Course\WorkType;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Task\TaskSolutionService;

/**
 * Class WorkSolutionReveal
 *
 * Решение вопросов «Опроса» для ученика: текст из метабокса «Решение» показывается к вопросу
 * после верного ответа на него или, когда попытки сдачи исчерпаны, ко всем вопросам.
 * Правило сервера, а не клиента: пока оно не выполнено, текст решения в браузер не уходит.
 * У других типов работ решение ученику не показывается. Общий для сдачи работы
 * ({@see \Inc\Callbacks\Course\BatchSubmissionCallbacks}) и загрузки плеера
 * ({@see LessonPlayerService}) — оба должны отвечать одинаково.
 *
 * @package Inc\Services\Course
 */
class WorkSolutionReveal {

	public function __construct(
		private readonly TaskSolutionService $solutions,
		private readonly PostManager         $posts,
	) {}

	/**
	 * @param WorkDTO                         $work         Работа
	 * @param array<int|string, array<string, mixed>> $verdicts     Вердикты последней сдачи: task_id => {verdict, …}
	 * @param int                             $attemptsUsed Сколько сдач ученик уже сделал
	 *
	 * @return array<int, string> task_id => HTML решения (только те, что уже можно показать и где решение есть)
	 */
	public function forStudent( WorkDTO $work, array $verdicts, int $attemptsUsed ): array {
		if ( WorkType::Survey !== $work->workType || array() === $verdicts ) {
			return array();
		}

		$exhausted = $work->maxAttempts > 0 && $attemptsUsed >= $work->maxAttempts;

		$result = array();
		foreach ( $work->itemIds as $taskId ) {
			$taskId = (int) $taskId;
			if ( ! $exhausted && 'correct' !== ( $verdicts[ $taskId ]['verdict'] ?? '' ) ) {
				continue;
			}

			$html = (string) ( $this->solutions->forTask( $taskId, $this->posts->taskMeta( $taskId ) )['html'] ?? '' );
			if ( '' !== $html ) {
				$result[ $taskId ] = $html;
			}
		}

		return $result;
	}
}
