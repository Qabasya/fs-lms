<?php

declare( strict_types=1 );

namespace Inc\Services\Export;

use Inc\Contracts\CsvExportProviderInterface;
use Inc\DTO\Export\CsvColumn;
use Inc\Services\Exam\ExamConductService;

/**
 * Экспорт участников сеанса экзамена в CSV (8.9).
 *
 * Колонки: ФИО, источник (школа), сеанс, статус, первичный балл / максимум, вторичный балл / максимум или отметка.
 * **Контактов (телефон, мессенджер, ссылки входа и результата) в файле нет.** Состав строк определяет контекст:
 * `session_id` — весь сеанс, `participation_ids` — только выбранные участия; `actor_user_id` — автор выгрузки (доступ к проведению
 * проверяет {@see ExamConductService::exportRows()}).
 */
class ExamParticipantsExportProvider implements CsvExportProviderInterface {

	public function __construct(
		private readonly ExamConductService $conduct,
	) {}

	public function columns( array $context = array() ): array {
		return array(
			new CsvColumn( 'ФИО', static fn ( array $r ): string => $r['name'] ),
			new CsvColumn( 'Источник', static fn ( array $r ): string => $r['source'] ),
			new CsvColumn( 'Сеанс', static fn ( array $r ): string => $r['session'] ),
			new CsvColumn( 'Статус', static fn ( array $r ): string => $r['status'] ),
			new CsvColumn( 'Первичный балл', static fn ( array $r ): string => $r['primary'] ),
			new CsvColumn( 'Вторичный балл / отметка', static fn ( array $r ): string => $r['secondary'] ),
		);
	}

	public function rows( array $context ): iterable {
		return $this->conduct->exportRows( (int) ( $context['actor_user_id'] ?? 0 ), $context );
	}

	public function filename(): string {
		return 'exam-participants';
	}
}
