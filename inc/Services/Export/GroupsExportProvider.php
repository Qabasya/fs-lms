<?php

declare( strict_types=1 );

namespace Inc\Services\Export;

use Inc\Contracts\CsvExportProviderInterface;
use Inc\DTO\CsvColumn;
use Inc\Managers\UserManager;
use Inc\Repositories\OptionsRepositories\AcademicPeriodRepository;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;

/**
 * Class GroupsExportProvider
 *
 * Провайдер экспорта групп студентов в CSV.
 *
 * @package Inc\Services\Export
 * @implements CsvExportProviderInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Определение колонок** — возврат структуры CSV-файла для групп.
 * 2. **Генерация строк** — итеративная выгрузка данных групп из БД.
 * 3. **Обогащение данных** — подстановка названий предметов, периодов, преподавателей.
 *
 * ### Архитектурная роль:
 *
 * Реализует интерфейс CsvExportProviderInterface для использования в ExportService.
 * Поддерживает экспорт всех групп или только выбранных по ID.
 *
 * ### Данные в CSV:
 *
 * - ID группы, название
 * - Предмет, период (название + ID)
 * - Преподаватель (отображаемое имя)
 * - Количество учеников в группе (активные записи)
 * - Дата создания
 */
class GroupsExportProvider implements CsvExportProviderInterface {

	/**
	 * Конструктор провайдера.
	 *
	 * @param GroupsRepository         $groups         Репозиторий групп
	 * @param SubjectRepository        $subjects       Репозиторий предметов
	 * @param AcademicPeriodRepository $periods        Репозиторий учебных периодов
	 * @param StudentRecordRepository  $studentRecords Репозиторий записей студентов
	 * @param UserManager              $userManager    Менеджер пользователей
	 */
	public function __construct(
		private readonly GroupsRepository          $groups,
		private readonly SubjectRepository         $subjects,
		private readonly AcademicPeriodRepository  $periods,
		private readonly StudentRecordRepository   $studentRecords,
		private readonly UserManager               $userManager,
	) {}

	/**
	 * Возвращает структуру колонок CSV-файла.
	 *
	 * @return CsvColumn[]
	 */
	public function columns(): array {
		return array(
			new CsvColumn( 'ID группы',       fn( $r ) => $r->id ),
			new CsvColumn( 'Название',         fn( $r ) => $r->name ),
			new CsvColumn( 'Предмет',          fn( $r ) => $this->subjectName( $r->subject_key ) ),
			new CsvColumn( 'Период',           fn( $r ) => $this->periodName( $r->academic_period_id ) ),
			new CsvColumn( 'ID периода',       fn( $r ) => $r->academic_period_id ?? '' ),
			new CsvColumn( 'Преподаватель',    fn( $r ) => $this->teacherName( $r->teacher_id ) ),
			new CsvColumn( 'Кол-во учеников',  fn( $r ) => $this->studentCount( $r->id ) ),
			new CsvColumn( 'Создана',          fn( $r ) => $r->created_at ?? '' ),
		);
	}

	/**
	 * Генерирует строки для CSV-файла.
	 * Поддерживает экспорт выбранных групп или всех.
	 *
	 * @param array $context Контекст экспорта (ids — массив ID групп)
	 *
	 * @return iterable
	 */
	public function rows( array $context ): iterable {
		$ids = $context['ids'] ?? array();
		if ( ! empty( $ids ) ) {
			// Возвращаем только выбранные группы
			return array_filter(
				array_map( fn( int $id ) => $this->groups->findById( $id ), $ids )
			);
		}
		// Возвращаем все группы
		return $this->groups->findAll();
	}

	/**
	 * Возвращает базовое имя файла (без расширения).
	 *
	 * @return string
	 */
	public function filename(): string {
		return 'groups';
	}

	/**
	 * Возвращает название предмета по ключу.
	 *
	 * @param string|null $key Ключ предмета
	 *
	 * @return string
	 */
	private function subjectName( ?string $key ): string {
		if ( ! $key ) {
			return '';
		}
		return $this->subjects->getByKey( $key )?->name ?? $key;
	}

	/**
	 * Возвращает название учебного периода по ID.
	 *
	 * @param string|null $periodId ID периода
	 *
	 * @return string
	 */
	private function periodName( ?string $periodId ): string {
		if ( ! $periodId ) {
			return '';
		}
		return $this->periods->getById( $periodId )?->name ?? $periodId;
	}

	/**
	 * Возвращает отображаемое имя преподавателя по ID пользователя.
	 *
	 * @param int|null $teacherId ID преподавателя (пользователя WP)
	 *
	 * @return string
	 */
	private function teacherName( ?int $teacherId ): string {
		if ( ! $teacherId ) {
			return '';
		}
		$user = $this->userManager->find( $teacherId );
		return $user?->display_name ?? '#' . $teacherId;
	}

	/**
	 * Подсчитывает количество активных студентов в группе.
	 *
	 * @param int $groupId ID группы
	 *
	 * @return int
	 */
	private function studentCount( int $groupId ): int {
		return count( $this->studentRecords->findActiveByGroupId( $groupId ) );
	}
}