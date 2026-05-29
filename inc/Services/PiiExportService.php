<?php

declare( strict_types=1 );

namespace Inc\Services;

use Inc\Enums\AuditAction;
use Inc\Repositories\WPDBRepositories\ConsentRepository;
use Inc\Repositories\WPDBRepositories\EnrollmentRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\PiiAccessLogRepository;
use Inc\Repositories\WPDBRepositories\RelationshipRepository;
use InvalidArgumentException;

/**
 * Class PiiExportService
 *
 * Сервис для экспорта персональных данных (PII) в соответствии с GDPR/152-ФЗ.
 *
 * @package Inc\Services
 *
 * ### Основные обязанности:
 *
 * 1. **Сбор данных** — сбор всех персональных данных человека (включая зачисления, связи, согласия).
 * 2. **Расшифровка** — расшифровка зашифрованных PII-полей.
 * 3. **Создание файла** — сохранение данных в JSON-файл на сервере.
 * 4. **Генерация ссылки** — создание одноразовой ссылки для скачивания экспорта.
 *
 * ### Архитектурная роль:
 *
 * Делегирует работу с БД соответствующим репозиториям.
 * Использует PiiCryptoService для расшифровки данных.
 * Логирует факт экспорта в AuditService и PiiAccessLogRepository.
 *
 * ### Примечания:
 *
 * - Экспорт включает: личные данные, зачисления, связи, согласия.
 * - Файлы хранятся временно (1 час) в wp-content/uploads/lms-exports/
 * - Ссылка одноразовая — после скачивания файл удаляется.
 */
readonly class PiiExportService {

	/**
	 * Конструктор сервиса.
	 *
	 * @param PersonRepository        $personRepository        Репозиторий лиц
	 * @param EnrollmentRepository    $enrollmentRepository    Репозиторий зачислений
	 * @param RelationshipRepository  $relationshipRepository  Репозиторий связей
	 * @param ConsentRepository       $consentRepository       Репозиторий согласий
	 * @param PiiCryptoService        $crypto                  Сервис шифрования PII
	 * @param PiiAccessLogRepository  $piiAccessLogRepository  Репозиторий логов доступа к PII
	 * @param AuditService            $auditService            Сервис аудита
	 */
	public function __construct(
		private PersonRepository      $personRepository,
		private EnrollmentRepository  $enrollmentRepository,
		private RelationshipRepository $relationshipRepository,
		private ConsentRepository     $consentRepository,
		private PiiCryptoService      $crypto,
		private PiiAccessLogRepository $piiAccessLogRepository,
		private AuditService          $auditService,
	) {}

	/**
	 * Собирает и формирует JSON-данные для экспорта.
	 *
	 * @param int $personId ID лица (из таблицы persons)
	 * @param int $actorId  ID пользователя, запросившего экспорт
	 *
	 * @throws InvalidArgumentException Если лицо не найдено
	 *
	 * @return string JSON-строка с данными для экспорта
	 */
	public function buildExport( int $personId, int $actorId ): string {
		$person = $this->personRepository->find( $personId );

		if ( null === $person ) {
			throw new InvalidArgumentException( "Person с ID {$personId} не найден." );
		}

		// Расшифровка персональных данных
		$decrypted = array(
			'full_name'  => null !== $person->fullNameEnc ? $this->crypto->decrypt( $person->fullNameEnc ) : null,
			'doc_number' => null !== $person->docNumberEnc ? $this->crypto->decrypt( $person->docNumberEnc ) : null,
			'inn'        => null !== $person->innEnc ? $this->crypto->decrypt( $person->innEnc ) : null,
			'address'    => null !== $person->addressEnc ? $this->crypto->decrypt( $person->addressEnc ) : null,
			'phone'      => null !== $person->phoneEnc ? $this->crypto->decrypt( $person->phoneEnc ) : null,
			'email'      => $person->email,
		);

		// Логирование доступа к PII (GDPR requirement)
		$this->piiAccessLogRepository->create( array(
			'actor_user_id'   => $actorId,
			'actor_role'      => 'exporter',
			'person_id'       => $personId,
			'fields_accessed' => 'full_name,doc_number,inn,address,phone',
			'access_reason'   => 'gdpr_export',
			'actor_ip'        => '',  // Заполняется в контроллере
			'created_at'      => current_time( 'mysql', true ),
		) );

		// Сбор связанных данных
		$enrollments   = $this->enrollmentRepository->findByStudent( $personId );
		$relAsStudent  = $this->relationshipRepository->findActiveByStudent( $personId );
		$relAsGuardian = $this->relationshipRepository->findActiveByGuardian( $personId );
		$consents      = $this->consentRepository->findByPerson( $personId );

		// Логирование факта экспорта в аудит
		$this->auditService->record( AuditAction::PiiExported->value, 'person', $personId );

		// Преобразование DTO в массивы
		$enrollmentsData   = array_map( fn( $e ) => $e->toArray(), $enrollments );
		$relationshipsData = array_map( fn( $r ) => $r->toArray(), array_merge( $relAsStudent, $relAsGuardian ) );
		$consentsData      = array_map( fn( $c ) => $c->toArray(), $consents );

		// Формирование JSON
		return (string) wp_json_encode( array(
			'exported_at'   => current_time( 'c' ),      // RFC 3339 (ISO 8601)
			'person'        => $decrypted,
			'enrollments'   => $enrollmentsData,
			'relationships' => $relationshipsData,
			'consents'      => $consentsData,
		) );
	}

	/**
	 * Создаёт одноразовую ссылку для скачивания экспорта.
	 *
	 * @param string $payload JSON-строка с данными
	 *
	 * @return string URL для скачивания
	 */
	public function createDownloadLink( string $payload ): string {
		// wp_generate_password() — генерация случайного токена
		$token = wp_generate_password( 32, false );

		// wp_upload_dir() — получение пути к директории загрузок
		$uploadDir = wp_upload_dir();
		$dir       = $uploadDir['basedir'] . '/lms-exports/';

		// wp_mkdir_p() — создание директории (рекурсивно)
		wp_mkdir_p( $dir );

		// Сохранение файла на сервере
		$filename = $dir . $token . '.json';
		// file_put_contents() — запись строки в файл
		file_put_contents( $filename, $payload );

		// set_transient() — сохранение пути к файлу по токену (время жизни — 1 час)
		set_transient( 'fs_lms_export_' . $token, $filename, HOUR_IN_SECONDS );

		// home_url() — формирование полного URL для скачивания
		return home_url( '/lms/pii-export/' . $token );
	}
}