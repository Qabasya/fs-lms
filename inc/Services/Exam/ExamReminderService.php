<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Exam\ExamRegistrationDTO;
use Inc\DTO\Exam\ExamSessionDTO;
use Inc\Enums\Exam\ExamRegistrationStatus;
use Inc\Enums\Exam\ExamSessionStatus;
use Inc\Enums\Profile\NotificationType;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Shared\PluginLogger;

/**
 * Напоминания «завтра», «скоро начало» и «вход открыт» (этап 9.3).
 *
 * Минутный продюсер: выбирает действующие записи в нужном окне и отдаёт {@see ExamNotificationComposer::remind()}; ключ
 * `exam:{вид}:{registration_id}` делает повторный запуск безвредным. Перед отправкой каждая запись проверяется заново:
 * действующая ли она и не сменился ли сеанс. Отмена, перенос и неявка снимают плитки (это делает композитор по событиям outbox).
 * Гостю напоминаний нет — у композитора для него нет получателей.
 *
 * Значения 18:00 и 60 минут — константы (SPEC §0: настраиваемые; в настройки их вынесут по просьбе владельца, этап 11a.6).
 */
class ExamReminderService {

	/** Местное время, начиная с которого рассылается «завтра экзамен». */
	public const TOMORROW_AT = '18:00';

	/** За сколько минут до начала приходит «скоро начнётся». */
	public const SOON_MINUTES = 60;

	/** Сколько минут после начала ещё рассылается «вход открыт» (тик не работал дольше — пропускаем). */
	public const ENTRY_WINDOW_MINUTES = 10;

	public function __construct(
		private readonly ExamRegistrationRepository $registrations,
		private readonly ExamSessionRepository $sessions,
		private readonly ExamNotificationComposer $composer,
		private readonly ExamTime $time,
	) {}

	/** «Скоро начнётся»: сеансы в ближайшие 60 минут; запись, сделанную позже, пропускаем — подтверждение уже содержит время. */
	public function soon(): int {
		$now = $this->time->nowUtc();

		return $this->send(
			$this->registrations->listConfirmedStartingBetween( $now, $this->time->addMinutes( $now, self::SOON_MINUTES ) ),
			NotificationType::ExamSoon,
			'soon',
			fn ( ExamRegistrationDTO $r, ExamSessionDTO $s ): bool => $r->createdAt <= $this->time->addMinutes( $s->scheduledAt, -self::SOON_MINUTES )
		);
	}

	/** «Завтра экзамен»: после 18:00 по местному времени — записи на сеансы завтрашнего дня, сделанные не позже 18:00 накануне. */
	public function tomorrow(): int {
		$local = $this->time->nowLocal();
		if ( substr( $local, 11, 5 ) < self::TOMORROW_AT ) {
			return 0;
		}

		$tomorrow = gmdate( 'Y-m-d', (int) strtotime( substr( $local, 0, 10 ) . ' UTC' ) + 86400 );

		return $this->send(
			$this->registrations->listConfirmedStartingBetween( $this->time->toUtc( $tomorrow . ' 00:00:00' ), $this->time->endOfLocalDayUtc( $tomorrow ) ),
			NotificationType::ExamTomorrow,
			'tomorrow',
			function ( ExamRegistrationDTO $r, ExamSessionDTO $s ): bool {
				$sessionDay = substr( $this->time->toLocal( $s->scheduledAt ), 0, 10 );
				$deadline   = $this->time->toUtc( gmdate( 'Y-m-d', (int) strtotime( $sessionDay . ' UTC' ) - 86400 ) . ' ' . self::TOMORROW_AT . ':00' );

				return $r->createdAt <= $deadline;
			}
		);
	}

	/** «Вход открыт»: сеансы, начавшиеся за последние 10 минут; начавшиеся раньше (тик не работал) пропускаются. */
	public function entryOpened(): int {
		$now = $this->time->nowUtc();

		return $this->send(
			$this->registrations->listConfirmedStartingBetween( $this->time->addMinutes( $now, -self::ENTRY_WINDOW_MINUTES ), $now ),
			NotificationType::ExamEntryOpened,
			'entry',
			static fn (): bool => true
		);
	}

	/**
	 * @param ExamRegistrationDTO[]                             $candidates
	 * @param callable(ExamRegistrationDTO, ExamSessionDTO):bool $eligible   Условие именно этого вида напоминания.
	 *
	 * @return int Сколько записей получили напоминание в этом запуске.
	 */
	private function send( array $candidates, NotificationType $type, string $prefix, callable $eligible ): int {
		$sent = 0;

		foreach ( $candidates as $candidate ) {
			try {
				// Повторная проверка: за время между выборкой и отправкой запись могли отменить, а сеанс — перенести.
				$registration = $this->registrations->find( $candidate->id );
				$session      = $this->sessions->find( $candidate->sessionId );
				if ( null === $registration || null === $session || ! $this->isCurrent( $registration, $session ) || ! $eligible( $registration, $session ) ) {
					continue;
				}

				if ( $this->composer->remind( $type, $prefix, $registration ) ) {
					++$sent;
				}
			} catch ( \Throwable $e ) {
				PluginLogger::exception( 'ExamReminder', $e, array( 'registration_id' => $candidate->id, 'kind' => $prefix ), true );
			}
		}

		return $sent;
	}

	private function isCurrent( ExamRegistrationDTO $registration, ExamSessionDTO $session ): bool {
		return 1 === $registration->activeSlot
			&& ExamRegistrationStatus::Confirmed->value === $registration->status
			&& ExamSessionStatus::Open->value === $session->status;
	}
}
