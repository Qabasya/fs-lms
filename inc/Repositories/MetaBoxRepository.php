<?php

declare(strict_types=1);

namespace Inc\Repositories;

use Inc\DTO\TaskTemplateAssignmentDTO;
use Inc\Enums\OptionName;

/**
 * Class MetaBoxRepository
 *
 * Репозиторий для работы с привязками шаблонов метабоксов к типам заданий.
 *
 * @package Inc\Repositories
 *
 * ### Основные обязанности:
 *
 * 1. **CRUD-операции** — создание, чтение, обновление и удаление привязок шаблонов.
 * 2. **Структура данных** — хранение в формате [subject_key][task_number] => template_id.
 * 3. **Каскадное удаление** — удаление всех привязок предмета по subject_key.
 *
 * ### Архитектурная роль:
 *
 * Инкапсулирует работу с опцией `fs_lms_custom_metaboxes` в wp_options.
 * Обрабатывает данные о том, какой шаблон метабокса привязан к конкретному
 * номеру задания в определённом предмете. Использует DTO TaskTemplateAssignmentDTO
 * для типобезопасной передачи данных.
 */
class MetaBoxRepository {

	/**
	 * Конструктор репозитория.
	 */
	public function __construct() {}

	/**
	 * Имя опции в wp_options.
	 */
	private string $option_name = OptionName::METABOXES->value;

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
	 * Возвращает все привязки шаблонов в виде массива DTO.
	 *
	 * @return TaskTemplateAssignmentDTO[]
	 */
	public function readAll(): array {
		$result = array();

		foreach ( $this->getRaw() as $subject => $tasks ) {
			foreach ( $tasks as $number => $template_id ) {
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
	 * Получает привязку шаблона для указанного предмета и номера задания.
	 *
	 * @param string $subject      Ключ предмета (например, 'math')
	 * @param string $task_number  Номер задания (например, '1')
	 *
	 * @return TaskTemplateAssignmentDTO|null
	 */
	public function getAssignment( string $subject, string $task_number ): ?TaskTemplateAssignmentDTO {
		$all         = $this->getRaw();
		$template_id = $all[ $subject ][ $task_number ] ?? null;

		if ( ! $template_id ) {
			return null;
		}

		return new TaskTemplateAssignmentDTO( $subject, $task_number, $template_id );
	}

	/**
	 * Сохраняет (создаёт или обновляет) привязку шаблона.
	 *
	 * @param TaskTemplateAssignmentDTO $dto DTO с данными привязки
	 *
	 * @return bool
	 */
	public function save( TaskTemplateAssignmentDTO $dto ): bool {
		$all = $this->getRaw();

		if ( ! isset( $all[ $dto->subject_key ] ) ) {
			$all[ $dto->subject_key ] = array();
		}

		$all[ $dto->subject_key ][ $dto->task_number ] = $dto->template_id;

		// update_option() — обновляет опцию, возвращает false при ошибке или отсутствии изменений
		return update_option( $this->option_name, $all );
	}

	/**
	 * Удаляет привязку шаблона для указанного предмета и номера задания.
	 *
	 * @param string $subject      Ключ предмета
	 * @param string $task_number  Номер задания
	 *
	 * @return bool
	 */
	public function remove( string $subject, string $task_number ): bool {
		$all = $this->getRaw();

		if ( ! isset( $all[ $subject ][ $task_number ] ) ) {
			return false;
		}

		// unset() — удаляет элемент из массива по ключу
		unset( $all[ $subject ][ $task_number ] );

		// Если у предмета не осталось привязок — удаляем весь ключ предмета
		if ( empty( $all[ $subject ] ) ) {
			unset( $all[ $subject ] );
		}

		return update_option( $this->option_name, $all );
	}

	/**
	 * Удаляет все привязки шаблонов указанного предмета (каскадное удаление).
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

	/**
	 * Полностью очищает все привязки шаблонов (удаляет опцию).
	 *
	 * @return bool
	 */
	public function clear(): bool {
		// delete_option() — удаляет опцию из таблицы wp_options
		return delete_option( $this->option_name );
	}
}