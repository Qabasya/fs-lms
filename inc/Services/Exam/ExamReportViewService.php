<?php
/**
 * Данные страницы школьного отчёта (этап 12.3): таблица результатов и разбор выбранного участника.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Exam\ExamReportDTO;
use Inc\DTO\Exam\ExamEventDTO;
use Inc\Enums\Exam\ExamAudience;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamReportMemberRepository;

/**
 * Данные страницы школьного отчёта (этап 12.3): таблица результатов и разбор выбранного участника.
 *
 * Состав — только участия отчёта; строка открывается по **порядковому номеру в отчёте** (`row`), а не по ID участия или попытки.
 * Допуск проверяется при **каждом** построении: утрата права включения (отозванное согласие, снятое утверждение), обезличивание или удаление
 * участника превращают строку в заглушку без пересоздания отчёта. В данных нет телефона, мессенджера, ключей, ID и ссылок входа/результата.
 */
class ExamReportViewService {

	public const PLACEHOLDER = 'Данные участника недоступны';

	/** Получает состав и текущий допуск каждой строки; разбор использует общее представление работы. */
	public function __construct(
		private readonly ExamReportMemberRepository $members,
		private readonly ExamParticipationRepository $participations,
		private readonly ExamParticipantRepository $participants,
		private readonly ExamEventRepository $events,
		private readonly AssessmentAttemptRepository $attempts,
		private readonly ExamReportService $reportService,
		private readonly ExamParticipantNameService $names,
		private readonly ExamScoreService $scores,
		private readonly ExamReviewViewService $result,
		private readonly GuestParticipantMaterializer $guestData,
		private readonly ExamStatsService $stats,
	) {}

	/**
	 * Перепроверяет доступ к каждой строке отчёта и готовит статистику и выбранный разбор.
	 *
	 * @param int|null $row Порядковый номер строки отчёта (с 1) для разбора; вне диапазона или заглушка — разбора нет.
	 *
	 * @return array<string, mixed>
	 */
	public function build( ExamReportDTO $report, ?int $row ): array {
		$event = $this->events->find( $report->eventId );
		$ids   = $this->members->listParticipationIds( $report->id );

		$rows       = array();
		$finals     = array();
		$accessible = array();
		foreach ( $ids as $index => $participationId ) {
			$number = $index + 1;
			$entry  = $this->row( $report, $event, $participationId, $number );
			if ( null !== $entry['summary'] && ! empty( $entry['summary']['final'] ) ) {
				$finals[] = $entry['summary'];
			}
			$accessible[ $number ] = $entry['open'];
			unset( $entry['summary'], $entry['scored_units'] );
			$rows[] = $entry;
		}

		$review = null;
		if ( null !== $row && ( $accessible[ $row ] ?? false ) ) {
			$data = $this->result->build( $ids[ $row - 1 ] );
			if ( null !== $data && true === ( $data['revealed'] ?? false ) ) {
				$review = $data + array(
					'row'  => $row,
					'name' => $rows[ $row - 1 ]['name'],
				);
			}
		}

		return array(
			'title'      => $report->title,
			'event'      => $event->title ?? '',
			'expires_at' => $report->expiresAt,
			'rows'       => $rows,
			'stats'      => array(
				'participants' => count( $ids ),
				'average'      => $this->average( $finals ),
				'best'         => $this->extreme( $finals, true ),
				'worst'        => $this->extreme( $finals, false ),
			),
			'review'     => $review,
		);
	}

	/**
	 * Матрица «ученики × задания» для таблицы страницы и CSV-выгрузки: полное ФИО, балл за каждое
	 * задание (пусто — не решали или на проверке), числовые первичный/вторичный баллы и подвал
	 * («Средний» по окончательным результатам, попытки, верные решения, проценты — как в статистике
	 * проведения). Доступ каждой строки перепроверяется тем же правилом, что у страницы.
	 *
	 * @return array{columns: list<string>, participants: int, rows: list<array<string, mixed>>, footer: array{average_primary: string, average_secondary: string, attempts: list<string>, correct: list<string>, attempts_share: list<string>, correct_share: list<string>}}
	 */
	public function matrix( ExamReportDTO $report ): array {
		$event   = $this->events->find( $report->eventId );
		$ids     = $this->members->listParticipationIds( $report->id );
		$numbers = array();
		$rows    = array();
		$finals  = array();

		foreach ( $ids as $index => $participationId ) {
			$entry   = $this->row( $report, $event, $participationId, $index + 1 );
			$summary = $entry['summary'];
			$scored  = $entry['scored_units'];
			unset( $entry['summary'], $entry['scored_units'] );

			$cells = array();
			foreach ( $scored as $unit ) {
				$number             = (string) $unit['number'];
				$numbers[ $number ] = true;

				$answered    = ! in_array( $unit['status'], array( 'unanswered', 'pending' ), true );
				$score       = (float) $unit['score'];
				$cells[ $number ] = array(
					'status' => (string) $unit['status'],
					'score'  => $answered ? $this->number( $score ) : '',
					// Заливка ячейки: есть балл — зелёный, ответ без балла — красный, без ответа — белая.
					'class'  => ! $answered ? '' : ( $score > 0 ? 'is-ok' : 'is-zero' ),
				);
			}

			if ( ! $entry['open'] || null === $summary ) {
				$rows[] = array_merge( $entry, array( 'cells' => array(), 'primary' => '', 'secondary' => '', 'final' => false ) );
				continue;
			}

			if ( ! empty( $summary['final'] ) ) {
				$finals[] = $summary;
			}

			$rows[] = array_merge(
				$entry,
				array(
					'cells'     => $cells,
					'primary'   => $this->number( (float) $summary['primary'] ),
					'secondary' => ! empty( $summary['final'] ) && isset( $summary['secondary'] ) ? $this->number( (float) $summary['secondary'] ) : '',
					'final'     => ! empty( $summary['final'] ),
				)
			);
		}

		$columns = array_map( 'strval', array_keys( $numbers ) );
		usort( $columns, static fn ( string $a, string $b ): int => (int) $a <=> (int) $b );

		// Сводка по заданиям: попытки (дан ответ), верные решения и доли — как в статистике проведения.
		$attempts = array_fill_keys( $columns, 0 );
		$correct  = array_fill_keys( $columns, 0 );
		foreach ( $rows as $row ) {
			foreach ( $columns as $number ) {
				$cell = $row['cells'][ $number ] ?? null;
				if ( null === $cell || '' === $cell['score'] ) {
					continue;
				}
				++$attempts[ $number ];
				if ( 'correct' === $cell['status'] ) {
					++$correct[ $number ];
				}
			}
		}

		$participants = count( $ids );
		$share        = static fn ( int $part, int $whole ): string => 0 === $whole ? '' : (string) (int) round( 100 * $part / $whole );
		$average      = function ( callable $pick ) use ( $finals ): string {
			$values = array_values(
				array_filter(
					array_map(
						static function ( array $summary ) use ( $pick ): ?float {
							$value = $pick( $summary );

							return null !== $value ? (float) $value : null;
						},
						$finals
					),
					static fn( ?float $value ): bool => null !== $value
				)
			);

			return array() === $values ? '' : $this->number( array_sum( $values ) / count( $values ) );
		};

		$footer = array(
			'average_primary'   => $average( static fn( array $summary ) => $summary['primary'] ?? null ),
			'average_secondary' => $average( static fn( array $summary ) => $summary['secondary'] ?? null ),
			'attempts'          => array(),
			'correct'           => array(),
			'attempts_share'    => array(),
			'correct_share'     => array(),
		);
		foreach ( $columns as $number ) {
			$footer['attempts'][]       = (string) $attempts[ $number ];
			$footer['correct'][]        = (string) $correct[ $number ];
			$footer['attempts_share'][] = $share( $attempts[ $number ], $participants );
			$footer['correct_share'][]  = $share( $correct[ $number ], $attempts[ $number ] );
		}

		return array(
			'columns'      => $columns,
			'participants' => $participants,
			'rows'         => $rows,
			'footer'       => $footer,
		);
	}

	/**
	 * Строка таблицы. Заглушка, если участие больше нельзя показывать школе.
	 *
	 * @return array{n: int, name: string, short_name: string, primary: string, secondary: string, units: list<array{number: string, status: string}>, scored_units: list<array{number: string, status: string, score: float, max: float}>, open: bool, summary: array<string, mixed>|null}
	 */
	private function row( ExamReportDTO $report, ?ExamEventDTO $event, int $participationId, int $number ): array {
		$participation = $this->participations->find( $participationId );
		$participant   = null !== $participation ? $this->participants->find( $participation->participantId ) : null;
		$placeholder   = array(
			'n'            => $number,
			'name'         => self::PLACEHOLDER,
			'short_name'   => self::PLACEHOLDER,
			'primary'      => '',
			'secondary'    => '',
			'units'        => array(),
			'scored_units' => array(),
			'open'         => false,
			'summary'      => null,
		);

		if ( null === $participation || null === $participant || null !== $participant->anonymizedAt || null !== $this->reportService->canInclude( $participation, $report->eventId ) ) {
			return $placeholder;
		}

		$attempt = null !== $participation->currentAttemptId ? $this->attempts->find( $participation->currentAttemptId ) : null;
		if ( null === $event || null === $attempt ) {
			return $placeholder;
		}

		$summary = $this->scores->summarize( $attempt, $event );
		$name    = ExamAudience::Guest->value === $participation->audience
			? ( $this->guestData->displayName( $participant ) ?? self::PLACEHOLDER )
			: $this->names->participantName( $participant );

		return array(
			'n'            => $number,
			'name'         => $name,
			// Для таблицы на странице — фамилия и инициалы; полное ФИО остаётся в `name` (CSV пишет его).
			'short_name'   => self::PLACEHOLDER === $name ? $name : $this->shortName( $name ),
			'primary'      => sprintf( '%s из %s', $summary['primary'], $summary['primary_max'] ),
			'secondary'    => $this->secondaryText( $summary ),
			'units'        => $this->stats->attemptUnits( $attempt ),
			'scored_units' => $this->stats->scoredAttemptUnits( $attempt ),
			'open'         => true,
			'summary'      => $summary,
		);
	}

	/** Фамилия + инициалы («Иванов А.С.»); одно слово — как есть. */
	private function shortName( string $name ): string {
		$parts = preg_split( '/\s+/u', trim( $name ) );

		if ( false === $parts || count( $parts ) < 2 ) {
			return $name;
		}

		$surname  = (string) array_shift( $parts );
		$initials = array_map(
			static fn( string $part ): string => mb_strtoupper( mb_substr( $part, 0, 1 ) ) . '.',
			$parts
		);

		return $surname . ' ' . implode( '', $initials );
	}

	/**
	 * Вторичный балл строки: ЕГЭ — «{балл} из {макс}», ОГЭ — отметка; пока идёт ручная проверка — «Проверяется».
	 *
	 * @param array<string, mixed> $summary Результат {@see ExamScoreService::summarize()}.
	 */
	private function secondaryText( array $summary ): string {
		if ( empty( $summary['final'] ) ) {
			return 'Проверяется';
		}
		if ( null !== ( $summary['secondary'] ?? null ) ) {
			return sprintf( '%s из %s', $summary['secondary'], $summary['secondary_max'] );
		}

		return null !== ( $summary['grade'] ?? null ) ? sprintf( 'отметка %s', $summary['grade'] ) : '—';
	}

	/**
	 * Зачётные значения окончательных результатов: ЕГЭ — вторичный балл, ОГЭ — первичный. Шкалы не смешиваются —
	 * разные направления дают пустой список.
	 *
	 * @param list<array<string, mixed>> $finals
	 *
	 * @return list<float>
	 */
	private function values( array $finals ): array {
		$directions = array_unique( array_map( static fn ( array $s ): string => (string) $s['direction'], $finals ) );
		if ( 1 !== count( $directions ) ) {
			return array();
		}

		return array_map( static fn ( array $s ): float => (float) ( 'ege' === $s['direction'] ? ( $s['secondary'] ?? $s['primary'] ) : $s['primary'] ), $finals );
	}

	/**
	 * Средний балл по окончательным результатам.
	 *
	 * @param list<array<string, mixed>> $finals
	 */
	private function average( array $finals ): string {
		$values = $this->values( $finals );

		return array() === $values ? '—' : $this->number( array_sum( $values ) / count( $values ) );
	}

	/**
	 * Лучший или худший окончательный результат.
	 *
	 * @param list<array<string, mixed>> $finals
	 */
	private function extreme( array $finals, bool $best ): string {
		$values = $this->values( $finals );
		if ( array() === $values ) {
			return '—';
		}

		return $this->number( $best ? max( $values ) : min( $values ) );
	}

	private function number( float $value ): string {
		return rtrim( rtrim( number_format( $value, 1, '.', '' ), '0' ), '.' );
	}
}
