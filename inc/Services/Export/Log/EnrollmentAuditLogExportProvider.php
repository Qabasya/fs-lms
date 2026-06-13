<?php

declare( strict_types=1 );

namespace Inc\Services\Export\Log;

use Inc\Contracts\CsvExportProviderInterface;
use Inc\DTO\Export\CsvColumn;
use Inc\Repositories\WPDBRepositories\Log\AuditLogRepository;
use Inc\Services\Log\LogNameResolver;

/**
 * Class EnrollmentAuditLogExportProvider
 *
 * Провайдер экспорта журнала аудита зачислений (audit_log) в CSV.
 *
 * @package Inc\Services\Export\Log
 * @implements CsvExportProviderInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Определение колонок** — возврат структуры CSV-файла для лога аудита зачислений.
 * 2. **Генерация строк** — получение записей из репозитория (с фильтрацией).
 *
 * ### Архитектурная роль:
 *
 * Реализует интерфейс CsvExportProviderInterface для использования в ExportService.
 * Делегирует получение данных AuditLogRepository.
 *
 * ### Данные в CSV:
 *
 * - ID записи
 * - Дата события (форматированная)
 * - Пользователь, выполнивший действие, и его роль
 * - Тип действия (StudentEnrolled, EnrollmentFailed, StudentExpelled, StudentRestored, EnrollmentStarted, EnrollmentCanceled)
 * - Тип объекта (application, enrollment)
 * - ID объекта
 * - IP-адрес пользователя
 * - Дополнительные детали в JSON (student_person_id, group_id)
 *
 * ### Примечания:
 *
 * - Фильтрация (по датам, action, actor_user_id) передаётся через контекст.
 * - Имена пользователей резолвятся через LogNameResolver.
 * - Поле details_json может содержать дополнительные данные для отладки.
 */
class EnrollmentAuditLogExportProvider implements CsvExportProviderInterface {

	/**
	 * Конструктор провайдера.
	 *
	 * @param AuditLogRepository $repository Репозиторий журнала аудита
	 */
	public function __construct(
		private readonly AuditLogRepository $repository,
	) {}

	/**
	 * Возвращает структуру колонок CSV-файла.
	 *
	 * @return CsvColumn[]
	 */
	public function columns(): array {
		return array(
			new CsvColumn( 'ID',           fn( $r ) => $r->id ),
			new CsvColumn( 'Дата',         fn( $r ) => LogNameResolver::date( $r->createdAt ) ),
			new CsvColumn( 'Пользователь', fn( $r ) => LogNameResolver::userName( $r->actorUserId ) ),
			new CsvColumn( 'Роль',         fn( $r ) => $r->actorRole ?? '' ),
			new CsvColumn( 'Действие',     fn( $r ) => $r->action ),
			new CsvColumn( 'Тип объекта',  fn( $r ) => $r->targetType ?? '' ),
			new CsvColumn( 'ID объекта',   fn( $r ) => $r->targetId ?? '' ),
			new CsvColumn( 'IP',           fn( $r ) => $r->actorIp ),
			new CsvColumn( 'Детали',       fn( $r ) => $r->detailsJson ?? '' ),
		);
	}

	/**
	 * Генерирует строки для CSV-файла.
	 * Поддерживает фильтрацию через контекст (date_from, date_to, action, actor_user_id).
	 *
	 * @param array $context Контекст экспорта (фильтры)
	 *
	 * @return iterable
	 */
	public function rows( array $context ): iterable {
		// listAll() — возвращает все записи по фильтрам (без пагинации)
		return $this->repository->listAll( $context );
	}

	/**
	 * Возвращает базовое имя файла (без расширения).
	 *
	 * @return string
	 */
	public function filename(): string {
		return 'enrollment-audit-log';
	}
}