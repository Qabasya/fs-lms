<?php

declare( strict_types=1 );

namespace Inc\Services\Subject\Bundle;

use Inc\Enums\Subject\BundleSection;

/**
 * Class ExportIdMapper
 *
 * Двусторонняя карта между WP post ID и стабильным `_export_id` пакета.
 *
 * @package Inc\Services\Subject\Bundle
 *
 * ### Зачем не WP ID
 *
 * Пост, выгруженный с ID 123, на целевом сайте получит совсем другой ID.
 * Поэтому все ссылки внутри пакета (`item_ids`, `task_ids`, `steps[].payload.ref`,
 * `modules[].lesson_ids`) хранятся как `_export_id` — строка вида `tasks:123`,
 * где префикс — раздел пакета, а число — ID на сайте-источнике.
 *
 * Префикс раздела нужен не для красоты: он делает ключ самоописывающим и
 * позволяет отличить ссылку на задание предмета от ссылки на задачу глобального
 * банка, даже если числовые ID совпали.
 *
 * ### Два направления, один объект
 *
 * - **Экспорт**: `register()` наполняет карту `WP ID → _export_id`, `toExportId()` читает.
 * - **Импорт**: `bind()` наполняет карту `_export_id → новый WP ID`, `toPostId()` читает.
 *
 * Экземпляр живёт в пределах одного прогона (экспорта или импорта) и создаётся
 * оркестратором через `new` — это рабочая структура данных, а не сервис.
 */
final class ExportIdMapper {

	/**
	 * Карта экспорта: WP ID → `_export_id`.
	 *
	 * @var array<int, string>
	 */
	private array $byPostId = array();

	/**
	 * Карта импорта: `_export_id` → новый WP ID.
	 *
	 * @var array<string, int>
	 */
	private array $byExportId = array();

	/**
	 * Собирает `_export_id` из раздела и исходного ID.
	 *
	 * @param BundleSection $section Раздел пакета
	 * @param int           $postId  ID записи на сайте-источнике
	 *
	 * @return string Ключ вида `tasks:123`
	 */
	public static function make( BundleSection $section, int $postId ): string {
		return $section->value . ':' . $postId;
	}

	/**
	 * Регистрирует запись при экспорте.
	 *
	 * @param BundleSection $section Раздел пакета
	 * @param int           $postId  ID записи на сайте-источнике
	 *
	 * @return string Присвоенный `_export_id`
	 */
	public function register( BundleSection $section, int $postId ): string {
		$exportId                  = self::make( $section, $postId );
		$this->byPostId[ $postId ] = $exportId;

		return $exportId;
	}

	/**
	 * Привязывает `_export_id` к созданной на целевом сайте записи (импорт).
	 *
	 * @param string $exportId Ключ из манифеста
	 * @param int    $postId   ID новой записи
	 *
	 * @return void
	 */
	public function bind( string $exportId, int $postId ): void {
		if ( '' !== $exportId && $postId > 0 ) {
			$this->byExportId[ $exportId ] = $postId;
		}
	}

	/**
	 * `_export_id` для исходного WP ID (экспорт).
	 *
	 * @param int $postId ID на сайте-источнике
	 *
	 * @return string|null null — запись не вошла в пакет
	 */
	public function toExportId( int $postId ): ?string {
		return $this->byPostId[ $postId ] ?? null;
	}

	/**
	 * Новый WP ID по `_export_id` (импорт).
	 *
	 * @param string $exportId Ключ из манифеста
	 *
	 * @return int|null null — ссылка не разрешена (записи нет в пакете)
	 */
	public function toPostId( string $exportId ): ?int {
		return $this->byExportId[ $exportId ] ?? null;
	}

	/**
	 * Запись с таким ID вошла в пакет.
	 *
	 * @param int $postId ID на сайте-источнике
	 *
	 * @return bool
	 */
	public function hasPost( int $postId ): bool {
		return isset( $this->byPostId[ $postId ] );
	}

	/**
	 * Все зарегистрированные исходные ID.
	 *
	 * @return int[]
	 */
	public function registeredPostIds(): array {
		return array_keys( $this->byPostId );
	}
}
