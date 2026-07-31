<?php

declare( strict_types=1 );

namespace Inc\Shared\Traits;

/**
 * Trait ScopedFilter
 *
 * Временный WP-фильтр на время одной операции: вешает, выполняет, снимает —
 * даже если операция бросила исключение.
 *
 * @package Inc\Shared\Traits
 *
 * ### Зачем это легально
 *
 * Иногда WP-гард нужно снять ровно на одну операцию: снос предмета обходит
 * референс-гард удаления ({@see \Inc\Services\Subject\SubjectDeletionService}),
 * загрузка вложений разрешает дополнительные MIME только для своей загрузки
 * ({@see \Inc\Managers\Wp\MediaManager}). Глобально ослаблять правила нельзя,
 * поэтому фильтр живёт ровно столько, сколько идёт операция.
 *
 * Это НЕ обход правила «хуки регистрируются в контроллерах»: там речь о
 * постоянных хуках жизненного цикла, здесь — о локальной области видимости.
 */
trait ScopedFilter {

	/**
	 * Выполняет операцию с временно навешенным фильтром.
	 *
	 * @template T
	 *
	 * @param string   $hook      Имя WP-фильтра
	 * @param callable $filter    Колбэк фильтра
	 * @param callable $operation Операция; её результат возвращается наружу
	 * @param int      $priority  Приоритет фильтра
	 * @param int      $args      Число аргументов колбэка
	 *
	 * @return mixed Результат операции
	 */
	protected function withFilter( string $hook, callable $filter, callable $operation, int $priority = 10, int $args = 1 ): mixed {
		add_filter( $hook, $filter, $priority, $args );

		try {
			return $operation();
		} finally {
			remove_filter( $hook, $filter, $priority );
		}
	}
}
