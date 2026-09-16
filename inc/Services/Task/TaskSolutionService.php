<?php

declare( strict_types=1 );

namespace Inc\Services\Task;

use Inc\Shared\SafeHtml;

/**
 * Class TaskSolutionService
 *
 * Эталон задачи для тех, кто ведёт занятие или пишет курс: человекочитаемый
 * правильный ответ (`CorrectAnswerResolver`) + авторское решение + листинг кода
 * (`task_code` — авторский листинг, если он заполнен).
 *
 * Решение живёт в двух полях: `task_text` («Решение») есть почти у всех шаблонов
 * и выводится в том числе на публичной странице задания, а `solution_text`
 * («Решение для проверяющего») — у ручных шаблонов («Развёрнутый ответ»,
 * «Альтернативные условия»), и на публику не уходит никогда. Преподавателю нужно
 * и то и другое, поэтому берём заполненное, отдавая приоритет `task_text`.
 *
 * Общий для обоих плееров — teacher-режима занятия
 * ({@see \Inc\Services\Course\LessonPlayerService}) и предпросмотра курса
 * ({@see \Inc\Services\Course\CoursePreviewService}): решение о том, показывать
 * эталон или нет, принимает вызывающий сервис, а собирается он здесь один раз.
 *
 * На клиент ученика эталон не уходит НИКОГДА: в ученическом view его добавляет
 * только штатный канал D20 (после исчерпания попыток), отдельным ключом.
 *
 * @package Inc\Services\Task
 */
readonly class TaskSolutionService {

	public function __construct(
		private CorrectAnswerResolver $correctAnswers,
	) {}

	/**
	 * @param int                  $taskId ID задачи
	 * @param array<string, mixed> $meta   Мета задачи из `StepContentRenderer::taskBundle()`
	 *
	 * @return array{answer:string, html:string, code:string}|null null — эталона нет (ручной шаблон без решения).
	 */
	public function forTask( int $taskId, array $meta ): ?array {
		$answer   = (string) ( $this->correctAnswers->resolve( $taskId ) ?? '' );
		$authored = trim( (string) ( $meta['task_text'] ?? '' ) );
		if ( '' === $authored ) {
			$authored = (string) ( $meta['solution_text'] ?? '' );
		}
		$html = SafeHtml::post( $authored );

		// Листинг берём по наличию, а не по `TaskTemplate::hasCodeField()`: тот
		// отвечает на другой вопрос — есть ли поле «Код» в ОТВЕТЕ УЧЕНИКА, — и
		// прятал авторский код у «19-21» и ручных шаблонов, где он как раз есть.
		$code = (string) ( $meta['task_code'] ?? '' );

		if ( '' === $answer && '' === $html && '' === $code ) {
			return null;
		}

		return array(
			'answer' => $answer,
			'html'   => $html,
			'code'   => $code,
		);
	}
}
