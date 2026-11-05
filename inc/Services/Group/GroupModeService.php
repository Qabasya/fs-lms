<?php

declare( strict_types=1 );

namespace Inc\Services\Group;

use Inc\Enums\Course\AccessMode;
use Inc\Enums\Course\GroupType;
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
	public function change( int $groupId, AccessMode $target, ?GroupType $type = null ): bool {
		return (bool) $this->inTransaction( function () use ( $groupId, $target, $type ): bool {
			// Под блокировкой строки группы: зачисление ученика параллельно со сменой режима ждёт своей очереди.
			$this->groups->lockForUpdate( $groupId );

			$group = $this->groups->findById( $groupId );
			if ( ! $group ) {
				throw new \InvalidArgumentException( 'Группа не найдена.' );
			}

			if ( (int) ( $group->self_paced_course_id ?? 0 ) > 0 ) {
				throw new \DomainException( 'Служебная группа изменяется только через настройки курса.' );
			}

			$current = AccessMode::fromValueOrDefault( (string) ( $group->access_mode ?? '' ) );
			$currentType = GroupType::fromStorage( $current->value, 1 === (int) ( $group->is_shared ?? 0 ) );
			$type ??= $current === $target
				? $currentType
				: GroupType::fromStorage( $target->value, $currentType->isShared() && AccessMode::Scheduled === $target );
			if ( $type->accessMode() !== $target ) {
				throw new \InvalidArgumentException( 'Тип группы не соответствует режиму доступа.' );
			}
			if ( ( $currentType->isLegacy() || $type->isLegacy() ) && $currentType !== $type ) {
				throw new \DomainException( 'Старое сочетание типов требует отдельной проверки прогресса; здесь его можно только сохранить.' );
			}
			$shared = $type->isShared();
			$sharedChanged = $shared !== ( 1 === (int) ( $group->is_shared ?? 0 ) );
			$extra = $sharedChanged ? array( 'is_shared' => $shared ? 1 : 0 ) : array();
			if ( $current === $target ) {
				return $sharedChanged ? $this->groups->update( $groupId, $extra ) : false;
			}

			if ( AccessMode::Open === $current || AccessMode::Open === $target ) {
				throw new \DomainException( 'Режим открытой группы изменить нельзя: он задаётся при создании.' );
			}

			if ( AccessMode::Individual === $target ) {
				$active = $this->records->countActiveByGroup( $groupId );
				if ( $active > 1 ) {
					throw new \DomainException( sprintf( 'В группе %d учеников — индивидуальной может быть только группа из одного ученика.', $active ) );
				}

				return $this->groups->update( $groupId, array_merge( $extra, array(
					'access_mode'       => $target->value,
					'meetings'          => '[]',
					'room_id'           => null,
					'program_locked_at' => null,
				) ) );
			}

			return $this->groups->update( $groupId, array_merge( $extra, array( 'access_mode' => $target->value ) ) );
		} );
	}
}
