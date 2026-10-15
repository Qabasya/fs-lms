<?php
/**
 * Закреплённые (важные) уведомления кабинета ученика.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Profile;

use Inc\DTO\Profile\NotificationDTO;
use Inc\Enums\Access\UserRole;
use Inc\Enums\Profile\NotificationType;
use Inc\Managers\Person\UserManager;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\NotificationRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Services\Exam\ExamTime;

/**
 * Закреплённые уведомления: показываются плашкой сверху экрана, пока действие не выполнено.
 *
 * Состояние «выполнено» вычисляется на чтении по данным домена, а не хранится в уведомлении:
 * - «Дедлайн близко» / «Дедлайн пропущен» — пока у ученика нет сдачи этой работы в этом занятии;
 * - «Открыта запись на экзамен» — пока у ученика нет действующей записи на это проведение
 *   (и пока проведение опубликовано, а запись не закрыта).
 * Прочитанность плитки в колокольчике на закрепление не влияет. Закрепление — только для учеников:
 * родителям те же плитки приходят обычной лентой.
 */
class PinnedNotificationService {

	/** Типы, которые закрепляются. */
	private const TYPES = array(
		NotificationType::DeadlineSoon,
		NotificationType::DeadlineMissed,
		NotificationType::ExamRegistrationOpened,
	);

	/** Старше этого срока (дней) плитка не закрепляется, даже если действие не выполнено. */
	private const MAX_AGE_DAYS = 14;

	/** Получает репозитории уведомлений и данные, по которым определяется выполнение действия. */
	public function __construct(
		private readonly NotificationRepository $notifications,
		private readonly NotificationService $service,
		private readonly UserManager $users,
		private readonly PersonRepository $persons,
		private readonly SubmissionRepository $submissions,
		private readonly ExamEventRepository $events,
		private readonly ExamParticipationRepository $participations,
		private readonly ExamTime $time,
	) {}

	/** Значения типов, которые закрепляются (клиент не показывает по ним тост). @return string[] */
	public static function typeValues(): array {
		return array_map( static fn( NotificationType $t ): string => $t->value, self::TYPES );
	}

	/**
	 * Невыполненные важные уведомления ученика, свежие сверху.
	 *
	 * @return array<int, array<string, mixed>> Элементы в формате {@see NotificationService::toClientArray()}.
	 */
	public function forUser( int $userId ): array {
		$user = $this->users->find( $userId );
		if ( null === $user || ! in_array( UserRole::FSStudent->value, (array) $user->roles, true ) ) {
			return array();
		}

		$person = $this->persons->findByWpUserId( $userId );
		if ( null === $person ) {
			return array();
		}

		$rows = $this->notifications->listByTypes( $userId, self::typeValues(), self::MAX_AGE_DAYS );

		// Пропущенный срок вытесняет «скоро срок» по той же работе.
		$missed = array();
		foreach ( $rows as $row ) {
			if ( NotificationType::DeadlineMissed === $row->type ) {
				$missed[ $this->deadlineKey( $row ) ] = true;
			}
		}

		$pinned = array();
		foreach ( $rows as $row ) {
			if ( NotificationType::DeadlineSoon === $row->type && isset( $missed[ $this->deadlineKey( $row ) ] ) ) {
				continue;
			}
			if ( $this->isDone( $row, $person->id ) ) {
				continue;
			}
			$pinned[] = $this->service->toClientArray( $row );
		}

		return $pinned;
	}

	/** Ключ «занятие:работа» плитки дедлайна; пусто, если работа в плитке не указана. */
	private function deadlineKey( NotificationDTO $row ): string {
		return ( $row->entityId ?? 0 ) . ':' . (int) ( $row->payload['work_id'] ?? 0 );
	}

	/** Действие по плитке выполнено (или плитка потеряла смысл) — закреплять не нужно. */
	private function isDone( NotificationDTO $row, int $personId ): bool {
		if ( NotificationType::ExamRegistrationOpened === $row->type ) {
			return $this->examRegistrationDone( $row, $personId );
		}

		$workId   = (int) ( $row->payload['work_id'] ?? 0 );
		$lessonId = (int) ( $row->entityId ?? 0 );
		if ( $workId <= 0 || $lessonId <= 0 ) {
			// Плитка без привязки к работе (создана до закрепления): определить выполнение нечем.
			return true;
		}

		foreach ( $this->submissions->listByStudentAndGroupLesson( $personId, $lessonId ) as $submission ) {
			if ( $submission->workId === $workId ) {
				return true;
			}
		}

		return false;
	}

	/** Запись на экзамен оформлена, либо проведение снято с публикации / запись закрыта. */
	private function examRegistrationDone( NotificationDTO $row, int $personId ): bool {
		$eventId = (int) ( $row->entityId ?? 0 );
		$event   = $eventId > 0 ? $this->events->find( $eventId ) : null;
		if ( null === $event || 'published' !== $event->status ) {
			return true;
		}

		$closes = $event->registrationClosesAt;
		if ( null !== $closes && $closes <= $this->time->nowUtc() ) {
			return true;
		}

		$participation = $this->participations->findByEventAndPerson( $eventId, $personId );

		return null !== $participation && null !== $participation->activeRegistrationId;
	}
}
