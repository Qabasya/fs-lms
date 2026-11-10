<?php

declare(strict_types=1);

namespace Inc\Repositories\OptionsRepositories;

use Inc\DTO\Subject\SubjectDTO;
use Inc\Enums\Settings\OptionName;

/**
 * Class SubjectRepository
 *
 * Репозиторий для работы с предметами.
 *
 * @package Inc\Repositories
 *
 * ### Основные обязанности:
 *
 * 1. **CRUD-операции** — создание, чтение, обновление и удаление предметов.
 * 2. **Преобразование в DTO** — работа с типобезопасными объектами SubjectDTO.
 * 3. **Санитизация данных** — очистка ключа и названия перед сохранением.
 *
 * ### Архитектурная роль:
 *
 * Инкапсулирует работу с опцией `fs_lms_subjects_list` в wp_options.
 * Хранит данные предметов в структурированном виде (key → {key, name}).
 * Использует DTO SubjectDTO для передачи данных между слоями приложения.
 */
class SubjectRepository {

	/** Discard request-local option snapshots after waiting for a lifecycle lock. */
	public function refreshCache(): void {
		wp_cache_delete( OptionName::Subjects->value, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Конструктор репозитория.
	 */
	public function __construct( private readonly PluginConfigRepository $config = new PluginConfigRepository() ) {}

	/**
	 * Имя опции в wp_options.
	 */
	private string $option_name = OptionName::Subjects->value;

	/**
	 * Получает "сырые" данные из опции.
	 *
	 * @return array<string, array{key: string, name: string}>
	 */
	private function getRaw(): array {
		// get_option() — получает опцию из таблицы wp_options
		$subjects = get_option( $this->option_name, array() );
		return is_array( $subjects ) ? $subjects : array();
	}

	/**
	 * Возвращает все предметы в виде массива DTO.
	 *
	 * @return SubjectDTO[]
	 */
	public function readAll(): array {
		return array_map(
			fn( array $item ) => SubjectDTO::fromArray( $item ),
			$this->getRaw()
		);
	}

	/**
	 * Возвращает только неархивные предметы (для UI/меню/создания групп).
	 * Архивные остаются в readAll() — их CPT регистрируются, группы работают.
	 *
	 * @return SubjectDTO[]
	 */
	public function readActive(): array {
		return array_filter( $this->readAll(), static fn( SubjectDTO $s ): bool => ! $s->archived );
	}

	/**
	 * Помечает предмет архивным / возвращает из архива.
	 *
	 * @param string $key
	 * @param bool   $archived
	 *
	 * @return bool
	 */
	public function setArchived( string $key, bool $archived ): bool {
		$result = false;
		$this->config->mutate( function ( array $config ) use ( $key, $archived, &$result ): array {
			$this->refreshCache();
			$subjects = $this->getRaw();
			if ( isset( $subjects[ $key ] ) ) {
				$subjects[ $key ]['archived'] = $archived;
				$result = update_option( $this->option_name, $subjects );
			}
			return $config;
		} );
		return $result;
	}

	/**
	 * Получает предмет по ключу.
	 *
	 * @param string $key Ключ предмета (например, 'math')
	 *
	 * @return SubjectDTO|null
	 */
	public function getByKey( string $key ): ?SubjectDTO {
		$raw = $this->getRaw();
		if ( ! isset( $raw[ $key ] ) ) {
			return null;
		}
		return SubjectDTO::fromArray( $raw[ $key ] );
	}

	/**
	 * Сохраняет (создаёт или обновляет) предмет.
	 *
	 * @param SubjectDTO $dto DTO с данными предмета
	 *
	 * @return bool
	 */
	public function save( SubjectDTO $dto ): bool {
		if ( empty( $dto->key ) || empty( $dto->name ) ) {
			return false;
		}

		$result = false;
		$this->config->mutate( function ( array $config ) use ( $dto, &$result ): array {
			$this->refreshCache();
			$subjects = $this->getRaw();
			$isNew = ! isset( $subjects[ $dto->key ] );
			if ( $isNew && (int) $config['ranking_subjects_version'] >= 1 ) {
				if ( ! is_array( $config['ranking_subjects'] ) ) {
					$config['ranking_subjects'] = array();
				}
				$config['ranking_subjects'][ $dto->key ] = array( 'generation' => bin2hex( random_bytes( 16 ) ) );
				$this->config->writeLocked( $config );
			}
			$subjects[ $dto->key ] = $dto->toArray();
			$result = update_option( $this->option_name, $subjects );
			return $config;
		} );
		return $result;
	}

	/**
	 * Удаляет предмет по ключу.
	 *
	 * @param string $key Ключ предмета
	 *
	 * @return bool
	 */
	public function remove( string $key ): bool {
		$result = false;
		$this->config->mutate( function ( array $config ) use ( $key, &$result ): array {
			$this->refreshCache();
			$subjects = $this->getRaw();
			if ( ! isset( $subjects[ $key ] ) ) {
				return $config;
			}
			unset( $subjects[ $key ] );
			$result = update_option( $this->option_name, $subjects );
			if ( $result && is_array( $config['ranking_subjects'] ) ) {
				unset( $config['ranking_subjects'][ $key ] );
			}
			return $config;
		} );
		return $result;
	}

	/**
	 * Полностью очищает все предметы (удаляет опцию).
	 *
	 * @return bool
	 */
	public function clear(): bool {
		$result = false;
		$this->config->mutate( function ( array $config ) use ( &$result ): array {
			$result = delete_option( $this->option_name );
			if ( $result ) {
				$config['ranking_subjects'] = array();
			}
			return $config;
		} );
		return $result;
	}
}
