<?php

declare(strict_types=1);

namespace Inc\Repositories\OptionsRepositories;

use Inc\DTO\Task\TaskTypeBoilerplateDTO;
use Inc\Enums\Settings\OptionName;
use Inc\Shared\PluginLogger;

/**
 * Class BoilerplateRepository
 *
 * Репозиторий для работы с типовыми условиями (boilerplate) заданий.
 *
 * @package Inc\Repositories
 *
 * ### Основные обязанности:
 *
 * 1. **CRUD-операции** — создание, чтение, обновление и удаление boilerplate.
 * 2. **Структура данных** — хранение в формате [subject_key][term_slug][...items].
 * 3. **Управление дефолтным шаблоном** — автоматическое снятие флага is_default при добавлении нового.
 * 4. **Каскадное удаление** — удаление всех boilerplate предмета по subject_key.
 *
 * ### Архитектурная роль:
 *
 * Инкапсулирует работу с опцией `fs_lms_task_type_boilerplates` в wp_options.
 * Обрабатывает структурированные данные (трёхуровневый массив) и преобразует
 * их в DTO TaskTypeBoilerplateDTO и обратно.
 */
class BoilerplateRepository {

	/**
	 * Конструктор репозитория.
	 */
	public function __construct() {}

	/**
	 * Имя опции в wp_options.
	 */
	private string $option_name = OptionName::Boilerplate->value;

	/**
	 * Получает "сырые" данные из опции.
	 *
	 * @return array
	 */
	private function getRaw(): array {
		// get_option() — получает опцию из таблицы wp_options
		$data = get_option( $this->option_name, array() );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Преобразует "сырой" массив в DTO.
	 *
	 * @param array  $item        Массив с данными boilerplate
	 * @param string $subject_key Ключ предмета
	 * @param string $term_slug   Слаг термина (номер задания)
	 *
	 * @return TaskTypeBoilerplateDTO
	 */
	private function hydrateDTO( array $item, string $subject_key, string $term_slug ): TaskTypeBoilerplateDTO {
		return new TaskTypeBoilerplateDTO(
			uid:         $item['uid'],
			subject_key: $subject_key,
			term_slug:   $term_slug,
			title:       $item['title'] ?? 'Без названия',
			content:     $item['content'] ?? '',
			is_default:  $item['is_default'] ?? false
		);
	}

	/**
	 * Возвращает все существующие boilerplate в виде плоского массива DTO.
	 *
	 * @return TaskTypeBoilerplateDTO[]
	 */
	public function readAll(): array {
		$flat = array();

		foreach ( $this->getRaw() as $subject_key => $terms ) {
			foreach ( $terms as $term_slug => $list ) {
				foreach ( $list as $item ) {
					$flat[] = $this->hydrateDTO( $item, $subject_key, $term_slug );
				}
			}
		}

		return $flat;
	}

	/**
	 * Возвращает список boilerplate для конкретного предмета и типа задания.
	 *
	 * @param string $subject_key Ключ предмета
	 * @param string $term_slug   Слаг термина (номер задания)
	 *
	 * @return TaskTypeBoilerplateDTO[]
	 */
	public function getBoilerplates( string $subject_key, string $term_slug ): array {
		$raw_list = $this->getRaw()[ $subject_key ][ $term_slug ] ?? array();

		return array_map(
			fn( array $item ) => $this->hydrateDTO( $item, $subject_key, $term_slug ),
			$raw_list
		);
	}

	/**
	 * Возвращает дефолтный boilerplate для указанного предмета и типа задания.
	 *
	 * @param string $subject_key Ключ предмета
	 * @param string $term_slug   Слаг термина (номер задания)
	 *
	 * @return TaskTypeBoilerplateDTO|null
	 */
	public function getDefaultBoilerplate( string $subject_key, string $term_slug ): ?TaskTypeBoilerplateDTO {
		$list = $this->getBoilerplates( $subject_key, $term_slug );

		foreach ( $list as $bp ) {
			if ( $bp->is_default ) {
				return $bp;
			}
		}

		// Если дефолтного нет — возвращаем первый в списке
		return $list[0] ?? null;
	}

	/**
	 * Находит boilerplate по UID.
	 *
	 * @param string $subject_key Ключ предмета
	 * @param string $term_slug   Слаг термина (номер задания)
	 * @param string $uid         Уникальный ID boilerplate
	 *
	 * @return TaskTypeBoilerplateDTO|null
	 */
	public function findBoilerplate( string $subject_key, string $term_slug, string $uid ): ?TaskTypeBoilerplateDTO {
		$raw_list = $this->getRaw()[ $subject_key ][ $term_slug ] ?? array();

		foreach ( $raw_list as $item ) {
			if ( isset( $item['uid'] ) && $item['uid'] === $uid ) {
				return $this->hydrateDTO( $item, $subject_key, $term_slug );
			}
		}

		return null;
	}

	/**
	 * Сохраняет (создаёт или обновляет) boilerplate.
	 *
	 * @param TaskTypeBoilerplateDTO $dto DTO с данными
	 *
	 * @return bool
	 */
	public function save( TaskTypeBoilerplateDTO $dto ): bool {
		$all  = $this->getRaw();
		$list = &$all[ $dto->subject_key ][ $dto->term_slug ];

		if ( ! isset( $list ) || ! is_array( $list ) ) {
			$list = array();
		}

		$found = false;

		// Обновление существующего или снятие флага is_default у других
		foreach ( $list as &$item ) {
			if ( $item['uid'] === $dto->uid ) {
				$item  = $dto->toArray();
				$found = true;
			} elseif ( $dto->is_default ) {
				$item['is_default'] = false;
			}
		}
		unset( $item );

		// Если не нашли — добавляем новый
		if ( ! $found ) {
			$list[] = $dto->toArray();
		}

		// update_option() — обновляет опцию, возвращает false при ошибке
		return update_option( $this->option_name, $all );
	}

	/**
	 * Удаляет конкретный boilerplate по UID.
	 *
	 * @param string $subject_key Ключ предмета
	 * @param string $term_slug   Слаг термина (номер задания)
	 * @param string $uid         Уникальный ID boilerplate
	 *
	 * @return bool
	 */
	public function remove( string $subject_key, string $term_slug, string $uid ): bool {
		$all = $this->getRaw();

		// Проверка существования пути
		if ( ! isset( $all[ $subject_key ][ $term_slug ] ) || ! is_array( $all[ $subject_key ][ $term_slug ] ) ) {
			PluginLogger::debug( 'BoilerplateRepository', 'path not found', array( 'subject_key' => $subject_key, 'term_slug' => $term_slug ) );
			return false;
		}

		$found = false;

		// Поиск и удаление элемента
		foreach ( $all[ $subject_key ][ $term_slug ] as $index => $item ) {
			if ( isset( $item['uid'] ) && $item['uid'] === $uid ) {
				unset( $all[ $subject_key ][ $term_slug ][ $index ] );
				$found = true;
				break;
			}
		}

		if ( ! $found ) {
			PluginLogger::debug( 'BoilerplateRepository', 'uid not found', array( 'uid' => $uid, 'subject_key' => $subject_key, 'term_slug' => $term_slug ) );
			return false;
		}

		// Переиндексация массива (после unset)
		$all[ $subject_key ][ $term_slug ] = array_values( $all[ $subject_key ][ $term_slug ] );

		// Удаление пустых уровней
		if ( empty( $all[ $subject_key ][ $term_slug ] ) ) {
			unset( $all[ $subject_key ][ $term_slug ] );
		}

		if ( empty( $all[ $subject_key ] ) ) {
			unset( $all[ $subject_key ] );
		}

		return update_option( $this->option_name, $all );
	}

	/**
	 * Удаляет все boilerplate указанного предмета (каскадное удаление).
	 *
	 * @param string $subject_key Ключ предмета
	 *
	 * @return bool
	 */
	public function removeBySubject( string $subject_key ): bool {
		$all = $this->getRaw();

		if ( ! isset( $all[ $subject_key ] ) ) {
			return true;  // Нечего удалять
		}

		unset( $all[ $subject_key ] );

		return update_option( $this->option_name, $all );
	}
}