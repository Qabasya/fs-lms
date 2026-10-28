<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\Controllers;

use Inc\Core\BaseController;
use Inc\Modules\Masterclass\Repositories\MasterclassRepository;
use Inc\Shared\PluginLogger;

/**
 * Class MasterclassIntegrityController
 *
 * Подписки модуля на швы ядра, которые обязаны работать ВСЕГДА, в том числе при выключенном модуле:
 * от них зависит целостность данных, а не возможности модуля.
 *
 * - Ш4 `fs_lms_service_group_ids` — группа мастер-класса остаётся служебной, иначе при выключении модуля она
 *   вернулась бы в списки зачисления, «Добавить всех учеников предмета» и выгрузку учеников.
 *
 * Дальше сюда же приходят Ш1 `fs_lms_lesson_access` (этап 3), `deleted_user` и Ш3 `fs_lms_group_deleting`
 * (этап 4): при выключенном модуле заморозка должна действовать, а удаление группы или пользователя — не
 * оставлять сирот. Подписка на метод, которого ещё нет, не регистрируется.
 *
 * @package Inc\Modules\Masterclass\Controllers
 */
class MasterclassIntegrityController extends BaseController {

	public function __construct(
		private readonly MasterclassRepository $masterclasses,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_filter( 'fs_lms_service_group_ids', array( $this, 'filterServiceGroupIds' ) );
	}

	/**
	 * Дописывает группы мастер-классов к служебным. Читается таблица, а не флаг модуля.
	 *
	 * Сбой чтения не должен ломать зачисление и выгрузку ядра (например, таблица ещё не создана): групп мастер-классов
	 * тогда фактически нет, поэтому список возвращается как пришёл, а причина уходит в журнал.
	 *
	 * @param mixed $ids Служебные группы, собранные другими подписчиками.
	 *
	 * @return list<int>
	 */
	public function filterServiceGroupIds( mixed $ids ): array {
		$ids = is_array( $ids ) ? $ids : array();

		try {
			return array_values( array_merge( $ids, $this->masterclasses->activeGroupIds() ) );
		} catch ( \Throwable $e ) {
			PluginLogger::exception( 'MasterclassIntegrity', $e, array( 'seam' => 'fs_lms_service_group_ids' ), true );

			return array_values( $ids );
		}
	}
}
