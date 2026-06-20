<?php

declare( strict_types=1 );

namespace Inc\Repositories\OptionsRepositories;

use Inc\Enums\Settings\OptionName;

/**
 * Class ExpulsionPolicyRepository
 *
 * Владеет опцией `fs_lms_expulsion_retention_policy` — политикой доступа
 * отчисленного ученика к пройденному материалу. Инкапсулирует get_option,
 * чтобы сервисы (LessonAccessPolicy) не читали wp_options напрямую.
 *
 * @package Inc\Repositories\OptionsRepositories
 */
readonly class ExpulsionPolicyRepository {

	/** Сохранять read-only доступ к пройденному (значение по умолчанию). */
	public const RETAIN = 'retain';

	/** Полностью блокировать доступ после отчисления. */
	public const BLOCK = 'block';

	/** @return string self::RETAIN | self::BLOCK */
	public function getRetentionPolicy(): string {
		$value = (string) get_option( OptionName::ExpulsionRetentionPolicy->value, self::RETAIN );

		return self::BLOCK === $value ? self::BLOCK : self::RETAIN;
	}
}
