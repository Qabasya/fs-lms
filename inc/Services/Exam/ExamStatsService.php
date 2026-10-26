<?php
/**
 * Внутренняя статистика проведений для сотрудника (этап 10). Все числа считает сервер; клиент только рисует.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\Enums\Exam\ExamAudience;

use Inc\Enums\Exam\ExamSessionStatus;

use Inc\Enums\Exam\ExamEventStatus;

use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Exam\ExamEventDTO;
use Inc\DTO\Exam\ExamParticipationDTO;
use Inc\DTO\Exam\ExamRegistrationDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Assessment\AttemptStatus;
use Inc\Enums\Exam\ExamDirection;
use Inc\Enums\Exam\ExamRegistrationStatus;
use Inc\Enums\Log\ErrorCode;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Services\Assessment\ScoringUnits;
use Inc\Shared\CodedException;

/**
 * Внутренняя статистика проведений для сотрудника (этап 10). Все числа считает сервер; клиент только рисует.
 *
 * **Единица подсчёта — участие**, а не строка записи: перенос не даёт «+1 записано», отменённая запись без попытки не считается.
 * **Шкалы не смешиваются**: средние считаются по одному формату (направлению) — по направлению первой оценённой работы; работы
 * другого направления в среднее не входят (`format.mixed = true`). Рядом со средними всегда размер выборки (`sample`).
 * Разбор по заданиям строится по единицам оценивания (несколько заданий одного номера — одна единица), как итог попытки.
 */
class ExamStatsService {

	public const BUCKETS = array( 'correct', 'partial', 'incorrect', 'unanswered', 'pending' );

	/** Получает ответы и шкалы для статистики в проверенной области доступа к проведению. */
	public function __construct(
		private readonly ExamEventRepository $events,
		private readonly ExamSessionRepository $sessions,
		private readonly ExamParticipationRepository $participations,
		private readonly ExamRegistrationRepository $registrations,
		private readonly AssessmentAttemptRepository $attempts,
		private readonly AssessmentAnswerRepository $answers,
		private readonly ExamScoreService $scores,
		private readonly ExamAccessGuard $guard,
		private readonly ScoringUnits $scoringUnits,
		private readonly AssessmentManager $assessments,
		private readonly ExamFormatRegistry $formats,
		private readonly ExamTime $time,
		private readonly ExamParticipantRepository $participants,
		private readonly ExamParticipantNameService $names,
		private readonly ExamParticipantProfileService $profiles,
	) {}

	/**
	 * Готовит статистику по выбранным фильтрам в пределах доступа пользователя.
	 *
	 * @param array{subject_key?: string, event_id?: int, session_id?: int, audience?: string} $filters
	 *
	 * @return array{filters: array<string, mixed>, format: ?array<string, mixed>, kpi: array<string, mixed>, tasks: list<array<string, mixed>>, table: array<string, mixed>, summary: array<string, mixed>}
	 *
	 * @throws CodedException `ExamAccess` — проведение чужое.
	 */
	public function overview( int $actorUserId, array $filters ): array {
		$subject   = (string) ( $filters['subject_key'] ?? '' );
		$eventId   = (int) ( $filters['event_id'] ?? 0 );
		$sessionId = (int) ( $filters['session_id'] ?? 0 );
		$audience  = (string) ( $filters['audience'] ?? 'all' );

		$available = array_values(
			array_filter(
				$this->events->findBySubjectKey( $subject ),
				fn ( ExamEventDTO $e ): bool => in_array( $e->status, array( ExamEventStatus::Published->value, ExamEventStatus::Completed->value ), true ) && $this->guard->canManageEvent( $actorUserId, $e )
			)
		);

		$selected = $available;
		if ( $eventId > 0 ) {
			$selected = array_values( array_filter( $available, static fn ( ExamEventDTO $e ): bool => $e->id === $eventId ) );
			if ( array() === $selected ) {
				throw new CodedException( ErrorCode::ExamAccess, 'Проведение не найдено.' );
			}
		}

		$rows = array();
		foreach ( $selected as $event ) {
			foreach ( $this->participationRows( $event, $sessionId, $audience ) as $row ) {
				$rows[] = $row;
			}
		}

		$kpi   = $this->kpi( $rows );
		$tasks = array() === $selected ? array() : $this->byTask(
			array_values( array_filter( array_column( $rows, 'attempt' ), static fn ( ?AttemptDTO $a ): bool => null !== $a ) ),
			$selected[0]
		);

		return array(
			'filters' => array(
				'events'   => array_map(
					static fn ( ExamEventDTO $e ): array => array(
						'id'    => $e->id,
						'title' => $e->title,
					),
					$available
				),
				'sessions' => 1 === count( $selected ) ? $this->sessionOptions( $selected[0] ) : array(),
			),
			'format'  => $kpi['format'],
			'kpi'     => $kpi['kpi'],
			'tasks'   => $tasks,
			'table'   => $this->table( $rows ),
			'summary' => $this->summary( $tasks, (int) $kpi['kpi']['submitted'] ),
		);
	}

	/**
	 * Разбор по единицам оценивания: сколько работ получили полный балл, частичный, ошибку, пропуск или ждут проверки.
	 * Попытки `in_progress` не входят; единица на ручной проверке попадает в `pending` и не входит в средний балл.
	 *
	 * @param AttemptDTO[] $attempts
	 *
	 * @return list<array{number: string, max: float, total: int, full: int, partial: int, incorrect: int, unanswered: int, pending: int, avg_score: ?float, full_share: int}>
	 */
	public function byTask( array $attempts, ExamEventDTO $event ): array {
		$rows = array();

		foreach ( $attempts as $attempt ) {
			if ( AttemptStatus::InProgress === $attempt->status ) {
				continue;
			}
			foreach ( $this->scores->units( $attempt, $this->taskRows( $attempt ) ) as $unit ) {
				$number = (string) $unit['number'];
				if ( '' === $number ) {
					continue; // задание без номера в единицы формата не входит
				}
				$row = $rows[ $number ] ?? array(
					'number'     => $number,
					'max'        => 0.0,
					'total'      => 0,
					'correct'    => 0,
					'partial'    => 0,
					'incorrect'  => 0,
					'unanswered' => 0,
					'pending'    => 0,
					'sum'        => 0.0,
					'scored'     => 0,
				);

				$status = in_array( $unit['status'], self::BUCKETS, true ) ? $unit['status'] : 'incorrect';
				++$row['total'];
				++$row[ $status ];
				$row['max'] = max( $row['max'], (float) $unit['max'] );
				if ( 'pending' !== $status ) {
					$row['sum'] += (float) $unit['score'];
					++$row['scored'];
				}
				$rows[ $number ] = $row;
			}
		}

		// Номера формата без единой работы показываются нулями: таблица всегда полная.
		$unitCount = $this->formatFor( $event, $attempts[0] ?? null )->unitCount ?? 0;
		for ( $n = 1; $n <= $unitCount; $n++ ) {
			$rows[ (string) $n ] ??= array(
				'number'     => (string) $n,
				'max'        => 0.0,
				'total'      => 0,
				'correct'    => 0,
				'partial'    => 0,
				'incorrect'  => 0,
				'unanswered' => 0,
				'pending'    => 0,
				'sum'        => 0.0,
				'scored'     => 0,
			);
		}
		uksort( $rows, static fn ( int|string $a, int|string $b ): int => (int) $a <=> (int) $b );

		return array_values(
			array_map(
				static fn ( array $r ): array => array(
					'number'     => $r['number'],
					'max'        => $r['max'],
					'total'      => $r['total'],
					'full'       => $r['correct'],
					'partial'    => $r['partial'],
					'incorrect'  => $r['incorrect'],
					'unanswered' => $r['unanswered'],
					'pending'    => $r['pending'],
					'avg_score'  => $r['scored'] > 0 ? round( $r['sum'] / $r['scored'], 1 ) : null,
					'full_share' => $r['total'] > 0 ? (int) round( 100 * $r['correct'] / $r['total'] ) : 0,
				),
				$rows
			)
		);
	}

	/**
	 * Статус каждой единицы оценивания одной попытки по возрастанию номера: для строки школьного отчёта.
	 * Условия и ответы не собираются — только номер и вердикт (`correct`, `partial`, `incorrect`, `unanswered`, `pending`).
	 *
	 * @return list<array{number: string, status: string}>
	 */
	public function attemptUnits( AttemptDTO $attempt ): array {
		return array_map(
			static fn ( array $unit ): array => array(
				'number' => $unit['number'],
				'status' => $unit['status'],
			),
			$this->unitDetails( $attempt )
		);
	}

	/**
	 * Единицы оценивания попытки с баллами по возрастанию номера; задание без номера в единицы формата не входит.
	 *
	 * @return list<array{number: string, status: string, score: float, max: float}>
	 */
	private function unitDetails( AttemptDTO $attempt ): array {
		$units = array();
		foreach ( $this->scores->units( $attempt, $this->taskRows( $attempt ) ) as $unit ) {
			$number = (string) $unit['number'];
			if ( '' === $number ) {
				continue;
			}
			$units[] = array(
				'number' => $number,
				'status' => in_array( $unit['status'], self::BUCKETS, true ) ? (string) $unit['status'] : 'incorrect',
				'score'  => (float) $unit['score'],
				'max'    => (float) $unit['max'],
			);
		}
		usort( $units, static fn ( array $a, array $b ): int => (int) $a['number'] <=> (int) $b['number'] );

		return $units;
	}

	/**
	 * Таблица «ученики × задания»: по строке на каждого, кто сдал работу, с баллом за каждое задание, первичным и вторичным баллом.
	 * Работа в процессе в таблицу не входит. Внизу — средние по столбцам (ожидающие проверки единицы и работы в среднее не идут).
	 * Поиск, группа и школа применяются клиентом по полям строки: строк на проведение — сотни, перезапрос на каждую букву не нужен.
	 *
	 * @param list<array{participation: ExamParticipationDTO, attempt: ?AttemptDTO, last: ?ExamRegistrationDTO, event: ExamEventDTO}> $rows
	 *
	 * @return array{columns: list<string>, secondary_label: string, rows: list<array<string, mixed>>, footer: array<string, mixed>}
	 */
	private function table( array $rows ): array {
		$columns = array();
		$list    = array();
		$ege     = true;

		foreach ( $rows as $row ) {
			$attempt     = $row['attempt'];
			$participant = null !== $attempt && AttemptStatus::InProgress !== $attempt->status ? $this->participants->find( $row['participation']->participantId ) : null;
			if ( null === $attempt || null === $participant ) {
				continue;
			}

			$summary = $this->scores->summarize( $attempt, $row['event'] );
			$units   = array();
			foreach ( $this->unitDetails( $attempt ) as $unit ) {
				$columns[ $unit['number'] ] = true;
				$units[ $unit['number'] ]   = array(
					'score'  => $unit['score'],
					'max'    => $unit['max'],
					'status' => $unit['status'],
				);
			}

			$ege    = ExamDirection::Ege->value === (string) $summary['direction'];
			$second = $ege ? $summary['secondary'] : $summary['grade'];
			$list[] = array(
				'participation_id' => $row['participation']->id,
				// Клик по строке открывает проверку этой работы.
				'attempt_id'       => $attempt->id,
				'name'             => $this->names->participantName( $participant ),
				'audience'         => $row['participation']->audience,
				'school'           => $this->profiles->school( $row['participation'], $participant ),
				'group'            => $this->profiles->groupName( $participant ),
				'primary'          => (int) $summary['primary'],
				'primary_max'      => (int) $summary['primary_max'],
				'secondary'        => null !== $second ? (int) $second : null,
				'pending'          => ! empty( $summary['pending'] ),
				'units'            => $units,
			);
		}

		// Сначала окончательные по убыванию первичного балла, затем ожидающие проверки; при равенстве — по имени.
		usort(
			$list,
			static fn ( array $a, array $b ): int => ( (int) $a['pending'] <=> (int) $b['pending'] )
				?: ( $b['primary'] <=> $a['primary'] )
				?: strcmp( (string) $a['name'], (string) $b['name'] )
		);

		$numbers = array_map( 'strval', array_keys( $columns ) );
		usort( $numbers, static fn ( string $a, string $b ): int => (int) $a <=> (int) $b );

		return array(
			'columns'         => $numbers,
			'secondary_label' => $ege ? 'Вторичный балл' : 'Отметка',
			'rows'            => $list,
			'footer'          => $this->footer( $list ),
		);
	}

	/**
	 * Сводная по заданиям: сколько работ вообще дали ответ («попытки») и сколько решили верно, абсолютно и в процентах.
	 * Верным считается любой положительный балл — и 1, и 2 балла за задание 26–27. Проценты: попыток — от сданных работ,
	 * верных — от попыток; без попыток процент — null (делить не на что).
	 *
	 * @param list<array<string, mixed>> $tasks Результат {@see byTask()}.
	 *
	 * @return array{works: int, columns: list<array{number: string, attempts: int, correct: int, attempts_share: ?int, correct_share: ?int}>}
	 */
	private function summary( array $tasks, int $works ): array {
		$columns = array();
		foreach ( $tasks as $task ) {
			$attempts  = (int) $task['total'] - (int) $task['unanswered'];
			$correct   = (int) $task['full'] + (int) $task['partial'];
			$columns[] = array(
				'number'         => (string) $task['number'],
				'attempts'       => $attempts,
				'correct'        => $correct,
				'attempts_share' => $works > 0 ? (int) round( 100 * $attempts / $works ) : null,
				'correct_share'  => $attempts > 0 ? (int) round( 100 * $correct / $attempts ) : null,
			);
		}

		return array(
			'works'   => $works,
			'columns' => $columns,
		);
	}

	/**
	 * Средние итоги таблицы: первичный и вторичный балл по окончательным работам; null — усреднять нечего.
	 * По заданиям средних нет: столбцы заданий внизу остаются пустыми.
	 *
	 * @param list<array<string, mixed>> $list
	 *
	 * @return array{primary: ?float, secondary: ?float}
	 */
	private function footer( array $list ): array {
		$mean  = static fn ( array $values ): ?float => array() === $values ? null : round( array_sum( $values ) / count( $values ), 1 );
		$final = array_values( array_filter( $list, static fn ( array $row ): bool => ! $row['pending'] ) );

		return array(
			'primary'   => $mean( array_map( static fn ( array $row ): float => (float) $row['primary'], $final ) ),
			'secondary' => $mean( array_map( static fn ( array $row ): float => (float) $row['secondary'], array_values( array_filter( $final, static fn ( array $row ): bool => null !== $row['secondary'] ) ) ) ),
		);
	}

	/**
	 * Строки участий проведения под фильтрами: участие, его попытка, последняя запись.
	 *
	 * @return list<array{participation: ExamParticipationDTO, attempt: ?AttemptDTO, last: ?ExamRegistrationDTO, event: ExamEventDTO}>
	 */
	private function participationRows( ExamEventDTO $event, int $sessionId, string $audience ): array {
		$participations = $this->participations->findByEvent( $event->id );
		if ( ExamAudience::Student->value === $audience || ExamAudience::Guest->value === $audience ) {
			$participations = array_filter( $participations, static fn ( ExamParticipationDTO $p ): bool => $p->audience === $audience );
		}

		$byParticipation = array();
		$ids             = array_map( static fn ( ExamParticipationDTO $p ): int => $p->id, $participations );
		foreach ( array() === $ids ? array() : $this->attempts->listByParticipations( $ids ) as $attempt ) {
			$byParticipation[ (int) $attempt->examParticipationId ] = $attempt;
		}

		$rows = array();
		foreach ( $participations as $participation ) {
			$history = $this->registrations->findByParticipation( $participation->id );
			$last    = array() === $history ? null : $history[ array_key_last( $history ) ];
			$attempt = $byParticipation[ $participation->id ] ?? null;

			if ( $sessionId > 0 ) {
				// Сеанс участия: у попытки — сеанс её записи, иначе сеанс последней (в том числе пропущенной) записи.
				$sessionOf = null !== $attempt && null !== $attempt->examRegistrationId
					? $this->registrationSession( $history, $attempt->examRegistrationId )
					: ( $last->sessionId ?? 0 );
				if ( $sessionOf !== $sessionId ) {
					continue;
				}
			}

			$rows[] = array(
				'participation' => $participation,
				'attempt'       => $attempt,
				'last'          => $last,
				'event'         => $event,
			);
		}

		return $rows;
	}

	/**
	 * Находит сеанс записи в уже загруженной истории участия.
	 *
	 * @param ExamRegistrationDTO[] $history
	 */
	private function registrationSession( array $history, int $registrationId ): int {
		foreach ( $history as $registration ) {
			if ( $registration->id === $registrationId ) {
				return $registration->sessionId;
			}
		}

		return 0;
	}

	/**
	 * Считает показатели выбранных участий и определяет совместимость шкал результатов.
	 *
	 * @param list<array{participation: ExamParticipationDTO, attempt: ?AttemptDTO, last: ?ExamRegistrationDTO, event: ExamEventDTO}> $rows
	 *
	 * @return array{kpi: array<string, mixed>, format: ?array<string, mixed>}
	 */
	private function kpi( array $rows ): array {
		$kpi = array(
			'registered'     => 0,
			'started'        => 0,
			'submitted'      => 0,
			'missed'         => 0,
			'pending_review' => 0,
			'avg_primary'    => null,
			'avg_secondary'  => null,
			'avg_grade'      => null,
			'sample'         => 0,
		);

		$direction = null;
		$mixed     = false;
		$format    = null;
		$primary   = array();
		$secondary = array();
		$grades    = array();

		foreach ( $rows as $row ) {
			$attempt = $row['attempt'];
			$last    = $row['last'];

			if ( null !== $attempt || ( null !== $last && in_array( $last->status, array( ExamRegistrationStatus::Confirmed->value, ExamRegistrationStatus::Missed->value ), true ) ) ) {
				++$kpi['registered'];
			}
			if ( null === $attempt ) {
				if ( null !== $last && ExamRegistrationStatus::Missed->value === $last->status ) {
					++$kpi['missed'];
				}
				continue;
			}

			++$kpi['started'];
			if ( AttemptStatus::InProgress === $attempt->status ) {
				continue;
			}
			++$kpi['submitted'];

			$summary = $this->scores->summarize( $attempt, $row['event'] );
			if ( ! empty( $summary['pending'] ) ) {
				++$kpi['pending_review'];
				continue;
			}

			// Шкалы не смешиваются: направление задаёт первая оценённая работа, остальные направления в средние не входят.
			$direction ??= (string) $summary['direction'];
			if ( $direction !== $summary['direction'] ) {
				$mixed = true;
				continue;
			}
			$format  ??= array(
				'direction'     => $summary['direction'],
				'primary_max'   => $summary['primary_max'],
				'secondary_max' => $summary['secondary_max'],
				'grade_max'     => $summary['grade_max'],
				'unit_count'    => $this->formatFor( $row['event'], $attempt )->unitCount ?? 0,
			);
			$primary[] = (float) $summary['primary'];
			if ( ExamDirection::Ege->value === $direction && null !== $summary['secondary'] ) {
				$secondary[] = (float) $summary['secondary'];
			}
			if ( ExamDirection::Oge->value === $direction && null !== $summary['grade'] ) {
				$grades[] = (float) $summary['grade'];
			}
		}

		$kpi['sample'] = count( $primary );
		if ( $kpi['sample'] > 0 ) {
			$kpi['avg_primary']   = $this->avg( $primary );
			$kpi['avg_secondary'] = array() !== $secondary ? $this->avg( $secondary ) : null;
			$kpi['avg_grade']     = array() !== $grades ? $this->avg( $grades ) : null;
		}

		return array(
			'kpi'    => $kpi,
			'format' => null !== $format ? $format + array( 'mixed' => $mixed ) : null,
		);
	}

	/**
	 * Считает среднее непустого списка значений с округлением до одного знака.
	 *
	 * @param list<float> $values
	 */
	private function avg( array $values ): float {
		return round( array_sum( $values ) / count( $values ), 1 );
	}

	/**
	 * Готовит список сеансов проведения с локальными подписями даты и времени.
	 *
	 * @return list<array{id: int, date: string, time_start: string}>
	 */
	private function sessionOptions( ExamEventDTO $event ): array {
		$list = array();
		foreach ( $this->sessions->findByEvent( $event->id ) as $session ) {
			if ( ExamSessionStatus::Cancelled->value === $session->status ) {
				continue;
			}
			$start  = $this->time->toLocal( $session->scheduledAt );
			$list[] = array(
				'id'         => $session->id,
				'date'       => substr( $start, 0, 10 ),
				'time_start' => substr( $start, 11, 5 ),
			);
		}

		return $list;
	}

	/**
	 * Определяет экзаменную шкалу попытки; без попытки возвращает null.
	 */
	private function formatFor( ExamEventDTO $event, ?AttemptDTO $attempt ): ?\Inc\DTO\Exam\ExamFormatDTO {
		if ( null === $attempt ) {
			return null;
		}
		$assessment = $this->assessments->get( $attempt->assessmentId );
		$kind       = AssessmentKind::tryFrom( (string) ( $event->snapshotFor( $attempt->assessmentId )['kind'] ?? '' ) ) ?? $assessment?->kind;

		return null !== $kind ? $this->formats->for( $kind ) : null;
	}

	/**
	 * Задания попытки в виде, который понимает {@see ExamScoreService::units()}: единица, номер, вердикт, баллы.
	 * Вердикт — по правилу 7.2.1 (`WorkDetailService::verdictFor()`), но без сборки условий: статистике условия не нужны.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function taskRows( AttemptDTO $attempt ): array {
		$assessment = $this->assessments->get( $attempt->assessmentId );
		if ( null === $assessment ) {
			return array();
		}

		$keys    = $this->scoringUnits->keysFor( $assessment );
		$answers = array();
		foreach ( $this->answers->listByAttempt( $attempt->id ) as $answer ) {
			$answers[ $answer->taskId ] = $answer;
		}

		$tasks = array();
		foreach ( $assessment->taskIds as $taskId ) {
			$taskId  = (int) $taskId;
			$answer  = $answers[ $taskId ] ?? null;
			$score   = $answer->score ?? ( null === $answer ? 0.0 : null );
			$max     = $answer->maxScore ?? ( null === $answer ? ( $assessment->taskPoints[ $taskId ] ?? null ) : null );
			$isEmpty = '' === trim( (string) ( $answer->answerText ?? '' ) );
			$unitKey = $keys[ $taskId ] ?? 't:' . $taskId;

			$tasks[] = array(
				'task_id'   => $taskId,
				'unit_key'  => $unitKey,
				'number'    => str_starts_with( $unitKey, 'n:' ) ? substr( $unitKey, 2 ) : '',
				'anchor'    => '',
				'verdict'   => null === $answer ? 'unanswered' : $this->verdict( $answer->isCorrect, $score, $max, $isEmpty ),
				'score'     => $score,
				'max_score' => $max,
			);
		}

		return $tasks;
	}

	/**
	 * Разделяет ожидающие проверки, пустые, верные, частичные и неверные ответы.
	 */
	private function verdict( ?bool $isCorrect, ?float $score, ?float $max, bool $isEmpty ): string {
		if ( null === $isCorrect ) {
			return 'pending';
		}
		if ( $isEmpty && ( null === $score || $score <= 0.0 ) ) {
			return 'unanswered';
		}
		if ( null !== $score && null !== $max && $score > 0.0 && $score < $max ) {
			return 'partial';
		}

		return $isCorrect ? 'correct' : 'incorrect';
	}
}
