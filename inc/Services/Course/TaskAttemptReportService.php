<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Task\TaskAttemptDTO;
use Inc\Enums\Course\AttemptSource;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Repositories\WPDBRepositories\TaskAttemptRepository;

/**
 * Class TaskAttemptReportService
 *
 * Отчёт «Решения задач»: история попыток занятия — задания-шаги, задачи работ
 * и попытки контрольных.
 *
 * @package Inc\Services\Course
 *
 * ### Откуда берётся история
 *
 * - **Задания-шаги и задачи работ** — `fs_lms_task_attempts`. Шаги пишутся туда
 *   с ключом шага, работы — с синтетическим `work:{id}` ({@see AttemptSource}):
 *   строка `fs_lms_submissions` при пересдаче перезаписывается, поэтому историю
 *   пересдач работ копит именно эта таблица.
 * - **Контрольные и экзамены** — `fs_lms_assessment_attempts`, где каждая
 *   попытка изначально отдельная запись со своим `attempt_number`.
 *
 * ### Имена
 *
 * Берутся из снимка в `student_records` (`snapshot_*`), как на всех экранах
 * преподавателя: зашифрованные ПД в `person_documents` для этого не трогаем.
 */
class TaskAttemptReportService {

	public function __construct(
		private readonly TaskAttemptRepository       $attempts,
		private readonly StudentRecordRepository     $records,
		private readonly AssessmentAttemptRepository $examAttempts,
	) {}

	/**
	 * Отчёт по занятию: задания урока, работы и контрольные.
	 *
	 * @param int $groupId       ID группы (для имён ростера)
	 * @param int $groupLessonId ID занятия группы
	 *
	 * @return array{
	 *   steps: array<int, array<string, mixed>>,
	 *   works: array<int, array<string, mixed>>,
	 *   exams: array<int, array<string, mixed>>
	 * }
	 */
	public function forLesson( int $groupId, int $groupLessonId ): array {
		$names  = $this->rosterNames( $groupId );
		$groups = array();

		foreach ( $this->attempts->listByGroupLesson( $groupLessonId ) as $attempt ) {
			$this->collectTaskAttempt( $groups, $attempt, $names );
		}

		$steps = array();
		$works = array();

		foreach ( $groups as $group ) {
			// Источник читаем ДО finishGroup(): он же разворачивает enum в строку для JSON.
			$isWork = AttemptSource::Work === $group['source'];
			$group  = $this->finishGroup( $group );

			if ( $isWork ) {
				$works[] = $group;
			} else {
				$steps[] = $group;
			}
		}

		return array(
			'steps' => $steps,
			'works' => $works,
			'exams' => $this->exams( $groupLessonId, $names ),
		);
	}

	/**
	 * Раскладывает попытку задания по группам «шаг/работа → ученик».
	 *
	 * @param array<string, array<string, mixed>> $groups Аккумулятор (по ссылке)
	 * @param array<int, string>                  $names  Имена ростера
	 */
	private function collectTaskAttempt( array &$groups, TaskAttemptDTO $attempt, array $names ): void {
		$stepKey = $attempt->stepKey;
		$source  = AttemptSource::fromStepKey( $stepKey );
		$key     = $stepKey . ':' . $attempt->taskId;

		if ( ! isset( $groups[ $key ] ) ) {
			$workId = AttemptSource::workIdFromStepKey( $stepKey );

			$groups[ $key ] = array(
				'step_key'   => $stepKey,
				'source'     => $source,
				'task_id'    => $attempt->taskId,
				'task_title' => $this->postTitle( $attempt->taskId, 'Задание' ),
				'work_id'    => $workId,
				'work_title' => $workId > 0 ? $this->postTitle( $workId, 'Работа' ) : '',
				'students'   => array(),
			);
		}

		$personId = $attempt->studentPersonId;

		if ( ! isset( $groups[ $key ]['students'][ $personId ] ) ) {
			$groups[ $key ]['students'][ $personId ] = $this->newStudent( $personId, $names );
		}

		[ $answerText, $code ] = $this->splitAnswer( $attempt->answer );

		$groups[ $key ]['students'][ $personId ]['attempts'][] = array(
			'number'     => $attempt->attemptNumber,
			'correct'    => $attempt->isCorrect,
			'score'      => $attempt->score,
			'max_score'  => $attempt->maxScore,
			'created_at' => $attempt->createdAt,
			'answer'     => $answerText,
			'code'       => $code,
		);
		++$groups[ $key ]['students'][ $personId ]['tries'];

		if ( true === $attempt->isCorrect ) {
			$groups[ $key ]['students'][ $personId ]['solved'] = true;
		}
	}

	/**
	 * Разбирает сохранённый ответ попытки на текст + необязательный код.
	 *
	 * Задания с кодом (Code/FileCode, {@see \Inc\Enums\Subject\TaskTemplate::
	 * hasCodeField()}) хранят ответ объектом `{text, code}` (см. `task-widget.js::
	 * buildTextAnswerWidget()`) — остальные шаблоны либо строкой (Standard/Common/
	 * Audio), либо структурой другой формы (Choice/Matching/Ordering/Fill), которую
	 * этот отчёт не разбирает — для них оба поля остаются `null`.
	 *
	 * @param mixed $answer Декодированный ответ попытки (строка/массив/null)
	 *
	 * @return array{0: string|null, 1: string|null} [answerText, code]
	 */
	private function splitAnswer( mixed $answer ): array {
		if ( is_string( $answer ) ) {
			return array( $answer, null );
		}

		if ( is_array( $answer ) && array_key_exists( 'text', $answer ) ) {
			$text = is_string( $answer['text'] ) ? $answer['text'] : (string) $answer['text'];
			$code = isset( $answer['code'] ) && '' !== $answer['code'] ? (string) $answer['code'] : null;

			return array( $text, $code );
		}

		return array( null, null );
	}

	/**
	 * Попытки контрольных занятия: контрольная → ученик → попытки.
	 *
	 * Здесь нет «решил / не решил»: у контрольной итог — балл, а не вердикт,
	 * поэтому вместо `solved` считается лучший результат.
	 *
	 * @param array<int, string> $names Имена ростера
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function exams( int $groupLessonId, array $names ): array {
		$exams = array();

		foreach ( $this->examAttempts->listByGroupLesson( $groupLessonId ) as $attempt ) {
			$assessmentId = $attempt->assessmentId;

			if ( ! isset( $exams[ $assessmentId ] ) ) {
				$exams[ $assessmentId ] = array(
					'assessment_id' => $assessmentId,
					'title'         => $this->postTitle( $assessmentId, 'Контрольная' ),
					'students'      => array(),
				);
			}

			$personId = $attempt->studentPersonId;

			if ( ! isset( $exams[ $assessmentId ]['students'][ $personId ] ) ) {
				$exams[ $assessmentId ]['students'][ $personId ] = $this->newStudent( $personId, $names );
			}

			$exams[ $assessmentId ]['students'][ $personId ]['attempts'][] = $this->examAttemptRow( $attempt );
			++$exams[ $assessmentId ]['students'][ $personId ]['tries'];
		}

		return array_values( array_map( $this->finishExam( ... ), $exams ) );
	}

	/**
	 * Одна попытка контрольной в виде строки отчёта.
	 *
	 * @return array{number:int, status:string, score:?float, max_score:?float, created_at:string}
	 */
	private function examAttemptRow( AttemptDTO $attempt ): array {
		return array(
			'number'     => $attempt->attemptNumber,
			'status'     => $attempt->status->value,
			'score'      => $attempt->totalScore,
			'max_score'  => $attempt->maxScore,
			'created_at' => $attempt->submittedAt ?? $attempt->startedAt,
		);
	}

	/**
	 * Заготовка строки ученика в любой группе отчёта.
	 *
	 * @param array<int, string> $names Имена ростера
	 *
	 * @return array<string, mixed>
	 */
	private function newStudent( int $personId, array $names ): array {
		return array(
			'person_id' => $personId,
			'name'      => $names[ $personId ] ?? "Ученик #{$personId}",
			'tries'     => 0,
			'solved'    => false,
			'attempts'  => array(),
		);
	}

	/**
	 * Досчитывает агрегаты задания/работы и разворачивает учеников в список.
	 *
	 * @param array<string, mixed> $group Накопленная группа
	 *
	 * @return array<string, mixed>
	 */
	private function finishGroup( array $group ): array {
		$students = array_values( $group['students'] );

		$group['source']   = $group['source']->value;
		$group['students'] = $students;
		$group['total']    = count( $students );
		$group['solved']   = count( array_filter( $students, static fn( array $s ): bool => $s['solved'] ) );

		return $group;
	}

	/**
	 * Досчитывает агрегаты контрольной: лучший балл вместо «решил / не решил».
	 *
	 * @param array<string, mixed> $exam Накопленная контрольная
	 *
	 * @return array<string, mixed>
	 */
	private function finishExam( array $exam ): array {
		$students = array_values( $exam['students'] );

		foreach ( $students as $index => $student ) {
			$scores = array_filter(
				array_column( $student['attempts'], 'score' ),
				static fn( $score ): bool => null !== $score
			);

			$students[ $index ]['best_score'] = array() !== $scores ? max( $scores ) : null;
			$students[ $index ]['max_score']  = $student['attempts'][0]['max_score'] ?? null;
			unset( $students[ $index ]['solved'] );
		}

		$exam['students'] = $students;
		$exam['total']    = count( $students );
		$exam['retakes']  = count( array_filter( $students, static fn( array $s ): bool => $s['tries'] > 1 ) );

		return $exam;
	}

	/**
	 * Имена активного ростера группы: person_id → «Фамилия Имя».
	 *
	 * @return array<int, string>
	 */
	private function rosterNames( int $groupId ): array {
		$names = array();

		foreach ( $this->records->findActiveByGroupId( $groupId ) as $record ) {
			$names[ $record->studentPersonId ] = trim( $record->snapshotLastName . ' ' . $record->snapshotFirstName );
		}

		return $names;
	}

	/**
	 * Заголовок записи; при удалённой — подпись с ID, а не пустая строка.
	 */
	private function postTitle( int $postId, string $fallbackLabel ): string {
		$title = $postId > 0 ? get_the_title( $postId ) : '';

		return '' !== $title ? $title : "{$fallbackLabel} #{$postId}";
	}
}
