<?php
/**
 * Validated ranking settings persistence.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Settings;

use Inc\Repositories\OptionsRepositories\PluginConfigRepository;

/** Validates the entire submitted patch before saving any field. */
class RankingSettingsService {

	/** Receives core settings storage. */
	public function __construct( private readonly PluginConfigRepository $repository ) {}

	/**
	 * Saves a partial patch; unrelated and omitted settings retain their values.
	 *
	 * @throws \InvalidArgumentException Unknown fields or invalid numeric values.
	 */
	public function save( array $settings ): void {
		$allowed = array( 'practice', 'independent', 'homework', 'survey', 'assessment', 'attendance_bonus', 'attendance_streak_length' );
		if ( array() === $settings || array_diff( array_keys( $settings ), $allowed ) ) {
			throw new \InvalidArgumentException( 'Передайте известные настройки рейтинга.' );
		}
		$patch = array();
		foreach ( $settings as $key => $value ) {
			$isLength = 'attendance_streak_length' === $key;
			$pattern  = $isLength ? '/^\d+$/' : '/^\d+(?:\.\d{1,2})?$/';
			if ( ( ! is_int( $value ) && ! is_float( $value ) && ! is_string( $value ) ) || ( is_float( $value ) && ! is_finite( $value ) ) || ! preg_match( $pattern, (string) $value ) ) {
				throw new \InvalidArgumentException( 'Укажите неотрицательные числовые значения; веса — до двух знаков после точки.' );
			}
			$number = (float) $value;
			if ( ! is_finite( $number ) || $number < ( $isLength ? 2 : 0 ) || $number > ( $isLength ? 100 : 1000 ) ) {
				throw new \InvalidArgumentException( 'Вес должен быть от 0 до 1000, длина серии — от 2 до 100 занятий.' );
			}
			$patch[ 'ranking_' . $key ] = $isLength ? (int) $number : round( $number, 2 );
		}
		$this->repository->save( $patch );
	}
}
