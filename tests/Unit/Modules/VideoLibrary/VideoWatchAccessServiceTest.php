<?php

declare( strict_types=1 );

namespace Unit\Modules\VideoLibrary;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Profile\ProfileContext;
use Inc\Enums\Access\UserRole;
use Inc\Enums\Course\GateState;
use Inc\Enums\Course\LessonKind;
use Inc\Modules\VideoLibrary\Services\VideoWatchAccessService;
use Inc\Services\Course\LessonGateResolver;
use Inc\Services\Profile\ProfileViewResolver;
use PHPUnit\Framework\TestCase;

class VideoWatchAccessServiceTest extends TestCase {
	private function lesson( ?int $student = null ): GroupLessonDTO {
		return new GroupLessonDTO(
			id: 42, groupId: 5, lessonId: 1, position: 0, workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: null, endsAt: null, isPinned: false, teacherUserId: null, visibility: 'open',
			openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: 's3://bucket/video',
			createdByUserId: null, updatedByUserId: null,
			kind: null === $student ? LessonKind::Group : LessonKind::Individual, studentPersonId: $student,
		);
	}

	public function test_student_requires_available_lesson(): void {
		$profiles = $this->createStub( ProfileViewResolver::class );
		$profiles->method( 'context' )->willReturn( new ProfileContext( 77, 10, UserRole::FSStudent, 10, false ) );
		$gate = $this->createMock( LessonGateResolver::class );
		$gate->expects( self::exactly( 2 ) )->method( 'resolveLesson' )->with( 10, self::anything() )
			->willReturnOnConsecutiveCalls( GateState::Available, GateState::Locked );
		$service = new VideoWatchAccessService( $profiles, $gate );
		self::assertTrue( $service->canWatch( 77, $this->lesson() ) );
		self::assertFalse( $service->canWatch( 77, $this->lesson() ) );
	}

	public function test_parent_can_watch_individual_lesson_of_own_child(): void {
		$profiles = $this->createStub( ProfileViewResolver::class );
		$profiles->method( 'context' )->willReturn( new ProfileContext( 77, 20, UserRole::FSParent, 10, true, array(
			array( 'personId' => 10, 'name' => 'Child' ),
		) ) );
		$gate = $this->createMock( LessonGateResolver::class );
		$gate->expects( self::once() )->method( 'resolveLesson' )->with( 10, self::anything() )->willReturn( GateState::Available );
		$service = new VideoWatchAccessService( $profiles, $gate );
		self::assertTrue( $service->canWatch( 77, $this->lesson( 10 ) ) );
		self::assertFalse( $service->canWatch( 77, $this->lesson( 11 ) ) );
	}
}
