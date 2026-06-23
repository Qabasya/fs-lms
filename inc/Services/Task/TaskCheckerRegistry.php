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
			TaskTemplate::Standard->value => $text,
			TaskTemplate::Common->value   => $text,
			TaskTemplate::Audio->value    => $text,
			TaskTemplate::Triple->value   => $triple,
			TaskTemplate::Choice->value   => $choice,
			TaskTemplate::Matching->value => $matching,
			TaskTemplate::Ordering->value => $ordering,
			TaskTemplate::Fill->value     => $fill,
		);
	}

	public function get( TaskTemplate $template ): ?TaskCheckerInterface {
		return $this->map[ $template->value ] ?? null;
	}

	public function has( TaskTemplate $template ): bool {
		return isset( $this->map[ $template->value ] );
	}
}
