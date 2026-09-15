<?php

declare( strict_types=1 );

namespace Inc\Services\Enrollment;

use DomainException;

/**
 * Class FamilyEmailPolicy
 *
 * Email ученика и email родителя обязаны различаться.
 *
 * @package Inc\Services\Enrollment
 *
 * ### Почему
 *
 * Учётка WordPress ищется по email. При зачислении сначала создаётся ученик с этим адресом, и
 * `AccountProvisioningService::provisionParent()` находит по нему уже **учётку ученика**:
 * привязывает к ней родителя и перезаписывает пароль, а письмо с данными для входа уходит
 * родителю с логином ребёнка. Частый сценарий — у школьника нет своей почты, и в заявке он
 * указывает почту родителя.
 *
 * Правило проверяется везде, где пара адресов впервые складывается или меняется: анкета
 * родителя, правка заявки администратором, зачисление (для заявок, поданных до правила).
 */
readonly class FamilyEmailPolicy {

	public const MESSAGE = 'Email родителя совпадает с email ученика. У ученика и родителя разные учётки — укажите разные адреса.';

	/**
	 * @param string $studentEmail Email ученика
	 * @param string $parentEmail  Email родителя
	 *
	 * @throws DomainException Адреса совпадают (без учёта регистра и пробелов)
	 */
	public function assertDistinct( string $studentEmail, string $parentEmail ): void {
		$student = strtolower( trim( $studentEmail ) );

		if ( '' !== $student && strtolower( trim( $parentEmail ) ) === $student ) {
			throw new DomainException( self::MESSAGE );
		}
	}
}
