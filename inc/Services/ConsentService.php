<?php

declare( strict_types=1 );

namespace Inc\Services;

use Inc\DTO\RequestContextDTO;
use Inc\Enums\AuditAction;
use Inc\Enums\ConsentType;
use Inc\Enums\OptionName;
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
 * ### Хранение текстов:
 *
 * Тексты согласий управляются через вкладку "Согласия" в настройках плагина
 * и хранятся в wp_options (OptionName::ConsentTexts). Структура:
 * [
 *   'pd_processing' => [
 *     'current_version' => 'v1',
 *     'versions' => ['v1' => 'HTML-текст...', 'v2' => 'HTML-текст v2...'],
 *   ],
 *   ...
 * ]
 * Версии никогда не удаляются — только добавляются новые, чтобы можно было
 * воспроизвести точный текст, который подписал конкретный человек.
 *
 * ### Хэш документа:
 *
 * sha256 от текста согласия. Хранится в consents.document_hash для доказательства
 * соответствия подписанного текста версии в архиве.
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
	 * Возвращает текущую (активную) версию согласия.
	 *
	 * @param ConsentType $type Тип согласия
	 *
	 * @return string Версия (например 'v1')
	 *
	 * @throws RuntimeException Если для данного типа нет ни одной опубликованной версии
	 */
	public function getCurrentVersion( ConsentType $type ): string {
		$data = $this->loadAll();
		$key  = $type->value;

		if ( empty( $data[ $key ]['current_version'] ) ) {
			throw new RuntimeException(
				'Текст согласия "' . $type->label() . '" не опубликован. Добавьте его в Настройки → Согласия.'
			);
		}

		return (string) $data[ $key ]['current_version'];
	}

	/**
	 * Возвращает HTML-текст согласия для заданной версии.
	 *
	 * @param ConsentType $type    Тип согласия
	 * @param string      $version Версия (например 'v1')
	 *
	 * @return string HTML-текст согласия
	 *
	 * @throws RuntimeException Если версия не найдена
	 */
	public function getDocumentText( ConsentType $type, string $version ): string {
		$data = $this->loadAll();
		$key  = $type->value;

		$text = $data[ $key ]['versions'][ $version ] ?? null;

		if ( null === $text || '' === $text ) {
			throw new RuntimeException(
				'Версия "' . $version . '" согласия "' . $type->label() . '" не найдена.'
			);
		}

		return (string) $text;
	}

	/**
	 * Сохраняет новую версию текста согласия и делает её текущей.
	 *
	 * Вызывается из callback-а страницы настроек плагина (вкладка "Согласия").
	 * Версии только добавляются, никогда не изменяются.
	 *
	 * @param ConsentType $type Тип согласия
	 * @param string      $text HTML-текст новой версии
	 *
	 * @return string Присвоенный номер версии (например 'v2')
	 */
	public function saveVersion( ConsentType $type, string $text ): string {
		$data = $this->loadAll();
		$key  = $type->value;

		$existing = $data[ $key ]['versions'] ?? array();
		$nextNum  = count( $existing ) + 1;
		$version  = 'v' . $nextNum;

		$data[ $key ]['versions'][ $version ]  = $text;
		$data[ $key ]['current_version']        = $version;

		update_option( OptionName::ConsentTexts->value, $data );

		return $version;
	}

	/**
	 * Возвращает все версии текстов для заданного типа.
	 *
	 * @param ConsentType $type Тип согласия
	 *
	 * @return array<string, string> ['v1' => 'текст', 'v2' => '...']
	 */
	public function getVersions( ConsentType $type ): array {
		$data = $this->loadAll();
		return (array) ( $data[ $type->value ]['versions'] ?? array() );
	}

	/**
	 * Загружает все тексты согласий из wp_options.
	 *
	 * @return array
	 */
	private function loadAll(): array {
		return (array) get_option( OptionName::ConsentTexts->value, array() );
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
	 * Фиксирует подписание согласия самим субъектом (subject_role = 'self').
	 *
	 * @param int|null          $appId ID заявки (null — вне контекста заявки)
	 * @param ConsentType       $type  Тип согласия
	 * @param RequestContextDTO $ctx   Контекст HTTP-запроса (IP, UA)
	 *
	 * @return int ID созданной записи
	 *
	 * @throws RuntimeException Если шаблон согласия не найден
	 */
	public function recordSelfConsent( ?int $appId, ConsentType $type, RequestContextDTO $ctx ): int {
		$version = $this->getCurrentVersion( $type );
		$hash    = $this->getDocumentHash( $type, $version );

		$id = $this->consentRepository->create( array(
			'application_id'       => $appId,
			'consent_type'         => $type->value,
			'subject_role'         => 'self',
			'version'              => $version,
			'document_hash'        => $hash,
			'ip_address'           => $ctx->ip,
			'user_agent'           => $ctx->userAgent,
			'accepted_at'          => current_time( 'mysql', true ),
			'signed_for_person_id' => null,
		) );

		$this->auditService->recordAnonymous(
			AuditAction::ConsentSigned->value,
			'consent',
			$id,
			array(
				'consent_type'   => $type->value,
				'version'        => $version,
				'application_id' => $appId,
				'subject_role'   => 'self',
			),
		);

		return $id;
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
