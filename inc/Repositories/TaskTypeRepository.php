<?php

namespace Inc\Repositories;

use Inc\Contracts\RepositoryInterface;
use Inc\Core\BaseController;
use Inc\DTO\TaskTypeBoilerplateDTO;

/**
 * Class TaskTypeRepository
 *
 * Репозиторий для хранения типовых условий (boilerplate) для подтипов заданий.
 *
 * Данные хранятся в WordPress-опции в формате:
 * [
 *     'subject_key' => [
 *         'term_slug' => 'Текст условия с картинками...',
 *         ...
 *     ],
 *     ...
 * ]
 *
 * При чтении данных возвращает DTO-объекты (TaskTypeBoilerplateDTO)
 * для типобезопасной работы.
 *
 * @package Inc\Repositories
 * @implements RepositoryInterface
 */
class TaskTypeRepository extends BaseController implements RepositoryInterface {
	/**
	 * Имя опции WordPress для хранения текстов условий (boilerplate).
	 *
	 * @var string
	 */
	private string $option_name = BaseController::BOILERPLATE_OPTION_NAME;

	/**
	 * Внутренний метод для получения сырых данных из Options API.
	 *
	 * @return array<string, array<string, string>> Сырые данные условий
	 */
	private function getRaw(): array {
		$data = get_option( $this->option_name, [] );

		return is_array( $data ) ? $data : [];
	}

	/**
	 * Возвращает все сохранённые условия в виде массива DTO.
	 *
	 * @return TaskTypeBoilerplateDTO[] Массив DTO-объектов всех условий
	 */
	public function readAll(): array {
		$raw_all = $this->getRaw();
		$result  = [];

		foreach ( $raw_all as $subject => $terms ) {
			foreach ( $terms as $slug => $text ) {
				$result[] = new TaskTypeBoilerplateDTO(
					(string) $subject,
					(string) $slug,
					(string) $text
				);
			}
		}

		return $result;
	}

	/**
	 * Получить типовое условие для конкретного подтипа задания.
	 *
	 * @param string $subject_key Ключ предмета
	 * @param string $term_slug Слаг подтипа задания (например, 'zvyozdy')
	 *
	 * @return TaskTypeBoilerplateDTO|null DTO-объект условия или null, если не найдено
	 */
	public function getBoilerplate( string $subject_key, string $term_slug ): ?TaskTypeBoilerplateDTO {
		$all = $this->getRaw();

		$text = $all[ $subject_key ][ $term_slug ] ?? null;

		if ( $text === null ) {
			return null;
		}

		return new TaskTypeBoilerplateDTO( $subject_key, $term_slug, $text );
	}

	/**
	 * Обновить или создать текст условия (boilerplate).
	 *
	 * Ожидает в $data:
	 * - subject_key: ключ предмета (slug)
	 * - term_slug: слаг подтипа задания
	 * - text: текст условия (может содержать HTML, картинки)
	 *
	 * @param array{subject_key: string, term_slug: string, text: string} $data
	 *        Данные для сохранения условия
	 *
	 * @return bool Успешность операции
	 */
	public function update( array $data ): bool {
		// Валидация обязательных полей
		if ( ! isset( $data['subject_key'], $data['term_slug'], $data['text'] ) ) {
			return false;
		}

		$all         = $this->getRaw();
		$subject_key = $data['subject_key'];
		$term_slug   = $data['term_slug'];

		// Инициализируем массив предмета, если его ещё нет
		if ( ! isset( $all[ $subject_key ] ) ) {
			$all[ $subject_key ] = [];
		}

		// Используем wp_kses_post, чтобы разрешить базовые HTML-теги и ссылки на картинки,
		// но защититься от вредоносного кода (XSS)
		$all[ $subject_key ][ $term_slug ] = wp_kses_post( $data['text'] );

		// Сохраняем обновлённый массив в опции WordPress
		return update_option( $this->option_name, $all );
	}

	/**
	 * Удалить текст условия (boilerplate) для подтипа задания.
	 *
	 * Ожидает в $data:
	 * - subject_key: ключ предмета (slug)
	 * - term_slug: слаг подтипа задания
	 *
	 * @param array{subject_key: string, term_slug: string} $data
	 *        Данные для удаления условия
	 *
	 * @return bool Успешность операции (false, если условие не найдено)
	 */
	public function delete( array $data ): bool {
		// Валидация обязательных полей
		if ( ! isset( $data['subject_key'], $data['term_slug'] ) ) {
			return false;
		}

		$all         = $this->getRaw();
		$subject_key = $data['subject_key'];
		$term_slug   = $data['term_slug'];

		// Проверяем существование условия
		if ( isset( $all[ $subject_key ][ $term_slug ] ) ) {
			// Удаляем конкретное условие
			unset( $all[ $subject_key ][ $term_slug ] );

			// Если у предмета больше не осталось условий — удаляем ключ предмета
			if ( empty( $all[ $subject_key ] ) ) {
				unset( $all[ $subject_key ] );
			}

			// Сохраняем обновлённый массив в опции WordPress
			return update_option( $this->option_name, $all );
		}

		return false;
	}

	/**
	 * Полностью очистить все типовые условия.
	 *
	 * Удаляет опцию из базы данных целиком.
	 *
	 * @return bool Успешность операции
	 */
	public function clear(): bool {
		return delete_option( $this->option_name );
	}
}