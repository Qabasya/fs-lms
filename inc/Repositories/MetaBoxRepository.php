<?php

declare(strict_types=1);

namespace Inc\Repositories;

use Inc\DTO\TaskTemplateAssignmentDTO;
use Inc\Enums\OptionName;

class MetaBoxRepository {

	private string $option_name = OptionName::METABOXES->value;

	private function getRaw(): array {
		$data = get_option( $this->option_name, array() );
		return is_array( $data ) ? $data : array();
	}

	/** @return TaskTemplateAssignmentDTO[] */
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

	public function getAssignment( string $subject, string $task_number ): ?TaskTemplateAssignmentDTO {
		$all         = $this->getRaw();
		$template_id = $all[ $subject ][ $task_number ] ?? null;

		if ( ! $template_id ) {
			return null;
		}

		return new TaskTemplateAssignmentDTO( $subject, $task_number, $template_id );
	}

	public function save( TaskTemplateAssignmentDTO $dto ): bool {
		$all = $this->getRaw();

		if ( ! isset( $all[ $dto->subject_key ] ) ) {
			$all[ $dto->subject_key ] = array();
		}

		$all[ $dto->subject_key ][ $dto->task_number ] = $dto->template_id;

		return update_option( $this->option_name, $all );
	}

	public function remove( string $subject, string $task_number ): bool {
		$all = $this->getRaw();

		if ( ! isset( $all[ $subject ][ $task_number ] ) ) {
			return false;
		}

		unset( $all[ $subject ][ $task_number ] );

		if ( empty( $all[ $subject ] ) ) {
			unset( $all[ $subject ] );
		}

		return update_option( $this->option_name, $all );
	}

	public function removeBySubject( string $subject_key ): bool {
		$all = $this->getRaw();

		if ( ! isset( $all[ $subject_key ] ) ) {
			return true;
		}

		unset( $all[ $subject_key ] );

		return update_option( $this->option_name, $all );
	}

	public function clear(): bool {
		return delete_option( $this->option_name );
	}
}
