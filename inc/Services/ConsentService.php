<?php

declare( strict_types=1 );

namespace Inc\Services;

use Inc\DTO\RequestContextDTO;
use Inc\Enums\AuditAction;
use Inc\Enums\ConsentType;
use Inc\Enums\PageRoutes;
use Inc\Managers\PostManager;
use Inc\Repositories\OptionsRepositories\ConsentOptionsRepository;
use Inc\Repositories\WPDBRepositories\ConsentRepository;
use RuntimeException;
use WP_Post;

/**
 * Class ConsentService
 *
 * Управление согласиями на обработку персональных данных (152-ФЗ).
 *
 * @package Inc\Services
 *
 * ### Источник текста:
 *
 * Текст согласия хранится непосредственно в содержимом WP-страницы
 * с маршрутом PageRoutes::ConsentPage ('consent'). Редактируется через стандартный
 * редактор WordPress — без отдельного пункта в меню плагина.
 *
 * ### Версионирование:
 *
 * При каждом сохранении страницы (хук save_post, через ConsentController) вычисляется
 * sha256 от post_content и сохраняется в wp_options (OptionName::ConsentPageMeta)
 * вместе с датой обновления. Этот хэш используется как идентификатор версии
 * в записях таблицы consents.
 *
 * Полная история текстов сохраняется через штатные ревизии WordPress.
 *
 * ### Роль субъекта (subject_role):
 *
 * 'self'     — ученик соглашается за себя.
 * 'guardian' — законный представитель соглашается за ребёнка (по 152-ФЗ).
 */
readonly class ConsentService {

	/**
	 * @param ConsentRepository $consentRepository Репозиторий согласий
	 * @param AuditService      $auditService      Сервис аудита
	 */
	public function __construct(
		private ConsentRepository       $consentRepository,
		private AuditService            $auditService,
		private PostManager             $postManager,
		private ConsentOptionsRepository $consentOptions,
	) {}

	/**
	 * Возвращает текущую версию согласия — sha256-хэш актуального текста.
	 *
	 * @throws RuntimeException Если хэш ещё не вычислен (страница ни разу не сохранялась после активации)
	 */
	public function getCurrentVersion( ConsentType $type ): string {
		$hash = $this->getStoredHash();

		if ( '' === $hash ) {
			throw new RuntimeException(
				'Хэш согласия не вычислен. Сохраните страницу «Согласие» в редакторе WordPress.'
			);
		}

		return $hash;
	}

	/**
	 * Возвращает HTML-текст согласия из содержимого WP-страницы.
	 *
	 * Параметры $type и $version сохранены для обратной совместимости сигнатуры
	 * (ConsentController передаёт их из URL /lms/consent/{type}/{version}).
	 *
	 * @throws RuntimeException Если страница не найдена
	 */
	public function getDocumentText( ConsentType $type, string $version ): string {
		$page = $this->postManager->findByPath( PageRoutes::ConsentPage->value );

		if ( null === $page ) {
			throw new RuntimeException(
				'Страница согласия не найдена. Переактивируйте плагин.'
			);
		}

		return $page->post_content;
	}

	/**
	 * Возвращает sha256-хэш текста согласия из wp_options.
	 *
	 * Параметры $type и $version сохранены для обратной совместимости сигнатуры.
	 */
	public function getDocumentHash( ConsentType $type, string $version ): string {
		return $this->getStoredHash();
	}

	/**
	 * Пересчитывает хэш и дату обновления, если сохраняется страница согласия.
	 *
	 * Вызывается из ConsentController на хуке save_post.
	 * Игнорирует автосохранения, черновики и посты с другим slug.
	 */
	public function onConsentPageSaved( WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( 'page' !== $post->post_type || 'publish' !== $post->post_status ) {
			return;
		}

		if ( PageRoutes::ConsentPage->value !== $post->post_name ) {
			return;
		}

		$this->consentOptions->savePageMeta( array(
			'hash'       => hash( 'sha256', $post->post_content ),
			'updated_at' => current_time( 'c', true ),
		) );
	}

	/**
	 * Фиксирует подписание согласия самим субъектом (subject_role = 'self').
	 *
	 * @throws RuntimeException Если хэш согласия ещё не вычислен
	 */
	public function recordSelfConsent( ?int $appId, ConsentType $type, RequestContextDTO $ctx ): int {
		$version = $this->getCurrentVersion( $type );

		$id = $this->consentRepository->create( array(
			'application_id'       => $appId,
			'consent_type'         => $type->value,
			'subject_role'         => 'self',
			'version'              => $version,
			'document_hash'        => $version,
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
	 * @throws RuntimeException Если хэш согласия ещё не вычислен
	 */
	public function recordGuardianConsent( ?int $appId, ConsentType $type, int $forPersonId, RequestContextDTO $ctx ): int {
		$version = $this->getCurrentVersion( $type );

		$id = $this->consentRepository->create( array(
			'application_id'       => $appId,
			'consent_type'         => $type->value,
			'subject_role'         => 'guardian',
			'version'              => $version,
			'document_hash'        => $version,
			'ip_address'           => $ctx->ip,
			'user_agent'           => $ctx->userAgent,
			'accepted_at'          => current_time( 'mysql', true ),
			'signed_for_person_id' => $forPersonId,
		) );

		$this->auditService->recordAnonymous(
			AuditAction::ConsentSigned->value,
			'consent',
			$id,
			array(
				'consent_type'   => $type->value,
				'version'        => $version,
				'application_id' => $appId,
				'subject_role'   => 'guardian',
				'for_person_id'  => $forPersonId,
			),
		);

		return $id;
	}

	/**
	 * Привязывает согласия заявки к записям person по роли субъекта.
	 */
	public function bindToPersons( int $appId, array $personMap ): void {
		$this->consentRepository->bindApplicationConsentsToPersons( $appId, $personMap );
	}

	/**
	 * Отзывает согласие.
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

	private function getStoredHash(): string {
		$meta = $this->consentOptions->getPageMeta();
		return (string) ( $meta['hash'] ?? '' );
	}
}