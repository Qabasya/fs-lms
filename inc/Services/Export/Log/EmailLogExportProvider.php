<?php

declare( strict_types=1 );

namespace Inc\Services\Export\Log;

use Inc\Contracts\CsvExportProviderInterface;
use Inc\DTO\CsvColumn;
use Inc\Enums\EmailTemplateType;
use Inc\Repositories\WPDBRepositories\EmailLogRepository;
use Inc\Services\Log\LogNameResolver;

/**
 * Class EmailLogExportProvider
 *
 * Провайдер экспорта журнала отправки email (email_log) в CSV.
 *
 * @package Inc\Services\Export\Log
 * @implements CsvExportProviderInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Определение колонок** — возврат структуры CSV-файла для лога отправки email.
 * 2. **Генерация строк** — получение записей из репозитория (с фильтрацией).
 *
 * ### Архитектурная роль:
 *
 * Реализует интерфейс CsvExportProviderInterface для использования в ExportService.
 * Делегирует получение данных EmailLogRepository.
 *
 * ### Данные в CSV:
 *
 * - ID записи
 * - Дата отправки (форматированная)
 * - Пользователь, инициировавший отправку
 * - Тип письма (otp_code, password_setup, application_confirmation и т.д.)
 * - Получатель (имя лица из таблицы persons)
 * - Статус отправки (success/failed)
 * - Сообщение об ошибке (если статус failed)
 *
 * ### Примечания:
 *
 * - Фильтрация (по датам, email_type, status, target_person_id) передаётся через контекст.
 * - Имена пользователей и получателей резолвятся через LogNameResolver.
 * - Лог отправки email важен для аудита и отладки проблем с доставкой уведомлений.
 */
class EmailLogExportProvider implements CsvExportProviderInterface {

	/**
	 * Конструктор провайдера.
	 *
	 * @param EmailLogRepository $repository Репозиторий журнала отправки email
	 */
	public function __construct(
		private readonly EmailLogRepository $repository,
	) {}

	/**
	 * Возвращает структуру колонок CSV-файла.
	 *
	 * @return CsvColumn[]
	 */
	public function columns(): array {
		return array(
			new CsvColumn( 'ID',               fn( $r ) => $r->id ),
			new CsvColumn( 'Дата',             fn( $r ) => LogNameResolver::date( $r->createdAt ) ),
			new CsvColumn( 'Пользователь',     fn( $r ) => LogNameResolver::userName( $r->actorUserId ) ),
			new CsvColumn( 'Тип письма',       fn( $r ) => EmailTemplateType::tryFrom( $r->emailType )?->label() ?? $r->emailType ),
			new CsvColumn( 'Получатель',       fn( $r ) => LogNameResolver::personName( $r->targetPersonId ) ),
			new CsvColumn( 'Email получателя', fn( $r ) => $r->recipientEmail ?? '' ),
			new CsvColumn( 'Статус',           fn( $r ) => $r->status ),
			new CsvColumn( 'Ошибка',           fn( $r ) => $r->errorMessage ?? '' ),
		);
	}

	/**
	 * Генерирует строки для CSV-файла.
	 * Поддерживает фильтрацию через контекст (date_from, date_to, email_type, status, target_person_id).
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
		return 'email-log';
	}
}