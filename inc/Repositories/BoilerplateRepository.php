<?php

namespace Inc\Repositories;

use Inc\Contracts\RepositoryInterface;

use Inc\DTO\TaskTypeBoilerplateDTO;
use Inc\Enums\OptionName;

/**
 * Class BoilerplateRepository
 *
 * Репозиторий для хранения типовых условий (boilerplate) для подтипов заданий.
 *
 * Данные хранятся в WordPress-опции в формате:
 * [
 *     'subject_key' => [
 *         'term_slug' => [
 *             [
 *                 'uid'        => 'bp_xxx',
 *                 'title'      => 'Название',
 *                 'content'    => 'Содержимое (JSON или текст)',
 *                 'is_default' => true/false
 *             ],
 *             ...
 *         ],
 *         ...
 *     ],
 *     ...
 * ]
 *
 * @package Inc\Repositories
 * @implements RepositoryInterface
 */
class BoilerplateRepository implements RepositoryInterface {
	/**
	 * Имя опции WordPress для хранения типовых условий.
	 *
	 * @var string
	 */
	private string $option_name = OptionName::BOILERPLATE->value;
	
	// ============================ ЧТЕНИЕ ============================ //
	
	/**
	 * Получает сырые данные из опции WordPress.
	 *
	 * @return array<string, array<string, array<int, array<string, mixed>>>>
	 */
	private function getRaw(): array {
		$data = get_option( $this->option_name, [] );
		
		return is_array( $data ) ? $data : [];
	}
	
	/**
	 * Преобразует сырые данные массива в DTO-объект.
	 *
	 * @param array<string, mixed> $item        Данные boilerplate
	 * @param string               $subject_key Ключ предмета
	 * @param string               $term_slug   Слаг термина
	 *
	 * @return TaskTypeBoilerplateDTO
	 */
	private function hydrateDTO( array $item, string $subject_key, string $term_slug ): TaskTypeBoilerplateDTO {
		return new TaskTypeBoilerplateDTO(
			uid        : $item['uid'],
			subject_key: $subject_key,
			term_slug  : $term_slug,
			title      : $item['title'] ?? 'Без названия',
			content    : $item['content'] ?? '',
			is_default : $item['is_default'] ?? false
		);
	}
	
	/**
	 * Возвращает все типовые условия из всех предметов и терминов.
	 *
	 * @return TaskTypeBoilerplateDTO[] Массив DTO-объектов всех условий
	 */
	public function readAll(): array {
		$flat = [];
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
	 * Получает список boilerplate для конкретного предмета и типа задания.
	 *
	 * @param string $subject_key Ключ предмета
	 * @param string $term_slug   Слаг типа задания
	 *
	 * @return TaskTypeBoilerplateDTO[] Массив DTO-объектов условий
	 */
	public function getBoilerplates( string $subject_key, string $term_slug ): array {
		$raw_list = $this->getRaw()[ $subject_key ][ $term_slug ] ?? [];
		
		return array_map(
			fn( array $item ) => $this->hydrateDTO( $item, $subject_key, $term_slug ),
			$raw_list
		);
	}
	
	/**
	 * Возвращает boilerplate по умолчанию для типа задания.
	 *
	 * Если ни один не помечен как is_default, возвращает первый в списке.
	 *
	 * @param string $subject_key Ключ предмета
	 * @param string $term_slug   Слаг типа задания
	 *
	 * @return TaskTypeBoilerplateDTO|null DTO или null, если нет ни одного
	 */
	public function getDefaultBoilerplate( string $subject_key, string $term_slug ): ?TaskTypeBoilerplateDTO {
		$list = $this->getBoilerplates( $subject_key, $term_slug );
		
		foreach ( $list as $bp ) {
			if ( $bp->is_default ) {
				return $bp;
			}
		}
		
		return $list[0] ?? null;
	}
	
	/**
	 * Находит boilerplate по UID.
	 *
	 * @param string $subject_key Ключ предмета
	 * @param string $term_slug   Слаг типа задания
	 * @param string $uid         Уникальный идентификатор
	 *
	 * @return TaskTypeBoilerplateDTO|null DTO или null, если не найден
	 */
	public function findBoilerplate( string $subject_key, string $term_slug, string $uid ): ?TaskTypeBoilerplateDTO {
		$raw_list = $this->getRaw()[ $subject_key ][ $term_slug ] ?? [];
		
		foreach ( $raw_list as $item ) {
			if ( isset( $item['uid'] ) && $item['uid'] === $uid ) {
				return $this->hydrateDTO( $item, $subject_key, $term_slug );
			}
		}
		
		return null;
	}
	
	// ============================ ЗАПИСЬ ============================ //
	
	/**
	 * Сохраняет или обновляет типовое условие.
	 *
	 * @param TaskTypeBoilerplateDTO $dto DTO с данными для сохранения
	 *
	 * @return bool Успешность операции
	 */
	public function updateBoilerplate( TaskTypeBoilerplateDTO $dto ): bool {
		$all  = $this->getRaw();
		$list = &$all[ $dto->subject_key ][ $dto->term_slug ];
		
		if ( ! isset( $list ) || ! is_array( $list ) ) {
			$list = [];
		}
		
		$found = false;
		
		// Обновляем существующий или сбрасываем флаги у других при установке is_default
		foreach ( $list as &$item ) {
			if ( $item['uid'] === $dto->uid ) {
				$item  = $dto->toArray();
				$found = true;
			} elseif ( $dto->is_default ) {
				// Сбрасываем флаг у остальных в одном проходе
				$item['is_default'] = false;
			}
		}
		unset( $item );
		
		// Если не нашли — добавляем новый
		if ( ! $found ) {
			$list[] = $dto->toArray();
		}
		
		return update_option( $this->option_name, $all );
	}
	
	/**
	 * Удаляет типовое условие по UID.
	 *
	 * @param string $subject_key Ключ предмета
	 * @param string $term_slug   Слаг типа задания
	 * @param string $uid         Уникальный идентификатор
	 *
	 * @return bool Успешность операции
	 */
	public function deleteBoilerplate( string $subject_key, string $term_slug, string $uid ): bool {
		$all = $this->getRaw();
		
		// Проверка существования пути
		if ( ! isset( $all[ $subject_key ][ $term_slug ] ) || ! is_array( $all[ $subject_key ][ $term_slug ] ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( "FS LMS: Path not found in database: [$subject_key][$term_slug]" );
			}
			
			return false;
		}
		
		// Поиск и удаление элемента
		$found = false;
		foreach ( $all[ $subject_key ][ $term_slug ] as $index => $item ) {
			if ( isset( $item['uid'] ) && $item['uid'] === $uid ) {
				unset( $all[ $subject_key ][ $term_slug ][ $index ] );
				$found = true;
				break;
			}
		}
		
		if ( ! $found ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( "FS LMS: UID $uid not found inside [$subject_key][$term_slug]" );
			}
			
			return false;
		}
		
		// Переиндексация и очистка пустых веток
		$all[ $subject_key ][ $term_slug ] = array_values( $all[ $subject_key ][ $term_slug ] );
		
		if ( empty( $all[ $subject_key ][ $term_slug ] ) ) {
			unset( $all[ $subject_key ][ $term_slug ] );
		}
		if ( empty( $all[ $subject_key ] ) ) {
			unset( $all[ $subject_key ] );
		}
		
		return update_option( $this->option_name, $all );
	}
	
	// ============================ ИНТЕРФЕЙС RepositoryInterface ============================ //
	
	/**
	 * Сохраняет или обновляет типовое условие (интерфейс RepositoryInterface).
	 *
	 * @param array{subject_key: string, term_slug: string, title?: string, content?: string, text?: string, uid?:
	 *                                   string} $data
	 *
	 * @return bool Успешность операции
	 */
	public function update( array $data ): bool {
		$dto = new TaskTypeBoilerplateDTO(
			uid        : $data['uid'] ?? uniqid( 'bp_', true ),
			subject_key: $data['subject_key'],
			term_slug  : $data['term_slug'],
			title      : $data['title'] ?? '',
			content    : $data['content'] ?? $data['text'] ?? '',
		);
		
		return $this->updateBoilerplate( $dto );
	}
	
	/**
	 * Удаляет типовое условие (интерфейс RepositoryInterface).
	 *
	 * @param array{subject_key: string, term_slug: string, uid: string} $data
	 *
	 * @return bool Успешность операции
	 */
	public function delete( array $data ): bool {
		if ( ! isset( $data['subject_key'], $data['term_slug'], $data['uid'] ) ) {
			return false;
		}
		
		return $this->deleteBoilerplate( $data['subject_key'], $data['term_slug'], $data['uid'] );
	}
	
	/**
	 * Удаляет все boilerplates для указанного предмета.
	 *
	 * @param string $subject_key Ключ предмета
	 *
	 * @return bool Успешность операции
	 */
	public function deleteBySubject( string $subject_key ): bool {
		$all = $this->getRaw();
		
		if ( ! isset( $all[ $subject_key ] ) ) {
			return true;
		}
		
		unset( $all[ $subject_key ] );
		
		return update_option( $this->option_name, $all );
	}
	
	/**
	 * Вернуть сырые данные boilerplates для указанного предмета.
	 *
	 * @param string $subject_key Ключ предмета
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public function getRawForSubject( string $subject_key ): array {
		return $this->getRaw()[ $subject_key ] ?? [];
	}
}