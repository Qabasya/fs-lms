<?php

declare( strict_types=1 );

namespace Inc\Services\Enrollment;

use Inc\DTO\Enrollment\StudentDataDTO;
use Inc\DTO\Import\AccountCredentialsDTO;
use Inc\DTO\Person\ParentDataDTO;
use Inc\DTO\Person\PersonInputDTO;
use Inc\Services\Security\PasswordGeneratorService;

/**
 * Class EnrollmentAccountsService
 *
 * Учётки WordPress ученика и родителя по данным заявки.
 *
 * @package Inc\Services\Enrollment
 *
 * ### Два вызывающих
 *
 * - {@see EnrollmentService::enroll()} — сразу после транзакции зачисления;
 * - {@see RecoveryService} — если создание учёток тогда упало: заявка остаётся в `converted`,
 *   и cron досоздаёт недостающие учётки с теми же логином и паролем, что задал ученик.
 *
 * Методы раздельные: восстановление создаёт только отсутствующую учётку и не должно
 * перегенерировать пароль той, что уже есть.
 */
readonly class EnrollmentAccountsService {

	public function __construct(
		private AccountProvisioningService $provisioning,
		private PasswordGeneratorService   $passwordGenerator,
	) {}

	/**
	 * Учётка ученика: логин и пароль из заявки; пароля нет — генерируется.
	 *
	 * @param StudentDataDTO $student  Данные ученика из заявки
	 * @param int            $personId Физлицо ученика
	 *
	 * @throws \RuntimeException Коллизия логина/email при создании
	 */
	public function provisionStudent( StudentDataDTO $student, int $personId ): AccountCredentialsDTO {
		return $this->provisioning->provisionStudent(
			$personId,
			$this->studentAccountData( $student ),
			$this->studentLogin( $student, $personId ),
			'' !== $student->loginPassword ? $student->loginPassword : $this->passwordGenerator->generatePlain()
		);
	}

	/**
	 * Учётка родителя: логин — email, пароль генерируется.
	 *
	 * @param ParentDataDTO $parent   Данные родителя из заявки
	 * @param int           $personId Физлицо родителя
	 *
	 * @throws \RuntimeException Коллизия логина/email при создании
	 */
	public function provisionGuardian( ParentDataDTO $parent, int $personId ): AccountCredentialsDTO {
		return $this->provisioning->provisionParent( $personId, $parent );
	}

	/**
	 * Данные ученика для провизии учётки (email + ФИО).
	 *
	 * @param StudentDataDTO $student Данные ученика из заявки
	 */
	private function studentAccountData( StudentDataDTO $student ): PersonInputDTO {
		return new PersonInputDTO(
			lastName:  $student->lastName,
			firstName: $student->firstName,
			docNumber: $student->docNumber,
			isStudent: true,
			email:     '' !== $student->email ? $student->email : null,
		);
	}

	/**
	 * Логин новой учётки ученика: явный из заявки → email → служебный по ID физлица.
	 *
	 * @param StudentDataDTO $student  Данные ученика
	 * @param int            $personId Физлицо ученика
	 */
	private function studentLogin( StudentDataDTO $student, int $personId ): string {
		if ( '' !== $student->username ) {
			return $student->username;
		}

		return '' !== $student->email ? $student->email : 'student_' . $personId;
	}
}
