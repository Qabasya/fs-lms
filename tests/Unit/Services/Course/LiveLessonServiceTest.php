<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Course\LiveLessonService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class LiveLessonServiceTest extends TestCase {

	private GroupLessonRepository&MockObject $groupLessons;
	private GroupsRepository&MockObject      $groups;
	private ClockInterface&MockObject        $clock;
	private LiveLessonService                $service;

	protected function setUp(): void {
		parent::setUp();
		$this->groupLessons = $this->createMock( GroupLessonRepository::class );
		$this->groups       = $this->createMock( GroupsRepository::class );
		$this->clock        = $this->createMock( ClockInterface::class );

		$this->service = new LiveLessonService(
			$this->groupLessons,
			$this->createMock( StudentRecordRepository::class ),
			$this->groups,
			$this->createMock( LessonManager::class ),
			$this->clock,
		);
	}

	private function lesson( ?string $scheduledAt, ?string $endsAt = null, string $status = 'scheduled', ?string $recordingLink = null ): GroupLessonDTO {
		return new GroupLessonDTO(
			id               : 7,
			groupId          : 5,
			lessonId         : 10,
			position         : 1,
			workIdsSnapshot  : null,
			extraWorkIds     : array(),
			scheduledAt      : $scheduledAt,
			endsAt           : $endsAt,
			isPinned         : false,
			teacherUserId    : null,
			visibility       : 'open',
			openedAt         : null,
			homeworkDueAt    : null,
			allowLate        : true,
			recordingUrl     : null,
			createdByUserId  : null,
			updatedByUserId  : null,
			status           : $status,
			recordingLink    : $recordingLink,
		);
	}

	public function test_live_between_start_and_end(): void {
		$this->clock->method( 'now' )->willReturn( '2026-10-12 17:30:00' );

		self::assertTrue( $this->service->isLive( $this->lesson( '2026-10-12 17:00:00', '2026-10-12 18:30:00' ) ) );
	}

	public function test_not_live_before_start_or_after_end(): void {
		$this->clock->method( 'now' )->willReturn( '2026-10-12 16:59:59' );
		self::assertFalse( $this->service->isLive( $this->lesson( '2026-10-12 17:00:00', '2026-10-12 18:30:00' ) ) );

		$clock = $this->createMock( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-10-12 18:30:00' );
		$late = new LiveLessonService(
			$this->groupLessons,
			$this->createMock( StudentRecordRepository::class ),
			$this->groups,
			$this->createMock( LessonManager::class ),
			$clock,
		);
		self::assertFalse( $late->isLive( $this->lesson( '2026-10-12 17:00:00', '2026-10-12 18:30:00' ) ) );
	}

	public function test_without_end_lasts_an_hour(): void {
		$this->clock->method( 'now' )->willReturn( '2026-10-12 17:59:00' );

		self::assertTrue( $this->service->isLive( $this->lesson( '2026-10-12 17:00:00' ) ) );
	}

	public function test_cancelled_unscheduled_and_recorded_lessons_are_not_live(): void {
		$this->clock->method( 'now' )->willReturn( '2026-10-12 17:30:00' );

		self::assertFalse( $this->service->isLive( $this->lesson( '2026-10-12 17:00:00', null, 'cancelled' ) ) );
		self::assertFalse( $this->service->isLive( $this->lesson( null ) ) );
		// Запись уже есть — занятие окончено, подключаться к трансляции поздно.
		self::assertFalse( $this->service->isLive( $this->lesson( '2026-10-12 17:00:00', null, 'scheduled', 'https://disk.example.com/rec' ) ) );
	}

	public function test_live_stream_url_only_while_lesson_is_live(): void {
		$this->clock->method( 'now' )->willReturn( '2026-10-12 17:30:00' );
		$this->groups->method( 'findById' )->willReturn( (object) array( 'broadcast_url' => 'https://stream.example.com/live' ) );

		self::assertSame( 'https://stream.example.com/live', $this->service->liveStreamUrl( $this->lesson( '2026-10-12 17:00:00' ) ) );
		self::assertSame( '', $this->service->liveStreamUrl( $this->lesson( '2026-10-12 19:00:00' ) ) );
	}

	public function test_stream_url_empty_when_group_has_none(): void {
		$this->groups->method( 'findById' )->willReturn( (object) array( 'broadcast_url' => null ) );

		self::assertSame( '', $this->service->streamUrl( 5 ) );
	}
}
