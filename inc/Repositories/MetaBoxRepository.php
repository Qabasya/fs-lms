<?php

namespace Inc\Repositories;

use Inc\Contracts\RepositoryInterface;
use Inc\Core\BaseController;

class MetaBoxRepository extends BaseController implements RepositoryInterface {

	private string $option_name = BaseController::METABOXES_OPTION_NAME;

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
			// Если у предмета больше не осталось заданий — можно очистить пустой массив
			if ( empty( $all[ $subject ] ) ) {
				unset( $all[ $subject ] );
			}

			// Сохраняем обновлённый массив в опции WordPress
			return update_option( $this->option_name, $all );
		}

		return false;
	}

	public function clear(): bool {
		return delete_option( $this->option_name );
	}

}