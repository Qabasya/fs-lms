<?php

declare( strict_types=1 );

namespace Inc\Services\Enrollment;

use Inc\Enums\AuditAction;
use Inc\Managers\UserManager;
use Inc\Services\AuditService;
use RuntimeException;

/**
 * Class PasswordLinkService
 *
 * Генерация и инвалидация одноразовых ссылок установки пароля для LMS-пользователей.
 *
 * @package Inc\Services
 *
 * ### Основные обязанности:
 *
 * 1. **Генерация ссылки** — создаёт WP password reset key и собирает полный URL.
 * 2. **Инвалидация** — сбрасывает user_activation_key, делая старую ссылку недействительной.
 * 3. **TTL** — возвращает актуальный TTL через стандартный WP-фильтр.
 *
 * ### Архитектурная роль:
 *
 * Единственная точка создания password reset ссылок. Автоматически пишет
 * audit log при каждой генерации. Вызывается из EnrollmentService (при зачислении)
 * и из AJAX-callback регенерации ссылки в карточке пользователя.
 *
 * ### Важно:
 *
 * Ссылка содержит ключ для смены пароля — не логировать и не передавать в деталях аудита.
 * В audit log пишется только факт генерации (actor, userId), без самого ключа.
 *
 * ### TTL:
 *
 * Базовый TTL — DAY_IN_SECONDS (24 часа). Для LMS-ролей (lms_student, lms_parent)
 * фильтр password_reset_expiration должен быть расширен до 48 часов в контроллере.
 */
readonly class PasswordLinkService {

	/**
	 * Конструктор сервиса.
	 *
	 * @param UserManager  $userManager  Менеджер пользователей
	 * @param AuditService $auditService Сервис аудита
	 */
	public function __construct(
		private UserManager  $userManager,
		private AuditService $auditService,
	) {}

	/**
	 * Генерирует одноразовую ссылку установки пароля для WP-пользователя.
	 *
	 * Получает WP_User через UserManager, вызывает get_password_reset_key(),
	 * строит URL и пишет audit log. Старый ключ при этом автоматически инвалидируется WP.
	 *
	 * @param int $userId ID пользователя WordPress
	 *
	 * @return string Полный URL вида https://site.com/wp-login.php?action=rp&key=...&login=...
	 *
	 * @throws RuntimeException Если пользователь не найден или WP вернул WP_Error
	 */
	public function generate( int $userId ): string {
		$user = $this->userManager->find( $userId );

		if ( null === $user ) {
			throw new RuntimeException( "Пользователь с ID {$userId} не найден." );
		}

		$key = $this->userManager->generatePasswordResetKey( $userId );

		$url = network_site_url(
			'wp-login.php?action=rp&key=' . rawurlencode( $key )
			. '&login=' . rawurlencode( $user->user_login ),
			'login'
		);

		$this->auditService->record(
			AuditAction::PasswordLinkGenerated->value,
			'user',
			$userId,
		);

		return $url;
	}

	/**
	 * Инвалидирует текущую ссылку сброса пароля пользователя.
	 *
	 * Сбрасывает user_activation_key, после чего любая ранее выданная
	 * ссылка становится недействительной.
	 *
	 * @param int $userId ID пользователя WordPress
	 *
	 * @return void
	 *
	 * @throws RuntimeException Если пользователь не найден или обновление не удалось
	 */
	public function invalidate( int $userId ): void {
		$this->userManager->clearActivationKey( $userId );
	}

	/**
	 * Возвращает актуальный TTL ссылки сброса пароля в секундах.
	 *
	 * Делегирует в WP-фильтр password_reset_expiration, который может
	 * быть расширен в контроллере (48 ч для lms_student / lms_parent).
	 *
	 * @return int TTL в секундах
	 */
	public function getDefaultTtl(): int {
		return (int) apply_filters( 'password_reset_expiration', DAY_IN_SECONDS );
	}
}