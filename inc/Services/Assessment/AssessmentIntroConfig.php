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
			AssessmentKind::Ege, AssessmentKind::EgeComputer =>
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
}
