<?php

declare( strict_types=1 );

namespace Inc\Modules\EgeComputer\Config;

/**
 * Class OgeScaleConfig
 *
 * Официальные правила подсчёта баллов ОГЭ по информатике (станция «Компьютерный
 * ОГЭ»): первичный балл 0–21 переводится в отметку 2–5 (не в 100-балльную шкалу,
 * как у ЕГЭ — историческое различие двух экзаменов). Источник цифр —
 * `.docs/oge/scores.md` (2026-08-18).
 *
 * Живёт в модуле, а не на работе ({@see \Inc\DTO\Assessment\AssessmentDTO::$scoreMap}) —
 * тот же принцип, что и {@see KegeScaleConfig}: станция имитирует реальный ОГЭ,
 * шкала фиксирована и не редактируется автором конкретного экзамена.
 *
 * Максимум первичного балла (21) складывается из позиций 1–12 (по 1 баллу
 * автопроверяемых заданий) + 13 (до 2 баллов, альтернатива 13.1/13.2) +
 * 14 (до 3 баллов) + 15 (до 2 баллов) + 16 (до 2 баллов) = 12+2+3+2+2 = 21.
 *
 * @package Inc\Modules\EgeComputer\Config
 */
class OgeScaleConfig {

	/**
	 * Шкала перевода первичного балла в отметку по пятибалльной шкале.
	 */
	private const SCALE = [
		0  => 2,
		1  => 2,
		2  => 2,
		3  => 2,
		4  => 2,
		5  => 3,
		6  => 3,
		7  => 3,
		8  => 3,
		9  => 3,
		10 => 3,
		11 => 4,
		12 => 4,
		13 => 4,
		14 => 4,
		15 => 4,
		16 => 4,
		17 => 5,
		18 => 5,
		19 => 5,
		20 => 5,
		21 => 5,
	];

	/**
	 * Таблица перевода для {@see \Inc\Services\Assessment\SecondaryScoreService}.
	 *
	 * @return array<int, int>
	 */
	public static function scale(): array {
		return self::SCALE;
	}

	/** Максимальная отметка — 5. */
	public static function secondaryMax(): int {
		return max( self::SCALE );
	}

	/** Максимальный первичный балл — 21 (см. докблок класса). */
	public static function maxPrimary(): int {
		return max( array_keys( self::SCALE ) );
	}

	/** Число позиций в списке заданий станции (см. .docs/Tasks.md, §2). */
	public const TASK_COUNT = 16;

	/**
	 * Баллы за задание по его номеру/позиции (§3.5, .docs/Tasks.md) — фиксированная
	 * таблица, не редактируется автором конкретного экзамена. Позиции 13.1/13.2/14/
	 * 15/16 (ручная проверка) берут максимум из {@see OgeCriteriaConfig::rubricFor()}
	 * — единый источник, чтобы баллы задания и рубрика проверки не разошлись.
	 * Всё остальное (числовые позиции 1-12, автопроверяемые) — 1 балл.
	 *
	 * @param string $position Номер/позиция задания («1»…«12», «13.1», «13.2», «14», «15», «16»)
	 */
	public static function pointsForPosition( string $position ): int {
		$rubric = OgeCriteriaConfig::rubricFor( $position );

		return $rubric['max_points'] ?? 1;
	}
}
