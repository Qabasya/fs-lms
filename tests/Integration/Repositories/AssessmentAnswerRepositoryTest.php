<?php

declare( strict_types=1 );

namespace Integration\Repositories;

use FakeWpdb;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use PHPUnit\Framework\TestCase;

/** D3: hasPendingAnswers() — критерий «требует ручной оценки» для вкладки «Работы». */
class AssessmentAnswerRepositoryTest extends TestCase {

	private FakeWpdb $wpdb;
	private AssessmentAnswerRepository $repo;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb = new FakeWpdb();
		$this->repo = new AssessmentAnswerRepository( $this->wpdb );
	}

	public function test_has_pending_answers_true_when_exists_returns_one(): void {
		$this->wpdb->queueVar( 1 );

		self::assertTrue( $this->repo->hasPendingAnswers( 5 ) );
		self::assertStringContainsString( 'is_correct IS NULL', $this->wpdb->lastQuery() );
		self::assertStringContainsString( 'attempt_id = 5', $this->wpdb->lastQuery() );
	}

	public function test_has_pending_answers_false_when_exists_returns_zero(): void {
		$this->wpdb->queueVar( 0 );

		self::assertFalse( $this->repo->hasPendingAnswers( 5 ) );
	}
}
