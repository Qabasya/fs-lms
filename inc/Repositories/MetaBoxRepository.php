<?php

namespace Inc\Repositories;

use Inc\Contracts\RepositoryInterface;
use Inc\Core\BaseController;

class MetaBoxRepository extends BaseController implements RepositoryInterface {
	/**
	 * Имя опции WordPress для хранения кастомных таксономий.
	 *
	 * @var string
	 */
	// Добавить потом константу
	private string $option_name = 'fs_custom_metaboxes';

	public function read_all(): array {
		return get_option( $this->option_name, [] );
	}

	public function update( array $data ): bool {
		if ( ! isset( $data['subject'], $data['task_number'], $data['template_id'] ) ) {
			return false;
		}
		// Получаем текущие данные всех таксономий
		$all = $this->read_all();

		$subject     = $data['subject'];
		$task_number = $data['task_number'];

		// Создаем вложенную структуру, если её нет
		if ( ! isset( $all[ $subject ] ) ) {
			$all[ $subject ] = [];
		}

		$all[ $subject ][ $task_number ] = $data['template_id'];

		// Сохраняем обновлённый массив в опции WordPress
		return update_option( $this->option_name, $all );
	}

	public function delete( array $data ): bool {
		if ( ! isset( $data['subject'], $data['task_number'] ) ) {
			return false;
		}

		$all = $this->read_all();

		$subject     = $data['subject'];
		$task_number = $data['task_number'];


		if ( isset( $all[ $subject ][ $task_number ] ) ) {

			unset( $all[ $subject ][ $task_number ] );

			// Сохраняем обновлённый массив в опции WordPress
			return update_option( $this->option_name, $all );
		}

		return false;
	}

	/**
	 * Дополнительный хелпер (вне интерфейса) для быстрого получения одного шаблона
	 */
	public function get_template_for_task( string $subject, string $task_number ): string {
		$all = $this->read_all();

		return $all[ $subject ][ $task_number ] ?? 'standard_task';
	}
}