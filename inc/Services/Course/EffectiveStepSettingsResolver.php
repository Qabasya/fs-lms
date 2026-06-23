<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\StepDTO;
use Inc\DTO\Course\StepSettingsDTO;
use Inc\Enums\Subject\TaskTemplate;

/**
 * Class EffectiveStepSettingsResolver
 *
 * Двухуровневое разрешение настроек задания на шаге (Этап 6, Фаза D):
 *   1. Базовые настройки из StepDTO.payload['settings'] (дефолты урока).
 *   2. Перекрытие из GroupLessonDTO.stepSettingsOverrides[step_key] (настройка для группы).
 *   3. Форсированные правила по типу задачи (ordering→shuffle=true, fill→shuffle=false).
 *
 * @package Inc\Services\Course
 */
class EffectiveStepSettingsResolver {

	public function resolve( StepDTO $step, GroupLessonDTO $groupLesson, TaskTemplate $template ): StepSettingsDTO {
		$base     = StepSettingsDTO::fromArray( $step->payload['settings'] ?? array() );
		$override = ( $groupLesson->stepSettingsOverrides ?? array() )[ $step->key ] ?? null;

		if ( is_array( $override ) && ! empty( $override ) ) {
			$merged = array_merge(
				$base->toArray(),
				array_filter( $override, static fn( $v ) => null !== $v && '' !== $v )
			);
			$base = StepSettingsDTO::fromArray( $merged );
		}

		return $base->withTypeDefaults( $template );
	}
}
