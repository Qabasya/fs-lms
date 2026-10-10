<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\DTO\Assessment\AssessmentDTO;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Services\Exam\ExamTime;
use Inc\Services\Exam\ExamVariantGuard;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExamFixtures;

/**
 * Заморозка варианта: что считается замороженным, состав по снимку, дочерние задания связки, вложения, предметы.
 */
#[AllowMockObjectsWithoutExpectations]
class ExamVariantGuardTest extends TestCase {

	use ExamFixtures;

	private ExamSessionRepository&MockObject $sessions;
	private ExamEventRepository&MockObject $events;
	private AssessmentManager&MockObject $assessments;
	private PostManager&MockObject $posts;
	private ExamVariantGuard $guard;

	/** @var list<array<string, mixed>> */
	private array $frozenRows = array();
	/** @var array<int, mixed> */
	private array $meta = array();

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_posts();

		$this->sessions    = $this->createMock( ExamSessionRepository::class );
		$this->events      = $this->createMock( ExamEventRepository::class );
		$this->assessments = $this->createMock( AssessmentManager::class );
		$this->posts       = $this->createMock( PostManager::class );
		$time              = $this->createStub( ExamTime::class );
		$time->method( 'nowUtc' )->willReturn( '2026-03-10 07:00:00' );

		$this->sessions->method( 'listFrozenVariants' )->willReturnCallback( fn (): array => $this->frozenRows );
		$this->events->method( 'find' )->willReturn( $this->examEvent( array(
			'status' => 'published',
			'variant_snapshot' => json_encode( array( '500' => array( 'task_ids' => array( 11, 12 ) ) ) ),
		) ) );
		$this->posts->method( 'getMeta' )->willReturnCallback( fn ( int $id, string $key ) => 'fs_lms_task_bundle_child_ids' === $key ? ( $this->meta[ $id ]['children'] ?? '' ) : ( $this->meta[ $id ]['data'] ?? '' ) );

		$this->posts->method( 'get' )->willReturnCallback( static fn ( int $id ) => get_post( $id ) );

		$this->guard = new ExamVariantGuard( $this->sessions, $this->events, $this->assessments, $this->posts, $time );
	}

	/** @return array<string, mixed> */
	private function row( int $assessmentId = 500, string $title = 'Пробный ЕГЭ', string $subject = 'inf_ege' ): array {
		return array( 'event_id' => 3, 'assessment_id' => $assessmentId, 'event_title' => $title, 'subject_key' => $subject );
	}

	public function test_draft_event_does_not_freeze(): void {
		// Репозиторий не отдаёт сеансы черновика и ещё не начавшиеся сеансы: замороженных нет.
		$this->frozenRows = array();

		self::assertSame( array(), $this->guard->frozenAssessmentIds() );
		self::assertFalse( $this->guard->isAssessmentFrozen( 500 ) );
		self::assertFalse( $this->guard->isTaskFrozen( 11 ) );
	}

	public function test_variant_frozen_after_first_start(): void {
		$this->frozenRows = array( $this->row() );

		self::assertTrue( $this->guard->isAssessmentFrozen( 500 ) );
		self::assertTrue( $this->guard->isTaskFrozen( 11 ) );
		self::assertTrue( $this->guard->isPostFrozen( 12 ) );
		self::assertSame( array( 500 ), $this->guard->frozenAssessmentIds() );
		self::assertStringContainsString( 'Пробный ЕГЭ', $this->guard->reason( 500 ) );
		self::assertStringContainsString( 'Пробный ЕГЭ', $this->guard->reason( 11 ), 'Для задания — проведение, которое его использует.' );
	}

	public function test_copy_of_frozen_variant_is_editable(): void {
		$this->frozenRows = array( $this->row() );

		// Копия — новый пост с другим ID и своими заданиями: гард его не касается.
		self::assertFalse( $this->guard->isAssessmentFrozen( 501 ) );
		self::assertFalse( $this->guard->isTaskFrozen( 99 ) );
	}

	public function test_child_task_of_bundle_is_frozen(): void {
		$this->frozenRows = array( $this->row() );
		$this->meta[11]   = array( 'children' => array( 21, 22, 23 ) );

		foreach ( array( 21, 22, 23 ) as $child ) {
			self::assertTrue( $this->guard->isTaskFrozen( $child ), (string) $child );
		}
	}

	public function test_task_outside_snapshot_is_not_frozen(): void {
		$this->frozenRows = array( $this->row() );

		self::assertFalse( $this->guard->isTaskFrozen( 77 ) );
	}

	public function test_attachment_of_frozen_task_is_frozen(): void {
		$this->frozenRows = array( $this->row() );
		fs_test_seed_post( array( 'ID' => 900, 'post_type' => 'attachment', 'post_parent' => 11 ) );
		fs_test_seed_post( array( 'ID' => 901, 'post_type' => 'attachment', 'post_parent' => 0 ) );
		fs_test_seed_post( array( 'ID' => 902, 'post_type' => 'attachment', 'post_parent' => 0 ) );
		$this->meta[12] = array( 'data' => array( 'condition' => 'x', 'files' => array( array( 'id' => '901' ) ) ) );

		self::assertTrue( $this->guard->isAttachmentFrozen( 900 ), 'Прикреплено к замороженному заданию.' );
		self::assertTrue( $this->guard->isAttachmentFrozen( 901 ), 'Упомянуто в данных замороженного задания.' );
		self::assertFalse( $this->guard->isAttachmentFrozen( 902 ) );
	}

	public function test_subject_with_frozen_variant_is_reported(): void {
		$this->frozenRows = array( $this->row( 500, 'Пробный ЕГЭ', 'inf_ege' ), $this->row( 501, 'Пробный ЕГЭ', 'inf_ege' ), $this->row( 600, 'Пробный ОГЭ', 'inf_oge' ) );

		self::assertSame( array( 'Пробный ЕГЭ' ), $this->guard->eventsFreezingSubject( 'inf_ege' ) );
		self::assertSame( array(), $this->guard->eventsFreezingSubject( 'math' ) );
	}

	public function test_without_snapshot_live_composition_is_used(): void {
		$this->frozenRows = array( $this->row() );
		$events           = $this->createMock( ExamEventRepository::class );
		$events->method( 'find' )->willReturn( $this->examEvent( array( 'status' => 'published' ) ) );
		$this->assessments->method( 'get' )->willReturn( new AssessmentDTO(
			id: 500, subjectKey: 'inf_ege', title: 'В', taskIds: array( 31, 32 ), timeLimit: 0, attemptsAllowed: 0, passScore: 0.0,
			scoringPolicy: \Inc\Enums\Assessment\ScoringPolicy::Highest, status: 'publish', kind: \Inc\Enums\Assessment\AssessmentKind::EgeComputer, taskPoints: array(), scoreMap: array()
		) );
		$time = $this->createStub( ExamTime::class );
		$guard = new ExamVariantGuard( $this->sessions, $events, $this->assessments, $this->posts, $time );

		self::assertTrue( $guard->isTaskFrozen( 31 ) );
	}
}
