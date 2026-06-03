<?php

declare( strict_types=1 );

namespace Inc\Services\Person;

use Inc\DTO\PersonDecryptedDTO;
use Inc\DTO\PiiAccessLogInputDTO;
use Inc\Managers\UserManager;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\PiiAccessLogRepository;
use Inc\Services\PiiCryptoService;
use Inc\Shared\Traits\RequestContextProvider;
use RuntimeException;

/**
 * Class PersonReader
 *
 * Единственный санкционированный способ читать PII для отображения пользователю.
 *
 * @package Inc\Services
 *
 * ### Основные обязанности:
 *
 * 1. **Расшифровка по запросу** — расшифровывает только запрошенные поля (principle of least privilege).
 * 2. **Автоматическое логирование** — каждый вызов readForDisplay() и readField()
 *    автоматически создаёт запись в pii_access_log (actor, person_id, fields, reason, IP).
 * 3. **Безопасная обработка обезличенных записей** — NULL в *_enc возвращает '',
 *    не бросает исключение.
 *
 * ### Запрещено:
 *
 * Прямые вызовы PiiCryptoService::decrypt() в callbacks и контроллерах запрещены.
 * Любое чтение PII для отображения — только через этот класс.
 *
 * ### Имена полей ($fields / $field):
 *
 * full_name, doc_number, inn, address, phone
 *
 * ### Маппинг полей:
 *
 * | field name  | PersonDTO property | PersonDecryptedDTO property |
 * |-------------|--------------------|-----------------------------|
 * | full_name   | fullNameEnc        | fullName                    |
 * | doc_number  | docNumberEnc       | pass                        |
 * | inn         | innEnc             | inn                         |
 * | address     | addressEnc         | address                     |
 * | phone       | phoneEnc           | phone                       |
 */
readonly class PersonReader {

	use RequestContextProvider;

	private const FIELD_MAP = array(
		'full_name'  => 'fullNameEnc',
		'doc_number' => 'docNumberEnc',
		'inn'        => 'innEnc',
		'address'    => 'addressEnc',
		'phone'      => 'phoneEnc',
	);

	/**
	 * Конструктор.
	 *
	 * @param PersonRepository      $personRepository    Репозиторий физических лиц
	 * @param PiiCryptoService      $crypto              Сервис шифрования
	 * @param PiiAccessLogRepository $piiAccessLogRepository Журнал доступа к PII
	 * @param UserManager           $userManager         Менеджер пользователей (для роли актора)
	 */
	public function __construct(
		private PersonRepository      $personRepository,
		private PiiCryptoService      $crypto,
		private PiiAccessLogRepository $piiAccessLogRepository,
		private UserManager           $userManager,
	) {}

	/**
	 * Расшифровывает запрошенные поля person и возвращает DTO для отображения.
	 *
	 * Автоматически создаёт запись в pii_access_log. Нерасшифрованные поля
	 * (не переданные в $fields) возвращаются как пустая строка.
	 *
	 * @param int      $personId ID записи person
	 * @param string[] $fields   Список полей для расшифровки (full_name, doc_number, ...)
	 * @param string   $reason   Обязательная причина доступа (для compliance)
	 *
	 * @return PersonDecryptedDTO
	 *
	 * @throws RuntimeException Если person не найден
	 */
	public function readForDisplay( int $personId, array $fields, string $reason ): PersonDecryptedDTO {
		$person = $this->personRepository->find( $personId );

		if ( null === $person ) {
			throw new RuntimeException( "Person с ID {$personId} не найден." );
		}

		$decrypted = array_fill_keys( array_keys( self::FIELD_MAP ), '' );

		foreach ( $fields as $field ) {
			if ( ! isset( self::FIELD_MAP[ $field ] ) ) {
				continue;
			}

			$encProperty        = self::FIELD_MAP[ $field ];
			$decrypted[ $field ] = $this->decryptField( $person->$encProperty );
		}

		$this->logAccess( $personId, $fields, $reason );

		return new PersonDecryptedDTO(
			personId: $personId,
			fullName: $decrypted['full_name'],
			pass:     $decrypted['doc_number'],
			inn:      $decrypted['inn'],
			address:  $decrypted['address'],
			phone:    $decrypted['phone'],
		);
	}

	/**
	 * Расшифровывает одно поле person.
	 *
	 * Используется в AJAX reveal: значение показывается на 30 секунд,
	 * затем JS возвращает маску. Пишет запись в pii_access_log.
	 *
	 * @param int    $personId ID записи person
	 * @param string $field    Имя поля (full_name, doc_number, inn, address, phone)
	 * @param string $reason   Причина доступа
	 *
	 * @return string Расшифрованное значение или '' если поле обезличено
	 *
	 * @throws RuntimeException Если person не найден или поле неизвестно
	 */
	public function readField( int $personId, string $field, string $reason ): string {
		if ( ! isset( self::FIELD_MAP[ $field ] ) ) {
			throw new RuntimeException( "Неизвестное PII-поле: {$field}." );
		}

		$person = $this->personRepository->find( $personId );

		if ( null === $person ) {
			throw new RuntimeException( "Person с ID {$personId} не найден." );
		}

		$encProperty = self::FIELD_MAP[ $field ];
		$value       = $this->decryptField( $person->$encProperty );

		$this->logAccess( $personId, array( $field ), $reason );

		return $value;
	}

	/**
	 * Расшифровывает одно зашифрованное значение.
	 * Возвращает '' если значение NULL (обезличенная запись).
	 *
	 * @param string|null $enc Бинарный blob или NULL
	 *
	 * @return string
	 */
	private function decryptField( ?string $enc ): string {
		if ( null === $enc || '' === $enc ) {
			return '';
		}

		return $this->crypto->decrypt( $enc );
	}

	/**
	 * Создаёт запись в pii_access_log.
	 *
	 * @param int      $personId ID человека, чьи данные запрошены
	 * @param string[] $fields   Запрошенные поля
	 * @param string   $reason   Причина доступа
	 *
	 * @return void
	 */
	private function logAccess( int $personId, array $fields, string $reason ): void {
		$ctx  = $this->requestContext();
		$user = $ctx->actorUserId > 0 ? $this->userManager->find( $ctx->actorUserId ) : null;

		$this->piiAccessLogRepository->create( new PiiAccessLogInputDTO(
			actorUserId:    $ctx->actorUserId > 0 ? $ctx->actorUserId : null,
			actorRole:      ( null !== $user && ! empty( $user->roles ) )
				? (string) reset( $user->roles )
				: null,
			personId:       $personId,
			fieldsAccessed: implode( ',', $fields ),
			accessReason:   $reason,
			actorIp:        $ctx->ip,
			createdAt:      current_time( 'mysql', true ),
		) );
	}
}