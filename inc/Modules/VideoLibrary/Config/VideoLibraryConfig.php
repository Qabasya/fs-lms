<?php

declare( strict_types=1 );

namespace Inc\Modules\VideoLibrary\Config;

use Inc\Modules\Shared\ModuleConfig;

/**
 * Class VideoLibraryConfig
 *
 * Конфигурация модуля VideoLibrary (видеозаписи занятий в S3 Beget).
 * Модуль владеет СВОЕЙ опцией `fs_lms_video_library` — ядро о ней не знает.
 *
 * Флаг включения: константа `FS_LMS_VIDEO_LIBRARY` в wp-config.php перекрывает тумблер из опции
 * (3 уровня выключения — паттерн AdSync, см. .docs/ModularArchitecture.md §3.2).
 * Секрет HMAC живёт в `FS_LMS_VIDEO_HMAC_SECRET` (wp-config), не в опции.
 * S3-реквизиты — константы `FS_LMS_S3_*` (wp-config): для выдачи достаточно read-only ключа.
 *
 * @package Inc\Modules\VideoLibrary\Config
 */
class VideoLibraryConfig extends ModuleConfig {

	/** Ключ опции модуля (вне core OptionName — изоляция). */
	public const OPTION = 'fs_lms_video_library';

	/** TTL presigned-ссылок по умолчанию: 6 часов. */
	private const DEFAULT_PRESIGN_TTL = 6 * HOUR_IN_SECONDS;

	private const DEFAULTS = array(
		'enabled'     => false,
		'presign_ttl' => self::DEFAULT_PRESIGN_TTL,
	);

	protected function option(): string {
		return self::OPTION;
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function defaults(): array {
		return self::DEFAULTS;
	}

	protected function toggleConstant(): ?string {
		return 'FS_LMS_VIDEO_LIBRARY';
	}

	/**
	 * Включён ли модуль в рантайме. Константа wp-config перекрывает тумблер.
	 */
	public function isEnabled(): bool {
		if ( defined( 'FS_LMS_VIDEO_LIBRARY' ) ) {
			return (bool) constant( 'FS_LMS_VIDEO_LIBRARY' );
		}
		return (bool) ( $this->get()['enabled'] ?? false );
	}

	/** Секрет HMAC из wp-config (подпись запросов fs-video-uploader → WP). */
	public function hmacSecret(): string {
		return defined( 'FS_LMS_VIDEO_HMAC_SECRET' ) ? (string) constant( 'FS_LMS_VIDEO_HMAC_SECRET' ) : '';
	}

	/**
	 * Реквизиты S3 Beget из wp-config.
	 *
	 * @return array{endpoint:string, region:string, bucket:string, key:string, secret:string}
	 */
	public function s3(): array {
		return array(
			'endpoint' => defined( 'FS_LMS_S3_ENDPOINT' ) ? (string) constant( 'FS_LMS_S3_ENDPOINT' ) : 'https://s3.ru1.storage.beget.cloud',
			'region'   => defined( 'FS_LMS_S3_REGION' ) ? (string) constant( 'FS_LMS_S3_REGION' ) : 'ru-1',
			'bucket'   => defined( 'FS_LMS_S3_BUCKET' ) ? (string) constant( 'FS_LMS_S3_BUCKET' ) : '',
			'key'      => defined( 'FS_LMS_S3_KEY' ) ? (string) constant( 'FS_LMS_S3_KEY' ) : '',
			'secret'   => defined( 'FS_LMS_S3_SECRET' ) ? (string) constant( 'FS_LMS_S3_SECRET' ) : '',
		);
	}

	/** Заданы ли обязательные S3-константы для presigned-выдачи. */
	public function s3Ready(): bool {
		$s3 = $this->s3();
		return '' !== $s3['bucket'] && '' !== $s3['key'] && '' !== $s3['secret'];
	}

	/** TTL presigned-ссылок в секундах. */
	public function presignTtl(): int {
		$ttl = (int) ( $this->get()['presign_ttl'] ?? self::DEFAULT_PRESIGN_TTL );
		return $ttl > 0 ? $ttl : self::DEFAULT_PRESIGN_TTL;
	}
}
