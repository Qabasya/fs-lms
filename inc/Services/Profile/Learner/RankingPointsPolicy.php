<?php
/**
 * Subject ranking read model.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Profile\Learner;

/** All ranking weights live here; repeat submissions deliberately receive the full weight. */
class RankingPointsPolicy {

	/** Receives current core ranking settings. */
	public function __construct( private readonly \Inc\Services\Shared\PluginConfig $config ) {}

	/** Weight of one fully solved task, configured independently of exam grades. */
	public function forSolvedTask( string $source ): float {
		return (float) ( $this->config->rankingSettings()[ $source ] ?? 0.0 );
	}

	/** Points per completed non-overlapping attendance series. */
	public function attendanceBonus(): float {
		return (float) $this->config->rankingSettings()['attendance_bonus'];
	}

	/** Number of consecutive held lessons required for an attendance bonus. */
	public function attendanceStreakLength(): int {
		return (int) $this->config->rankingSettings()['attendance_streak_length'];
	}
}
