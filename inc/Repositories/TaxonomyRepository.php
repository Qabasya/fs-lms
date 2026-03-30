<?php

namespace Inc\Repositories;

use Inc\Contracts\RepositoryInterface;
use Inc\Core\BaseController;

/**
 * Class TaxonomyRepository
 *
 * Управляет кастомными таксономиями, привязанными к предметам.
 * Данные хранятся в формате:
 * [
 *     'subject_key' => [
 *         'tax_slug' => ['name' => 'Название таксономии'],
 *         ...
 *     ],
 *     ...
 * ]
 *
 * @package Inc\Repositories
 * @implements RepositoryInterface
 */
class TaxonomyRepository extends BaseController implements RepositoryInterface {
	/**
	 * Имя опции WordPress для хранения кастомных таксономий.
	 *
	 * @var string
	 */
	// Добавить потом константу
	private string $option_name = 'fs_custom_taxonomies';

	/**
	 * Получить все кастомные таксономии всех предметов.
	 *
	 * @return array<string, array<string, array{name: string}>>
	 *         Массив всех таксономий, сгруппированных по предметам
	 */
	public function read_all(): array {
		// Получаем опцию из БД, если пусто — возвращаем пустой массив
		return get_option( $this->option_name, array() );
	}


	/**
	 * Обновить или создать таксономию для предмета.
	 *
	 * Ожидает в $data:
	 * - subject_key: ключ предмета (slug)
	 * - tax_slug: уникальный идентификатор таксономии
	 * - name: отображаемое название таксономии
	 *
	 * @param array{subject_key: string, tax_slug: string, name: string} $data
	 *        Данные для сохранения таксономии
	 *
	 * @return bool Успешность операции
	 */
	public function update( array $data ): bool {
		// Получаем текущие данные всех таксономий
		$all = $this->read_all();

		$subject_key = $data['subject_key'];
		$tax_slug    = $data['tax_slug'];

		// Инициализируем массив предмета, если его ещё нет
		if ( ! isset( $all[ $subject_key ] ) ) {
			$all[ $subject_key ] = array();
		}

		// Сохраняем данные таксономии с санитизацией названия
		$all[ $subject_key ][ $tax_slug ] = [
			'name' => sanitize_text_field( $data['name'] )
		];

		// Сохраняем обновлённый массив в опции WordPress
		return update_option( $this->option_name, $all );
	}

	/**
	 * Удалить таксономию предмета.
	 *
	 * Ожидает в $data:
	 * - subject_key: ключ предмета (slug)
	 * - tax_slug: уникальный идентификатор таксономии
	 *
	 * @param array{subject_key: string, tax_slug: string} $data
	 *        Данные для удаления таксономии
	 *
	 * @return bool Успешность операции (false, если таксономия не найдена)
	 */
	public function delete( array $data ): bool {
		// Получаем текущие данные всех таксономий
		$all = $this->read_all();

		$subject_key = $data['subject_key'];
		$tax_slug    = $data['tax_slug'];

		// Проверяем существование таксономии
		if ( isset( $all[ $subject_key ][ $tax_slug ] ) ) {
			// Удаляем конкретную таксономию
			unset( $all[ $subject_key ][ $tax_slug ] );

			// Сохраняем обновлённый массив в опции WordPress
			return update_option( $this->option_name, $all );
		}

		// Таксономия не найдена
		return false;
	}

	/**
	 * Получить таксономии конкретного предмета.
	 *
	 * Хелпер для контроллеров — возвращает только таксономии указанного предмета.
	 *
	 * @param string $subject_key Ключ предмета (slug)
	 *
	 * @return array<string, array{name: string}>
	 *         Массив таксономий предмета или пустой массив, если предмет не найден
	 */
	public function get_by_subject( string $subject_key ): array {
		$all = $this->read_all();

		// Возвращаем таксономии предмета или пустой массив
		return $all[ $subject_key ] ?? array();
	}
}