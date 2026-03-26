<?php

	namespace Inc\Repositories;

	use Inc\Core\BaseController;

	/**
	 * Class AbstractRepository
	 *
	 * Абстрактный базовый класс для всех репозиториев плагина.
	 *
	 * Определяет единый контракт для работы с данными, обеспечивая
	 * согласованность интерфейсов всех репозиториев.
	 *
	 * Паттерн: Repository Pattern — абстрагирует слой доступа к данным,
	 * скрывая детали хранения (опции WordPress, база данных, внешние API).
	 *
	 * @package Inc\Repositories
	 */
	abstract class AbstractRepository extends BaseController
	{
		/**
		 * Получить все записи.
		 *
		 * Возвращает массив всех записей из хранилища.
		 * Если записей нет, возвращает пустой массив.
		 *
		 * @return array<int|string, array<string, mixed>> Массив всех записей
		 */
		abstract public function read_all(): array;

		/**
		 * Обновить или создать запись.
		 *
		 * Сохраняет переданные данные в хранилище.
		 * Если запись с таким ключом существует — обновляет,
		 * если нет — создаёт новую.
		 *
		 * @param array<string, mixed> $data Данные для сохранения
		 *
		 * @return bool Успешность операции
		 */
		abstract public function update(array $data): bool;

		/**
		 * Очистить (санитизировать) входные данные.
		 *
		 * Защищает данные перед сохранением:
		 * - Применяет WordPress-функции sanitize_*()
		 * - Экранирует опасные символы
		 * - Приводит данные к нужному формату
		 *
		 * @param array<string, mixed> $data Исходные данные
		 *
		 * @return array<string, mixed> Очищенные данные
		 */
		abstract protected function sanitize(array $data): array;

		/**
		 * Удалить запись по ключу.
		 *
		 * @param string $key Уникальный идентификатор записи
		 *
		 * @return bool Успешность удаления (false, если запись не найдена)
		 */
		abstract public function delete(string $key): bool;
	}