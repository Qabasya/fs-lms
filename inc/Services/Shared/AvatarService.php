<?php

declare( strict_types=1 );

namespace Inc\Services\Shared;

use Inc\Enums\Settings\AvatarEntity;
use Inc\Repositories\OptionsRepositories\AvatarsRepository;
use Inc\Repositories\OptionsRepositories\PluginConfigRepository;

/**
 * Аватары предметов, групп и логотип кабинета (Настройки → Аватары).
 *
 * Единая точка чтения/записи: админка берёт отсюда ID и URL для превью, кабинет — карту URL
 * для чипов. Цепочка выбора чипа (группа → предмет → цветной чип) собирается на клиенте
 * по карте {@see self::urlMap()}.
 */
readonly class AvatarService {

	/** Размер превью: чип в кабинете — 30×30, на retina хватает thumbnail. */
	private const SIZE = 'thumbnail';

	public function __construct(
		private AvatarsRepository $repository,
		private PluginConfigRepository $config,
		private PluginConfig $pluginConfig,
	) {}

	/**
	 * Карта URL для кабинета: `{subject: {key: url}, group: {id: url}}`.
	 *
	 * @return array{subject: array<string,string>, group: array<string,string>}
	 */
	public function urlMap(): array {
		$map = array(
			AvatarEntity::Subject->value => array(),
			AvatarEntity::Group->value   => array(),
		);

		foreach ( $this->repository->all() as $entity => $ids ) {
			foreach ( $ids as $key => $id ) {
				$url = $this->attachmentUrl( $id );
				if ( '' !== $url ) {
					$map[ $entity ][ $key ] = $url;
				}
			}
		}

		return $map;
	}

	/** Attachment ID аватара сущности; 0 — не задан. */
	public function id( AvatarEntity $entity, string $key ): int {
		if ( AvatarEntity::Brand === $entity ) {
			return $this->pluginConfig->brandLogoId();
		}

		return $this->repository->all()[ $entity->value ][ $key ] ?? 0;
	}

	/** URL превью по attachment ID; '' — нет файла. */
	public function attachmentUrl( int $id ): string {
		if ( $id <= 0 ) {
			return '';
		}

		return (string) ( wp_get_attachment_image_url( $id, self::SIZE ) ?: '' );
	}

	/**
	 * Сохраняет аватар. Вложение должно быть картинкой; 0 — снять аватар.
	 *
	 * @return bool false — вложение не картинка (ничего не записано)
	 */
	public function set( AvatarEntity $entity, string $key, int $attachmentId ): bool {
		if ( $attachmentId > 0 && ! wp_attachment_is_image( $attachmentId ) ) {
			return false;
		}

		if ( AvatarEntity::Brand === $entity ) {
			$this->config->save( array( 'brand_logo_id' => max( 0, $attachmentId ) ) );
			return true;
		}

		$this->repository->set( $entity, $key, $attachmentId );

		return true;
	}
}
