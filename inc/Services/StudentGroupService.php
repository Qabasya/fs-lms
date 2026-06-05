<?php

declare( strict_types=1 );

namespace Inc\Services;

use Inc\DTO\StudentGroupDTO;
use Inc\Repositories\OptionsRepositories\StudentGroupRepository;
use Inc\Shared\Traits\SlugGenerator;

readonly class StudentGroupService {

	use SlugGenerator;

	public function __construct(
		private StudentGroupRepository $group_repository,
	) {}

	public function getGroupsByPeriod( string $period_id ): array {
		if ( empty( $period_id ) ) {
			return array();
		}

		return $this->group_repository->getByPeriod( $period_id );
	}

	public function createGroup( string $title, string $period_id, string $subject_id, int $teacher_id, array $schedule = [] ): ?StudentGroupDTO {
		if ( empty( $title ) || empty( $period_id ) || empty( $subject_id ) || $teacher_id <= 0 ) {
			return null;
		}

		$slugized_title  = $this->slugify( $title, 'group' );
		$clean_period_id = $this->slugify( $period_id );

		$base_id      = sprintf( '%s_%s', $slugized_title, $clean_period_id );
		$generated_id = $base_id;
		$counter      = 1;

		while ( null !== $this->group_repository->getById( $generated_id ) ) {
			$counter++;
			$generated_id = sprintf( '%s-%d_%s', $slugized_title, $counter, $clean_period_id );
		}

		$dto = new StudentGroupDTO(
			id:         $generated_id,
			title:      $title,
			period_id:  $period_id,
			subject_id: $subject_id,
			teacher_id: $teacher_id,
			schedule:   $schedule,
		);

		$is_saved = $this->group_repository->save( $dto );

		return $is_saved ? $dto : null;
	}

	public function deleteGroup( string $id ): bool {
		if ( empty( $id ) ) {
			return false;
		}

		return $this->group_repository->remove( $id );
	}
}
