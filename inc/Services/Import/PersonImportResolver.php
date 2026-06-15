<?php

declare( strict_types=1 );

namespace Inc\Services\Import;

use Inc\DTO\Person\PersonInputDTO;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Person\PersonService;
use Inc\Services\Security\PiiCryptoService;

/**
 * Class PersonImportResolver
 *
 * Находит существующего person для строки импорта, чтобы не плодить дубли.
 *
 * ### Зачем отдельный резолвер
 *
 * `PersonService::createOrFindBy()` дедуплицирует **только** по
 * `doc_number_hash`. У учеников прошлых лет документов нет, поэтому
 * нужен расширенный порядок поиска:
 *
 * 1. `doc_number_hash` — если в строке есть документ;
 * 2. `email_hash` — если есть email;
 * 3. ФИО + дата рождения (если задана) + роль.
 *
 * Возвращает ID найденного person или `null` (тогда вызывающий код
 * создаёт нового через `PersonService::createOrFindBy()`).
 */
readonly class PersonImportResolver {

	/**
	 * @param PersonService    $personService    Поиск по хешам документа/email
	 * @param PersonRepository $personRepository Поиск по ФИО + дате рождения
	 * @param PiiCryptoService $crypto           Хеширование PII для поиска
	 */
	public function __construct(
		private PersonService    $personService,
		private PersonRepository $personRepository,
		private PiiCryptoService $crypto,
	) {}

	/**
	 * Резолвит существующего person по данным строки.
	 *
	 * @param PersonInputDTO $input Данные лица из строки CSV
	 *
	 * @return int|null ID существующего person или null
	 */
	public function resolve( PersonInputDTO $input ): ?int {
		// 1. По номеру документа
		if ( '' !== $input->docNumber ) {
			$byDoc = $this->personService->findByDocNumberHash( $this->crypto->hash( $input->docNumber ) );
			if ( null !== $byDoc ) {
				return $byDoc;
			}
		}

		// 2. По email
		$email = (string) ( $input->email ?? '' );
		if ( '' !== $email ) {
			$byEmail = $this->personService->findByEmailHash( $this->crypto->hash( $email ) );
			if ( null !== $byEmail ) {
				return $byEmail;
			}
		}

		// 3. По ФИО (+ дата рождения, если задана) и роли
		$byName = $this->personRepository->findByNameAndBirthDate(
			$input->lastName,
			$input->firstName,
			'' !== $input->middleName ? $input->middleName : null,
			'' !== $input->birthDate ? $input->birthDate : null,
			$input->isStudent
		);

		return $byName?->id;
	}
}
