<?php

declare( strict_types=1 );

namespace Inc\Services\Profile\Learner;

/**
 * Class LearnerHighlightsSection
 *
 * Расчёт показателей главной ученика: серия занятий без пропусков, сколько заданий
 * осталось исправить и последний результат экзамена. Серия и последний результат остаются в пилоте, исправления доступны всем. Читает уже собранные данные кабинета (посещаемость, дедлайны,
 * карточки экзаменов), своих запросов к БД не делает.
 *
 * Список логинов пилота — фильтр {@see self::LOGINS_FILTER} (по умолчанию тестовый аккаунт).
 *
 * @package Inc\Services\Profile\Learner
 */
class LearnerHighlightsSection {

	public const LOGINS_FILTER = 'fs_lms_learner_highlights_logins';

	private const PILOT_LOGINS = array( 'belova2024' );

	/** Включены ли дополнительные плитки смотрящему (логин из списка пилота). */
	public function enabledFor( string $login ): bool {
		$logins = apply_filters( self::LOGINS_FILTER, self::PILOT_LOGINS );

		return '' !== $login && is_array( $logins ) && in_array( $login, $logins, true );
	}

	/**
	 * Занятий подряд без пропуска: от самого свежего, пока стоит «был».
	 *
	 * @param array<int, array{present?: bool}> $rows Посещаемость, новые первыми
	 */
	public function streak( array $rows ): int {
		$count = 0;
		foreach ( $rows as $row ) {
			if ( empty( $row['present'] ) ) {
				break;
			}
			++$count;
		}

		return $count;
	}

	/**
	 * Сколько заданий осталось исправить: сумма нерешённых в сданных работах, которые ещё можно пересдать.
	 *
	 * @param array<int, array<string, mixed>> $deadlines Дедлайны (полный список, до обрезки для показа)
	 */
	public function toFix( array $deadlines ): int {
		return (int) array_sum( array_map( static fn( array $d ): int => (int) ( $d['remaining'] ?? 0 ), $deadlines ) );
	}

	/**
	 * Последний утверждённый результат экзамена: ЕГЭ — вторичный балл, ОГЭ — отметка, иначе первичный балл.
	 *
	 * @param array<int, array<string, mixed>> $examCards Карточки экзаменов кабинета
	 *
	 * @return array{value: string, note: string}|null
	 */
	public function lastResult( array $examCards ): ?array {
		$latest = null;
		foreach ( $examCards as $card ) {
			if ( empty( $card['result'] ) || ! is_array( $card['result'] ) ) {
				continue;
			}
			if ( null === $latest || strcmp( (string) ( $card['approved_at'] ?? '' ), (string) ( $latest['approved_at'] ?? '' ) ) > 0 ) {
				$latest = $card;
			}
		}
		if ( null === $latest ) {
			return null;
		}

		$result = $latest['result'];
		$title  = (string) ( $latest['title'] ?? '' );
		if ( null !== ( $result['secondary'] ?? null ) ) {
			return array( 'value' => (string) $result['secondary'], 'note' => $title . ' · баллов' );
		}
		if ( null !== ( $result['grade'] ?? null ) ) {
			return array( 'value' => (string) $result['grade'], 'note' => $title . ' · отметка' );
		}

		return array( 'value' => ( $result['primary'] ?? 0 ) . ' из ' . ( $result['primary_max'] ?? 0 ), 'note' => $title . ' · первичный балл' );
	}
}
