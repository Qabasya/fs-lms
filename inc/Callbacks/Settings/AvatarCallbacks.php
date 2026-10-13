<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Settings;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Settings\AvatarEntity;
use Inc\Enums\Wp\Nonce;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Services\Shared\AvatarService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * AJAX вкладки «Настройки → Аватары»: задать или убрать аватар предмета, группы, кабинета.
 */
class AvatarCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly AvatarService     $avatars,
		private readonly SubjectRepository $subjects,
		private readonly GroupsRepository  $groups,
	) {
		parent::__construct();
	}

	/** Сохраняет аватар одной сущности. `attachment_id` = 0 — убрать. */
	public function ajaxSaveAvatar(): void {
		$this->authorize( Nonce::Avatar, Capability::ManageLmsPlatform );

		$entity = AvatarEntity::tryFrom( $this->sanitizeKey( 'entity' ) );
		if ( null === $entity ) {
			$this->error( 'Неизвестный тип сущности.' );
		}

		$key = AvatarEntity::Brand === $entity ? '' : $this->sanitizeText( 'key' );
		if ( AvatarEntity::Brand !== $entity && ! $this->entityExists( $entity, $key ) ) {
			$this->error( 'Предмет или группа не найдены.' );
		}

		$id = $this->sanitizeInt( 'attachment_id' );
		if ( ! $this->avatars->set( $entity, $key, $id ) ) {
			$this->error( 'Выбранный файл не является изображением.' );
		}

		$this->success(
			array(
				'id'  => max( 0, $id ),
				'url' => $this->avatars->attachmentUrl( $id ),
			)
		);
	}

	private function entityExists( AvatarEntity $entity, string $key ): bool {
		if ( '' === $key ) {
			return false;
		}

		if ( AvatarEntity::Subject === $entity ) {
			return isset( $this->subjects->readAll()[ $key ] );
		}

		return ctype_digit( $key ) && null !== $this->groups->findById( (int) $key );
	}
}
