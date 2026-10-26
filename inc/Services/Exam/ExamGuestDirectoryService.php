<?php
/**
 * Список гостей экзамена для админки («Пользователи» → «Гости экзамена»).
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Exam\ExamParticipantDTO;
use Inc\Enums\Exam\ExamRegistrationStatus;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;

/**
 * Готовые к показу строки гостей: данные формы записи (ФИО, телефон, мессенджер, школа, класс) и сеанс, на который гость записан.
 *
 * Расшифровка — только через {@see GuestParticipantMaterializer}; обезличенный гость показывается без личных данных.
 * Доступ к экрану проверяет вызывающий шаблон.
 */
class ExamGuestDirectoryService {

	/** Получает выборку гостей, расшифровку и перевод времени. */
	public function __construct(
		private readonly ExamParticipantRepository $participants,
		private readonly GuestParticipantMaterializer $guestData,
		private readonly ExamTime $time,
		private readonly ApplyPrefillLinkService $applyLinks,
	) {}

	/**
	 * Страница списка.
	 *
	 * @param array{event_id?: int, session_id?: int, school?: string} $filters Фильтры.
	 * @param string                                                 $orderby `name` или `session`.
	 * @param string                                                 $order   `asc` или `desc`.
	 * @return array{rows: list<array<string, string>>, total: int}
	 */
	public function page( int $page, int $perPage, array $filters = array(), string $orderby = '', string $order = 'asc' ): array {
		$rows = array();
		foreach ( $this->participants->listGuestRows( $filters ) as $row ) {
			$rows[] = $this->present( $row );
		}

		// ФИО в базе зашифровано, поэтому сортировка и страницы — здесь.
		if ( 'name' === $orderby || 'session' === $orderby ) {
			$key = 'name' === $orderby ? 'name' : 'session_utc';
			usort(
				$rows,
				static function ( array $a, array $b ) use ( $key, $order ): int {
					// Без сеанса — в конец при любом направлении.
					if ( 'session_utc' === $key && ( '' === $a[ $key ] ) !== ( '' === $b[ $key ] ) ) {
						return '' === $a[ $key ] ? 1 : -1;
					}
					$cmp = mb_strtolower( $a[ $key ] ) <=> mb_strtolower( $b[ $key ] );

					return 'desc' === $order ? -$cmp : $cmp;
				}
			);
		}

		return array(
			'rows'  => array_slice( $rows, max( 0, $page - 1 ) * $perPage, $perPage ),
			'total' => count( $rows ),
		);
	}

	/**
	 * Словари фильтров: проведения, сеансы, школы.
	 *
	 * @return array{events: array<int, string>, sessions: array<int, string>, schools: array<string, string>}
	 */
	public function filterOptions(): array {
		$events   = array();
		$sessions = array();
		foreach ( $this->participants->guestSessionOptions() as $row ) {
			$events[ (int) $row['event_id'] ] = (string) $row['event_title'];
			if ( isset( $row['session_id'] ) ) {
				$sessions[ (int) $row['session_id'] ] = $this->time->toLocal( (string) $row['scheduled_at'] ) . ' · ' . (string) $row['event_title'];
			}
		}
		$schools = array();
		foreach ( $this->participants->guestSchools() as $school ) {
			$schools[ $school ] = $school;
		}

		return array( 'events' => $events, 'sessions' => $sessions, 'schools' => $schools );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, string>
	 */
	private function present( array $row ): array {
		$participant = ExamParticipantDTO::fromArray( $row );
		$anonymized  = null !== $participant->anonymizedAt;
		$contacts    = array( 'phone' => '', 'messenger' => '' );
		if ( ! $anonymized ) {
			try {
				$contacts = $this->guestData->decryptContacts( $participant );
			} catch ( \Throwable ) {
				$contacts = array( 'phone' => '', 'messenger' => '' );
			}
		}

		$status = ExamRegistrationStatus::tryFrom( (string) ( $row['registration_status'] ?? '' ) );

		return array(
			// Отметка «Запись подтверждена» — та же, что в меню строки гостя на доске проведения (`exam_participations.staff_confirmed_at`).
			'participation_id' => (string) ( $row['participation_id'] ?? '' ),
			'session_id' => (string) ( $row['session_id'] ?? '' ),
			'notified'   => ! empty( $row['staff_notified_at'] ) ? '1' : '0',
			'can_notify' => ! $anonymized && isset( $row['participation_id'] ) ? '1' : '0',
			'confirmed'  => ! empty( $row['staff_confirmed_at'] ) ? '1' : '0',
			'can_confirm' => ! $anonymized && isset( $row['participation_id'], $row['session_id'] ) && ExamRegistrationStatus::Confirmed === $status ? '1' : '0',
			'apply_link' => $anonymized ? '' : $this->applyLinks->issue( $participant->id ),
			'name'       => $anonymized ? 'Обезличен' : $this->guestData->decryptName( $participant ),
			'phone'      => $contacts['phone'],
			'messenger'  => $contacts['messenger'],
			'school'     => (string) ( $participant->schoolName ?? '' ),
			'grade'      => null !== $participant->grade ? (string) $participant->grade : '',
			'created_at' => $this->time->toLocal( $participant->createdAt ),
			'event'      => (string) ( $row['event_title'] ?? '' ),
			'session'    => isset( $row['scheduled_at'] ) ? $this->time->toLocal( (string) $row['scheduled_at'] ) : '',
			'session_utc' => (string) ( $row['scheduled_at'] ?? '' ),
			'status'     => null !== $status ? $status->label() : '',
		);
	}
}
