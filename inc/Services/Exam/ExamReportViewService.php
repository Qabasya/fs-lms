<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Exam\ExamReportDTO;
use Inc\Enums\Exam\ExamAudience;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamReportMemberRepository;

/**
 * Данные страницы школьного отчёта (этап 12.3): таблица результатов и разбор выбранного участника.
 *
 * Состав — только участия отчёта; строка открывается по **порядковому номеру в отчёте** (`p`), а не по ID участия или попытки.
 * Допуск проверяется при **каждом** построении: утрата права включения (отозванное согласие, снятое утверждение), обезличивание или удаление
 * участника превращают строку в заглушку без пересоздания отчёта. В данных нет телефона, мессенджера, ключей, ID и ссылок входа/результата.
 */
class ExamReportViewService {

	public const PLACEHOLDER = 'Данные участника недоступны';

	public function __construct(
		private readonly ExamReportMemberRepository $members,
		private readonly ExamParticipationRepository $participations,
		private readonly ExamParticipantRepository $participants,
		private readonly ExamEventRepository $events,
		private readonly AssessmentAttemptRepository $attempts,
		private readonly ExamReportService $reportService,
		private readonly ExamConductService $conduct,
		private readonly ExamScoreService $scores,
		private readonly GuestResultViewService $result,
		private readonly GuestParticipantMaterializer $guestData,
	) {}

	/**
	 * @param int|null $row Порядковый номер строки отчёта (с 1) для разбора; вне диапазона или заглушка — разбора нет.
	 *
	 * @return array<string, mixed>
	 */
	public function build( ExamReportDTO $report, ?int $row ): array {
		$event = $this->events->find( $report->eventId );
		$ids   = $this->members->listParticipationIds( $report->id );

		$rows       = array();
		$finals     = array();
		$pending    = 0;
		$accessible = array();
		foreach ( $ids as $index => $participationId ) {
			$number = $index + 1;
			$entry  = $this->row( $report, $participationId, $number );
			if ( null !== $entry['summary'] ) {
				if ( empty( $entry['summary']['final'] ) ) {
					++$pending;
				} else {
					$finals[] = $entry['summary'];
				}
			}
			$accessible[ $number ] = $entry['open'];
			unset( $entry['summary'] );
			$rows[] = $entry;
		}

		$review = null;
		if ( null !== $row && ( $accessible[ $row ] ?? false ) ) {
			$data = $this->result->build( $ids[ $row - 1 ] );
			if ( null !== $data && true === ( $data['revealed'] ?? false ) ) {
				$review = $data + array( 'row' => $row, 'name' => $rows[ $row - 1 ]['name'] );
			}
		}

		return array(
			'title'      => $report->title,
			'event'      => $event->title ?? '',
			'expires_at' => $report->expiresAt,
			'rows'       => $rows,
			'stats'      => array( 'participants' => count( $ids ), 'average' => $this->average( $finals ), 'pending' => $pending ),
			'review'     => $review,
		);
	}

	/**
	 * Строка таблицы. Заглушка, если участие больше нельзя показывать школе.
	 *
	 * @return array{n: int, name: string, status: string, caption: string, open: bool, summary: array<string, mixed>|null}
	 */
	private function row( ExamReportDTO $report, int $participationId, int $number ): array {
		$participation = $this->participations->find( $participationId );
		$participant   = null !== $participation ? $this->participants->find( $participation->participantId ) : null;
		$placeholder   = array( 'n' => $number, 'name' => self::PLACEHOLDER, 'status' => '', 'caption' => '', 'open' => false, 'summary' => null );

		if ( null === $participation || null === $participant || null !== $participant->anonymizedAt || null !== $this->reportService->canInclude( $participation, $report->eventId ) ) {
			return $placeholder;
		}

		$event   = $this->events->find( $report->eventId );
		$attempt = null !== $participation->currentAttemptId ? $this->attempts->find( $participation->currentAttemptId ) : null;
		if ( null === $event || null === $attempt ) {
			return $placeholder;
		}

		$summary = $this->scores->summarize( $attempt, $event );
		$name    = ExamAudience::Guest->value === $participation->audience
			? ( $this->guestData->displayName( $participant ) ?? self::PLACEHOLDER )
			: $this->conduct->participantName( $participant );

		return array(
			'n'       => $number,
			'name'    => $name,
			'status'  => empty( $summary['final'] ) ? 'Проверяется' : 'Проверено',
			'caption' => $this->scores->caption( $summary ),
			'open'    => true,
			'summary' => $summary,
		);
	}

	/**
	 * Средний итог по окончательным результатам: ЕГЭ — вторичный балл, ОГЭ — первичный. Шкалы не смешиваются — разные направления дают «—».
	 *
	 * @param list<array<string, mixed>> $finals
	 */
	private function average( array $finals ): string {
		if ( array() === $finals ) {
			return '—';
		}
		$directions = array_unique( array_map( static fn ( array $s ): string => (string) $s['direction'], $finals ) );
		if ( 1 !== count( $directions ) ) {
			return '—';
		}

		$values = array_map( static fn ( array $s ): float => (float) ( 'ege' === $s['direction'] ? ( $s['secondary'] ?? $s['primary'] ) : $s['primary'] ), $finals );
		$mean   = array_sum( $values ) / count( $values );

		return rtrim( rtrim( number_format( $mean, 1, '.', '' ), '0' ), '.' );
	}
}
