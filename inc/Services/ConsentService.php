<?php

declare( strict_types=1 );

namespace Inc\Services;

use Inc\DTO\RequestContextDTO;
use Inc\Enums\AuditAction;
use Inc\Enums\ConsentType;
use Inc\Repositories\WPDBRepositories\ConsentRepository;
use RuntimeException;

/**
 * Class ConsentService
 *
 * Управление согласиями на обработку персональных данных (152-ФЗ).
 *
 * @package Inc\Services
 *
 * ### Основные обязанности:
 *
 * 1. **Версионирование документов** — определяет актуальную версию по папкам templates/consents/.
 * 2. **Чтение текстов** — возвращает содержимое HTML-файла согласия; папки версий неизменны.
 * 3. **Фиксация факта подписания** — создаёт запись с версией, хэшем документа, IP и UA.
 * 4. **Отзыв согласия** — проставляет withdrawn_at и пишет аудит.
 * 5. **Привязка к persons** — после создания person-записей обновляет person_id в согласиях.
 *
 * ### Инвариант версионирования:
 *
 * Папки v1/, v2/, ... никогда не изменяются и не удаляются — только добавляются новые.
 * Это гарантирует воспроизведение точного текста, который подписал конкретный человек.
 *
 * ### Хэш документа:
 *
 * sha256 от байтового содержимого HTML-файла. Хранится в consents.document_hash
 * для доказательства того, что подписанный текст соответствует версии в архиве.
 *
 * ### Роль субъекта (subject_role):
 *
 * Единственная роль — 'guardian': законный представитель подписывает за ребёнка.
 * По 152-ФЗ достаточно одного согласия от родителя; отдельное согласие от ученика не требуется.
 */
readonly class ConsentService {

	/**
	 * Конструктор сервиса.
	 *
	 * @param ConsentRepository $consentRepository Репозиторий согласий
	 * @param AuditService      $auditService      Сервис аудита
	 */
	public function __construct(
		private ConsentRepository $consentRepository,
		private AuditService      $auditService,
	) {}

	/**
	 * Возвращает последнюю доступную версию согласия.
	 *
	 * Сканирует директорию templates/consents/ на наличие папок вида v1, v2, ...
	 * и возвращает натурально-максимальную. Не зависит от конкретного типа согласия —
	 * все типы версионируются совместно.
	 *
	 * @param ConsentType $type Тип согласия
	 *
	 * @return string Версия (например 'v1')
	 *
	 * @throws RuntimeException Если директория не найдена или не содержит ни одной версии
	 */
	public function getCurrentVersion( ConsentType $type ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$dir = FS_LMS_PATH . 'templates/consents/';

		if ( ! is_dir( $dir ) ) {
			throw new RuntimeException( "Директория согласий не найдена: {$dir}" );
		}

		$entries   = scandir( $dir );
		$versions  = array_filter(
			$entries !== false ? $entries : array(),
			static fn( string $entry ) => preg_match( '/^v\d+$/', $entry ) === 1
				&& is_dir( $dir . $entry )
		);

		if ( empty( $versions ) ) {
			throw new RuntimeException( "В директории {$dir} нет ни одной версии согласия." );
		}

		natsort( $versions );

		return end( $versions );
	}

	/**
	 * Возвращает HTML-текст согласия для заданной версии.
	 *
	 * @param ConsentType $type    Тип согласия
	 * @param string      $version Версия (например 'v1')
	 *
	 * @return string Содержимое HTML-файла
	 *
	 * @throws RuntimeException Если файл согласия не существует
	 */
	public function getDocumentText( ConsentType $type, string $version ): string {
		$path = FS_LMS_PATH . $type->templateFile( $version );

		if ( ! file_exists( $path ) ) {
			throw new RuntimeException(
				"Текст согласия не найден: {$type->value} {$version} ({$path})"
			);
		}

		$content = file_get_contents( $path );

		if ( $content === false ) {
			throw new RuntimeException( "Не удалось прочитать файл согласия: {$path}" );
		}

		return $content;
	}

	/**
	 * Возвращает sha256-хэш байтового содержимого файла согласия.
	 *
	 * @param ConsentType $type    Тип согласия
	 * @param string      $version Версия (например 'v1')
	 *
	 * @return string Hex-строка sha256
	 *
	 * @throws RuntimeException Если файл не найден
	 */
	public function getDocumentHash( ConsentType $type, string $version ): string {
		return hash( 'sha256', $this->getDocumentText( $type, $version ) );
	}

	/**
	 * Фиксирует подписание согласия законным представителем за ребёнка.
	 *
	 * Аналогично recordSelfConsent, но subject_role = 'guardian' и заполняется
	 * signed_for_person_id — ID person ребёнка, за которого подписано согласие.
	 *
	 * @param int|null          $appId       ID заявки (null — вне контекста заявки)
	 * @param ConsentType       $type        Тип согласия
	 * @param int               $forPersonId ID person ребёнка
	 * @param RequestContextDTO $ctx         Контекст HTTP-запроса (IP, UA)
	 *
	 * @return int ID созданной записи
	 *
	 * @throws RuntimeException Если шаблон согласия не найден
	 */
	public function recordGuardianConsent( ?int $appId, ConsentType $type, int $forPersonId, RequestContextDTO $ctx ): int {
		$version = $this->getCurrentVersion( $type );
		$hash    = $this->getDocumentHash( $type, $version );

		$id = $this->consentRepository->create( array(
			'application_id'      => $appId,
			'consent_type'        => $type->value,
			'subject_role'        => 'guardian',
			'version'             => $version,
			'document_hash'       => $hash,
			'ip_address'          => $ctx->ip,
			'user_agent'          => $ctx->userAgent,
			'accepted_at'         => current_time( 'mysql', true ),
			'signed_for_person_id' => $forPersonId,
		) );

		$this->auditService->recordAnonymous(
			AuditAction::ConsentSigned->value,
			'consent',
			$id,
			array(
				'consent_type'    => $type->value,
				'version'         => $version,
				'application_id'  => $appId,
				'subject_role'    => 'guardian',
				'for_person_id'   => $forPersonId,
			),
		);

		return $id;
	}

	/**
	 * Привязывает согласия заявки к записям person по роли субъекта.
	 *
	 * Вызывается после того, как person-записи созданы: обновляет person_id
	 * в согласиях на основе subject_role → person_id.
	 *
	 * @param int                $appId     ID заявки
	 * @param array<string, int> $personMap subject_role => person_id
	 *
	 * @return void
	 */
	public function bindToPersons( int $appId, array $personMap ): void {
		$this->consentRepository->bindApplicationConsentsToPersons( $appId, $personMap );
	}

	/**
	 * Отзывает согласие.
	 *
	 * Проставляет withdrawn_at и причину отзыва. Пишет audit log.
	 *
	 * @param int    $consentId ID согласия
	 * @param string $reason    Причина отзыва
	 *
	 * @return void
	 *
	 * @throws RuntimeException Если согласие не найдено
	 */
	public function withdraw( int $consentId, string $reason ): void {
		if ( null === $this->consentRepository->find( $consentId ) ) {
			throw new RuntimeException( "Согласие с ID {$consentId} не найдено." );
		}

		$this->consentRepository->withdraw( $consentId, $reason );

		$this->auditService->record(
			AuditAction::ConsentWithdrawn->value,
			'consent',
			$consentId,
			array( 'reason' => $reason ),
		);
	}
}
