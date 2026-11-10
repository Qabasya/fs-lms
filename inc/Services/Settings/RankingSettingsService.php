<?php
/**
 * Validated ranking settings persistence.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Settings;

use Inc\Enums\Course\WorkType;
use Inc\Repositories\OptionsRepositories\PluginConfigRepository;
use Inc\Repositories\OptionsRepositories\RankingSnapshotRepository;
use Inc\Repositories\OptionsRepositories\SubjectRepository;

/** Validates the entire submitted patch before saving any field. */
class RankingSettingsService {

	/** Receives core settings storage. */
	public function __construct( private readonly PluginConfigRepository $repository, private readonly ?RankingSnapshotRepository $snapshots = null, private readonly ?SubjectRepository $subjects = null ) {}

	/**
	 * Saves a partial patch; unrelated and omitted settings retain their values.
	 *
	 * @throws \InvalidArgumentException Unknown fields or invalid numeric values.
	 */
	public function save( array $settings ): void {
		$patch = $this->validate( $settings );
		$changed = false;
		$this->repository->mutate( static function ( array $current ) use ( $patch, &$changed ): array {
			if ( (int) ( $current['ranking_subjects_version'] ?? 0 ) >= 1 ) {
				throw new \InvalidArgumentException( 'Обновите страницу настроек рейтинга.' );
			}
			$updated = array_merge( $current, $patch );
			$changed = $updated !== $current;
			return $updated;
		} );
		if ( $changed ) {
			$this->snapshots?->clear();
		}
	}

	private function validate( array $settings ): array {
		$allowed = array( 'practice', 'independent', 'homework', 'survey', 'assessment', 'attendance_bonus', 'attendance_streak_length', 'hidden_subjects', 'excluded_students' );
		if ( array() === $settings || array_diff( array_keys( $settings ), $allowed ) ) {
			throw new \InvalidArgumentException( 'Передайте известные настройки рейтинга.' );
		}
		$patch = array();
		foreach ( $settings as $key => $value ) {
			if ( in_array( $key, array( 'hidden_subjects', 'excluded_students' ), true ) ) {
				if ( ! is_string( $value ) ) {
					throw new \InvalidArgumentException( 'Некорректный список настроек рейтинга.' );
				}
				$items = '' === $value ? array() : explode( ',', $value );
				foreach ( $items as $item ) {
					$pattern = 'hidden_subjects' === $key ? '/^[a-z0-9_-]{1,100}$/' : '/^[1-9][0-9]{0,9}$/';
					if ( ! preg_match( $pattern, $item ) ) {
						throw new \InvalidArgumentException( 'Некорректный список настроек рейтинга.' );
					}
				}
				$patch[ 'ranking_' . $key ] = array_values( array_unique( 'excluded_students' === $key ? array_map( 'intval', $items ) : $items ) );
				continue;
			}
			$isLength = 'attendance_streak_length' === $key;
			$pattern  = '/^\d+$/';
			if ( ( ! is_int( $value ) && ! is_float( $value ) && ! is_string( $value ) ) || ( is_float( $value ) && ! is_finite( $value ) ) || ! preg_match( $pattern, (string) $value ) ) {
				throw new \InvalidArgumentException( 'Укажите целые неотрицательные значения.' );
			}
			$number = (float) $value;
			if ( ! is_finite( $number ) || $number < ( $isLength ? 2 : 0 ) || $number > ( $isLength ? 100 : 1000 ) ) {
				throw new \InvalidArgumentException( 'Вес должен быть от 0 до 1000, длина серии — от 2 до 100 занятий.' );
			}
			$patch[ 'ranking_' . $key ] = $isLength ? (int) $number : round( $number, 2 );
		}
		return $patch;
	}

	/** Validate every subject before atomically applying its partial patch. */
	public function saveSubjects( array $subjects, array $settings = array() ): void {
		if ( ( array() === $subjects && array() === $settings ) || null === $this->subjects || (int) $this->repository->get()['ranking_subjects_version'] < 1 ) {
			throw new \InvalidArgumentException( 'Передайте настройки предметов.' );
		}
		if ( array_diff( array_keys( $settings ), array( 'excluded_students' ) ) ) {
			throw new \InvalidArgumentException( 'Неизвестная общая настройка рейтинга.' );
		}
		$globalPatch = $settings ? $this->validate( $settings ) : array();
		$patches = array();
		foreach ( $subjects as $key => $values ) {
			if ( ! is_string( $key ) || ! is_array( $values ) || array() === $values ) {
				throw new \InvalidArgumentException( 'Неизвестный предмет.' );
			}
			$hasEnabled = array_key_exists( 'enabled', $values );
			$enabled = $values['enabled'] ?? null;
			unset( $values['enabled'] );
			if ( $hasEnabled && ! in_array( $enabled, array( '0', '1' ), true ) ) {
				throw new \InvalidArgumentException( 'Некорректный переключатель предмета ' . $key . '.' );
			}
			$validated = array();
			foreach ( $values as $field => $value ) {
				try {
					$validated += $this->validate( array( $field => $value ) );
				} catch ( \InvalidArgumentException $error ) {
					$label = WorkType::tryFrom( (string) $field )?->label() ?? match ( $field ) {
						'assessment' => 'Экзамены и контрольные',
						'attendance_bonus' => 'Бонус за серию посещений',
						'attendance_streak_length' => 'Занятий подряд для бонуса',
						'excluded_students' => 'Исключённые ученики',
						default => (string) $field,
					};
					throw new \InvalidArgumentException( 'Предмет ' . $key . ', поле «' . $label . '»: ' . $error->getMessage(), 0, $error );
				}
			}
			foreach ( $validated as $field => $value ) {
				if ( in_array( $field, array( 'ranking_hidden_subjects', 'ranking_excluded_students' ), true ) ) {
					throw new \InvalidArgumentException( 'Предмет ' . $key . ': недопустимое поле hidden_subjects.' );
				}
				$patches[ $key ][ substr( $field, 8 ) ] = $value;
			}
			if ( $hasEnabled ) {
				$patches[ $key ]['enabled'] = '1' === $enabled;
			}
		}
		$this->repository->mutate( function ( array $config ) use ( $patches, $globalPatch ): array {
			if ( (int) $config['ranking_subjects_version'] < 1 ) {
				throw new \InvalidArgumentException( 'Обновите страницу настроек рейтинга.' );
			}
			$this->subjects->refreshCache();
			foreach ( array_keys( $patches ) as $key ) {
				if ( null === $this->subjects->getByKey( $key ) ) {
					throw new \InvalidArgumentException( 'Неизвестный предмет: ' . $key );
				}
			}
			$map = is_array( $config['ranking_subjects'] ) ? $config['ranking_subjects'] : array();
			foreach ( $patches as $key => $patch ) {
				$previous = $map[ $key ] ?? array();
				$next = array_merge( $previous, $patch );
				if ( $next !== $previous ) {
					$next['revision'] = (int) ( $previous['revision'] ?? 0 ) + 1;
					$map[ $key ] = $next;
				}
			}
			$config = array_merge( $config, $globalPatch );
			$config['ranking_subjects'] = $map;
			return $config;
		} );
	}
}
