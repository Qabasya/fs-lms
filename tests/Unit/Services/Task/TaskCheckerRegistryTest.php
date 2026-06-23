<?php

declare( strict_types=1 );

namespace Unit\Services\Task;

use Inc\Enums\Subject\TaskTemplate;
use Inc\Services\Task\Checkers\ChoiceChecker;
use Inc\Services\Task\Checkers\FillChecker;
use Inc\Services\Task\Checkers\MatchingChecker;
use Inc\Services\Task\Checkers\OrderingChecker;
use Inc\Services\Task\Checkers\TextAnswerChecker;
use Inc\Services\Task\Checkers\TripleAnswerChecker;
use Inc\Services\Task\TaskCheckerRegistry;
use PHPUnit\Framework\TestCase;

class TaskCheckerRegistryTest extends TestCase {

	private TaskCheckerRegistry $registry;

	protected function setUp(): void {
		$this->registry = new TaskCheckerRegistry(
			new TextAnswerChecker(),
			new TripleAnswerChecker(),
			new ChoiceChecker(),
			new MatchingChecker(),
			new OrderingChecker(),
			new FillChecker(),
		);
	}

	public function test_has_returns_true_for_auto_gradeable(): void {
		self::assertTrue( $this->registry->has( TaskTemplate::Choice ) );
		self::assertTrue( $this->registry->has( TaskTemplate::Matching ) );
		self::assertTrue( $this->registry->has( TaskTemplate::Ordering ) );
		self::assertTrue( $this->registry->has( TaskTemplate::Fill ) );
	}

	public function test_has_returns_false_for_code_tasks(): void {
		// Code/File task types require manual review — no auto-checker registered.
		self::assertFalse( $this->registry->has( TaskTemplate::Code ) );
		self::assertFalse( $this->registry->has( TaskTemplate::File ) );
	}

	public function test_get_returns_correct_checker_type(): void {
		self::assertInstanceOf( ChoiceChecker::class,   $this->registry->get( TaskTemplate::Choice ) );
		self::assertInstanceOf( MatchingChecker::class, $this->registry->get( TaskTemplate::Matching ) );
		self::assertInstanceOf( OrderingChecker::class, $this->registry->get( TaskTemplate::Ordering ) );
		self::assertInstanceOf( FillChecker::class,     $this->registry->get( TaskTemplate::Fill ) );
	}

	public function test_get_returns_null_for_code_task(): void {
		self::assertNull( $this->registry->get( TaskTemplate::Code ) );
	}
}
