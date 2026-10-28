<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\Config;

use Inc\Modules\Shared\ModuleConfig;

/**
 * Class MasterclassConfig
 *
 * Конфигурация модуля Masterclass (публичные мастер-классы во внешних школах).
 * Модуль владеет СВОЕЙ опцией `fs_lms_masterclass` — ядро о ней не знает (в core `OptionName`
 * и `PluginConfigRepository` ничего не добавляется).
 *
 * Флаг включения: константа `FS_LMS_MASTERCLASS` в wp-config.php перекрывает тумблер из опции.
 * Границы чисел держит {@see self::INT_BOUNDS}: чтение и {@see self::normalize()} клампят одинаково,
 * поэтому испорченное значение в опции не ломает тик и форму. Телефон окна «Связаться» в настройки
 * не входит — он берётся из центра обучения (`PluginConfig::centerContactsFallback()`).
 *
 * @package Inc\Modules\Masterclass\Config
 */
class MasterclassConfig extends ModuleConfig {

	/** Ключ опции модуля (вне core OptionName — изоляция). */
	public const OPTION = 'fs_lms_masterclass';

	/** Константа wp-config, перекрывающая тумблер. */
	public const TOGGLE_CONSTANT = 'FS_LMS_MASTERCLASS';

	public const DEFAULT_START_TIME  = '09:00';
	public const DEFAULT_END_TIME    = '14:00';
	public const DEFAULT_BANNER_TEXT = 'Свяжитесь с администратором, чтобы записаться на занятия';

	/** Предел длины текста баннера, символов. */
	public const BANNER_MAX = 300;

	/**
	 * Целые настройки: ключ → [по умолчанию, минимум, максимум].
	 *
	 * @var array<string, array{0: int, 1: int, 2: int}>
	 */
	public const INT_BOUNDS = array(
		'batch_size'           => array( 60, 1, 500 ),
		'home_timeout_minutes' => array( 60, 5, 720 ),
		'freeze_days'          => array( 14, 1, 60 ),
		'unused_purge_days'    => array( 7, 1, 60 ),
		'frozen_purge_days'    => array( 30, 1, 365 ),
		'log_retention_days'   => array( 365, 30, 1095 ),
	);

	private const DEFAULTS = array(
		'enabled'              => false,
		'start_time'           => self::DEFAULT_START_TIME,
		'end_time'             => self::DEFAULT_END_TIME,
		'batch_size'           => 60,
		'home_timeout_minutes' => 60,
		'freeze_days'          => 14,
		'unused_purge_days'    => 7,
		'frozen_purge_days'    => 30,
		'log_retention_days'   => 365,
		'messenger_url'        => '',
		'banner_text'          => self::DEFAULT_BANNER_TEXT,
	);

	protected function option(): string {
		return self::OPTION;
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function defaults(): array {
		return self::DEFAULTS;
	}

	protected function toggleConstant(): ?string {
		return self::TOGGLE_CONSTANT;
	}

	/** Начало окна по умолчанию для формы (`HH:MM`). */
	public function startTime(): string {
		return $this->window()['start'];
	}

	/** Конец окна по умолчанию для формы (`HH:MM`); всегда позже начала. */
	public function endTime(): string {
		return $this->window()['end'];
	}

	/** Сколько аккаунтов за один шаг цикла «создать». */
	public function batchSize(): int {
		return $this->bounded( 'batch_size' );
	}

	/** Через сколько минут после первого входа повторный вход считается «дома». */
	public function homeTimeoutMinutes(): int {
		return $this->bounded( 'home_timeout_minutes' );
	}

	/** Через сколько дней после конца мастер-класса доступ замораживается. */
	public function freezeDays(): int {
		return $this->bounded( 'freeze_days' );
	}

	/** Через сколько дней после конца удаляются аккаунты, ни разу не входившие. */
	public function unusedPurgeDays(): int {
		return $this->bounded( 'unused_purge_days' );
	}

	/** Через сколько дней после заморозки мастер-класс вычищается целиком. */
	public function frozenPurgeDays(): int {
		return $this->bounded( 'frozen_purge_days' );
	}

	/** Сколько дней хранится журнал модуля. */
	public function logRetentionDays(): int {
		return $this->bounded( 'log_retention_days' );
	}

	/** Ссылка на мессенджер для окна «Связаться»; пусто — кнопки нет. */
	public function messengerUrl(): string {
		return trim( (string) ( $this->get()['messenger_url'] ?? '' ) );
	}

	/** Текст баннера в плеере; пустое значение заменяется текстом по умолчанию. */
	public function bannerText(): string {
		$text = trim( (string) ( $this->get()['banner_text'] ?? '' ) );

		return '' === $text ? self::DEFAULT_BANNER_TEXT : mb_substr( $text, 0, self::BANNER_MAX );
	}

	/**
	 * Приводит присланные значения к допустимым перед {@see ModuleConfig::save()}.
	 *
	 * Целые клампятся в границы, текст баннера обрезается, неизвестные ключи отбрасываются; ключи, которых
	 * нет в `$partial`, не появляются (сохраняются только присланные). Время вне формата `HH:MM` и окно
	 * «конец не позже начала» (с учётом уже сохранённого значения парного ключа) — ошибка ввода.
	 *
	 * @param array<string, mixed> $partial Уже очищенные `Sanitizer`-ом значения.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException Время не в формате `HH:MM` или конец окна не позже начала.
	 */
	public function normalize( array $partial ): array {
		$clean = array();

		foreach ( self::INT_BOUNDS as $key => $bounds ) {
			if ( array_key_exists( $key, $partial ) ) {
				$clean[ $key ] = self::clamp( $partial[ $key ], $bounds );
			}
		}

		foreach ( array( 'start_time', 'end_time' ) as $key ) {
			if ( array_key_exists( $key, $partial ) ) {
				if ( ! self::isValidTime( (string) $partial[ $key ] ) ) {
					throw new \InvalidArgumentException( 'Время нужно указать в формате ЧЧ:ММ.' );
				}
				$clean[ $key ] = (string) $partial[ $key ];
			}
		}

		if ( isset( $clean['start_time'] ) || isset( $clean['end_time'] ) ) {
			$stored = $this->get();
			$start  = $clean['start_time'] ?? (string) $stored['start_time'];
			$end    = $clean['end_time'] ?? (string) $stored['end_time'];
			if ( $end <= $start ) {
				throw new \InvalidArgumentException( 'Конец окна должен быть позже начала.' );
			}
		}

		if ( array_key_exists( 'messenger_url', $partial ) ) {
			$clean['messenger_url'] = trim( (string) $partial['messenger_url'] );
		}

		if ( array_key_exists( 'banner_text', $partial ) ) {
			$clean['banner_text'] = mb_substr( trim( (string) $partial['banner_text'] ), 0, self::BANNER_MAX );
		}

		return $clean;
	}

	/**
	 * Окно по умолчанию: оба значения валидны и конец позже начала, иначе — пара по умолчанию целиком.
	 *
	 * @return array{start: string, end: string}
	 */
	private function window(): array {
		$stored = $this->get();
		$start  = (string) ( $stored['start_time'] ?? '' );
		$end    = (string) ( $stored['end_time'] ?? '' );

		if ( self::isValidTime( $start ) && self::isValidTime( $end ) && $end > $start ) {
			return array(
				'start' => $start,
				'end'   => $end,
			);
		}

		return array(
			'start' => self::DEFAULT_START_TIME,
			'end'   => self::DEFAULT_END_TIME,
		);
	}

	/** Целая настройка в границах (по образцу `PluginConfig::bounded()`). */
	private function bounded( string $key ): int {
		return self::clamp( $this->get()[ $key ] ?? null, self::INT_BOUNDS[ $key ] );
	}

	/**
	 * @param array{0: int, 1: int, 2: int} $bounds По умолчанию, минимум, максимум.
	 */
	private static function clamp( mixed $value, array $bounds ): int {
		[ $default, $min, $max ] = $bounds;

		return max( $min, min( $max, is_numeric( $value ) ? (int) $value : $default ) );
	}

	/** Валидное время суток `HH:MM`. */
	private static function isValidTime( string $value ): bool {
		return 1 === preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value );
	}
}
