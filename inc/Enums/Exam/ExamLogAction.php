<?php

declare( strict_types=1 );

namespace Inc\Enums\Exam;

/**
 * Действия журнала «Экзамены» ({@see \Inc\Enums\Log\LogChannel::Exams}).
 *
 * Значение кейса хранится в `exam_log.action`; менять его нельзя без
 * data-миграции — по нему фильтруется журнал и читаются старые записи.
 */
enum ExamLogAction: string {

	case EventCreated = 'event_created';
	case EventUpdated = 'event_updated';
	case EventPublished = 'event_published';
	case EventCancelled = 'event_cancelled';
	case EventDeleted = 'event_deleted';
	case SnapshotRebuilt = 'snapshot_rebuilt';
	case SessionCreated = 'session_created';
	case SessionUpdated = 'session_updated';
	case SessionMoved = 'session_moved';
	case SessionCancelled = 'session_cancelled';
	case SessionDeleted = 'session_deleted';
	case Registered = 'registered';
	case RegistrationChanged = 'registration_changed';
	case RegistrationCancelledSelf = 'registration_cancelled_self';
	case RegistrationCancelledStaff = 'registration_cancelled_staff';
	case RegistrationDeleted = 'registration_deleted';
	case RegistrationTransferred = 'registration_transferred';
	case NoShow = 'no_show';
	case Arrival = 'arrival';
	case ApplicationCaptured = 'application_captured';
	case ApplicationConverted = 'application_converted';
	case ApplicationAccepted = 'application_accepted';
	case ApplicationResolved = 'application_resolved';
	case ApplicationReleased = 'application_released';
	case SourceSaved = 'source_saved';
	case SourceLinkIssued = 'source_link_issued';
	case SourceLinkReissued = 'source_link_reissued';
	case SourceLinkRevoked = 'source_link_revoked';
	case SourceToggled = 'source_toggled';
	case SourceDeleted = 'source_deleted';
	case GuestDeleted = 'guest_deleted';
	case PublicRegistrationToggled = 'public_registration_toggled';
	case FormOpened = 'form_opened';
	case FormInvalid = 'form_invalid';
	case FormLimited = 'form_limited';
	case Admitted = 'admitted';
	case EntryLinkIssued = 'entry_link_issued';
	case ResultLinkIssued = 'result_link_issued';
	case ResultLinkRevoked = 'result_link_revoked';
	case LinkMarkedPassed = 'link_marked_passed';
	case AttemptStarted = 'attempt_started';
	case AttemptSubmitted = 'attempt_submitted';
	case AttemptExpired = 'attempt_expired';
	case AttemptExtended = 'attempt_extended';
	case AttemptTechError = 'attempt_tech_error';
	case AttemptGraded = 'attempt_graded';
	case AttemptApproved = 'attempt_approved';
	case AttemptCorrected = 'attempt_corrected';
	case ReportCreated = 'report_created';
	case ReportMemberChanged = 'report_member_changed';
	case ReportLinkIssued = 'report_link_issued';
	case ReportRevoked = 'report_revoked';
	case PaymentReceived = 'payment_received';
	/** Хук, на который подписан журнал «Экзамены» ({@see \Inc\Controllers\Subscribers\ExamLogController}). */
	public const HOOK = 'fs_lms_exam_logged';

	/**
	 * Сообщает о действии подписчику журнала «Экзамены» ({@see self::HOOK}).
	 *
	 * Вызывать строго после успешного коммита транзакции действия (вне блокировок),
	 * чтобы журнал не удлинял транзакцию и не показывал несостоявшееся. Подписчик сам
	 * изолирует сбой записи — действие пользователя журнал не ломает.
	 *
	 * Ключи `$data` (все необязательны):
	 * - `event_id`, `session_id`, `registration_id`, `attempt_id`, `participation_id`, `participant_id` — объект
	 *   действия; недостающие связи и ФИО участника писатель достраивает по ним сам;
	 * - `actor` — ID пользователя WP; `actor_type` — `user`|`guest`|`system` (по умолчанию `user`
	 *   при `actor`, иначе `system`); `actor_label` — подпись актора, если не пользователь;
	 * - `result` — `success` (по умолчанию) | `failure`;
	 * - `subject` — ФИО участника, если уже известно;
	 * - `context` — произвольные данные (причина, код ошибки, было/стало).
	 *
	 * @param array<string, mixed> $data Данные действия
	 */
	public function record( array $data = array() ): void {
		do_action( self::HOOK, $this, $data );
	}

	/** Подпись для таблицы и фильтра журнала. */
	public function label(): string {
		return match ( $this ) {
			self::EventCreated => 'Проведение: создано',
			self::EventUpdated => 'Проведение: изменено',
			self::EventPublished => 'Проведение: опубликовано',
			self::EventCancelled => 'Проведение: отменено',
			self::EventDeleted => 'Проведение: удалено',
			self::SnapshotRebuilt => 'Проведение: снимок варианта пересобран',
			self::SessionCreated => 'Сеанс: создан',
			self::SessionUpdated => 'Сеанс: изменён',
			self::SessionMoved => 'Сеанс: перенесён',
			self::SessionCancelled => 'Сеанс: отменён',
			self::SessionDeleted => 'Сеанс: удалён',
			self::Registered => 'Заявка: запись оформлена',
			self::RegistrationChanged => 'Заявка: перенос на другой сеанс (участник)',
			self::RegistrationCancelledSelf => 'Заявка: отмена участником',
			self::RegistrationCancelledStaff => 'Заявка: отмена сотрудником',
			self::RegistrationDeleted => 'Запись: отменённая запись удалена',
			self::RegistrationTransferred => 'Заявка: перенос сотрудником',
			self::NoShow => 'Заявка: неявка',
			self::Arrival => 'Заявка: отметка о прибытии',
			self::ApplicationCaptured => 'Гость: бронь места',
			self::ApplicationConverted => 'Гость: бронь превращена в запись',
			self::ApplicationAccepted => 'Гость: запись без оплаты',
			self::ApplicationResolved => 'Гость: заявка урегулирована вручную',
			self::ApplicationReleased => 'Гость: бронь снята',
			self::SourceSaved => 'Источник записи: сохранён',
			self::SourceLinkIssued => 'Источник записи: ссылка выдана',
			self::SourceLinkReissued => 'Источник записи: ссылка перевыпущена',
			self::SourceLinkRevoked => 'Источник записи: ссылка отозвана',
			self::SourceToggled => 'Источник записи: включён/выключен',
			self::SourceDeleted => 'Источник записи: удалён',
			self::GuestDeleted => 'Гость: заявка и данные удалены полностью',
			self::PublicRegistrationToggled => 'Открытая запись: включена/выключена',
			self::FormOpened => 'Форма записи: открыта по ссылке',
			self::FormInvalid => 'Форма записи: ошибка в данных',
			self::FormLimited => 'Форма записи: отказ по лимиту',
			self::Admitted => 'Допуск к экзамену',
			self::EntryLinkIssued => 'Ссылка входа гостя: выдана или скопирована',
			self::ResultLinkIssued => 'Ссылка результата: выдана',
			self::ResultLinkRevoked => 'Ссылка результата: отозвана',
			self::LinkMarkedPassed => 'Ссылка: отмечена как переданная',
			self::AttemptStarted => 'Работа: начата',
			self::AttemptSubmitted => 'Работа: сдана',
			self::AttemptExpired => 'Работа: завершена по времени',
			self::AttemptExtended => 'Работа: время продлено',
			self::AttemptTechError => 'Работа: технический сбой',
			self::AttemptGraded => 'Проверка: работа проверена преподавателем',
			self::AttemptApproved => 'Проверка: результат утверждён',
			self::AttemptCorrected => 'Проверка: результат исправлен',
			self::ReportCreated => 'Отчёт: создан',
			self::ReportMemberChanged => 'Отчёт: изменён состав',
			self::ReportLinkIssued => 'Отчёт: ссылка выдана',
			self::ReportRevoked => 'Отчёт: отозван',
			self::PaymentReceived => 'Оплата: поступила',
		};
	}
}
