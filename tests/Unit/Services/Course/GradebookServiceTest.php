<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\DTO\Course\GradebookEntryDTO;
use Inc\Services\Course\GradebookService;
use Inc\Services\Course\GradeSourceRegistry;
use Inc\Services\Course\SubmissionGradeSource;
use PHPUnit\Framework\TestCase;

class GradebookServiceTest extends TestCase {

	private SubmissionGradeSource&\PHPUnit\Framework\MockObject\MockObject $source;
	private GradebookService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->source = $this->createMock( SubmissionGradeSource::class );

		$registry = $this->createMock( GradeSourceRegistry::class );
		$registry->method( 'all' )->willReturn( [ $this->source ] );

		$this->service = new GradebookService( $registry );
	}

	private function makeEntry( int $studentId, string $title, float $score ): GradebookEntryDTO {
		return new GradebookEntryDTO(
			studentPersonId : $studentId,
			groupId         : 1,
			sourceType      : 'submission',
			sourceId        : 1,
			title           : $title,
			category        : 'practice',
			score           : $score,
			maxScore        : 100,
			gradedAt        : '2024-01-01 12:00:00',
		);
	}

	public function test_forGroup_delegates_to_submission_source(): void {
		$this->source->expects( $this->once() )
			->method( 'entriesForGroup' )
			->with( 7 )
			->willReturn( [ $this->makeEntry( 1, 'Work A', 80 ) ] );

		$result = $this->service->forGroup( 7 );

		$this->assertCount( 1, $result );
		$this->assertSame( 'Work A', $result[0]->title );
	}

	public function test_forGroup_returns_empty_when_source_has_none(): void {
		$this->source->method( 'entriesForGroup' )->willReturn( [] );

		$this->assertSame( [], $this->service->forGroup( 1 ) );
	}

	public function test_forStudent_delegates_to_submission_source(): void {
		$this->source->expects( $this->once() )
			->method( 'entriesForStudent' )
			->with( 42 )
			->willReturn( [ $this->makeEntry( 42, 'Task', 55 ) ] );

		$result = $this->service->forStudent( 42 );

		$this->assertCount( 1, $result );
		$this->assertSame( 55.0, $result[0]->score );
	}

	public function test_forStudent_returns_empty_when_source_has_none(): void {
		$this->source->method( 'entriesForStudent' )->willReturn( [] );

		$this->assertSame( [], $this->service->forStudent( 5 ) );
	}

	public function test_forGroup_does_not_call_forStudent(): void {
		$this->source->expects( $this->once() )->method( 'entriesForGroup' )->willReturn( [] );
		$this->source->expects( $this->never() )->method( 'entriesForStudent' );

		$this->service->forGroup( 1 );
	}

	public function test_forStudent_does_not_call_forGroup(): void {
		$this->source->expects( $this->never() )->method( 'entriesForGroup' );
		$this->source->expects( $this->once() )->method( 'entriesForStudent' )->willReturn( [] );

		$this->service->forStudent( 1 );
	}
}
