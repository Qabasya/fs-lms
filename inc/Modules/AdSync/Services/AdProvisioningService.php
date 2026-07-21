<?php

declare( strict_types=1 );

namespace Inc\Modules\AdSync\Services;

use Inc\Managers\Person\UserManager;
use Inc\Modules\AdSync\Config\AdSyncConfig;
use Inc\Modules\AdSync\DTO\AdOutboxItemDTO;
use Inc\Modules\AdSync\Enums\AdSyncEvent;
use Inc\Modules\AdSync\Repositories\AdOutboxRepository;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Security\PiiCryptoService;

/**
 * Class AdProvisioningService
 *
 * Логика синхронизации с AD в **pull-модели**: WP ставит задания в очередь, Python из локальной
 * сети сам забирает их (`pendingJobs()`) и отчитывается (`ack()`). WP наружу не ходит.
 *
 * Идентификатор учётки в AD — `username` (sAMAccountName). Для provision он (и пароль) читаются из
 * зашифрованного блоба заявки в момент выдачи задания. Для deprovision `username` резолвится
 * **при enqueue** и кладётся в `target` (устойчиво к последующему удалению заявки/пользователя).
 * Пароль в очереди не хранится никогда.
 *
 * Учётка создаётся сразу в целевой OU направления (по `subject_key`, карту `subject_key → OU` держит
 * Python-сервис) — отдельной стадии «зачислен» в OU-структуре нет, `promote`-событий не существует.
 * `deprovision` переносит учётку в OU=Отчисленные: по истечении/удалению заявки (до зачисления,
 * см. {@see enqueueDeprovisionByApplication()}) либо по факту отчисления зачисленного ученика
 * (см. {@see enqueueDeprovisionByPerson()}).
 *
 * @package Inc\Modules\AdSync\Services
 */
class AdProvisioningService {

	public function __construct(
		private readonly AdOutboxRepository    $outbox,
		private readonly ApplicationRepository $applications,
		private readonly PiiCryptoService      $crypto,
		private readonly PersonRepository      $persons,
		private readonly UserManager           $users,
		private readonly AdSyncConfig          $config,
	) {}

	/**
	 * Provision: задание создания учётки (логин/пароль читаются при выдаче из блоба заявки).
	 * Ставится только для направлений из provision_subjects — остальным доменная учётка не нужна.
	 */
	public function enqueueProvision( int $applicationId ): void {
		$app = $this->applications->find( $applicationId );
		if ( null === $app || ! $this->config->shouldProvision( $app->subjectKey ?? null ) ) {
			return;
		}
		$this->outbox->enqueue( array(
			'event'           => AdSyncEvent::Provision->value,
			'application_id'  => $applicationId,
			'idempotency_key' => 'app:' . $applicationId,
		) );
	}

	/** Deprovision по заявке (истекла/в корзину): username резолвим из блоба сейчас и кладём в target. */
	public function enqueueDeprovisionByApplication( int $applicationId ): void {
		// Provision по этой заявке не ставился (направление вне provision_subjects) — деправижнить нечего.
		if ( null === $this->outbox->latestByApplication( $applicationId ) ) {
			return;
		}
		$username = $this->usernameFromApplication( $applicationId );
		if ( '' === $username ) {
			return;
		}
		$this->outbox->enqueue( array(
			'event'           => AdSyncEvent::Deprovision->value,
			'application_id'  => $applicationId,
			'target'          => $username,
			'idempotency_key' => 'deprovision:app:' . $applicationId,
		) );
	}

	/** Deprovision по факту отчисления зачисленного ученика: username из WP-пользователя person'а. */
	public function enqueueDeprovisionByPerson( int $personId ): void {
		$username = $this->usernameFromPerson( $personId );
		if ( '' === $username ) {
			return;
		}
		$this->outbox->enqueue( array(
			'event'           => AdSyncEvent::Deprovision->value,
			'person_id'       => $personId,
			'target'          => $username,
			'idempotency_key' => 'deprovision:person:' . $personId,
		) );
	}

	/**
	 * Готовые задания для Python (`/jobs`).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function pendingJobs( int $limit = 50 ): array {
		$jobs = array();
		foreach ( $this->outbox->listPending( $limit ) as $row ) {
			$payload = $this->buildPayload( $row );
			if ( null !== $payload ) {
				$jobs[] = $payload;
			}
		}
		return $jobs;
	}

	/** Отчёт Python о выполнении (`/ack`). */
	public function ack( int $id, bool $ok, string $error = '' ): void {
		if ( $ok ) {
			$this->outbox->markSent( $id );
		} else {
			$this->outbox->markFailed( $id, '' !== $error ? $error : 'ack: failed' );
		}
	}

	/** Статус провижна по заявке для статус-поллинга фронта: pending|done|failed|none. */
	public function statusForApplication( int $applicationId ): string {
		$row = $this->outbox->latestByApplication( $applicationId );
		if ( null === $row ) {
			return 'none';
		}
		return match ( $row->status ) {
			'sent'  => 'done',
			'dead'  => 'failed',
			default => 'pending',
		};
	}

	/**
	 * Собирает payload задания по типу события.
	 *
	 * @return array<string, mixed>|null
	 */
	private function buildPayload( AdOutboxItemDTO $row ): ?array {
		if ( AdSyncEvent::Provision->value === $row->event ) {
			return $this->provisionPayload( $row );
		}
		// deprovision — нужен только username (из target).
		$username = (string) ( $row->target ?? '' );
		if ( '' === $username ) {
			return null;
		}
		return array(
			'id'              => $row->id,
			'event'           => $row->event,
			'idempotency_key' => $row->idempotencyKey,
			'username'        => $username,
		);
	}

	/** @return array<string, mixed>|null */
	private function provisionPayload( AdOutboxItemDTO $row ): ?array {
		$appId = $row->applicationId;
		if ( null === $appId ) {
			return null;
		}
		$app = $this->applications->find( $appId );
		if ( null === $app || empty( $app->studentDataEnc ) ) {
			return null;
		}

		$blob = json_decode( $this->crypto->decrypt( $app->studentDataEnc ), true ) ?? array();

		return array(
			'id'              => $row->id,
			'event'           => $row->event,
			'idempotency_key' => $row->idempotencyKey,
			'username'        => (string) ( $blob['username'] ?? '' ),
			'password'        => (string) ( $blob['login_password'] ?? '' ),
			'first'           => (string) ( $blob['first_name'] ?? '' ),
			'last'            => (string) ( $blob['last_name'] ?? '' ),
			'subject_key'     => (string) ( $app->subjectKey ?? '' ), // Python выбирает группу по нему
		);
	}

	private function usernameFromApplication( int $applicationId ): string {
		$app = $this->applications->find( $applicationId );
		if ( null === $app || empty( $app->studentDataEnc ) ) {
			return '';
		}
		$blob = json_decode( $this->crypto->decrypt( $app->studentDataEnc ), true ) ?? array();
		return (string) ( $blob['username'] ?? '' );
	}

	private function usernameFromPerson( int $personId ): string {
		$person = $this->persons->find( $personId );
		if ( null === $person || empty( $person->wpUserId ) ) {
			return '';
		}
		return (string) ( $this->users->find( $person->wpUserId )?->user_login ?? '' );
	}
}
