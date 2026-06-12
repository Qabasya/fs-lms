<?php

declare( strict_types=1 );

namespace Inc\Services;

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Log\Events\ConsentChangedEvent;
use Inc\DTO\RequestContextDTO;
use Inc\Enums\LogEvent;
use Inc\Repositories\OptionsRepositories\ConsentDefinitionsRepository;
use Inc\Repositories\WPDBRepositories\ConsentRepository;
use RuntimeException;

/**
 * Class ConsentService
 *
 * Сервис управления согласиями на обработку персональных данных (152-ФЗ, GDPR).
 *
 * @package Inc\Services
 *
 * ### Основные обязанности:
 *
 * 1. **Получение версий согласий** — расчёт SHA-256 хеша контента страницы согласия.
 * 2. **Получение текста документа** — по типу и версии (текущая или историческая ревизия).
 * 3. **Фиксация подписания** — запись согласия для субъекта (self) или законного представителя (guardian).
 * 4. **Привязка к лицам** — связывание согласий заявки с конкретными записями persons.
 * 5. **Отзыв согласия** — логирование отзыва с указанием причины.
 *
 * ### Архитектурная роль:
 *
 * Делегирует хранение согласий ConsentRepository, определения — ConsentDefinitionsRepository,
 * события — LogEventDispatcher.
 *
 * ### Примечания:
 *
 * - Каждое согласие — отдельная WP-страница.
 * - Версия = SHA-256(post_content) (хеш содержимого страницы).
 * - История версий хранится в штатных WP-ревизиях.
 * - Документация: соответствие 152-ФЗ и GDPR.
 */
readonly class ConsentService {

	/**
	 * Конструктор сервиса.
	 *
	 * @param ConsentRepository            $consentRepository    Репозиторий согласий
	 * @param ConsentDefinitionsRepository $consentDefinitions   Репозиторий определений согласий
	 * @param ClockInterface               $clock                Интерфейс часов
	 * @param LogEventDispatcherInterface  $logEvents            Диспетчер событий логирования
	 */
	public function __construct(
		private ConsentRepository            $consentRepository,
		private ConsentDefinitionsRepository $consentDefinitions,
		private ClockInterface               $clock,
		private LogEventDispatcherInterface  $logEvents,
	) {}

	/**
	 * Возвращает текущий SHA-256 хеш текста согласия.
	 *
	 * @param string $typeKey Ключ типа согласия
	 *
	 * @throws RuntimeException Если страница не создана или не найдена
	 *
	 * @return string
	 */
	public function getCurrentVersion( string $typeKey ): string {
		$page = $this->requirePage( $typeKey );
		// hash() — вычисляет SHA-256 хеш содержимого страницы
		return hash( 'sha256', $page->post_content );
	}

	/**
	 * Возвращает человекочитаемое название согласия.
	 *
	 * @param string $typeKey Ключ типа согласия
	 *
	 * @return string
	 */
	public function getDefinitionName( string $typeKey ): string {
		$def = $this->consentDefinitions->findByKey( $typeKey );
		return $def ? (string) ( $def['name'] ?? $typeKey ) : $typeKey;
	}

	/**
	 * Возвращает HTML-текст согласия по ключу типа и версии (SHA-256 хеш).
	 * Если version == hash(current) — возвращает текущую версию.
	 * Иначе ищет совпадение среди WP-ревизий страницы.
	 *
	 * @param string $typeKey Ключ типа согласия
	 * @param string $version SHA-256 хеш версии
	 *
	 * @throws RuntimeException Если страница или версия не найдена
	 *
	 * @return string
	 */
	public function getDocumentText( string $typeKey, string $version ): string {
		$page = $this->requirePage( $typeKey );

		// Проверка текущей версии
		if ( hash( 'sha256', $page->post_content ) === $version || 'current' === $version ) {
			return $page->post_content;
		}

		// Поиск среди ревизий
		foreach ( wp_get_post_revisions( $page->ID, array( 'order' => 'DESC' ) ) as $revision ) {
			if ( hash( 'sha256', $revision->post_content ) === $version ) {
				return $revision->post_content;
			}
		}

		throw new RuntimeException( "Версия согласия не найдена: {$version}" );
	}

	/**
	 * Фиксирует подписание согласия субъектом (сам студент).
	 *
	 * @param int|null          $appId  ID заявки
	 * @param string            $typeKey Ключ типа согласия
	 * @param RequestContextDTO $ctx    Контекст запроса (IP, User-Agent)
	 *
	 * @throws RuntimeException Если страница согласия не настроена
	 *
	 * @return int ID записи согласия
	 */
	public function recordSelfConsent( ?int $appId, string $typeKey, RequestContextDTO $ctx ): int {
		$version = $this->getCurrentVersion( $typeKey );

		$id = $this->consentRepository->create( array(
			'application_id'       => $appId,
			'consent_type'         => $typeKey,
			'subject_role'         => 'self',
			'version'              => $version,
			'document_hash'        => $version,
			'ip_address'           => $ctx->ip,
			'user_agent'           => $ctx->userAgent,
			'accepted_at'          => $this->clock->now( 'mysql', true ),
			'signed_for_person_id' => null,
		) );

		$this->logEvents->dispatch(
			LogEvent::ConsentChanged,
			new ConsentChangedEvent( null, null, $typeKey, null, $version )
		);

		return $id;
	}

	/**
	 * Фиксирует подписание согласия законным представителем за ребёнка.
	 *
	 * @param int|null          $appId        ID заявки
	 * @param string            $typeKey      Ключ типа согласия
	 * @param int               $forPersonId  ID ребёнка (из persons)
	 * @param RequestContextDTO $ctx          Контекст запроса
	 *
	 * @throws RuntimeException Если страница согласия не настроена
	 *
	 * @return int ID записи согласия
	 */
	public function recordGuardianConsent( ?int $appId, string $typeKey, int $forPersonId, RequestContextDTO $ctx ): int {
		$version = $this->getCurrentVersion( $typeKey );

		$id = $this->consentRepository->create( array(
			'application_id'       => $appId,
			'consent_type'         => $typeKey,
			'subject_role'         => 'guardian',
			'version'              => $version,
			'document_hash'        => $version,
			'ip_address'           => $ctx->ip,
			'user_agent'           => $ctx->userAgent,
			'accepted_at'          => $this->clock->now( 'mysql', true ),
			'signed_for_person_id' => $forPersonId ?: null,
		) );

		$this->logEvents->dispatch(
			LogEvent::ConsentChanged,
			new ConsentChangedEvent( null, $forPersonId ?: null, $typeKey, null, $version )
		);

		return $id;
	}

	/**
	 * Привязывает согласия заявки к записям persons по роли субъекта.
	 *
	 * @param int   $appId     ID заявки
	 * @param array $personMap Маппинг ['self' => personId, 'guardian' => personId]
	 *
	 * @return void
	 */
	public function bindToPersons( int $appId, array $personMap ): void {
		$this->consentRepository->bindApplicationConsentsToPersons( $appId, $personMap );
	}

	/**
	 * Отзывает согласие.
	 *
	 * @param int    $consentId ID записи согласия
	 * @param string $reason    Причина отзыва
	 *
	 * @throws RuntimeException Если согласие не найдено
	 *
	 * @return void
	 */
	public function withdraw( int $consentId, string $reason ): void {
		if ( null === $this->consentRepository->find( $consentId ) ) {
			throw new RuntimeException( "Согласие с ID {$consentId} не найдено." );
		}

		$consent = $this->consentRepository->find( $consentId );
		$this->consentRepository->withdraw( $consentId, $reason );

		$this->logEvents->dispatch(
			LogEvent::ConsentChanged,
			new ConsentChangedEvent(
				get_current_user_id() ?: null,
				$consent?->personId,
				$consent?->consentType ?? '',
				$consent?->documentHash,
				null,
			)
		);
	}

	/**
	 * Возвращает WP-страницу для указанного типа согласия, или null, если не найдена.
	 *
	 * @param string $typeKey Ключ типа согласия
	 *
	 * @return \WP_Post|null
	 */
	public function getPageForType( string $typeKey ): ?\WP_Post {
		try {
			return $this->requirePage( $typeKey );
		} catch ( RuntimeException $e ) {
			return null;
		}
	}

	/**
	 * Получает WP-страницу согласия или выбрасывает исключение.
	 *
	 * @param string $typeKey Ключ типа согласия
	 *
	 * @throws RuntimeException Если определение не найдено, страница не создана или не опубликована
	 *
	 * @return \WP_Post
	 */
	private function requirePage( string $typeKey ): \WP_Post {
		$def = $this->consentDefinitions->findByKey( $typeKey );
		if ( ! $def ) {
			throw new RuntimeException( "Согласие '{$typeKey}' не найдено. Создайте его в настройках." );
		}

		$pageId = (int) ( $def['page_id'] ?? 0 );
		if ( $pageId <= 0 ) {
			throw new RuntimeException( "Страница для согласия '{$typeKey}' не создана." );
		}

		$page = get_post( $pageId );
		if ( ! $page instanceof \WP_Post || 'publish' !== $page->post_status ) {
			throw new RuntimeException( "Страница согласия не опубликована." );
		}

		return $page;
	}
}