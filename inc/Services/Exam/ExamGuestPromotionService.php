<?php
/**
 * Гость экзамена становится учеником: его результаты переходят в кабинет ученика.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\Enums\Exam\ExamLogAction;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Shared\PluginLogger;

/**
 * Перевод результатов пробного экзамена к новому ученику (вызывается при зачислении, {@see \Inc\Services\Enrollment\EnrollmentService}).
 *
 * Участник-гость привязывается к карточке ученика (`exam_participants.person_id`), а его участия становятся ученическими:
 * дальше кабинет, «Мои экзамены» и разбор работают как у любого нашего ученика. Если запись участника у ученика уже была
 * (он успел записаться на экзамен как «наш»), участия гостя переносятся к ней. Личные данные гостя стираются — они в карточке.
 * Попытки получают ученика, а уже оценённые без ручной проверки утверждаются: гость видел их сразу, у ученика результат
 * раскрывается только после утверждения.
 *
 * Шаги идемпотентны: повторный вызов ничего не ломает. Сбой не должен срывать зачисление — вызывающий код его только журналирует.
 */
class ExamGuestPromotionService {

	/** Получает участников, участия, попытки и часы для отметки времени. */
	public function __construct(
		private readonly ExamParticipantRepository $participants,
		private readonly ExamParticipationRepository $participations,
		private readonly AssessmentAttemptRepository $attempts,
		private readonly ExamTime $time,
	) {}

	/**
	 * @param int $guestParticipantId Участник-гость, по чьей ссылке подана заявка
	 * @param int $personId           Карточка нового ученика
	 * @param int $actorUserId        Сотрудник, выполняющий зачисление (автор утверждения)
	 *
	 * @return bool true — результаты переведены; false — привязывать нечего (участник не гость, обезличен или не найден)
	 */
	public function promote( int $guestParticipantId, int $personId, int $actorUserId ): bool {
		$guest = $this->participants->find( $guestParticipantId );
		if ( null === $guest || null !== $guest->personId || null !== $guest->anonymizedAt ) {
			return false;
		}

		$nowUtc = $this->time->nowUtc();

		$existing      = $this->participants->findByPersonId( $personId );
		$participantId = $guest->id;
		if ( null !== $existing ) {
			// У ученика уже есть участник: гостевые участия переезжают к нему, пустой участник-гость удаляется.
			$this->participations->moveToParticipant( $guest->id, $existing->id );
			$this->participants->deleteWithoutParticipations( $guest->id );
			$participantId = $existing->id;
		} elseif ( ! $this->participants->linkToPerson( $guest->id, $personId, $nowUtc ) ) {
			return false;
		}

		$this->participations->makeStudentAudience( $participantId, $nowUtc );
		$this->attempts->assignStudentToParticipantAttempts( $participantId, $personId );
		$approved = $this->attempts->approveRevealedOfParticipant( $participantId, $actorUserId, $this->time->nowLocal() );

		PluginLogger::debug( 'ExamGuestPromotion', 'Результаты гостя переведены к ученику', array( 'participant_id' => $participantId, 'person_id' => $personId, 'approved' => $approved ) );
		ExamLogAction::GuestPromoted->record(
			array(
				'actor'   => $actorUserId,
				'context' => array( 'participant_id' => $participantId, 'person_id' => $personId, 'merged' => null !== $existing, 'approved' => $approved ),
			)
		);

		return true;
	}
}
