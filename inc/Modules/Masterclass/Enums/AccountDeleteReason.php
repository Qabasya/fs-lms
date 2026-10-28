<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\Enums;

/**
 * Причина мягкого удаления временного аккаунта (`masterclass_accounts.delete_reason`).
 *
 * Значение хранится в БД — менять нельзя без data-миграции.
 */
enum AccountDeleteReason: string {

	/** Ни разу не входил: убран тиком через `unused_purge_days`. */
	case Unused = 'unused';

	/** Вышел срок мастер-класса: убран вместе с ним. */
	case Expired = 'expired';

	/** Администратор нажал «Удалить» у мастер-класса. */
	case Requested = 'requested';

	/** Пользователя WordPress удалили вручную (`deleted_user`). */
	case UserDeleted = 'user_deleted';

	/** Учётная запись не создалась (занят логин или сбой): номер сожжён, аккаунта нет. */
	case CreateFailed = 'create_failed';

	/** Группу мастер-класса удалили мимо модуля (шов `fs_lms_group_deleting`). */
	case GroupDeleted = 'group_deleted';

	public function label(): string {
		return match ( $this ) {
			self::Unused       => 'не использовался',
			self::Expired      => 'истёк срок',
			self::Requested    => 'удалён администратором',
			self::UserDeleted  => 'учётная запись удалена',
			self::CreateFailed => 'не удалось создать',
			self::GroupDeleted => 'удалена группа',
		};
	}
}
