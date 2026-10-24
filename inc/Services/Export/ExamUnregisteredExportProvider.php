<?php

declare( strict_types=1 );

namespace Inc\Services\Export;

use Inc\Contracts\CsvExportProviderInterface;
use Inc\DTO\Export\CsvColumn;
use Inc\Services\Exam\ExamUnregisteredService;

/**
 * Экспорт учеников, не записавшихся на проведение, в CSV.
 *
 * Колонки: ФИО, группа, статус. Контактов нет. Контекст: `session_id` (опорный сеанс проведения), `person_ids` — выбор фильтрами
 * (пусто — все незаписавшиеся), `actor_user_id` — автор выгрузки (доступ к проведению проверяет сервис).
 */
class ExamUnregisteredExportProvider implements CsvExportProviderInterface {

	public function __construct(
		private readonly ExamUnregisteredService $unregistered,
	) {}

	public function columns( array $context = array() ): array {
		return array(
			new CsvColumn( 'ФИО', static fn ( array $r ): string => $r['name'] ),
			new CsvColumn( 'Группа', static fn ( array $r ): string => $r['source'] ),
			new CsvColumn( 'Статус', static fn ( array $r ): string => $r['status'] ),
		);
	}

	public function rows( array $context ): iterable {
		return $this->unregistered->exportRows(
			(int) ( $context['actor_user_id'] ?? 0 ),
			(int) ( $context['session_id'] ?? 0 ),
			array_map( 'intval', (array) ( $context['person_ids'] ?? array() ) )
		);
	}

	public function filename(): string {
		return 'exam-unregistered';
	}
}
