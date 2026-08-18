<?php

declare( strict_types=1 );

namespace Inc\Modules\EgeComputer\Config;

use Inc\Enums\Assessment\AssessmentKind;

/**
 * Class StationExamConfig
 *
 * Настройки станций-экзаменов (время, лимит попыток, проходной балл, шкала
 * перевода), захардкоженные для «Компьютерного ЕГЭ» и «Компьютерного ОГЭ» —
 * станции имитируют реальный экзамен, поэтому эти параметры больше не
 * редактируются автором конкретной работы через `AssessmentTemplate`
 * (см. .docs/Tasks.md, §3.2). Единая точка чтения для обеих станций —
 * {@see AssessmentManager::STATION_SETTINGS_FILTER} подменяет соответствующие
 * поля `AssessmentDTO` этими значениями до того, как DTO попадёт в
 * `AttemptService`/`AttemptOutcomeService`/`ExamResultService`/`AssessmentIntroConfig`.
 *
 * Источник цифр — `.docs/oge/scores.md` (2026-08-18). Значение шкалы ЕГЭ
 * переиспользует {@see KegeScaleConfig} — не дублируется здесь.
 *
 * @package Inc\Modules\EgeComputer\Config
 */
class StationExamConfig {

	/**
	 * Настройки станции по виду экзамена.
	 *
	 * @return array{timeLimit: int, maxAttempts: int, passScore: float, scoreMap: array<int, int>}|null
	 *         null — kind не станция, override не нужен.
	 */
	public static function for( AssessmentKind $kind ): ?array {
		return match ( $kind ) {
			AssessmentKind::EgeComputer => [
				'timeLimit'   => 235, // 3 часа 55 минут
				'maxAttempts' => 1,
				'passScore'   => 6.0, // первичных
				'scoreMap'    => KegeScaleConfig::scale(),
			],
			AssessmentKind::OgeComputer => [
				'timeLimit'   => 150, // 2 часа 30 минут
				'maxAttempts' => 1,
				'passScore'   => 5.0, // первичных
				'scoreMap'    => OgeScaleConfig::scale(),
			],
			default => null,
		};
	}
}
