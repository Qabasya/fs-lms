<?php

declare( strict_types=1 );

namespace Inc\Services\Assessment;

use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Subject\TaskTemplate;

/**
 * Class ExamPayloadFilter
 *
 * Фильтрует метаданные задачи перед отправкой в браузер во время экзамена.
 * Гарантирует, что эталонные ответы, код решений и объяснения не покидают сервер.
 * Вызывается на сервере до формирования payload для шаблона / AJAX.
 *
 * @package Inc\Services\Assessment
 */
class ExamPayloadFilter {

	/**
	 * Возвращает безопасный subset мета-данных задачи для отображения в экзамене.
	 *
	 * @param array          $meta         Полный массив fs_lms_meta задачи.
	 * @param TaskTemplate   $template     Тип шаблона.
	 * @param AssessmentKind $kind         Тип экзамена.
	 * @return array Отфильтрованные мета-данные (без эталонных ответов и кода).
	 */
	public function filter( array $meta, TaskTemplate $template, AssessmentKind $kind ): array {
		if ( ! $kind->answersOnly() ) {
			return $meta;
		}

		$filtered = $meta;

		// Универсальные поля — всегда убираем.
		unset(
			$filtered['task_code'],
			$filtered['correct_answer'],
			$filtered['solution'],
			$filtered['solution_text'],
			$filtered['explanation'],
		);

		// Тип-специфичная очистка.
		switch ( $template ) {
			case TaskTemplate::Choice:
				$filtered = $this->filterChoice( $filtered );
				break;

			case TaskTemplate::Matching:
				$filtered = $this->filterMatching( $filtered );
				break;

			case TaskTemplate::Fill:
				$filtered = $this->filterFill( $filtered );
				break;

			case TaskTemplate::Triple:
				// ThreeInOne в режиме экзамена разворачивается (T7.12).
				// На уровне фильтра убираем ответы трёх подзаданий.
				unset( $filtered['answer_19'], $filtered['answer_20'], $filtered['answer_21'] );
				break;
		}

		return $filtered;
	}

	/** Убирает флаги is_correct из вариантов выбора. */
	private function filterChoice( array $meta ): array {
		if ( empty( $meta['options'] ) || ! is_array( $meta['options'] ) ) {
			return $meta;
		}
		$meta['options'] = array_map( static function ( array $option ): array {
			unset( $option['is_correct'] );
			return $option;
		}, $meta['options'] );

		return $meta;
	}

	/**
	 * Перемешивает правую колонку сопоставления и убирает исходные пары
	 * (студент видит левую и правую колонки отдельно — без правильного сопоставления).
	 */
	private function filterMatching( array $meta ): array {
		if ( empty( $meta['pairs'] ) || ! is_array( $meta['pairs'] ) ) {
			return $meta;
		}

		$leftItems  = [];
		$rightItems = [];

		foreach ( $meta['pairs'] as $pair ) {
			$leftItems[]  = $pair['left']  ?? '';
			$rightItems[] = $pair['right'] ?? '';
		}

		shuffle( $rightItems );

		// Заменяем pairs на раздельные колонки без правильного сопоставления.
		unset( $meta['pairs'] );
		$meta['left_items']  = $leftItems;
		$meta['right_items'] = $rightItems;

		return $meta;
	}

	/** Убирает эталонные ответы пропусков (студент видит текст с пустыми полями). */
	private function filterFill( array $meta ): array {
		if ( ! empty( $meta['gaps'] ) && is_array( $meta['gaps'] ) ) {
			$meta['gaps'] = array_map( static function ( array $gap ): array {
				unset( $gap['answer'], $gap['correct'] );
				return $gap;
			}, $meta['gaps'] );
		}
		unset( $meta['answers'] );

		return $meta;
	}
}
