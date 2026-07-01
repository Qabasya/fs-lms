<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\SubstitutionDTO;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\SubstitutionRepository;
use Inc\Services\Course\EffectiveTeacherResolver;
use PHPUnit\Framework\TestCase;

class EffectiveTeacherResolverTest extends TestCase {

	private GroupsRepository&\PHPUnit\Framework\MockObject\MockObject $groups;
	private SubstitutionRepository&\PHPUnit\Framework\MockObject\MockObject $substitutions;
	private EffectiveTeacherResolver $resolver;

	protected function setUp(): void {
		parent::setUp();
		$this->groups        = $this->createMock( GroupsRepository::class );
		$this->substitutions = $this->createMock( SubstitutionRepository::class );
		$this->resolver      = new EffectiveTeacherResolver( $this->groups, $this->substitutions );
	}

	public function test_for_group_returns_substitute_when_active(): void {
		$this->substitutions->method( 'findActiveForGroup' )->with( 7, '2026-05-20' )
			->willReturn( $this->sub( 55 ) );
		$this->groups->expects( self::never() )->method( 'findById' );

		self::assertSame( 55, $this->resolver->forGroup( 7, '2026-05-20' ) );
	}

	public function test_for_group_falls_back_to_group_teacher(): void {
		$this->substitutions->method( 'findActiveForGroup' )->willReturn( null );
		$this->groups->method( 'findById' )->with( 7 )->willReturn( (object) array( 'teacher_id' => 42 ) );

		self::assertSame( 42, $this->resolver->forGroup( 7, '2026-05-20' ) );
	}

	public function test_for_lesson_prefers_lesson_teacher_override(): void {
		$lesson = $this->lesson( teacherUserId: 88, scheduledAt: '2026-05-20 10:00:00' );
		$this->substitutions->expects( self::never() )->method( 'findActiveForGroup' );

		self::assertSame( 88, $this->resolver->forLesson( $lesson ) );
	}

	public function test_for_lesson_resolves_by_date_when_no_override(): void {
		$lesson = $this->lesson( teacherUserId: null, scheduledAt: '2026-05-20 10:00:00' );
		$this->substitutions->method( 'findActiveForGroup' )->with( 7, '2026-05-20' )
			->willReturn( $this->sub( 55 ) );

		self::assertSame( 55, $this->resolver->forLesson( $lesson ) );
	}

	private function sub( int $substituteTeacherId ): SubstitutionDTO {
		return new SubstitutionDTO( 1, 7, 42, $substituteTeacherId, '2026-05-01', '2026-05-31', null, 3, '2026-05-01 00:00:00' );
	}

	private function lesson( ?int $teacherUserId, ?string $scheduledAt ): GroupLessonDTO {
		return new GroupLessonDTO(
			id: 1, groupId: 7, lessonId: 10, position: 0, workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: $scheduledAt, endsAt: null, isPinned: false, teacherUserId: $teacherUserId,
			visibility: 'open', openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null,
			createdByUserId: null, updatedByUserId: null,
		);
	}
}
