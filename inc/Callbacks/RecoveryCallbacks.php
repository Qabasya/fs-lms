<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Services\ApplicationService;
use Inc\Services\RecoveryService;
use Inc\Services\RetentionService;

/**
 * Class RecoveryCallbacks
 *
 * Cron-коллбеки для recovery (восстановления) и retention (очистки) задач.
 *
 * @package Inc\Callbacks
 *
 * ### Основные обязанности:
 *
 * 1. **Восстановление зависших зачислений** — поиск и разрешение проблемных зачислений.
 * 2. **Истечение заявок** — автоматическое закрытие просроченных заявок.
 * 3. **Очистка устаревших данных** — анонимизация удалённых лиц, удаление старых логов.
 *
 * ### Архитектурная роль:
 *
 * Вызывается через WordPress Cron (wp_schedule_event) для фонового выполнения
 * периодических задач по обслуживанию системы зачисления.
 *
 * ### Требования к безопасности:
 *
 * Методы не должны требовать авторизации, так как вызываются из cron.
 * Все ошибки логируются, но не прерывают выполнение других задач.
 */
class RecoveryCallbacks extends BaseController {

	/**
	 * Конструктор коллбеков.
	 *
	 * @param RecoveryService   $recoveryService    Сервис восстановления зависших зачислений
	 * @param ApplicationService $applicationService Сервис работы с заявками
	 * @param RetentionService  $retentionService   Сервис очистки устаревших данных
	 */
	public function __construct(
		private readonly RecoveryService  $recoveryService,
		private readonly ApplicationService $applicationService,
		private readonly RetentionService $retentionService,
	) {
		parent::__construct();
	}

	/**
	 * Задача cron: восстановление зависших зачислений.
	 * Ищет заявки в статусе Enrolling, которые не были завершены в течение
	 * заданного таймаута, и повторяет попытку создания пользователей WordPress.
	 *
	 * @return void
	 */
	public function cronRecoveryTick(): void {
		try {
			$this->recoveryService->resolveStuckEnrollments();
		} catch ( \Throwable $e ) {
			// error_log() — запись ошибки в лог PHP
			error_log( '[FS LMS] Recovery tick error: ' . $e->getMessage() );
		}
	}

	/**
	 * Задача cron: истечение просроченных заявок.
	 * Находит заявки в статусах PendingParent и ReadyForReview,
	 * у которых истёк срок действия join_code_expires_at,
	 * и переводит их в статус Expired.
	 *
	 * @return void
	 */
	public function cronExpireApplications(): void {
		try {
			$this->applicationService->expireStale();
		} catch ( \Throwable $e ) {
			error_log( '[FS LMS] Expire applications error: ' . $e->getMessage() );
		}
	}

	/**
	 * Задача cron: очистка устаревших данных (retention).
	 * Выполняет несколько операций:
	 * - Анонимизация записей лиц, помеченных как deleted_at
	 * - Удаление окончательно истекших заявок
	 * - Очистка старых записей аудита (старше 3 лет)
	 * - Очистка старых записей доступа к PII (старше 1 года)
	 *
	 * @return void
	 */
	public function cronRetentionCleanup(): void {
		try {
			// Анонимизация персональных данных удалённых лиц
			$this->retentionService->anonymizeDeletedPersons();
			// Удаление заявок с истекшим сроком хранения
			$this->retentionService->purgeExpiredApplications();
			// Очистка старых записей аудита (по настройкам retention)
			$this->retentionService->purgeOldAuditLogs();
			// Очистка старых записей доступа к PII
			$this->retentionService->purgeOldPiiAccessLogs();
		} catch ( \Throwable $e ) {
			error_log( '[FS LMS] Retention cleanup error: ' . $e->getMessage() );
		}
	}
}