<?php

declare( strict_types=1 );

namespace Inc\Modules\AdSync\Services;

use Inc\DTO\Application\ApplicationDTO;
use Inc\Enums\ApplicationStatus;
use Inc\Managers\UserManager;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Security\PiiCryptoService;

/**
 * Class AdReconcileService
 *
 * Сверка-«пылесос»: формирует авторитетный список AD-логинов, которые ДОЛЖНЫ оставаться
 * активными. Python из локальной сети забирает его (`GET /ad/active-usernames`) и отключает
 * в управляемой OU всё, чего нет в списке — это закрывает «удалили из БД → логин остался».
 *
 * Должны жить: активные зачисленные (student_records.status=active) + живые заявки
 * (pending_parent / ready_for_review / enrolling). Истёкшие/в корзине/converted сюда не входят
 * (converted уже покрыт активной записью; expired/trash деправижятся отдельно).
 *
 * @package Inc\Modules\AdSync\Services
 */
class AdReconcileService {

	public function __construct(
		private readonly StudentRecordRepository $records,
		private readonly ApplicationRepository   $applications,
		private readonly PersonRepository        $persons,
		private readonly UserManager             $users,
		private readonly PiiCryptoService        $crypto,
	) {}

	/**
	 * Логины, которые должны оставаться активными в AD (уникальные).
	 *
	 * @return string[]
	 */
	public function activeUsernames(): array {
		$set = array();

		foreach ( $this->records->allActiveStudentPersonIds() as $personId ) {
			$username = $this->usernameFromPerson( $personId );
			if ( '' !== $username ) {
				$set[ $username ] = true;
			}
		}

		$live = $this->applications->findByStatuses( array(
			ApplicationStatus::PendingParent->value,
			ApplicationStatus::ReadyForReview->value,
			ApplicationStatus::Enrolling->value,
		) );
		foreach ( $live as $app ) {
			$username = $this->usernameFromApplication( $app );
			if ( '' !== $username ) {
				$set[ $username ] = true;
			}
		}

		return array_keys( $set );
	}

	private function usernameFromPerson( int $personId ): string {
		$person = $this->persons->find( $personId );
		if ( null === $person || empty( $person->wpUserId ) ) {
			return '';
		}
		return (string) ( $this->users->find( $person->wpUserId )?->user_login ?? '' );
	}

	private function usernameFromApplication( ApplicationDTO $app ): string {
		if ( empty( $app->studentDataEnc ) ) {
			return '';
		}
		$blob = json_decode( $this->crypto->decrypt( $app->studentDataEnc ), true ) ?? array();
		return (string) ( $blob['username'] ?? '' );
	}
}
