<?php

declare( strict_types=1 );

namespace Inc\Services\Log\Pages;

use Inc\Contracts\LogPageProviderInterface;
use Inc\DTO\Log\LogPageQueryDTO;
use Inc\Enums\Access\UserRole;
use Inc\Enums\Log\LogChannel;
use Inc\Managers\Person\UserManager;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Services\Exam\ExamTime;
use Inc\Repositories\WPDBRepositories\Log\ExamLogRepository;
use Inc\Shared\Traits\Sanitizer;

/**
 * Вкладка «Экзамены» — действия с проведениями, сеансами, заявками и попытками.
 *
 * @package Inc\Services\Log\Pages
 */
readonly class ExamLogPageProvider implements LogPageProviderInterface {

	use Sanitizer;

	public function __construct(
		private ExamLogRepository $repository,
		private ExamEventRepository $events,
		private ExamSessionRepository $sessions,
		private UserManager $users,
		private ExamTime $time,
	) {}

	public function channel(): LogChannel {
		return LogChannel::Exams;
	}

	/**
	 * @param LogPageQueryDTO $query Общий контекст страницы
	 *
	 * @return array<string, mixed>
	 */
	public function data( LogPageQueryDTO $query ): array {
		$filters = array_filter(
			array_merge(
				array(
					'action'          => $this->sanitizeGetKey( 'action' ),
					'result'          => $this->sanitizeGetKey( 'result' ),
					'event_id'        => $this->sanitizeGetInt( 'event_id' ),
					'session_id'      => $this->sanitizeGetInt( 'session_id' ),
					'registration_id' => $this->sanitizeGetInt( 'registration_id' ),
					'person'          => $this->sanitizeGetText( 'person' ),
				),
				$query->dateFilters()
			)
		);

		$rows = $this->repository->list( $filters, $query->page, $query->perPage, $query->orderby, $query->order );

		return array(
			'exam_filters' => $filters,
			'exam_page'    => $query->page,
			'exam_total'   => $this->repository->countFiltered( $filters ),
			'exam_rows'    => $rows,
			'exam_actions' => $this->repository->distinctActions(),
			'exam_names'   => $this->names( $rows ),
		);
	}

	/**
	 * Читаемые подписи для столбцов «Кто» и «Объект»: названия проведений, даты сеансов, роль ученика/родителя.
	 *
	 * @param \Inc\DTO\Log\ExamLogDTO[] $rows Строки страницы
	 *
	 * @return array{events: array<int, string>, sessions: array<int, string>, actors: array<int, string>}
	 */
	private function names( array $rows ): array {
		$names = array( 'events' => array(), 'sessions' => array(), 'actors' => array() );
		foreach ( $rows as $row ) {
			if ( null !== $row->eventId && ! isset( $names['events'][ $row->eventId ] ) ) {
				$names['events'][ $row->eventId ] = $this->events->find( $row->eventId )?->title ?? '';
			}
			if ( null !== $row->sessionId && ! isset( $names['sessions'][ $row->sessionId ] ) ) {
				$session = $this->sessions->find( $row->sessionId );
				$names['sessions'][ $row->sessionId ] = null !== $session ? $this->time->toLocal( $session->scheduledAt ) : '';
			}
			if ( 'user' === $row->actorType && null !== $row->actorUserId && ! isset( $names['actors'][ $row->actorUserId ] ) ) {
				$roles = (array) ( $this->users->find( $row->actorUserId )?->roles ?? array() );
				$names['actors'][ $row->actorUserId ] = in_array( UserRole::FSStudent->value, $roles, true )
					? 'Ученик'
					: ( in_array( UserRole::FSParent->value, $roles, true ) ? 'Родитель' : '' );
			}
		}

		return $names;
	}
}
