<?php

declare( strict_types=1 );

namespace Unit\Services\Task;

use Inc\Services\Task\CorrectAnswerResolver;
use Inc\Services\Task\TaskSolutionService;
use PHPUnit\Framework\TestCase;

/**
 * Пакет задачи для плеера: мета уходит всегда, эталон добавляется только по запросу и когда он есть.
 */
class TaskSolutionServiceAttachTest extends TestCase {

	private function service( ?string $answer ): TaskSolutionService {
		$resolver = $this->createMock( CorrectAnswerResolver::class );
		$resolver->method( 'resolve' )->willReturn( $answer );

		return new TaskSolutionService( $resolver );
	}

	/** @return array<string,mixed> */
	private function bundle(): array {
		return array( 'task_id' => 5, 'meta' => array( 'task_text' => '' ), 'auto_grade' => true );
	}

	public function test_adds_solution_and_strips_meta(): void {
		$out = $this->service( '42' )->attachTo( $this->bundle(), 5 );

		self::assertSame( '42', $out['solution']['answer'] );
		self::assertArrayNotHasKey( 'meta', $out );
		self::assertTrue( $out['auto_grade'] );
	}

	public function test_without_solution_only_strips_meta(): void {
		$out = $this->service( null )->attachTo( $this->bundle(), 5 );

		self::assertArrayNotHasKey( 'solution', $out );
		self::assertArrayNotHasKey( 'meta', $out );
	}

	public function test_student_view_never_gets_solution(): void {
		$out = $this->service( '42' )->attachTo( $this->bundle(), 5, false );

		self::assertArrayNotHasKey( 'solution', $out );
		self::assertArrayNotHasKey( 'meta', $out );
	}
}
