<?php

declare( strict_types=1 );

namespace Inc\Repositories\OptionsRepositories;

use Inc\Enums\Settings\AvatarEntity;
use Inc\Enums\Settings\OptionName;

/**
 * Аватары предметов и групп: `['subject' => [key => attachment_id], 'group' => [id => attachment_id]]`.
 *
 * Логотип кабинета сюда не входит — он живёт в {@see PluginConfigRepository} (`brand_logo_id`).
 */
readonly class AvatarsRepository {

	/**
	 * @return array{subject: array<string,int>, group: array<string,int>}
	 */
	public function all(): array {
		$stored = get_option( OptionName::Avatars->value, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			AvatarEntity::Subject->value => $this->ids( $stored[ AvatarEntity::Subject->value ] ?? array() ),
			AvatarEntity::Group->value   => $this->ids( $stored[ AvatarEntity::Group->value ] ?? array() ),
		);
	}

	/** Задаёт аватар одной сущности; $attachmentId <= 0 — убирает. Остальные записи не трогает. */
	public function set( AvatarEntity $entity, string $key, int $attachmentId ): void {
		$all = $this->all();

		if ( $attachmentId > 0 ) {
			$all[ $entity->value ][ $key ] = $attachmentId;
		} else {
			unset( $all[ $entity->value ][ $key ] );
		}

		update_option( OptionName::Avatars->value, $all, false );
	}

	/**
	 * @param mixed $raw
	 * @return array<string,int>
	 */
	private function ids( mixed $raw ): array {
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $key => $id ) {
			if ( (int) $id > 0 ) {
				$out[ (string) $key ] = (int) $id;
			}
		}

		return $out;
	}
}
