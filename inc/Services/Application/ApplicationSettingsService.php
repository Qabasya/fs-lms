<?php

declare( strict_types=1 );

namespace Inc\Services\Application;

use Inc\Repositories\OptionsRepositories\PluginConfigRepository;

/**
 * Class ApplicationSettingsService
 *
 * Типизированный доступ к настройкам заявок (раздел «Настройка заявок» конфига плагина):
 * тумблер «Привязать заявку к направлению» + карта кодов направлений `[subject_key => code]`.
 * Резолвит введённый учеником код направления в `subject_key`.
 *
 * @package Inc\Services\Application
 */
readonly class ApplicationSettingsService {

	public function __construct(
		private PluginConfigRepository $config,
	) {}

	/** Включена ли привязка заявки к направлению (модалка кода на /lms/apply). */
	public function isBindToSubject(): bool {
		return (bool) ( $this->config->get()['applications_bind_to_subject'] ?? false );
	}

	/**
	 * Карта направлений: `[subject_key => code]`.
	 *
	 * @return array<string, string>
	 */
	public function directionCodes(): array {
		$codes = $this->config->get()['direction_codes'] ?? array();
		return is_array( $codes ) ? $codes : array();
	}

	/**
	 * Резолвит код направления в ключ предмета. Сравнение по обрезанной строке.
	 *
	 * @param string $code Введённый учеником код.
	 *
	 * @return string|null subject_key или null, если код не найден.
	 */
	public function resolveSubjectByCode( string $code ): ?string {
		$code = trim( $code );
		if ( '' === $code ) {
			return null;
		}

		foreach ( $this->directionCodes() as $subjectKey => $directionCode ) {
			if ( trim( (string) $directionCode ) === $code && '' !== trim( (string) $directionCode ) ) {
				return (string) $subjectKey;
			}
		}

		return null;
	}
}
