<?php

declare( strict_types=1 );

namespace Inc\Managers;

use Inc\Enums\CronHook;

/**
 * Class CronManager
 *
 * Менеджер WP Cron для LMS-событий.
 *
 * @package Inc\Managers
 *
 * ### Основные обязанности:
 *
 * 1. **Регистрация кастомных интервалов** — накапливает интервалы для фильтра cron_schedules.
 * 2. **Планирование событий** — идемпотентный schedule через wp_schedule_event.
 * 3. **Снятие событий** — unschedule одного хука или всех LMS-хуков разом.
 *
 * ### Архитектурная роль:
 *
 * Оборачивает WP Cron API. Не вызывает add_filter/add_action —
 * это обязанность CronController. filterCronSchedules() используется
 * как callback фильтра, который регистрирует Controller.
 */
class CronManager {

	/** @var array<string, array{interval: int, display: string}> */
	private array $customIntervals = array();

	/**
	 * Накапливает кастомный интервал для последующей передачи в фильтр cron_schedules.
	 * Не регистрирует add_filter — вызов filterCronSchedules() как callback делает Controller.
	 *
	 * @param string $name    Идентификатор интервала (напр. 'every_15_minutes')
	 * @param int    $seconds Длина интервала в секундах
	 * @param string $label   Человекочитаемое название
	 * @return void
	 */
	public function addCustomInterval( string $name, int $seconds, string $label ): void {
		$this->customIntervals[ $name ] = array(
			'interval' => $seconds,
			'display'  => $label,
		);
	}

	/**
	 * Callback фильтра cron_schedules. Добавляет накопленные кастомные интервалы.
	 *
	 * @param array $schedules Текущий массив WP-интервалов
	 * @return array
	 */
	public function filterCronSchedules( array $schedules ): array {
		return array_merge( $schedules, $this->customIntervals );
	}

	/**
	 * Планирует WP cron-событие, если оно ещё не запланировано.
	 *
	 * @param string $hook       Имя хука события
	 * @param string $recurrence Идентификатор интервала ('daily', 'every_15_minutes', ...)
	 * @return void
	 */
	public function schedule( string $hook, string $recurrence ): void {
		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time(), $recurrence, $hook );
		}
	}

	/**
	 * Снимает конкретное WP cron-событие.
	 *
	 * @param string $hook Имя хука события
	 * @return void
	 */
	public function unschedule( string $hook ): void {
		wp_clear_scheduled_hook( $hook );
	}

	/**
	 * Снимает все LMS cron-события. Вызывается при деактивации плагина.
	 *
	 * @return void
	 */
	public function unregisterAll(): void {
		foreach ( CronHook::cases() as $hook ) {
			wp_clear_scheduled_hook( $hook->value );
		}
	}
}