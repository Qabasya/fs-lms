<?php

declare( strict_types=1 );

namespace Inc\Services\Assessment;

use Inc\DTO\Assessment\AssessmentDTO;
use Inc\Enums\Assessment\AssessmentKind;

/**
 * Class AssessmentIntroConfig
 *
 * Контент интро-шага экзамена, отделённый от рендера (паттерн KegeSlidesConfig,
 * D16.4). В одном месте — дефолтное описание работы (переопределяется per-work
 * полем `intro_html`) и структура блока правил (собирается из DTO). Шаблон
 * `attempt-intro.php` ничего не знает о содержимом заранее — только выводит.
 *
 * @package Inc\Services\Assessment
 */
class AssessmentIntroConfig {

	/**
	 * Дефолтное описание работы (когда per-work `intro_html` пуст).
	 * HTML санитизируется в шаблоне (`wp_kses_post`), не здесь.
	 */
	public static function defaultDescription( AssessmentKind $kind ): string {
		return match ( $kind ) {
			AssessmentKind::Control     =>
				'<p>Перед вами контрольная работа. Ответьте на все задания и нажмите ' .
				'«Сдать». Каждое задание оценивается в один балл.</p>',
			AssessmentKind::EgeComputer, AssessmentKind::OgeComputer =>
				'<p>Перед вами экзаменационная работа в формате ЕГЭ. Задания открываются ' .
				'по одному; переходите между ними через меню номеров и сохраняйте ответы. ' .
				'Ответ можно изменить до завершения работы.</p>',
		};
	}

	/**
	 * Блок правил, собранный из DTO (авто, D16.4): время / попытки / число заданий /
	 * проходной балл. Пункты с нулевым значением («без лимита») опускаются, кроме
	 * числа заданий — оно показывается всегда.
	 *
	 * @return array<int, array{label: string, value: string}>
	 */
	public static function rules( AssessmentDTO $assessment ): array {
		$rules = array();

		if ( $assessment->timeLimit > 0 ) {
			$rules[] = array( 'label' => 'Время', 'value' => $assessment->timeLimit . ' мин' );
		}
		if ( $assessment->attemptsAllowed > 0 ) {
			$rules[] = array( 'label' => 'Попыток', 'value' => (string) $assessment->attemptsAllowed );
		}
		$rules[] = array( 'label' => 'Заданий', 'value' => (string) count( $assessment->taskIds ) );

		if ( $assessment->passScore > 0 ) {
			$rules[] = array( 'label' => 'Проходной балл', 'value' => (string) (float) $assessment->passScore );
		}

		return $rules;
	}

	/**
	 * Предупреждение о том, что попытки на исходе (Tasks.md, п. 8) — то же
	 * правило, что у работы в плеере (`step-work.js`): за две попытки до конца
	 * и на последней. Работы с единственной попыткой не предупреждаем: там
	 * первая же попытка и есть последняя, и об этом говорит блок правил.
	 *
	 * @param int $attemptsUsed Сколько попыток ученик уже израсходовал
	 */
	public static function attemptWarning( AssessmentDTO $assessment, int $attemptsUsed ): string {
		if ( $assessment->attemptsAllowed <= 1 ) {
			return '';
		}

		return match ( max( 0, $assessment->attemptsAllowed - $attemptsUsed ) ) {
			2       => 'Это предпоследняя попытка — после неё останется ещё одна.',
			1       => 'Осторожно, это последняя попытка.',
			default => '',
		};
	}
}
