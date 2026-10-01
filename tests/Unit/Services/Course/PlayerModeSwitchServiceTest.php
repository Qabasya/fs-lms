<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Services\Course\CoursePreviewAccessGuard;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Course\PlayerModeSwitchService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Переключение режимов плеера по бейджу: преподаватель → предпросмотр (только автору
 * курсов) и предпросмотр → преподаватель (запрошенное занятие либо группа преподавателя).
 */
class PlayerModeSwitchServiceTest extends TestCase {

	private CoursePreviewAccessGuard&MockObject $previewAccess;
	private GroupAccessGuard&MockObject $groupAccess;
	private GroupLessonRepository&MockObject $groupLessons;
	private GroupsRepository&MockObject $groups;
	private PlayerModeSwitchService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->previewAccess = $this->createMock( CoursePreviewAccessGuard::class );
		$this->groupAccess   = $this->createMock( GroupAccessGuard::class );
		$this->groupLessons  = $this->createMock( GroupLessonRepository::class );
		$this->groups        = $this->createMock( GroupsRepository::class );
		$this->service       = new PlayerModeSwitchService( $this->previewAccess, $this->groupAccess, $this->groupLessons, $this->groups );
	}

	private function row( int $id, int $groupId, int $lessonId ): GroupLessonDTO {
		return GroupLessonDTO::fromArray( array( 'id' => $id, 'group_id' => $groupId, 'lesson_id' => $lessonId, 'position' => 1 ) );
	}

	public function test_preview_url_is_empty_for_non_author(): void {
		$this->previewAccess->method( 'canPreview' )->willReturn( false );

		self::assertSame( '', $this->service->previewUrl( 7, 5, 10, 31 ) );
	}

	public function test_preview_url_carries_course_lesson_and_group_lesson(): void {
		$this->previewAccess->method( 'canPreview' )->willReturn( true );

		$url = $this->service->previewUrl( 7, 5, 10, 31 );

		self::assertStringContainsString( 'course=5', $url );
		self::assertStringContainsString( 'lesson=10', $url );
		self::assertStringContainsString( 'gl=31', $url );
	}

	public function test_teacher_url_uses_requested_group_lesson_when_managed(): void {
		$this->groupLessons->method( 'find' )->with( 31 )->willReturn( $this->row( 31, 3, 10 ) );
		$this->groupAccess->method( 'canManage' )->with( 3, 7 )->willReturn( true );

		$url = $this->service->teacherUrl( 7, 10, 31 );

		self::assertStringContainsString( 'gid=3', $url );
		self::assertStringContainsString( 'gl=31', $url );
	}

	public function test_teacher_url_ignores_requested_row_of_another_lesson(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->row( 31, 3, 99 ) );
		$this->groupAccess->method( 'canManage' )->willReturn( true );
		$this->groups->method( 'findByTeacherId' )->willReturn( array() );

		self::assertSame( '', $this->service->teacherUrl( 7, 10, 31 ) );
	}

	public function test_teacher_url_falls_back_to_own_group_lesson(): void {
		$this->groups->method( 'findByTeacherId' )->willReturn( array( (object) array( 'id' => 4 ) ) );
		$this->groupLessons->method( 'listByGroup' )->with( 4 )->willReturn( array(
			$this->row( 40, 4, 11 ),
			$this->row( 41, 4, 10 ),
		) );

		self::assertStringContainsString( 'gl=41', $this->service->teacherUrl( 7, 10, 0 ) );
	}

	public function test_teacher_url_is_empty_without_any_group_lesson(): void {
		$this->groups->method( 'findByTeacherId' )->willReturn( array() );

		self::assertSame( '', $this->service->teacherUrl( 7, 10, 0 ) );
	}
}
