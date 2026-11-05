<?php
declare( strict_types=1 );
namespace Unit\Services\Profile\Learner;

use Inc\DTO\Course\CourseDTO;
use Inc\DTO\Course\LessonDTO;
use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\DTO\Subject\SubjectDTO;
use Inc\Managers\Course\CourseManager;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Enrollment\SelfPacedAccessPolicy;
use Inc\Services\Profile\Learner\SelfPacedCourseCatalog;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class SelfPacedCourseCatalogTest extends TestCase {
	private CourseManager $courses;
	private SelfPacedAccessPolicy $access;
	private SelfPacedCourseCatalog $catalog;
	private string $lessonStatus = 'publish';

	protected function setUp(): void {
		$this->courses = $this->createMock( CourseManager::class );
		$this->access = $this->createMock( SelfPacedAccessPolicy::class );
		$subjects = $this->createStub( SubjectRepository::class );
		$subjects->method( 'readAll' )->willReturn( array( new SubjectDTO( 'inf', 'Информатика' ) ) );
		$lessons = $this->createStub( LessonManager::class );
		$lessons->method( 'get' )->willReturnCallback( fn() => LessonDTO::fromArray( array( 'id' => 17, 'status' => $this->lessonStatus, 'topic' => 'Тема', 'steps' => array( array( 'type' => 'text', 'payload' => array( 'html' => 'SECRET' ) ) ) ) ) );
		$this->catalog = new SelfPacedCourseCatalog( $this->courses, $lessons, $subjects, $this->access );
	}

	private function course( array $override = array() ): CourseDTO {
		return CourseDTO::fromArray( array_replace( array(
			'id' => 8, 'subject_key' => 'inf', 'title' => 'Курс', 'status' => 'publish', 'self_paced_enabled' => true,
			'audience_subject_keys' => array( 'inf' ), 'service_group_id' => 18,
			'description_html' => '<p>Описание <img src="https://private.test/token"> <a href="s3://secret/key">текст</a></p>[resource id="777"] https://private.test/?token=SECRET',
			'modules' => array( array( 'id' => 'private-module', 'title' => 'Раздел', 'lesson_ids' => array( 17 ), 'description' => 'PRIVATE' ) ),
		), $override ) );
	}

	private function eligible(): void {
		$this->access->method( 'eligibleBasis' )->willReturn( StudentRecordDTO::fromArray( array() ) );
	}

	public function test_preview_whitelists_titles_and_removes_private_content_and_resource_links(): void {
		$this->courses->method( 'getBankBySubject' )->willReturn( array( $this->course() ) );
		$this->eligible();
		$card = $this->catalog->offers( 10, array(), true )[0];
		self::assertSame( 'course:8', $card['key'] );
		self::assertNull( $card['id'] );
		self::assertNull( $card['group_id'] );
		self::assertFalse( $card['enrolled'] );
		self::assertTrue( $card['can_start'] );
		self::assertSame( array( 'name' => 'Раздел', 'lessons' => array( array(
			'num' => 1, 'title' => 'Тема', 'date' => '', 'room' => '', 'status' => 'locked', 'player_url' => '', 'mod' => 0,
		) ) ), $card['modules'][0] );
		$serialized = json_encode( $card );
		foreach ( array( 'SECRET', 'private.test', 's3:', '777', 'private-module', 'PRIVATE', 'payload', 'steps', 'lesson_id' ) as $private ) {
			self::assertStringNotContainsString( $private, $serialized );
		}
	}

	public function test_parent_gets_preview_without_start_and_normal_group_course_is_not_deduplicated(): void {
		$this->courses->method( 'getBankBySubject' )->willReturn( array( $this->course() ) );
		$this->eligible();
		$cards = $this->catalog->offers( 10, array( array( 'course_id' => 8, 'self_paced_course_id' => 0 ) ), false );
		self::assertCount( 1, $cards );
		self::assertFalse( $cards[0]['can_start'] );
	}

	public function test_existing_service_enrollment_deduplicates_offer(): void {
		$this->courses->method( 'getBankBySubject' )->willReturn( array( $this->course() ) );
		$this->access->expects( self::never() )->method( 'assertReady' );
		self::assertSame( array(), $this->catalog->offers( 10, array( array( 'self_paced_course_id' => 8 ) ), true ) );
	}

	public function test_disabled_or_unpublished_courses_are_never_offered(): void {
		$this->courses->method( 'getBankBySubject' )->willReturn( array( $this->course( array( 'self_paced_enabled' => false ) ), $this->course( array( 'status' => 'draft' ) ) ) );
		$this->access->expects( self::never() )->method( 'assertReady' );
		self::assertSame( array(), $this->catalog->offers( 10, array(), true ) );
	}

	public function test_no_eligible_audience_excludes_offer(): void {
		$this->courses->method( 'getBankBySubject' )->willReturn( array( $this->course() ) );
		$this->access->method( 'eligibleBasis' )->willReturn( null );
		self::assertSame( array(), $this->catalog->offers( 10, array(), true ) );
	}

	public function test_stale_or_invalid_program_is_not_offered(): void {
		$this->courses->method( 'getBankBySubject' )->willReturn( array( $this->course() ) );
		$this->eligible();
		$this->access->method( 'assertReady' )->willThrowException( new \DomainException( 'unavailable' ) );
		self::assertSame( array(), $this->catalog->offers( 10, array(), true ) );
	}

	public function test_unpublished_lesson_never_leaks_its_title_as_an_offer(): void {
		$this->lessonStatus = 'draft';
		$this->courses->method( 'getBankBySubject' )->willReturn( array( $this->course() ) );
		$this->eligible();
		self::assertSame( array(), $this->catalog->offers( 10, array(), true ) );
	}
}
