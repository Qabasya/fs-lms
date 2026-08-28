<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Shared\SafeHtml;
use Inc\Enums\Subject\TaskTemplate;
use Inc\Enums\Course\AttemptSource;
use Inc\Enums\Course\SubmissionStatus;
use Inc\Enums\Course\WorkSourceType;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Course\WorkManager;
use Inc\Managers\Wp\MediaManager;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Repositories\WPDBRepositories\TaskAttemptRepository;
use Inc\Services\Task\CorrectAnswerResolver;
use Inc\Services\Task\TaskMetaService;

/**
 * Class WorkDetailService
 *
 * Деталь работы для «Сводки по ученику» (Эпик 10 T10.9): по `source_type` из
 * GradebookEntryDTO собирает условия задач, ответ ученика, вердикт и баллы.
 *  - `submission` (source_id = id агрегатной строки сдачи) — «работа».
 *  - `attempt`    (source_id = id попытки ассессмента)      — экзамен.
 *
 * Правильные ответы НЕ отдаются (чекеры возвращают только вердикт; см.
 * страница экзамена отдаёт только whitelist полей) — показываем условие + ответ
 * ученика + вердикт/баллы.
 * `group_id` в результате — только для проверки доступа в колбэке (удаляется перед отдачей).
 *
 * @package Inc\Services\Course
 */
class WorkDetailService {

	/**
	 * WP filter: рубрика ручной проверки для задания станции «Компьютерный ОГЭ»
	 * (13/14/15/16) — единый балл + текст всех уровней, вместо покритерийной
	 * суммы (см. .docs/Tasks.md §3.4). Ядро не знает о модуле — тот же приём, что
	 * `AssessmentManager::STATION_SETTINGS_FILTER`.
	 *
	 *   apply_filters( self::OGE_RUBRIC_FILTER, null, $assessment, $taskId )
	 *
	 * @see \Inc\DTO\Assessment\AssessmentDTO $assessment
	 * @return array{max_points: int, html: string}|null
	 */
	public const OGE_RUBRIC_FILTER = 'fs_lms_oge_manual_rubric';

	/**
	 * WP filter: приводит сериализованный табличный ответ (№17/18/20/25/26/27
	 * «Компьютерного ЕГЭ» — станция кодирует несколько ячеек одной строкой,
	 * `|` разделяет столбцы, `\n` строки) к читаемому виду для экрана «Работы»
	 * учителя. Без фильтра здесь остаётся сырой вид с `|` — модуль сам решает,
	 * какие номера заданий табличные (ядро не знает о модулях), тот же приём,
	 * что `OGE_RUBRIC_FILTER`.
	 *
	 *   apply_filters( self::TABLE_ANSWER_FILTER, $answerText, $assessment, $taskId )
	 *
	 * @see \Inc\DTO\Assessment\AssessmentDTO $assessment
	 */
	public const TABLE_ANSWER_FILTER = 'fs_lms_kege_table_answer';

	public function __construct(
		private readonly SubmissionRepository        $submissions,
		private readonly WorkManager                 $works,
		private readonly PostManager                 $posts,
		private readonly GroupLessonRepository       $groupLessons,
		private readonly AssessmentAttemptRepository $attempts,
		private readonly AssessmentAnswerRepository  $answers,
		private readonly AssessmentManager           $assessments,
		private readonly CorrectAnswerResolver       $correctAnswers,
		private readonly MediaManager                $media,
		private readonly TaskMetaService             $taskMeta,
		private readonly TaskAttemptRepository       $taskAttempts,
	) {}

	/**
	 * @return array<string,mixed>|null  null, если работа не найдена / тип неизвестен
	 */
	public function forWork( string $sourceType, int $sourceId ): ?array {
		return match ( WorkSourceType::fromValueOrNull( $sourceType ) ) {
			WorkSourceType::Submission => $this->fromSubmission( $sourceId ),
			WorkSourceType::Attempt    => $this->fromAttempt( $sourceId ),
			default                    => null,
		};
	}

	/**
	 * История всех попыток сдачи работы (submission), сгруппированных по
	 * раунду сдачи. `SubmissionService::submitBatch()` перезаписывает текущую
	 * строку `fs_lms_submissions` при пересдаче («Пройти заново»), но КАЖДАЯ
	 * сдача пишет отдельные строки в `fs_lms_task_attempts` с общим для всех
	 * задач работы `attemptNumber` (см. `SubmissionService::recordWorkAttempt()`)
	 * — этот номер и есть «раунд», по нему группируем историю без изменения
	 * модели данных.
	 *
	 * Экзамены (WorkSourceType::Attempt) сюда не относятся — у `fs_lms_assessment_attempts`
	 * каждая попытка и так отдельная запись, история видна без этого метода.
	 *
	 * @param int $submissionId ID агрегатной строки сдачи (fs_lms_submissions)
	 *
	 * @return array<int, array{
	 *   round: int,
	 *   submitted_at: string,
	 *   is_current: bool,
	 *   tasks: array<int, array{n:int, condition:string, answer:?string, code:?string, verdict:string, score:?float, max_score:?float}>
	 * }>|null null, если сдача не найдена
	 */
	public function attemptHistory( int $submissionId ): ?array {
		$sub = $this->submissions->find( $submissionId );
		if ( ! $sub ) {
			return null;
		}

		$attempts = $this->taskAttempts->listByStep(
			$sub->studentPersonId,
			$sub->groupLessonId,
			AttemptSource::workStepKey( $sub->workId )
		);
		if ( empty( $attempts ) ) {
			return array();
		}

		$rounds = array();
		foreach ( $attempts as $attempt ) {
			$rounds[ $attempt->attemptNumber ][] = $attempt;
		}
		ksort( $rounds );
		$maxRound = max( array_keys( $rounds ) );

		$work    = $this->works->get( $sub->workId );
		$itemIds = $work?->itemIds ? array_map( 'intval', $work->itemIds ) : array();

		$result = array();
		foreach ( $rounds as $round => $roundAttempts ) {
			$byTask = array();
			foreach ( $roundAttempts as $a ) {
				$byTask[ $a->taskId ] = $a;
			}

			$orderedTaskIds = $itemIds ?: array_keys( $byTask );
			$submittedAt    = $roundAttempts[0]->createdAt;
			$tasks          = array();
			$n              = 0;

			foreach ( $orderedTaskIds as $taskId ) {
				$a = $byTask[ $taskId ] ?? null;
				if ( ! $a ) {
					continue;
				}
				if ( $a->createdAt > $submittedAt ) {
					$submittedAt = $a->createdAt;
				}

				[ $answer, $code ] = $this->splitAttemptAnswer( $taskId, $a->answer );

				$tasks[] = array(
					'n'         => ++$n,
					'condition' => $this->condition( $taskId ),
					'answer'    => $answer,
					'code'      => $code,
					'verdict'   => true === $a->isCorrect ? 'correct' : ( false === $a->isCorrect ? 'incorrect' : 'pending' ),
					'score'     => $a->score,
					'max_score' => $a->maxScore,
				);
			}

			$result[] = array(
				'round'        => $round,
				'submitted_at' => $submittedAt,
				'is_current'   => $round === $maxRound,
				'tasks'        => $tasks,
			);
		}

		return $result;
	}

	/**
	 * Разбирает декодированный ответ попытки (уже `mixed` из `TaskAttemptDTO`) на
	 * текст + необязательный код — та же логика, что {@see parseCodeAnswer()},
	 * только источник не JSON-строка `answer_text`, а уже декодированное значение
	 * из `fs_lms_task_attempts`.
	 *
	 * @param int   $taskId    ID задания
	 * @param mixed $rawAnswer Декодированный ответ попытки (строка/массив/null)
	 *
	 * @return array{0: string|null, 1: string|null} [answer, code]
	 */
	private function splitAttemptAnswer( int $taskId, mixed $rawAnswer ): array {
		if ( is_string( $rawAnswer ) ) {
			return array( $rawAnswer, null );
		}

		if ( $this->taskTemplate( $taskId )->hasCodeField() && is_array( $rawAnswer ) && array_key_exists( 'text', $rawAnswer ) ) {
			$code = isset( $rawAnswer['code'] ) && '' !== $rawAnswer['code'] ? (string) $rawAnswer['code'] : null;

			return array( (string) $rawAnswer['text'], $code );
		}

		return array( null, null );
	}

	private function fromSubmission( int $submissionId ): ?array {
		$sub = $this->submissions->find( $submissionId );
		if ( ! $sub ) {
			return null;
		}
		$work    = $this->works->get( $sub->workId );
		$perTask = $this->decode( $sub->answerText );

		// Per-task строки сдачи (task_id NOT NULL) — источник ответа ученика И, для
		// ручных заданий, авторитетного вердикта/балла ПОСЛЕ оценки (D4, .docs/Tasks.md):
		// `SubmissionService::gradeBatchTask()` пишет score/status в саму эту строку,
		// не трогая JSON-снапшот агрегата ($perTask выше) — он остаётся авто-проверкой
		// на момент сдачи и достоверен только для авто-проверяемых заданий.
		$rowsByTask = array();
		foreach ( $this->submissions->listPerTaskByStudentWorkLesson( $sub->studentPersonId, $sub->groupLessonId, $sub->workId ) as $row ) {
			if ( null !== $row->taskId ) {
				$rowsByTask[ $row->taskId ] = $row;
			}
		}

		$itemIds  = $work?->itemIds ?: array_map( 'intval', array_keys( $perTask ) );
		$tasks    = array();
		$n        = 0;
		$hasGradableTask = false;
		foreach ( $itemIds as $taskId ) {
			$taskId   = (int) $taskId;
			$pt       = $perTask[ $taskId ] ?? array();
			$row      = $rowsByTask[ $taskId ] ?? null;
			$template = $this->taskTemplate( $taskId );
			$gradable = $template->isFileAnswerShape();
			if ( $gradable ) {
				$hasGradableTask = true;
			}

			// Задания с кодом (Code/FileCode) хранят ответ JSON {"text","code"} —
			// разбираем на текст (участвует в вердикте/сверке) и код (только для показа).
			$rawAnswer = (string) ( $row->answerText ?? '' );
			$answer    = $rawAnswer;
			$code      = null;
			if ( $template->hasCodeField() ) {
				$parsedCode = $this->parseCodeAnswer( $rawAnswer );
				$answer     = $parsedCode['text'];
				$code       = $parsedCode['code'];
			}

			// Ручное задание (file_answer_task) с уже начатой/законченной проверкой —
			// per-task строка авторитетнее авто-снапшота агрегата (см. докблок выше).
			if ( $gradable && $row ) {
				$verdict = SubmissionStatus::PendingReview === $row->status
					? 'pending'
					: ( ( $row->score ?? 0.0 ) >= ( $row->maxScore ?? 1.0 ) ? 'correct' : 'incorrect' );
				$tasks[] = array(
					'n'                  => ++$n,
					'condition'          => $this->condition( $taskId ),
					'answer'             => $answer,
					'code'               => $code,
					'correct'            => $this->correctAnswers->resolve( $taskId ),
					'verdict'            => $verdict,
					'score'              => $row->score,
					'max_score'          => $row->maxScore,
					'feedback'           => $row->feedback,
					'gradable'           => true,
					'task_submission_id' => $row->id,
				);
				continue;
			}

			$tasks[] = array(
				'n'                  => ++$n,
				'condition'          => $this->condition( $taskId ),
				'answer'             => $answer,
				'code'               => $code,
				'correct'            => $this->correctAnswers->resolve( $taskId ),
				'verdict'            => (string) ( $pt['verdict'] ?? 'pending' ),
				'score'              => isset( $pt['score'] ) ? (float) $pt['score'] : null,
				'max_score'          => isset( $pt['maxScore'] ) ? (float) $pt['maxScore'] : null,
				'feedback'           => null,
				'gradable'           => $gradable,
				'task_submission_id' => $row?->id,
			);
		}

		// Фолбэк: свободный ответ (не разложен по задачам) — единый блок, старая
		// цельная форма оценивания (см. `gradable` ниже) остаётся для этого случая.
		if ( empty( $tasks ) && null !== $sub->answerText && '' !== $sub->answerText ) {
			$tasks[] = array(
				'n'         => 1,
				'condition' => $work?->instructions ? SafeHtml::post( $work->instructions ) : '',
				'answer'    => (string) $sub->answerText,
				'correct'   => null,
				'verdict'   => 'pending',
				'score'     => $sub->score,
				'max_score' => $sub->maxScore,
				'feedback'  => null,
				'gradable'  => false,
				'task_submission_id' => null,
			);
		}

		// Решено (D): submission-работы с разбором по заданиям оцениваются поштучно,
		// как экзамены — единая форма «Сохранить оценку» под всей сдачей нужна только
		// фолбэку выше (свободный ответ без разбора на задачи).
		$wholeSubmissionGradable = ! $hasGradableTask && empty( $work?->itemIds );

		// T13.1: вложение ученика (фото/файл решения) — форма одиночной сдачи уже
		// принимает файл (SubmissionService::submit → MediaManager::uploadFromRequest),
		// но деталь работы его раньше не отдавала — учитель не мог его увидеть.
		$attachmentUrl  = null;
		$attachmentMime = null;
		if ( $sub->attachmentId ) {
			$attachmentUrl  = $this->media->url( $sub->attachmentId );
			$attachmentMime = get_post_mime_type( $sub->attachmentId ) ?: null;
		}

		return array(
			'kind'            => 'work',
			'title'           => $work?->title ?? 'Работа',
			'status'          => $sub->status->value,
			'score'           => $sub->score,
			'max_score'       => $sub->maxScore,
			'feedback'        => $sub->feedback,
			'gradable'        => $wholeSubmissionGradable,
			'submission_id'   => $sub->id,
			'tasks'           => $tasks,
			// T12.2 (D13): дедлайн работы (снимок на момент сдачи) + постоянная метка «Просрочено».
			'due_at'          => $sub->dueAt,
			'is_late'         => $sub->isLate(),
			'attachment_url'  => $attachmentUrl,
			'attachment_mime' => $attachmentMime,
			'group_id'        => $this->groupLessons->find( $sub->groupLessonId )?->groupId ?? 0,
		);
	}

	private function fromAttempt( int $attemptId ): ?array {
		$attempt = $this->attempts->find( $attemptId );
		if ( ! $attempt ) {
			return null;
		}
		$assessment = $this->assessments->get( $attempt->assessmentId );

		$rows = $this->answers->listByAttempt( $attemptId );

		// ID заданий приходят из таблицы ответов, не из WP_Query — прогреваем
		// мета-кэш одним запросом (в цикле по два чтения меты на задание).
		$this->posts->primeMetaCache( array_map( static fn( $a ) => $a->taskId, $rows ) );

		// Строки ответов идут в порядке вставки (когда ученик отвечал), а не в
		// порядке позиций экзамена — «Задача 1» рендерилась бы тем заданием,
		// которое сохранилось первым (нередко №25/№27, набранные не по порядку).
		// Перекладываем в порядок assessment->taskIds — как уже делает
		// KegeResultSheetService::assemble() для листа результатов.
		$byTaskId = array();
		foreach ( $rows as $ans ) {
			$byTaskId[ $ans->taskId ] = $ans;
		}
		$orderedRows = array();
		foreach ( $assessment?->taskIds ?? array() as $taskId ) {
			if ( isset( $byTaskId[ (int) $taskId ] ) ) {
				$orderedRows[] = $byTaskId[ (int) $taskId ];
				unset( $byTaskId[ (int) $taskId ] );
			}
		}
		// Остаток (ответ на задание, которого больше нет в task_ids контрольной) —
		// в хвост, как и раньше, чтобы ни одна сдача не потерялась молча.
		$orderedRows = array_merge( $orderedRows, array_values( $byTaskId ) );

		$tasks = array();
		$n     = 0;
		foreach ( $orderedRows as $ans ) {
			$verdict = null === $ans->isCorrect ? 'pending' : ( $ans->isCorrect ? 'correct' : 'incorrect' );

			// Эпик 13 (D16/D17): «Развёрнутый ответ» — ответ закодирован как JSON
			// {"text","files":[attachment_ids]}; плюс опциональные критерии оценивания.
			$template = TaskTemplate::fromDatabase(
				(string) $this->posts->getMeta( $ans->taskId, PostMetaName::TemplateType->value )
			);
			// Ручная проверка нужна ТОЛЬКО этой форме ответа (TaskCheckerRegistry не
			// умеет её проверить автоматически) — балл/чекбокс/комментарий учителя на
			// экране показываем только здесь; для авто-проверяемых задач эти поля
			// никто не читает (итог считает AutoGradeService по task_checker'у), и
			// показанная форма вводила в заблуждение — заполнялась, но не влияла.
			$isManual = $template->isFileAnswerShape();
			if ( $isManual ) {
				$parsed     = $this->parseFileAnswer( $ans->answerText );
				$answerText = $parsed['text'];
				$files      = $parsed['files'];
			} else {
				$answerText = (string) ( $ans->answerText ?? '' );
				$files      = array();
			}
			// Табличные задания станции (№17/18/20/25/26/27) кодируют ответ одной
			// строкой (`|` между столбцами, `\n` между строками таблицы) — без
			// фильтра здесь остался бы сырой вид с `|`.
			$answerText = (string) apply_filters( self::TABLE_ANSWER_FILTER, $answerText, $assessment, $ans->taskId );

			$tasks[] = array(
				'n'          => ++$n,
				'task_id'    => $ans->taskId,
				'condition'  => $this->condition( $ans->taskId ),
				'answer'     => $answerText,
				'files'      => $files,
				'correct'    => $this->correctAnswers->resolve( $ans->taskId ),
				'verdict'    => $verdict,
				'score'      => $ans->score,
				'max_score'  => $ans->maxScore,
				'manual'     => $isManual,
				'feedback'   => $ans->graderNote,
				'criteria'   => $this->criteriaFor( $ans->taskId, $ans->criteriaScores ),
				'oge_rubric' => $assessment ? apply_filters( self::OGE_RUBRIC_FILTER, null, $assessment, $ans->taskId ) : null,
			);
		}

		return array(
			'kind'          => 'exam',
			'title'         => $assessment?->title ?? 'Экзамен',
			'status'        => $attempt->status->value,
			'score'         => $attempt->totalScore,
			'max_score'     => $attempt->maxScore,
			'feedback'      => null,
			'gradable'      => false, // целиком не оценивается — грейдинг по задачам (T11.9)
			'submission_id' => null,
			'attempt_id'      => $attemptId,
			'tasks'           => $tasks,
			'group_id'        => $attempt->groupId ?? 0,
			// D18: «Утвердить работу» — для kind без ручной проверки заданий (ЕГЭ
			// компьютерный) Graded наступает сразу при сдаче и не значит «учитель
			// посмотрел»; approved_at — отдельный явный шаг (см. AttemptRevealPolicy).
			'assessment_kind' => $assessment?->kind->value,
			'approved_at'     => $attempt->approvedAt,
		);
	}

	/** Условие задания хранится в мете (`task_condition` и составные шаблоны), не в `post_content`. */
	private function condition( int $taskId ): string {
		return $this->taskMeta->getCombinedCondition( $this->posts->taskMeta( $taskId ) );
	}

	/** Резолвит шаблон задания по его типу из меты. */
	private function taskTemplate( int $taskId ): TaskTemplate {
		return TaskTemplate::fromDatabase(
			(string) $this->posts->getMeta( $taskId, PostMetaName::TemplateType->value )
		);
	}

	/**
	 * Разбор ответа заданий с кодом (Code/FileCode, {@see TaskTemplate::
	 * hasCodeField()}): ответ хранится JSON `{"text","code"}` (см.
	 * `src/js/frontend/components/task-widget.js::buildTextAnswerWidget()`), код —
	 * необязателен. Не-JSON (старая запись без кода, до появления этого поля) —
	 * весь текст как есть, без кода.
	 *
	 * @return array{text: string, code: string|null}
	 */
	private function parseCodeAnswer( ?string $answerText ): array {
		$decoded = is_string( $answerText ) && '' !== $answerText ? json_decode( $answerText, true ) : null;
		if ( ! is_array( $decoded ) || ! array_key_exists( 'text', $decoded ) ) {
			return array( 'text' => (string) $answerText, 'code' => null );
		}

		return array(
			'text' => (string) $decoded['text'],
			'code' => isset( $decoded['code'] ) ? (string) $decoded['code'] : null,
		);
	}

	/**
	 * Разбор ответа «Развёрнутый ответ» (Эпик 13): JSON {"text","files":[ids]} →
	 * текст + резолвленные файлы (url/name/mime). Не-JSON (или пустой) ответ —
	 * весь текст как есть, без файлов.
	 *
	 * @return array{text: string, files: array<int, array{url: string, name: string, mime: string}>}
	 */
	private function parseFileAnswer( ?string $answerText ): array {
		$decoded = is_string( $answerText ) && '' !== $answerText ? json_decode( $answerText, true ) : null;
		if ( ! is_array( $decoded ) ) {
			return array( 'text' => (string) $answerText, 'files' => array() );
		}

		$ids   = is_array( $decoded['files'] ?? null ) ? $decoded['files'] : array();
		$files = array();
		foreach ( $ids as $attachmentId ) {
			$attachmentId = (int) $attachmentId;
			$url          = $attachmentId ? $this->media->url( $attachmentId ) : null;
			if ( ! $url ) {
				continue;
			}
			$files[] = array(
				'url'  => $url,
				'name' => get_the_title( $attachmentId ) ?: "Файл #{$attachmentId}",
				'mime' => get_post_mime_type( $attachmentId ) ?: '',
			);
		}

		return array( 'text' => (string) ( $decoded['text'] ?? '' ), 'files' => $files );
	}

	/**
	 * Критерии оценивания задачи (Эпик 13, D17) + уже начисленные баллы (если
	 * оценено). Пустой список — у задачи нет критериев (обычный один балл).
	 *
	 * @return array<int, array{label: string, max_points: float, awarded: float|null}>
	 */
	private function criteriaFor( int $taskId, ?array $criteriaScores ): array {
		$meta = $this->posts->getMeta( $taskId, PostMetaName::Meta->value );
		$defs = is_array( $meta ) && is_array( $meta['task_criteria']['criteria'] ?? null )
			? $meta['task_criteria']['criteria']
			: array();
		if ( empty( $defs ) ) {
			return array();
		}

		$out = array();
		foreach ( $defs as $i => $def ) {
			$out[] = array(
				'label'      => (string) ( $def['label'] ?? '' ),
				'max_points' => (float) ( $def['max_points'] ?? 0 ),
				'awarded'    => isset( $criteriaScores[ $i ] ) ? (float) $criteriaScores[ $i ] : null,
			);
		}
		return $out;
	}

	/** @return array<int|string,mixed> */
	private function decode( ?string $json ): array {
		if ( null === $json || '' === $json ) {
			return array();
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
