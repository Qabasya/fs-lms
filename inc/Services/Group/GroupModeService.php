<?php

declare( strict_types=1 );

namespace Inc\Services\Group;

use Inc\Enums\Course\AccessMode;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Shared\Traits\TransactionRunner;

/**
 * Class GroupModeService
 *
 * Смена режима группы между «по расписанию» и «индивидуальная».
 *
 * @package Inc\Services\Group
 *
 * Режим «открытая» (Эпик 15) задаётся при создании и не меняется: у неё опубликована вся
 * программа курса. Само создание группы с любым режимом — `StudentGroupCallbacks`.
 */
readonly class GroupModeService {

	use TransactionRunner;

	/**
	 * @param GroupsRepository        $groups  Группы
	 * @param StudentRecordRepository $records Записи учеников (число активных)
	 */
	public function __construct(
		private GroupsRepository        $groups,
		private StudentRecordRepository $records,
	) {}

	/**
	 * Переводит группу в другой режим.
	 *
	 * В индивидуальную — только группу, где не больше одного активного ученика; расписание
	 * (`meetings`), кабинет группы и блокировка публикации КТП снимаются: даты ставятся занятиям
	 * вручную. Занятия, уже стоящие на датах, остаются как есть. Обратно в «по расписанию» —
	 * расписание преподаватель задаёт в карточке группы.
	 *
	 * @param int        $groupId ID группы
	 * @param AccessMode $target  Новый режим
	 *
	 * @return bool true — режим изменён, false — группа уже в этом режиме.
	 *
	 * @throws \InvalidArgumentException Если группа не найдена
	 * @throws \DomainException          Если режим менять нельзя (открытая группа, больше одного ученика)
	 */
	public function change( int $groupId, AccessMode $target ): bool {
		return (bool) $this->inTransaction( function () use ( $groupId, $target ): bool {
			// Под блокировкой строки группы: зачисление ученика параллельно со сменой режима ждёт своей очереди.
			$this->groups->lockForUpdate( $groupId );

			$group = $this->groups->findById( $groupId );
			if ( ! $group ) {
				throw new \InvalidArgumentException( 'Группа не найдена.' );
			}

			$current = AccessMode::fromValueOrDefault( (string) ( $group->access_mode ?? '' ) );
			if ( $current === $target ) {
				return false;
			}

			if ( AccessMode::Open === $current || AccessMode::Open === $target ) {
				throw new \DomainException( 'Режим открытой группы изменить нельзя: он задаётся при создании.' );
			}

			if ( AccessMode::Individual === $target ) {
				$active = $this->records->countActiveByGroup( $groupId );
				if ( $active > 1 ) {
					throw new \DomainException( sprintf( 'В группе %d учеников — индивидуальной может быть только группа из одного ученика.', $active ) );
				}

				return $this->groups->update( $groupId, array(
					'access_mode'       => $target->value,
					'meetings'          => '[]',
					'room_id'           => null,
					'program_locked_at' => null,
				) );
			}

			return $this->groups->update( $groupId, array( 'access_mode' => $target->value ) );
		} );
	}
}
