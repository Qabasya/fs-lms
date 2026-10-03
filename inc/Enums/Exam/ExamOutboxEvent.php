<?php declare( strict_types=1 );

namespace Inc\Enums\Exam;

enum ExamOutboxEvent: string {
	case EventPublished = 'event_published';
	case RegistrationOpened = 'registration_opened';
	case RegistrationConfirmed = 'registration_confirmed';
	case RegistrationTransferred = 'registration_transferred';
	case RegistrationCancelled = 'registration_cancelled';
	case ParticipantMissed = 'participant_missed';
	case EntryOpened = 'entry_opened';
	case AttemptStarted = 'attempt_started';
	case AttemptSubmitted = 'attempt_submitted';
	case AttemptApproved = 'attempt_approved';
	case ResultCorrected = 'result_corrected';
	case AttemptExtended = 'attempt_extended';
	case SessionMoved = 'session_moved';
	case SessionCancelled = 'session_cancelled';
	case EventCancelled = 'event_cancelled';
	case PaidNeedsResolution = 'paid_needs_resolution';
	case ReconcileFailed = 'reconcile_failed';
	case SourceLimitExceeded = 'source_limit_exceeded';

	public function label(): string {
		return match ( $this ) {
			self::EventPublished => 'Проведение опубликовано',
			self::RegistrationOpened => 'Запись открыта',
			self::RegistrationConfirmed => 'Запись подтверждена',
			self::RegistrationTransferred => 'Запись перенесена',
			self::RegistrationCancelled => 'Запись отменена',
			self::ParticipantMissed => 'Участник не явился',
			self::EntryOpened => 'Вход открыт',
			self::AttemptStarted => 'Попытка начата',
			self::AttemptSubmitted => 'Попытка сдана',
			self::AttemptApproved => 'Попытка одобрена',
			self::ResultCorrected => 'Результат исправлен',
			self::AttemptExtended => 'Попытка продлена',
			self::SessionMoved => 'Сеанс перенесён',
			self::SessionCancelled => 'Сеанс отменён',
			self::EventCancelled => 'Проведение отменено',
			self::PaidNeedsResolution => 'Требуется помощь с оплатой',
			self::ReconcileFailed => 'Сверка не удалась',
			self::SourceLimitExceeded => 'Лимит источника превышен',
		};
	}
}
