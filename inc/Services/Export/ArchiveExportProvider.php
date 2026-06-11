<?php

declare( strict_types=1 );

namespace Inc\Services\Export;

use Inc\Contracts\CsvExportProviderInterface;
use Inc\DTO\CsvColumn;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;

/**
 * Class ArchiveExportProvider
 *
 * Провайдер экспорта архивных записей (отчисленные студенты) в CSV.
 *
 * @package Inc\Services\Export
 * @implements CsvExportProviderInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Определение колонок** — возврат структуры CSV-файла для архивных записей.
 * 2. **Генерация строк** — итеративная выгрузка данных из БД.
 * 3. **Форматирование данных** — обогащение записи данными о группе, предмете, родителе.
 *
 * ### Архитектурная роль:
 *
 * Реализует интерфейс CsvExportProviderInterface для использования в ExportService.
 * Поддерживает экспорт всех архивных записей или только выбранных по ID.
 * Использует пакетную обработку (BATCH = 200) для экономии памяти.
 *
 * ### Данные в CSV:
 *
 * - ID записи, ID студента
 * - Данные из снимка (snapshot): ФИО, школа, класс
 * - Информация о группе и предмете
 * - Данные родителя
 * - Данные договора и приказа
 * - Статус, даты зачисления и отчисления, причина отчисления
 */
class ArchiveExportProvider implements CsvExportProviderInterface {

	/** Размер пакета для пагинации (экономия памяти) */
	private const BATCH = 200;

	/**
	 * Конструктор провайдера.
	 *
	 * @param StudentRecordRepository $studentRecords Репозиторий записей студентов
	 * @param PersonRepository        $persons       Репозиторий лиц
	 * @param GroupsRepository        $groups        Репозиторий групп
	 * @param SubjectRepository       $subjects      Репозиторий предметов
	 */
	public function __construct(
		private readonly StudentRecordRepository $studentRecords,
		private readonly PersonRepository        $persons,
		private readonly GroupsRepository        $groups,
		private readonly SubjectRepository       $subjects,
	) {}

	/**
	 * Возвращает структуру колонок CSV-файла.
	 *
	 * @return CsvColumn[]
	 */
	public function columns(): array {
		return array(
			new CsvColumn( 'ID записи',       fn( $r ) => $r['id'] ),
			new CsvColumn( 'ID ученика',      fn( $r ) => $r['student_person_id'] ),
			new CsvColumn( 'Фамилия (снимок)', fn( $r ) => $r['snapshot_last_name'] ),
			new CsvColumn( 'Имя (снимок)',     fn( $r ) => $r['snapshot_first_name'] ),
			new CsvColumn( 'Отчество (снимок)', fn( $r ) => $r['snapshot_middle_name'] ),
			new CsvColumn( 'Школа (снимок)',   fn( $r ) => $r['snapshot_school'] ),
			new CsvColumn( 'Класс (снимок)',   fn( $r ) => $r['snapshot_grade'] ),
			new CsvColumn( 'ID группы',        fn( $r ) => $r['group_id'] ),
			new CsvColumn( 'Группа',           fn( $r ) => $r['group_name'] ),
			new CsvColumn( 'Предмет',          fn( $r ) => $r['subject_name'] ),
			new CsvColumn( 'Родитель',         fn( $r ) => $r['parent_name'] ),
			new CsvColumn( 'ID родителя',      fn( $r ) => $r['parent_person_id'] ),
			new CsvColumn( '№ договора',       fn( $r ) => $r['contract_no'] ),
			new CsvColumn( 'Дата договора',    fn( $r ) => $r['contract_date'] ),
			new CsvColumn( '№ приказа',        fn( $r ) => $r['order_no'] ),
			new CsvColumn( 'Дата приказа',     fn( $r ) => $r['order_date'] ),
			new CsvColumn( 'Статус',           fn( $r ) => $r['status'] ),
			new CsvColumn( 'Зачислен',         fn( $r ) => $r['enrolled_at'] ),
			new CsvColumn( 'Отчислен',         fn( $r ) => $r['expelled_at'] ),
			new CsvColumn( 'Причина отчисл.',  fn( $r ) => $r['expel_reason'] ),
		);
	}

	/**
	 * Генерирует строки для CSV-файла.
	 * Поддерживает экспорт выбранных записей или всех (с пагинацией).
	 *
	 * @param array $context Контекст экспорта (ids — массив ID записей)
	 *
	 * @return iterable
	 */
	public function rows( array $context ): iterable {
		$ids = $context['ids'] ?? array();

		// Если указаны конкретные ID — экспортируем только их
		if ( ! empty( $ids ) ) {
			foreach ( $ids as $id ) {
				$rec = $this->studentRecords->find( (int) $id );
				if ( $rec ) {
					yield $this->format( $rec );
				}
			}
			return;
		}

		// Иначе — экспортируем все записи с пагинацией
		$page = 1;
		do {
			$batch = $this->studentRecords->list( array(), $page, self::BATCH );
			foreach ( $batch as $rec ) {
				yield $this->format( $rec );
			}
			$page++;
		} while ( count( $batch ) === self::BATCH );
	}

	/**
	 * Возвращает базовое имя файла (без расширения).
	 *
	 * @return string
	 */
	public function filename(): string {
		return 'archive';
	}

	/**
	 * Форматирует DTO записи студента в массив для CSV.
	 *
	 * @param \Inc\DTO\Enrollment\StudentRecordDTO $rec DTO записи студента
	 *
	 * @return array
	 */
	private function format( \Inc\DTO\Enrollment\StudentRecordDTO $rec ): array {
		// Получение данных группы
		$group       = $rec->groupId ? $this->groups->findById( $rec->groupId ) : null;
		// Получение названия предмета
		$subjectName = $group ? ( $this->subjects->getByKey( $group->subject_key )?->name ?? $group->subject_key ) : '';
		// Получение ФИО родителя
		$parent      = $this->persons->find( $rec->parentPersonId );
		$parentName  = $parent ? trim( implode( ' ', array_filter( array( $parent->lastName, $parent->firstName, $parent->middleName ) ) ) ) : '';

		return array(
			'id'                  => $rec->id,
			'student_person_id'   => $rec->studentPersonId,
			'snapshot_last_name'  => $rec->snapshotLastName,
			'snapshot_first_name' => $rec->snapshotFirstName,
			'snapshot_middle_name'=> $rec->snapshotMiddleName ?? '',
			'snapshot_school'     => $rec->snapshotSchool ?? '',
			'snapshot_grade'      => $rec->snapshotGrade ?? '',
			'group_id'            => $rec->groupId ?? '',
			'group_name'          => $group?->name ?? '',
			'subject_name'        => $subjectName,
			'parent_name'         => $parentName,
			'parent_person_id'    => $rec->parentPersonId,
			'contract_no'         => $rec->contractNo ?? '',
			'contract_date'       => $rec->contractDate ?? '',
			'order_no'            => $rec->orderNo ?? '',
			'order_date'          => $rec->orderDate ?? '',
			'status'              => $rec->status->value,
			'enrolled_at'         => $rec->enrolledAt,
			'expelled_at'         => $rec->expelledAt ?? '',
			'expel_reason'        => $rec->expelReason ?? '',
		);
	}
}