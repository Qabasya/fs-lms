<?php
declare(strict_types=1);

namespace Inc\Enums;

enum EnrollmentStatus: string {

	/** Студент обучается в группе */
	case Active = 'active';

	/** Студент завершил обучение */
	case Finished = 'finished';

	/** Студент отчислен*/
	case Expelled = 'expelled';

	/** Студент переведён в другую группу */
	case Transferred = 'transferred';

	/**
	 * Возвращает true для завершённых (терминальных) статусов.
	 * Терминальные статусы не предполагают дальнейших изменений.
	 *
	 * @return bool
	 */
	public function isTerminal(): bool {
		return match ( $this ) {
			// Активный статус — не терминальный (можно изменить)
			self::Active => false,

			// Завершённые статусы — терминальные (дальнейшие изменения не допускаются)
			self::Finished, self::Expelled, self::Transferred => true,
		};
	}
}
