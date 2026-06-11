<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Person;

use Inc\Core\BaseController;
use Inc\Enums\Capability;
use Inc\Enums\Nonce;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\PasswordGeneratorService;
use Inc\Services\Person\PersonService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class PersonUpdateCallbacks
 *
 * AJAX-обработчики для обновления данных лиц (Person) и мягкого удаления (PII deletion).
 *
 * @package Inc\Callbacks\Person
 *
 * ### Основные обязанности:
 *
 * 1. **Обновление лица** — изменение персональных данных (ФИО, документы, контакты) и
 *    связанного пользователя WordPress (логин, email, пароль, отображаемое имя).
 * 2. **Мягкое удаление PII** — запрос на удаление персональных данных (soft delete).
 *
 * ### Архитектурная роль:
 *
 * Делегирует бизнес-логику PersonService и PasswordGeneratorService.
 * Работает с репозиториями PersonRepository и StudentRecordRepository.
 * Поддерживает обновление данных представителя и его подопечного.
 */
class PersonUpdateCallbacks extends BaseController {

	use Authorizer;  // Трейт с методами authorize(), success(), error()
	use Sanitizer;   // Трейт с методами sanitizeInt(), sanitizeText()

	/**
	 * Конструктор коллбеков.
	 *
	 * @param PersonService            $personService            Сервис управления лицами
	 * @param PersonRepository         $personRepository         Репозиторий лиц
	 * @param StudentRecordRepository  $studentRecordRepository  Репозиторий записей студентов
	 * @param PasswordGeneratorService $passwordGenerator        Сервис генерации паролей
	 */
	public function __construct(
		private readonly PersonService           $personService,
		private readonly PersonRepository        $personRepository,
		private readonly StudentRecordRepository $studentRecordRepository,
		private readonly PasswordGeneratorService $passwordGenerator,
	) {
		parent::__construct();
	}

	/**
	 * Запрос на удаление персональных данных (soft delete).
	 *
	 * @return void
	 */
	public function ajaxRequestPiiDeletion(): void {
		$this->authorize( Nonce::RequestPiiDeletion, Capability::ManagePersons );

		$personId = $this->sanitizeInt( 'person_id' );

		$this->personService->softDelete( $personId, get_current_user_id() );

		$this->success();
	}

	/**
	 * Обновляет данные лица (Person).
	 * При необходимости синхронизирует данные с пользователем WordPress.
	 *
	 * @return void
	 */
	public function ajaxUpdatePerson(): void {
		$this->authorize( Nonce::UpdatePerson, Capability::ManagePersons );

		$personId = $this->sanitizeInt( 'person_id' );
		$person   = $this->personRepository->find( $personId );

		if ( null === $person ) {
			$this->error( 'Person не найден.' );
		}

		// Получение данных из формы
		$lastName   = $this->sanitizeText( 'last_name' );
		$firstName  = $this->sanitizeText( 'first_name' );
		$middleName = $this->sanitizeText( 'middle_name' );

		// Фильтрация пустых значений (обновляем только переданные поля)
		$personChanges = array_filter( array(
			'phone'           => $this->sanitizeText( 'phone' ),
			'email'           => $this->sanitizeText( 'email' ),
			'birth_date'      => $this->sanitizeText( 'birth_date' ),
			'doc_number'      => $this->sanitizeText( 'doc_number' ),
			'inn'             => $this->sanitizeText( 'inn' ),
			'address'         => $this->sanitizeText( 'address' ),
			'doc_issued_by'   => $this->sanitizeText( 'doc_issued_by' ),
			'doc_issued_date' => $this->sanitizeText( 'doc_issued_date' ),
		) );

		if ( $lastName ) { $personChanges['last_name']   = $lastName; }
		if ( $firstName ) { $personChanges['first_name'] = $firstName; }
		if ( $middleName ) { $personChanges['middle_name'] = $middleName; }

		// Обновление записи Person
		if ( ! empty( $personChanges ) ) {
			$this->personService->update( $personId, $personChanges, get_current_user_id() );
		}

		// Если лицо привязано к пользователю WordPress — синхронизируем данные
		if ( $person->wpUserId ) {
			$userData = array( 'ID' => $person->wpUserId );

			// Изменение логина
			$newLogin = $this->sanitizeText( 'login' );
			if ( $newLogin ) {
				$userData['user_login'] = $newLogin;
			}

			// Изменение email
			if ( isset( $personChanges['email'] ) ) {
				$userData['user_email'] = $personChanges['email'];
			}

			// Изменение отображаемого имени
			if ( $lastName || $firstName ) {
				$userData['display_name'] = trim( $lastName . ' ' . $firstName . ' ' . $middleName );
				$userData['first_name']   = $firstName;
				$userData['last_name']    = $lastName;
			}

			// Обновление пользователя WP
			if ( count( $userData ) > 1 ) {
				wp_update_user( $userData );
			}

			// Изменение пароля (если передан)
			$newPassword = $this->sanitizeText( 'password' );
			if ( $newPassword ) {
				try {
					$this->passwordGenerator->setFromPlain( $person->wpUserId, $newPassword );
				} catch ( \RuntimeException ) {
					// Логирование ошибки (можно расширить)
				}
			}
		}

		// Обновление данных ребёнка (если текущее лицо — родитель)
		$childDocNumber = $this->sanitizeText( 'child_doc_number' );
		$childInn       = $this->sanitizeText( 'child_inn' );
		$childBirthDate = $this->sanitizeText( 'child_birth_date' );

		if ( $childDocNumber || $childInn || $childBirthDate ) {
			// Находим первого активного ребёнка (запись студента)
			$dependents = $this->studentRecordRepository->findActiveByParent( $personId );
			if ( ! empty( $dependents ) ) {
				$childPersonId = $dependents[0]->studentPersonId;
				$childChanges  = array_filter( array(
					'doc_number' => $childDocNumber,
					'inn'        => $childInn,
					'birth_date' => $childBirthDate,
				) );
				if ( ! empty( $childChanges ) ) {
					$this->personService->update( $childPersonId, $childChanges, get_current_user_id() );
				}
			}
		}

		$this->success();
	}
}