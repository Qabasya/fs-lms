<?php

declare( strict_types=1 );

namespace Inc\Shared\Traits;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Group\ProgramCompositionService;

/**
 * Trait ProgramAccess
 *
 * Общие гарды AJAX-коллбэков КТП: доступ к группе, доступ к строке программы
 * и запрет правок опубликованной (заблокированной) КТП.
 *
 * @package Inc\Shared\Traits
 *
 * Все методы прерывают ответ JSON-ошибкой через `AjaxResponse::error()`,
 * поэтому применимы только внутри AJAX-коллбэков. Класс-потребитель отдаёт
 * трейту свои зависимости через {@see accessGuard()} и {@see programService()}.
 */
trait ProgramAccess {

	/**
	 * Гард доступа к группе (проверка прав преподавателя/офиса).
	 */
	abstract protected function accessGuard(): GroupAccessGuard;

	/**
	 * Сервис состава программы (строки КТП и состояние публикации).
	 */
	abstract protected function programService(): ProgramCompositionService;

	/**
	 * Прерывает ответ, если у текущего пользователя нет доступа к группе.
	 *
	 * @param int $groupId ID группы
	 *
	 * @return void
	 */
	protected function requireGroupAccess( int $groupId ): void {
		if ( ! $this->accessGuard()->canManage( $groupId, get_current_user_id() ) ) {
			$this->error( __( 'Нет доступа к группе.', 'fs-lms' ) );
		}
	}

	/**
	 * Возвращает строку программы, проверив доступ к её группе.
	 *
	 * @param int         $groupLessonId ID строки программы
	 * @param string|null $message       Текст ошибки (по умолчанию — про доступ к группе)
	 *
	 * @return GroupLessonDTO
	 */
	protected function requireProgramRow( int $groupLessonId, ?string $message = null ): GroupLessonDTO {
		$row = $this->programService()->getProgramRow( $groupLessonId );

		if ( null === $row || ! $this->accessGuard()->canManage( $row->groupId, get_current_user_id() ) ) {
			$this->error( $message ?? __( 'Нет доступа к группе.', 'fs-lms' ) );
		}

		return $row;
	}

	/**
	 * T1.8: блокирует правку опубликованной (locked) КТП. Прерывает ответ JSON-ошибкой.
	 *
	 * @param int $groupId ID группы
	 *
	 * @return void
	 */
	protected function denyIfProgramLocked( int $groupId ): void {
		if ( $this->programService()->isProgramLocked( $groupId ) ) {
			$this->error( __( 'КТП опубликована и заблокирована для изменений. Снимите публикацию, чтобы редактировать.', 'fs-lms' ) );
		}
	}
}
