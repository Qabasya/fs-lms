<?php

namespace Inc\Repositories;

use Inc\Contracts\RepositoryInterface;
use Inc\DTO\TaskTemplateAssignmentDTO;
use Inc\DTO\TaskTypeDTO;
use Inc\Enums\OptionName;
use Inc\Enums\TaskTemplate;
use Inc\Managers\PostManager;

/**
 * Class MetaBoxRepository
 *
 * Репозиторий для хранения информации о том, какой шаблон метабокса
 * используется для каждого задания (предмет + номер задания).
 *
 * Данные хранятся в WordPress-опции в формате:
 * [
 *     'subject_key' => [
 *         'task_number' => 'template_id',
 *         ...
 *     ],
 *     ...
 * ]
 *
 * При чтении данных возвращает DTO-объекты (TaskTemplateAssignmentDTO)
 * для типобезопасной работы.
 *
 * @package Inc\Repositories
 * @implements RepositoryInterface
 */
class MetaBoxRepository implements RepositoryInterface {
	/**
	 * Имя опции WordPress для хранения привязки заданий к шаблонам.
	 *
	 * @var string
	 */
	private string $option_name = OptionName::METABOXES->value;

	public function __construct( private PostManager $posts ) {
	}

	/**
	 * Внутренний метод для получения сырых данных из Options API.
	 *
	 * @return array<string, array<string, string>> Сырые данные привязок
	 */
	private function getRaw(): array {
		$data = get_option( $this->option_name, array() );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Возвращает все привязки заданий к шаблонам.
	 *
	 * @return TaskTemplateAssignmentDTO[] Массив DTO-объектов всех привязок
	 */
	public function readAll(): array {
		$raw_all = $this->getRaw();
		$result  = array();

		foreach ( $raw_all as $subject => $tasks ) {
			foreach ( $tasks as $number => $template_id ) {
				// Создаём DTO для каждой привязки
				$result[] = new TaskTemplateAssignmentDTO(
					(string) $subject,
					(string) $number,
					(string) $template_id
				);
			}
		}

		return $result;
	}

	/**
	 * Получить шаблон для конкретного задания.
	 *
	 * Хелпер для быстрой проверки привязки.
	 *
	 * @param string $subject     Ключ предмета (например, 'inf')
	 * @param string $task_number Номер задания (например, '1')
	 *
	 * @return TaskTemplateAssignmentDTO|null DTO-объект привязки или null, если не найдена
	 */
	public function getAssignment( string $subject, string $task_number ): ?TaskTemplateAssignmentDTO {
		$all = $this->getRaw();

		// Проверяем существование ключа предмета
		if ( ! isset( $all[ $subject ] ) ) {
			return null;
		}

		// Приводим номер к строке для надёжного поиска в ключах массива
		$number_key = (string) $task_number;

		// Ищем ID шаблона
		$template_id = $all[ $subject ][ $number_key ] ?? null;

		if ( ! $template_id ) {
			return null;
		}

		// Возвращаем DTO
		return new TaskTemplateAssignmentDTO( $subject, $number_key, $template_id );
	}

	/**
	 * Обновить или создать привязку задания к шаблону.
	 *
	 * Ожидает в $data:
	 * - subject: ключ предмета (slug)
	 * - task_number: номер задания
	 * - template_id: идентификатор шаблона (например, 'standard_task')
	 *
	 * @param array{subject: string, task_number: string, template_id: string} $data
	 *        Данные для сохранения привязки
	 *
	 * @return bool Успешность операции
	 */
	public function update( array $data ): bool {
		// Валидация обязательных полей
		if ( ! isset( $data['subject'], $data['task_number'], $data['template_id'] ) ) {
			return false;
		}

		$all = $this->getRaw();

		$subject     = $data['subject'];
		$task_number = $data['task_number'];

		// Инициализируем массив предмета, если его ещё нет
		if ( ! isset( $all[ $subject ] ) ) {
			$all[ $subject ] = array();
		}

		// Сохраняем привязку
		$all[ $subject ][ $task_number ] = $data['template_id'];

		// Сохраняем обновлённый массив в опции WordPress
		return update_option( $this->option_name, $all );
	}

	/**
	 * Хелпер для обновления привязки.
	 *
	 * Удобная обёртка над методом update().
	 *
	 * @param string $subject     Ключ предмета
	 * @param string $task_number Номер задания
	 * @param string $template_id ID шаблона
	 *
	 * @return bool Успешность операции
	 */
	public function updateAssignment( string $subject, string $task_number, string $template_id ): bool {
		return $this->update(
			array(
				'subject'     => $subject,
				'task_number' => $task_number,
				'template_id' => $template_id,
			)
		);
	}

	/**
	 * Удалить привязку задания к шаблону.
	 *
	 * Ожидает в $data:
	 * - subject: ключ предмета (slug)
	 * - task_number: номер задания
	 *
	 * @param array{subject: string, task_number: string} $data
	 *        Данные для удаления привязки
	 *
	 * @return bool Успешность операции (false, если привязка не найдена)
	 */
	public function delete( array $data ): bool {
		// Валидация обязательных полей
		if ( ! isset( $data['subject'], $data['task_number'] ) ) {
			return false;
		}

		$all = $this->getRaw();

		$subject     = $data['subject'];
		$task_number = $data['task_number'];

		// Проверяем существование привязки
		if ( isset( $all[ $subject ][ $task_number ] ) ) {
			// Удаляем конкретную привязку
			unset( $all[ $subject ][ $task_number ] );

			// Если у предмета больше не осталось заданий — удаляем ключ предмета
			if ( empty( $all[ $subject ] ) ) {
				unset( $all[ $subject ] );
			}

			// Сохраняем обновлённый массив в опции WordPress
			return update_option( $this->option_name, $all );
		}

		return false;
	}

	/**
	 * Удалить все привязки шаблонов для указанного предмета.
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
	 * Вернуть сырые данные привязок шаблонов для указанного предмета.
	 *
	 * @param string $subject_key Ключ предмета
	 *
	 * @return array<string, string>
	 */
	public function getRawForSubject( string $subject_key ): array {
		return $this->getRaw()[ $subject_key ] ?? array();
	}

	/**
	 * Полностью очистить все привязки заданий к шаблонам.
	 *
	 * Удаляет опцию из базы данных целиком.
	 *
	 * @return bool Успешность операции
	 */
	public function clear(): bool {
		return delete_option( $this->option_name );
	}

	// ============================ ЗАПРОСЫ К WP-ТАКСОНОМИЯМ ============================ //

	/**
	 * Возвращает типы заданий предмета в виде DTO с привязанными шаблонами.
	 *
	 * Комбинирует термины таксономии {subject_key}_task_number с хранимыми
	 * привязками шаблонов. Если привязка отсутствует — подставляет STANDARD.
	 *
	 * @param string $subject_key Ключ предмета, например: "inf"
	 *
	 * @return TaskTypeDTO[] Массив DTO типов заданий
	 */
	public function getTaskTypes( string $subject_key ): array {
		$taxonomy  = "{$subject_key}_task_number";
		$post_type = "{$subject_key}_tasks";

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'orderby'    => 'slug',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		return array_map(
			function ( $term ) use ( $subject_key, $taxonomy, $post_type ): TaskTypeDTO {
				$assignment = $this->getAssignment( $subject_key, $term->slug );

				$db_id         = $assignment ? $assignment->template_id : 'standard_task';
				$template_enum = TaskTemplate::fromDatabase( $db_id );
				$post_count    = $this->posts->countByTerm( $post_type, $taxonomy, (int) $term->term_id );

				return new TaskTypeDTO(
					$term->term_id,
					$term->slug,
					$term->taxonomy,
					$term->description,
					$template_enum,
					$db_id,
					$post_count,
				);
			},
			$terms
		);
	}
}
