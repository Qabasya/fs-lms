<?php

namespace Inc\Repositories;

use Inc\Contracts\RepositoryInterface;
use Inc\Core\BaseController;

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
 * @package Inc\Repositories
 * @implements RepositoryInterface
 */
class MetaBoxRepository extends BaseController implements RepositoryInterface
{
	/**
	 * Имя опции WordPress для хранения привязки заданий к шаблонам.
	 *
	 * @var string
	 */
	private string $option_name = BaseController::METABOXES_OPTION_NAME;

	/**
	 * Получить все привязки заданий к шаблонам.
	 *
	 * @return array<string, array<string, string>> Массив всех привязок,
	 *         сгруппированных по предметам и номерам заданий
	 */
	public function read_all(): array
	{
		return get_option($this->option_name, []);
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
	public function update(array $data): bool
	{
		// Валидация обязательных полей
		if (!isset($data['subject'], $data['task_number'], $data['template_id'])) {
			return false;
		}

		// Получаем текущие данные всех привязок
		$all = $this->read_all();

		$subject     = $data['subject'];
		$task_number = $data['task_number'];

		// Инициализируем массив предмета, если его ещё нет
		if (!isset($all[$subject])) {
			$all[$subject] = [];
		}

		// Сохраняем привязку: предмет → номер задания → ID шаблона
		$all[$subject][$task_number] = $data['template_id'];

		// Сохраняем обновлённый массив в опции WordPress
		return update_option($this->option_name, $all);
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
	public function delete(array $data): bool
	{
		// Валидация обязательных полей
		if (!isset($data['subject'], $data['task_number'])) {
			return false;
		}

		// Получаем текущие данные всех привязок
		$all = $this->read_all();

		$subject     = $data['subject'];
		$task_number = $data['task_number'];

		// Проверяем существование привязки
		if (isset($all[$subject][$task_number])) {
			// Удаляем конкретную привязку
			unset($all[$subject][$task_number]);

			// Если у предмета больше не осталось заданий — удаляем ключ предмета
			if (empty($all[$subject])) {
				unset($all[$subject]);
			}

			// Сохраняем обновлённый массив в опции WordPress
			return update_option($this->option_name, $all);
		}

		// Привязка не найдена
		return false;
	}

	/**
	 * Полностью очистить все привязки заданий к шаблонам.
	 *
	 * Удаляет опцию из базы данных целиком.
	 *
	 * @return bool Успешность операции
	 */
	public function clear(): bool
	{
		return delete_option($this->option_name);
	}
}