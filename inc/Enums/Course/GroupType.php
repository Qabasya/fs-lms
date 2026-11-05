<?php

declare( strict_types=1 );

namespace Inc\Enums\Course;

/** Тип в интерфейсе; хранилище по-прежнему использует access_mode и is_shared. */
enum GroupType: string {
	case Scheduled = 'scheduled';
	case Individual = 'individual';
	case Open = 'open';
	case Shared = 'shared';
	case LegacyOpenShared = 'legacy_open_shared';
	case LegacyIndividualShared = 'legacy_individual_shared';

	public function label(): string {
		return match ( $this ) {
			self::Scheduled => 'По расписанию',
			self::Individual => 'Индивидуальная',
			self::Open => 'Открытая (свободное прохождение)',
			self::Shared => 'Общая',
			self::LegacyOpenShared => 'Открытая + общая (старое сочетание)',
			self::LegacyIndividualShared => 'Индивидуальная + общая (старое сочетание)',
		};
	}

	public function accessMode(): AccessMode {
		return match ( $this ) {
			self::Individual, self::LegacyIndividualShared => AccessMode::Individual,
			self::Open, self::LegacyOpenShared => AccessMode::Open,
			default => AccessMode::Scheduled,
		};
	}

	public function isShared(): bool {
		return in_array( $this, array( self::Shared, self::LegacyOpenShared, self::LegacyIndividualShared ), true );
	}

	public function isLegacy(): bool {
		return self::LegacyOpenShared === $this || self::LegacyIndividualShared === $this;
	}

	public static function fromStorage( string $mode, bool $shared ): self {
		return match ( AccessMode::fromValueOrDefault( $mode ) ) {
			AccessMode::Open => $shared ? self::LegacyOpenShared : self::Open,
			AccessMode::Individual => $shared ? self::LegacyIndividualShared : self::Individual,
			AccessMode::Scheduled => $shared ? self::Shared : self::Scheduled,
		};
	}

	/** @return list<self> */
	public static function selectable(): array {
		return array( self::Scheduled, self::Individual, self::Open, self::Shared );
	}
}
