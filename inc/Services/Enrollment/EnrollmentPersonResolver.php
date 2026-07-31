<?php

declare( strict_types=1 );

namespace Inc\Services\Enrollment;

use Inc\DTO\Application\ApplicationDTO;
use Inc\DTO\Person\ParentDataDTO;
use Inc\DTO\Enrollment\StudentDataDTO;
use Inc\DTO\Person\PersonDTO;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Person\PersonService;
use Inc\Services\Security\PiiCryptoService;

/**
 * Class EnrollmentPersonResolver
 *
 * Ищет уже существующих ученика и родителя для заявки: сначала по явной привязке
 * в заявке, затем по хэшу номера документа.
 *
 * @package Inc\Services\Enrollment
 *
 * ### Почему поиск не симметричен
 *
 * Ученик, найденный по документу, обязан быть учеником (`is_student = 1`), а
 * родитель — наоборот: иначе коллизия `doc_number_hash` делала бы родителем
 * самого ученика. Отчисленный ученик при повторном зачислении «воскрешается»
 * (снимается `expelled_at`), поэтому ищется среди удалённых тоже.
 */
readonly class EnrollmentPersonResolver {

	/**
	 * @param PersonRepository  $persons Физлица
	 * @param PersonService     $people  Поиск по хэшу документа
	 * @param PiiCryptoService  $crypto  Хэширование номера документа
	 */
	public function __construct(
		private PersonRepository  $persons,
		private PersonService     $people,
		private PiiCryptoService  $crypto,
	) {}

	/**
	 * Существующий ученик заявки или null.
	 *
	 * @param ApplicationDTO $app     Заявка
	 * @param StudentDataDTO $student Расшифрованные данные ученика
	 */
	public function resolveStudent( ApplicationDTO $app, StudentDataDTO $student ): ?PersonDTO {
		if ( null !== $app->studentPersonId ) {
			$existing = $this->persons->findIncludingDeleted( $app->studentPersonId );

			// Повторное зачисление отчисленного: снимаем отметку об отчислении.
			if ( null !== $existing && null !== $existing->expelledAt ) {
				$this->persons->update( $existing->id, array( 'expelled_at' => null ) );
			}

			return $existing;
		}

		$candidate = $this->findByDocument( $student->docNumber );

		return ( null !== $candidate && $candidate->isStudent ) ? $candidate : null;
	}

	/**
	 * Существующий родитель заявки или null.
	 *
	 * @param ApplicationDTO $app    Заявка
	 * @param ParentDataDTO  $parent Расшифрованные данные родителя
	 */
	public function resolveGuardian( ApplicationDTO $app, ParentDataDTO $parent ): ?PersonDTO {
		if ( null !== $app->parentPersonId ) {
			return $this->persons->find( $app->parentPersonId );
		}

		$candidate = $this->findByDocument( $parent->docNumber );

		return ( null !== $candidate && ! $candidate->isStudent ) ? $candidate : null;
	}

	/**
	 * Физлицо по номеру документа (пустой номер поиск не запускает).
	 *
	 * @param string $docNumber Номер документа
	 */
	private function findByDocument( string $docNumber ): ?PersonDTO {
		if ( '' === $docNumber ) {
			return null;
		}

		$personId = $this->people->findByDocNumberHash( $this->crypto->hash( $docNumber ) );

		return null !== $personId ? $this->persons->find( $personId ) : null;
	}
}
