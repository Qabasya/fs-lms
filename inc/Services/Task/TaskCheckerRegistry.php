<?php

declare( strict_types=1 );

namespace Inc\Services\Task;

use Inc\Contracts\TaskCheckerInterface;
use Inc\Enums\Subject\TaskTemplate;
use Inc\Services\Task\Checkers\ChoiceChecker;
use Inc\Services\Task\Checkers\FillChecker;
use Inc\Services\Task\Checkers\MatchingChecker;
use Inc\Services\Task\Checkers\OrderingChecker;
use Inc\Services\Task\Checkers\TextAnswerChecker;
use Inc\Services\Task\Checkers\TripleAnswerChecker;

/**
 * Class TaskCheckerRegistry
 *
 * Реестр авто-проверщиков: TaskTemplate → TaskCheckerInterface.
 * Шаблоны без записи в реестре требуют ручной проверки.
 *
 * Единственный ручной тип во всём плагине — «Развёрнутый ответ»
 * ({@see TaskTemplate::FileAnswer}): у него нет поля `task_answer`, только
 * материалы/критерии для преподавателя. У ВСЕХ остальных шаблонов есть строка
 * ответа `task_answer` (в т.ч. код/файловые: код и файл не автопроверяются —
 * сверяется ТОЛЬКО ответ), поэтому они привязаны к {@see TextAnswerChecker}.
 *
 * @package Inc\Services\Task
 */
class TaskCheckerRegistry {

	/** @var array<string, TaskCheckerInterface> */
	private array $map;

	public function __construct(
		TextAnswerChecker  $text,
		TripleAnswerChecker $triple,
		ChoiceChecker      $choice,
		MatchingChecker    $matching,
		OrderingChecker    $ordering,
		FillChecker        $fill,
	) {
		$this->map = array(
			// Текстовый ответ (`task_answer`) — сверяется строка ответа.
			// Код/файловые шаблоны: сам код/файл НЕ автопроверяется, только ответ.
			TaskTemplate::Standard->value     => $text,
			TaskTemplate::Common->value       => $text,
			TaskTemplate::Audio->value        => $text,
			TaskTemplate::Code->value         => $text,
			TaskTemplate::FileCode->value     => $text,
			TaskTemplate::File->value         => $text,
			TaskTemplate::TwoFile->value      => $text,
			TaskTemplate::TextSolution->value => $text,
			TaskTemplate::Triple->value       => $triple,
			TaskTemplate::Choice->value       => $choice,
			TaskTemplate::Matching->value     => $matching,
			TaskTemplate::Ordering->value     => $ordering,
			TaskTemplate::Fill->value         => $fill,
			// TaskTemplate::FileAnswer — намеренно НЕ в реестре: единственный ручной.
		);
	}

	public function get( TaskTemplate $template ): ?TaskCheckerInterface {
		return $this->map[ $template->value ] ?? null;
	}

	public function has( TaskTemplate $template ): bool {
		return isset( $this->map[ $template->value ] );
	}
}
