<?php

declare( strict_types=1 );

namespace Inc\Services\Profile\Learner;

use Inc\Services\Shared\PluginConfig;

/**
 * Class ExamCountdownSection
 *
 * Плитка «До экзамена» на главной ученика: по каждому предмету его групп, для которого в настройках плагина задана
 * дата и время экзамена и экзамен ещё впереди. Дальше недели — календарные дни, в последние 7 суток — часы (срочно, красным).
 *
 * @package Inc\Services\Profile\Learner
 */
class ExamCountdownSection {

	/** Последняя неделя — граница, с которой счёт идёт в часах. */
	private const URGENT_SECONDS = 7 * DAY_IN_SECONDS;

	public function __construct(
		private readonly PluginConfig $config,
	) {}

	/**
	 * @param array<int, array<string, mixed>> $groups   Группы ученика (`subject_key`, `subject`)
	 * @param string                           $nowLocal Сейчас, местное время сайта (`Y-m-d H:i:s`)
	 *
	 * @return list<array{subject_key: string, subject: string, at: string, days: int, hours: int, urgent: bool}>
	 *         Ближайший экзамен первым; по предмету один экзамен
	 */
	public function forGroups( array $groups, string $nowLocal ): array {
		$dates = $this->config->examDates();
		$now   = new \DateTimeImmutable( $nowLocal );
		$items = array();

		foreach ( $groups as $group ) {
			$key = (string) ( $group['subject_key'] ?? '' );
			if ( '' === $key || isset( $items[ $key ] ) || ! isset( $dates[ $key ] ) ) {
				continue;
			}

			$at      = new \DateTimeImmutable( $dates[ $key ] );
			$seconds = $at->getTimestamp() - $now->getTimestamp();
			if ( $seconds <= 0 ) {
				continue;
			}

			$items[ $key ] = array(
				'subject_key' => $key,
				'subject'     => (string) ( $group['subject'] ?? $key ),
				'at'          => $dates[ $key ],
				'days'        => (int) $now->setTime( 0, 0 )->diff( $at->setTime( 0, 0 ) )->days,
				'hours'       => (int) ceil( $seconds / HOUR_IN_SECONDS ),
				'urgent'      => $seconds <= self::URGENT_SECONDS,
				'seconds'     => $seconds,
			);
		}

		usort( $items, static fn( array $a, array $b ): int => $a['seconds'] <=> $b['seconds'] );

		return array_map( static function ( array $item ): array {
			unset( $item['seconds'] );
			return $item;
		}, $items );
	}
}
