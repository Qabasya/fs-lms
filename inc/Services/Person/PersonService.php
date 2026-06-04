<?php

declare( strict_types=1 );

namespace Inc\Services\Person;

use Inc\Contracts\ClockInterface;
use Inc\DTO\PersonInputDTO;
use Inc\Enums\AuditAction;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\AuditService;
use Inc\Services\PiiCryptoService;
use RuntimeException;

/**
 * Class PersonService
 *
 * Управление записями о физических лицах с шифрованием PII.
 *
 * @package Inc\Services
 *
 * ### Основные обязанности:
 *
 * 1. **Идемпотентное создание** — createOrFindBy() ищет по хэшу документа перед INSERT.
 * 2. **Шифрование при записи** — все PII-поля шифруются через PiiCryptoService перед сохранением.
 * 3. **Аудит изменений** — в журнал пишутся только названия изменённых полей, не их значения.
 * 4. **Soft delete и обезличивание** — делегирует в репозиторий, пишет audit log.
 *
 * ### Поля PII и их DB-колонки:
 *
 * | rawData key  | enc column      | hash column      |
 * |--------------|-----------------|------------------|
 * | full_name    | full_name_enc   | —                |
 * | doc_number   | doc_number_enc  | doc_number_hash  |
 * | inn          | inn_enc         | inn_hash         |
 * | address      | address_enc     | —                |
 * | phone        | phone_enc       | —                |
 *
 * ### Важно:
 *
 * Этот сервис только записывает данные. Чтение PII для отображения —
 * исключительно через PersonReader, который логирует каждый доступ.
 */
readonly class PersonService {

	private const ENCRYPTED_FIELDS = array(
		'full_name'  => 'full_name_enc',
		'doc_number' => 'doc_number_enc',
		'inn'        => 'inn_enc',
		'address'    => 'address_enc',
		'phone'      => 'phone_enc',
	);

	private const HASH_FIELDS = array(
		'doc_number' => 'doc_number_hash',
		'inn'        => 'inn_hash',
	);

	/**
	 * Конструктор сервиса.
	 *
	 * @param PersonRepository $personRepository Репозиторий физических лиц
	 * @param PiiCryptoService $crypto           Сервис шифрования PII
	 * @param AuditService     $auditService     Сервис аудита
	 */
	public function __construct(
		private PersonRepository $personRepository,
		private PiiCryptoService $crypto,
		private AuditService     $auditService,
		private ClockInterface   $clock,
	) {}

	/**
	 * Ищет существующего person по хэшу номера документа или создаёт нового.
	 *
	 * Идемпотентен: повторный вызов с теми же данными вернёт тот же ID
	 * без создания дублей.
	 *
	 * @param PersonInputDTO $input Входные данные физического лица
	 *
	 * @return int ID существующей или созданной записи
	 *
	 * @throws RuntimeException Если создание записи не удалось
	 */
	public function createOrFindBy( PersonInputDTO $input ): int {
		$docHash  = $this->crypto->hash( $input->docNumber );
		$existing = $this->personRepository->findByDocNumberHash( $docHash );

		if ( null !== $existing ) {
			return $existing->id;
		}

		$data = $this->buildEncryptedData( $input->toRawData() );

		$id = $this->personRepository->create( $data );

		if ( 0 === $id ) {
			throw new RuntimeException( 'Не удалось создать запись person.' );
		}

		return $id;
	}

	/**
	 * Обновляет данные person с шифрованием изменённых PII-полей.
	 *
	 * В audit log записываются только названия изменённых полей —
	 * значения PII никогда не попадают в журнал.
	 *
	 * @param int   $personId ID записи person
	 * @param array $changes  Изменяемые поля в rawData-формате (full_name, phone и т.д.)
	 * @param int   $actorId  ID пользователя WP, инициировавшего изменение
	 *
	 * @return void
	 *
	 * @throws RuntimeException Если person не найден
	 */
	public function update( int $personId, array $changes, int $actorId ): void {
		if ( null === $this->personRepository->find( $personId ) ) {
			throw new RuntimeException( "Person с ID {$personId} не найден." );
		}

		$data          = array();
		$changedFields = array();

		foreach ( self::ENCRYPTED_FIELDS as $rawKey => $encColumn ) {
			if ( ! array_key_exists( $rawKey, $changes ) ) {
				continue;
			}

			$value            = (string) $changes[ $rawKey ];
			$data[ $encColumn ] = $this->crypto->encrypt( $value );
			$changedFields[]  = $rawKey;

			if ( isset( self::HASH_FIELDS[ $rawKey ] ) ) {
				$data[ self::HASH_FIELDS[ $rawKey ] ] = $this->crypto->hash( $value );
			}
		}

		if ( isset( $changes['email'] ) ) {
			$data['email'] = (string) $changes['email'];
			$changedFields[] = 'email';
		}

		if ( isset( $changes['birth_date'] ) ) {
			$data['birth_date'] = (string) $changes['birth_date'];
			$changedFields[] = 'birth_date';
		}

		if ( isset( $changes['doc_type'] ) ) {
			$data['doc_type'] = (string) $changes['doc_type'];
			$changedFields[] = 'doc_type';
		}

		if ( empty( $data ) ) {
			return;
		}

		$data['updated_at'] = $this->clock->now( 'mysql', true );

		$this->personRepository->update( $personId, $data );

		$this->auditService->record(
			AuditAction::UpdatePerson->value,
			'person',
			$personId,
			array( 'changed_fields' => $changedFields ),
		);
	}

	/**
	 * Помечает person как удалённого (soft delete).
	 *
	 * Физические данные остаются в БД; retention job обезличит их
	 * по истечении срока хранения. Пишет audit log.
	 *
	 * @param int $personId ID записи person
	 * @param int $actorId  ID пользователя WP, инициировавшего удаление
	 *
	 * @return void
	 *
	 * @throws RuntimeException Если person не найден или удаление не удалось
	 */
	public function softDelete( int $personId, int $actorId ): void {
		if ( null === $this->personRepository->find( $personId ) ) {
			throw new RuntimeException( "Person с ID {$personId} не найден." );
		}

		$result = $this->personRepository->softDelete( $personId );

		if ( ! $result ) {
			throw new RuntimeException( "Не удалось выполнить soft delete для person ID {$personId}." );
		}

		$this->auditService->record(
			AuditAction::PiiDeletionRequested->value,
			'person',
			$personId,
		);
	}

	/**
	 * Обезличивает person: обнуляет все зашифрованные PII-поля.
	 *
	 * Вызывается только retention job после истечения срока хранения.
	 * После вызова расшифровка данных невозможна — операция необратима.
	 *
	 * @param int $personId ID записи person
	 *
	 * @return void
	 */
	public function anonymize( int $personId ): void {
		$this->personRepository->anonymize( $personId );
	}

	/**
	 * Формирует массив данных для INSERT/UPDATE с зашифрованными PII-полями и хэшами.
	 *
	 * @param array $rawData Сырые данные от вызывающего кода
	 *
	 * @return array Массив для передачи в PersonRepository::create()
	 */
	private function buildEncryptedData( array $rawData ): array {
		$data = array();

		foreach ( self::ENCRYPTED_FIELDS as $rawKey => $encColumn ) {
			if ( isset( $rawData[ $rawKey ] ) && '' !== (string) $rawData[ $rawKey ] ) {
				$value              = (string) $rawData[ $rawKey ];
				$data[ $encColumn ] = $this->crypto->encrypt( $value );

				if ( isset( self::HASH_FIELDS[ $rawKey ] ) ) {
					$data[ self::HASH_FIELDS[ $rawKey ] ] = $this->crypto->hash( $value );
				}
			}
		}

		if ( isset( $rawData['email'] ) ) {
			$data['email'] = (string) $rawData['email'];
		}

		if ( isset( $rawData['birth_date'] ) && '' !== (string) $rawData['birth_date'] ) {
			$data['birth_date'] = (string) $rawData['birth_date'];
		}

		if ( isset( $rawData['doc_type'] ) && '' !== (string) $rawData['doc_type'] ) {
			$data['doc_type'] = (string) $rawData['doc_type'];
		}

		if ( isset( $rawData['wp_user_id'] ) ) {
			$data['wp_user_id'] = (int) $rawData['wp_user_id'];
		}

		$now               = $this->clock->now( 'mysql', true );
		$data['created_at'] = $now;
		$data['updated_at'] = $now;

		return $data;
	}
}