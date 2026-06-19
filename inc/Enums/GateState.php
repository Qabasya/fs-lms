<?php

declare( strict_types=1 );

namespace Inc\Enums;

/**
 * Состояние гейта шага/урока (★, T1.5.10): доступен ли элемент ученику с учётом
 * даты и выполнения предусловий. Отдельно от `ProgressStatus` (что сделано) —
 * это «можно ли войти».
 *
 * @package Inc\Enums
 */
enum GateState: string {

	case Locked    = 'locked';
	case Available = 'available';

	public function isAvailable(): bool {
		return self::Available === $this;
	}
}
