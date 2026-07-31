<?php

declare( strict_types=1 );

namespace Inc\Services\Subject\Bundle;

/**
 * Class MediaIdMap
 *
 * Карта вложений: `_export_id` медиа → новый attachment ID целевого сайта.
 *
 * @package Inc\Services\Subject\Bundle
 *
 * ### Почему отдельно от ExportIdMapper
 *
 * Вложения не являются записями учебного графа: они не участвуют в
 * топологическом порядке импорта и заливаются одним проходом ДО вставки
 * записей — чтобы к моменту записи меты все новые attachment ID уже были
 * известны и мету не пришлось переписывать вторым проходом.
 *
 * Ключ выводится прямо из attachment ID источника (`media:123`) — отдельной
 * регистрации, как у записей, не требуется.
 */
final class MediaIdMap {

	/**
	 * Префикс ключа вложения.
	 */
	private const string PREFIX = 'media';

	/**
	 * `_export_id` → новый attachment ID.
	 *
	 * @var array<string, int>
	 */
	private array $map = array();

	/**
	 * Ключ вложения по его ID на сайте-источнике.
	 *
	 * @param int $attachmentId ID вложения источника
	 *
	 * @return string Ключ вида `media:123`
	 */
	public static function exportId( int $attachmentId ): string {
		return self::PREFIX . ':' . $attachmentId;
	}

	/**
	 * Привязывает ключ к новому вложению.
	 *
	 * @param string $exportId     Ключ из манифеста
	 * @param int    $attachmentId ID нового вложения
	 *
	 * @return void
	 */
	public function bind( string $exportId, int $attachmentId ): void {
		if ( '' !== $exportId && $attachmentId > 0 ) {
			$this->map[ $exportId ] = $attachmentId;
		}
	}

	/**
	 * Новый attachment ID по ключу.
	 *
	 * @param string $exportId Ключ из манифеста
	 *
	 * @return int|null null — файла нет в пакете или заливка не удалась
	 */
	public function resolve( string $exportId ): ?int {
		return $this->map[ $exportId ] ?? null;
	}

	/**
	 * Все залитые вложения.
	 *
	 * @return int[]
	 */
	public function attachmentIds(): array {
		return array_values( $this->map );
	}

	/**
	 * Количество перенесённых вложений.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->map );
	}
}
