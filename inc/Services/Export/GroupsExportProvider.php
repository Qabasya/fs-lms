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

class GroupsExportProvider implements CsvExportProviderInterface {

	public function __construct(
		private readonly GroupsRepository          $groups,
		private readonly SubjectRepository         $subjects,
		private readonly AcademicPeriodRepository  $periods,
		private readonly StudentRecordRepository   $studentRecords,
		private readonly UserManager               $userManager,
	) {}

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

	public function rows( array $context ): iterable {
		$ids = $context['ids'] ?? array();
		if ( ! empty( $ids ) ) {
			return array_filter(
				array_map( fn( int $id ) => $this->groups->findById( $id ), $ids )
			);
		}
		return $this->groups->findAll();
	}

	public function filename(): string {
		return 'groups';
	}

	private function subjectName( ?string $key ): string {
		if ( ! $key ) {
			return '';
		}
		return $this->subjects->getByKey( $key )?->name ?? $key;
	}

	private function periodName( ?string $periodId ): string {
		if ( ! $periodId ) {
			return '';
		}
		return $this->periods->getById( $periodId )?->name ?? $periodId;
	}

	private function teacherName( ?int $teacherId ): string {
		if ( ! $teacherId ) {
			return '';
		}
		return $this->userManager->find( $teacherId )?->display_name ?? '#' . $teacherId;
	}

	private function studentCount( int $groupId ): int {
		return count( $this->studentRecords->findActiveByGroupId( $groupId ) );
	}
}
