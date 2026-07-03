<?php

declare( strict_types=1 );

namespace Unit\Services\Assessment;

use Inc\DTO\Assessment\AttemptAnswerDTO;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\MediaManager;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Services\Assessment\AttemptResultService;
use PHPUnit\Framework\TestCase;

/**
 * T13.7: per-task результат попытки для ученика — критерии + загруженные файлы.
 */
class AttemptResultServiceTest extends TestCase {

	private AssessmentAttemptRepository&\PHPUnit\Framework\MockObject\MockObject $attempts;
	private AssessmentAnswerRepository&\PHPUnit\Framework\MockObject\MockObject  $answers;
	private PostManager&\PHPUnit\Framework\MockObject\MockObject                 $posts;
	private MediaManager&\PHPUnit\Framework\MockObject\MockObject                $media;
	private AttemptResultService $service;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_fs_test_post_mime_types'] = array();
		$this->attempts = $this->createMock( AssessmentAttemptRepository::class );
		$this->answers  = $this->createMock( AssessmentAnswerRepository::class );
		$this->posts    = $this->createMock( PostManager::class );
		$this->media    = $this->createMock( MediaManager::class );
		$this->service  = new AttemptResultService(
			$this->attempts,
			$this->answers,
			$this->posts,
			$this->media,
		);
	}

	private function attempt( int $personId = 99 ): AttemptDTO {
		return AttemptDTO::fromArray( array(
			'id' => 7, 'assessment_id' => 1, 'student_person_id' => $personId,
			'group_id' => null, 'attempt_number' => 1,
			'started_at' => '2026-06-01 10:00:00', 'deadline_at' => '2026-06-01 11:00:00',
			'status' => 'submitted',
		) );
	}

	private function answer( int $taskId, ?string $answerText, ?array $criteriaScores = null ): AttemptAnswerDTO {
		$row = array(
			'id' => 1, 'attempt_id' => 7, 'task_id' => $taskId,
			'answer_text' => $answerText,
		);
		if ( null !== $criteriaScores ) {
			$row['criteria_scores'] = json_encode( $criteriaScores );
		}
		return AttemptAnswerDTO::fromArray( $row );
	}

	public function test_throws_when_attempt_not_found(): void {
		$this->attempts->method( 'find' )->willReturn( null );
		$this->expectException( \InvalidArgumentException::class );
		$this->service->studentPerTask( 7, 99 );
	}

	public function test_throws_when_attempt_belongs_to_other_student(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt( 50 ) );
		$this->expectException( \InvalidArgumentException::class );
		$this->service->studentPerTask( 7, 99 );
	}

	public function test_returns_empty_for_attempt_without_answers(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt() );
		$this->answers->method( 'listByAttempt' )->willReturn( array() );

		self::assertSame( array(), $this->service->studentPerTask( 7, 99 ) );
	}

	public function test_includes_verdict_score_and_empty_criteria_for_regular_task(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt() );
		$ans = AttemptAnswerDTO::fromArray( array(
			'id' => 1, 'attempt_id' => 7, 'task_id' => 10,
			'answer_text' => 'Paris', 'is_correct' => '1', 'score' => '2', 'max_score' => '2',
		) );
		$this->answers->method( 'listByAttempt' )->willReturn( array( $ans ) );
		$this->posts->method( 'getMeta' )->willReturn( '' ); // шаблон не file_answer

		$result = $this->service->studentPerTask( 7, 99 );

		self::assertCount( 1, $result );
		self::assertSame( 1, $result[0]['n'] );
		self::assertSame( 10, $result[0]['task_id'] );
		self::assertSame( 'correct', $result[0]['verdict'] );
		self::assertSame( 2.0, $result[0]['score'] );
		self::assertSame( 2.0, $result[0]['max_score'] );
		self::assertSame( array(), $result[0]['criteria'] );
		self::assertSame( array(), $result[0]['files'] );
	}

	public function test_pending_verdict_when_is_correct_null(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt() );
		$this->answers->method( 'listByAttempt' )->willReturn( array(
			$this->answer( 10, 'My answer' ),
		) );
		$this->posts->method( 'getMeta' )->willReturn( '' );

		$result = $this->service->studentPerTask( 7, 99 );

		self::assertSame( 'pending', $result[0]['verdict'] );
	}

	public function test_file_answer_parses_text_and_resolves_file_urls(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt() );
		$this->answers->method( 'listByAttempt' )->willReturn( array(
			$this->answer( 42, '{"text":"Решение задачи","files":[501,502]}' ),
		) );
		$this->posts->method( 'getMeta' )->willReturnCallback( function ( int $postId, string $key ) {
			return PostMetaName::TemplateType->value === $key ? 'file_answer_task' : array();
		} );
		$this->media->method( 'url' )->willReturnMap( array(
			array( 501, 'https://example.test/photo.jpg' ),
			array( 502, 'https://example.test/doc.pdf' ),
		) );
		$GLOBALS['_fs_test_post_mime_types'] = array( 501 => 'image/jpeg', 502 => 'application/pdf' );

		$result = $this->service->studentPerTask( 7, 99 );
		$files  = $result[0]['files'];

		self::assertCount( 2, $files );
		self::assertSame( 'https://example.test/photo.jpg', $files[0]['url'] );
		self::assertSame( 'image/jpeg', $files[0]['mime'] );
		self::assertSame( 'https://example.test/doc.pdf', $files[1]['url'] );
		self::assertSame( 'application/pdf', $files[1]['mime'] );
	}

	public function test_file_answer_with_no_files_returns_empty_files(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt() );
		$this->answers->method( 'listByAttempt' )->willReturn( array(
			$this->answer( 42, '{"text":"текст","files":[]}' ),
		) );
		$this->posts->method( 'getMeta' )->willReturnCallback( function ( int $postId, string $key ) {
			return PostMetaName::TemplateType->value === $key ? 'file_answer_task' : array();
		} );
		$this->media->expects( $this->never() )->method( 'url' );

		$result = $this->service->studentPerTask( 7, 99 );

		self::assertSame( array(), $result[0]['files'] );
	}

	public function test_criteria_awarded_from_criteria_scores(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt() );
		$this->answers->method( 'listByAttempt' )->willReturn( array(
			$this->answer( 42, 'some text', array( '0' => 1.5, '1' => 2.0 ) ),
		) );
		$this->posts->method( 'getMeta' )->willReturnCallback( function ( int $postId, string $key ) {
			if ( PostMetaName::Meta->value === $key ) {
				return array(
					'task_criteria' => array(
						'criteria' => array(
							array( 'label' => 'К1', 'max_points' => 2 ),
							array( 'label' => 'К2', 'max_points' => 2 ),
						),
					),
				);
			}
			return '';
		} );

		$criteria = $this->service->studentPerTask( 7, 99 )[0]['criteria'];

		self::assertCount( 2, $criteria );
		self::assertSame( 'К1', $criteria[0]['label'] );
		self::assertSame( 2.0, $criteria[0]['max_points'] );
		self::assertSame( 1.5, $criteria[0]['awarded'] );
		self::assertSame( 'К2', $criteria[1]['label'] );
		self::assertSame( 2.0, $criteria[1]['awarded'] );
	}

	public function test_criteria_awarded_null_when_not_yet_graded(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt() );
		$this->answers->method( 'listByAttempt' )->willReturn( array(
			$this->answer( 42, 'pending answer', null ),
		) );
		$this->posts->method( 'getMeta' )->willReturnCallback( function ( int $postId, string $key ) {
			if ( PostMetaName::Meta->value === $key ) {
				return array(
					'task_criteria' => array(
						'criteria' => array(
							array( 'label' => 'К1', 'max_points' => 3 ),
						),
					),
				);
			}
			return '';
		} );

		$criteria = $this->service->studentPerTask( 7, 99 )[0]['criteria'];

		self::assertNull( $criteria[0]['awarded'] );
	}

	public function test_increments_n_across_multiple_tasks(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt() );
		$this->answers->method( 'listByAttempt' )->willReturn( array(
			$this->answer( 10, 'a' ),
			$this->answer( 20, 'b' ),
			$this->answer( 30, 'c' ),
		) );
		$this->posts->method( 'getMeta' )->willReturn( '' );

		$result = $this->service->studentPerTask( 7, 99 );

		self::assertSame( 1, $result[0]['n'] );
		self::assertSame( 2, $result[1]['n'] );
		self::assertSame( 3, $result[2]['n'] );
	}
}
