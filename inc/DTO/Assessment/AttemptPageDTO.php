<?php

declare( strict_types=1 );

namespace Inc\DTO\Assessment;

use Inc\DTO\Person\PersonDTO;

/**
 * Данные страницы прохождения контрольной, собранные для шаблона.
 *
 * Готовится {@see \Inc\Services\Assessment\AttemptPageService}: контроллер только
 * исполняет решение о доступе, распаковывает DTO в переменные шаблона и подключает
 * нужный рендерер.
 */
readonly class AttemptPageDTO {

	/**
	 * @param PersonDTO|null            $person         Ученик (по текущему пользователю WP); null — предпросмотр автора
	 * @param AttemptDTO|null $activeAttempt  Незавершённая попытка, если есть
	 * @param AttemptDTO|null $lastAttempt    Последняя сданная попытка (экран результата)
	 * @param bool                      $examInProgress Идёт активная непросроченная попытка
	 * @param array                     $taskViews      Per-task данные для рендера заданий
	 * @param array                     $resultPerTask  Результаты по заданиям последней попытки
	 * @param string                    $outcome        Метка исхода (по вторичному баллу для ЕГЭ)
	 * @param string                    $outcomeState   Состояние исхода: pass|fail|…
	 * @param bool                      $canRetry       Доступна ли ещё попытка
	 * @param string                    $now            Текущее время (mysql)
	 * @param bool                      $previewMode    Предпросмотр автора: ученика и попытки нет, ответы не сохраняются
	 * @param int                       $attemptsUsed   Сколько попыток ученик уже израсходовал (Tasks.md, п. 8)
	 */
	public function __construct(
		public ?PersonDTO            $person,
		public ?AttemptDTO $activeAttempt,
		public ?AttemptDTO $lastAttempt,
		public bool                  $examInProgress,
		public array                 $taskViews,
		public array                 $resultPerTask,
		public string                $outcome,
		public string                $outcomeState,
		public bool                  $canRetry,
		public string                $now,
		public bool                  $previewMode = false,
		public int                   $attemptsUsed = 0,
	) {}
}
