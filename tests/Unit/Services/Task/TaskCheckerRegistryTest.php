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
		self::assertTrue( $this->registry->has( TaskTemplate::Standard ) );
	}

	/**
	 * Код/файловые и текст-решение шаблоны имеют поле ответа (`task_answer`) —
	 * сверяется ТОЛЬКО ответ, поэтому они автопроверяемы (не ручные).
	 */
	public function test_has_returns_true_for_code_and_file_tasks(): void {
		self::assertTrue( $this->registry->has( TaskTemplate::Code ) );
		self::assertTrue( $this->registry->has( TaskTemplate::FileCode ) );
		self::assertTrue( $this->registry->has( TaskTemplate::File ) );
		self::assertTrue( $this->registry->has( TaskTemplate::TwoFile ) );
		self::assertTrue( $this->registry->has( TaskTemplate::TextSolution ) );
	}

	/** Единственный ручной тип во всём плагине — «Развёрнутый ответ». */
	public function test_only_file_answer_is_manual(): void {
		self::assertFalse( $this->registry->has( TaskTemplate::FileAnswer ) );
		self::assertNull( $this->registry->get( TaskTemplate::FileAnswer ) );
	}

	public function test_get_returns_correct_checker_type(): void {
		self::assertInstanceOf( ChoiceChecker::class,   $this->registry->get( TaskTemplate::Choice ) );
		self::assertInstanceOf( MatchingChecker::class, $this->registry->get( TaskTemplate::Matching ) );
		self::assertInstanceOf( OrderingChecker::class, $this->registry->get( TaskTemplate::Ordering ) );
		self::assertInstanceOf( FillChecker::class,     $this->registry->get( TaskTemplate::Fill ) );
	}

	public function test_code_and_file_tasks_use_text_answer_checker(): void {
		self::assertInstanceOf( TextAnswerChecker::class, $this->registry->get( TaskTemplate::Code ) );
		self::assertInstanceOf( TextAnswerChecker::class, $this->registry->get( TaskTemplate::FileCode ) );
		self::assertInstanceOf( TextAnswerChecker::class, $this->registry->get( TaskTemplate::TextSolution ) );
	}
}
