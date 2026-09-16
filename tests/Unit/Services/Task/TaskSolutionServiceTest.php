<?php

declare( strict_types=1 );

namespace Unit\Services\Task;

use Inc\Services\Task\CorrectAnswerResolver;
use Inc\Services\Task\TaskSolutionService;
use PHPUnit\Framework\TestCase;

/**
 * Эталон задачи для преподавателя/автора: ответ + решение + листинг кода.
 * Общий сервис teacher-режима занятия и предпросмотра курса.
 */
class TaskSolutionServiceTest extends TestCase {

	private CorrectAnswerResolver&\PHPUnit\Framework\MockObject\MockObject $correctAnswers;
	private TaskSolutionService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->correctAnswers = $this->createMock( CorrectAnswerResolver::class );
		$this->service        = new TaskSolutionService( $this->correctAnswers );
	}

	public function test_collects_answer_solution_and_code(): void {
		$this->correctAnswers->method( 'resolve' )->with( 77 )->willReturn( 'Вариант Б' );

		$solution = $this->service->forTask( 77, array(
			'task_text' => '<p>Считаем по формуле</p>',
			'task_code' => 'print(42)',
		) );

		self::assertSame( 'Вариант Б', $solution['answer'] );
		self::assertStringContainsString( 'Считаем по формуле', $solution['html'] );
		self::assertSame( 'print(42)', $solution['code'] );
	}

	/** Ручные шаблоны хранят решение в `solution_text` — оно тоже должно доезжать. */
	public function test_falls_back_to_reviewer_solution_text(): void {
		$this->correctAnswers->method( 'resolve' )->willReturn( null );

		$solution = $this->service->forTask( 77, array( 'solution_text' => '<p>Эталон проверяющего</p>' ) );

		self::assertStringContainsString( 'Эталон проверяющего', $solution['html'] );
	}

	/** Заполненное `task_text` приоритетнее: оно и публичное, и авторское. */
	public function test_task_text_wins_over_reviewer_solution(): void {
		$this->correctAnswers->method( 'resolve' )->willReturn( null );

		$solution = $this->service->forTask( 77, array(
			'task_text'     => '<p>Публичное решение</p>',
			'solution_text' => '<p>Эталон проверяющего</p>',
		) );

		self::assertStringContainsString( 'Публичное решение', $solution['html'] );
		self::assertStringNotContainsString( 'Эталон проверяющего', $solution['html'] );
	}

	/** Листинг отдаётся по наличию: «19-21» и ручные шаблоны тоже его хранят. */
	public function test_code_is_returned_for_any_template_with_code(): void {
		$this->correctAnswers->method( 'resolve' )->willReturn( null );

		$solution = $this->service->forTask( 77, array( 'task_code' => 'print(1)' ) );

		self::assertSame( 'print(1)', $solution['code'] );
	}

	public function test_returns_null_when_nothing_authored(): void {
		$this->correctAnswers->method( 'resolve' )->willReturn( null );

		self::assertNull( $this->service->forTask( 77, array() ) );
	}
}
