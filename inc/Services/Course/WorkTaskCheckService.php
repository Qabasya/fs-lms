<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Enums\Log\ErrorCode;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Course\WorkManager;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\WorkTaskCheckRepository;
use Inc\Services\Task\TaskCheckerRegistry;
use Inc\Services\Template\TemplateResolver;
use Inc\Shared\CodedException;

/**
 * Class WorkTaskCheckService
 *
 * Проверка одного ответа кнопкой «Проверить ответ» внутри работы — до её сдачи.
 *
 * - Только в практике ({@see \Inc\Enums\Course\WorkType::allowsInlineCheck()}) и только текстовые автозадания ({@see \Inc\Enums\Subject\TaskTemplate::allowsInlineCheck()}):
 *   выбор/сопоставление/сортировка и ручные шаблоны кнопки не имеют.
 * - Три проверки на задачу в каждом раунде сдачи работы ({@see self::MAX_CHECKS}); после
 *   верной проверки задача закрыта, как засчитанная при пересдаче.
 * - Наружу уходит только вердикт: эталон и разбор по пунктам — после сдачи.
 * - Проверка не сдача: в лимит сдач работы и в историю пересдач (`task_attempts`) не идёт.
 *   Её след — основа жёлтой отметки «верно с исправлением»
 *   ({@see SubmissionService::submitBatch()}).
 *
 * @package Inc\Services\Course
 */
class WorkTaskCheckService {

	/** Сколько раз можно проверить одну задачу за раунд сдачи. */
	public const int MAX_CHECKS = 3;

	public const string STATUS_CORRECT = 'correct';
	public const string STATUS_WRONG   = 'wrong';

	public function __construct(
		private readonly WorkTaskCheckRepository $checks,
		private readonly SubmissionService       $submissions,
		private readonly LessonAccessPolicy      $accessPolicy,
		private readonly GroupLessonRepository   $groupLessons,
		private readonly EffectiveWorksResolver  $worksResolver,
		private readonly WorkManager             $works,
		private readonly PostManager             $posts,
		private readonly TemplateResolver        $resolver,
		private readonly TaskCheckerRegistry     $checkers,
	) {}

	/**
	 * Проверяет ответ и записывает проверку.
	 *
	 * @param mixed $answer Ответ ученика (строка или массив — как у сдачи)
	 *
	 * @return array{is_correct: bool, checks_used: int, checks_max: int}
	 * @throws CodedException Если проверка недоступна: доступ, состав работы, лимиты, тип задания.
	 */
	public function check( int $studentPersonId, int $groupLessonId, int $workId, int $taskId, mixed $answer ): array {
		if ( ! $this->accessPolicy->canSubmit( $studentPersonId, $groupLessonId ) ) {
			throw new CodedException( ErrorCode::WorkAccess, 'Проверка недоступна для данного ученика и урока.' );
		}

		$row = $this->groupLessons->find( $groupLessonId );
		if ( ! $row ) {
			throw new CodedException( ErrorCode::WorkNoLesson, 'Строка программы не найдена.' );
		}

		$workIds = array_map( static fn( $w ) => $w->id, $this->worksResolver->resolve( $row ) );
		if ( ! in_array( $workId, $workIds, true ) ) {
			throw new CodedException( ErrorCode::WorkNotInLesson, 'Работа не входит в эффективный набор урока.' );
		}

		$work = $this->works->get( $workId );
		if ( ! $work ) {
			throw new CodedException( ErrorCode::WorkNotFound, 'Работа не найдена.' );
		}
		if ( ! $work->workType->allowsInlineCheck() ) {
			throw new CodedException( ErrorCode::WorkCheckKind, 'В работах этого типа ответ до сдачи не проверяется.' );
		}
		if ( ! in_array( $taskId, array_map( 'intval', $work->itemIds ), true ) ) {
			throw new CodedException( ErrorCode::WorkNotInLesson, 'Задание не входит в работу.' );
		}

		$attemptsUsed = $this->submissions->workAttemptsUsed( $studentPersonId, $groupLessonId, $workId );
		if ( $work->maxAttempts > 0 && $attemptsUsed >= $work->maxAttempts ) {
			throw new CodedException( ErrorCode::WorkLimit, 'Исчерпан лимит попыток сдачи. Обратитесь к преподавателю.' );
		}
		if ( in_array( $taskId, $this->submissions->lockedTaskIds( $studentPersonId, $groupLessonId, $workId ), true ) ) {
			throw new CodedException( ErrorCode::WorkCheckKind, 'Задание уже засчитано.' );
		}

		$post = $this->posts->get( $taskId );
		if ( ! $post ) {
			throw new CodedException( ErrorCode::WorkNotFound, 'Задание не найдено.' );
		}

		$template = $this->resolver->resolveEnum( $post );
		$checker  = $this->checkers->get( $template );
		if ( null === $checker || ! $template->allowsInlineCheck() ) {
			throw new CodedException( ErrorCode::WorkCheckKind, 'Это задание не проверяется кнопкой.' );
		}

		$round = $attemptsUsed + 1;
		$prior = array_values( array_filter(
			$this->checks->listByRound( $studentPersonId, $groupLessonId, $workId, $round ),
			static fn( $c ) => $c->taskId === $taskId
		) );

		foreach ( $prior as $check ) {
			if ( $check->isCorrect ) {
				throw new CodedException( ErrorCode::WorkCheckKind, 'Ответ уже проверен и засчитан.' );
			}
		}
		if ( count( $prior ) >= self::MAX_CHECKS ) {
			throw new CodedException( ErrorCode::WorkCheckLimit, 'Проверки ответа на это задание закончились.' );
		}

		$meta   = $this->posts->getMeta( $taskId, PostMetaName::Meta->value );
		$result = $checker->check( is_array( $meta ) ? $meta : array(), $answer );

		$this->checks->create( $studentPersonId, $groupLessonId, $workId, $taskId, $round, $answer, $result->isCorrect );

		return array(
			'is_correct'  => $result->isCorrect,
			'checks_used' => count( $prior ) + 1,
			'checks_max'  => self::MAX_CHECKS,
		);
	}

	/**
	 * Состояние проверок текущего раунда для плеера: что проверялось, сколько раз
	 * и каким был последний проверенный ответ (чтобы восстановить поле после перезагрузки).
	 *
	 * @return array<int, array{status: string, used: int, answer: mixed}> task_id => состояние
	 */
	public function state( int $studentPersonId, int $groupLessonId, int $workId ): array {
		if ( $studentPersonId <= 0 ) {
			return array();
		}

		$round = $this->submissions->workAttemptsUsed( $studentPersonId, $groupLessonId, $workId ) + 1;
		$state = array();

		foreach ( $this->checks->listByRound( $studentPersonId, $groupLessonId, $workId, $round ) as $check ) {
			$previous = $state[ $check->taskId ] ?? null;

			$state[ $check->taskId ] = array(
				// Верная проверка закрывает задачу — после неё других не бывает.
				'status' => $check->isCorrect || self::STATUS_CORRECT === ( $previous['status'] ?? '' )
					? self::STATUS_CORRECT
					: self::STATUS_WRONG,
				'used'   => ( $previous['used'] ?? 0 ) + 1,
				'answer' => $check->answer,
			);
		}

		return $state;
	}
}
