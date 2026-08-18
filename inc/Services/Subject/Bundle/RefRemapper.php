<?php

declare( strict_types=1 );

namespace Inc\Services\Subject\Bundle;

use Inc\Enums\Wp\PostMetaName;

/**
 * Class RefRemapper
 *
 * Переписывает ссылки внутри мета-структур записи: WP ID ↔ `_export_id`.
 *
 * @package Inc\Services\Subject\Bundle
 *
 * ### Почему обход по имени ключа, а не по схеме
 *
 * Все сущности учебной программы хранят своё состояние в одном мета-поле
 * `fs_lms_meta` как вложенный массив, и ссылки живут на разной глубине:
 *
 * ```
 * works:       meta.item_ids[]                    → tasks | problems
 * assessments: meta.task_ids[]                    → tasks | problems
 * lessons:     meta.steps[].payload.ref           → tasks | works | assessments | problems
 * courses:     meta.modules[].lesson_ids[]        → lessons
 * поля задания: meta.<любое>.attachment_id(s)     → медиабиблиотека
 * ```
 *
 * Описывать путь к каждой ссылке отдельно — значит ломать перенос каждый раз,
 * когда в конструкторе появится новое поле. Поэтому обход рекурсивный, а
 * критерий — имя ключа: набор имён мал, стабилен и уже зафиксирован в DTO
 * ({@see \Inc\DTO\Course\ModuleDTO}, {@see \Inc\DTO\Course\StepDTO}).
 *
 * ### Нерезолвимые ссылки
 *
 * Ссылка, которой нет в карте (задание удалили до экспорта, битая мета),
 * выбрасывается из списка, а не переносится «как есть»: чужой числовой ID на
 * целевом сайте укажет на произвольную запись — это хуже отсутствующей ссылки.
 * Такие случаи собираются в {@see droppedRefs()} и показываются в отчёте.
 */
final class RefRemapper {

	/**
	 * Ключи со списком ссылок на записи. `TaskBundleChildIds` (§3.2, .docs/Tasks.md) —
	 * parent-пост связки 19-21 хранит id трёх materialized children тем же
	 * способом, что Work/Assessment хранят свои item_ids/task_ids.
	 */
	private const array REF_LIST_KEYS = array( 'item_ids', 'task_ids', 'lesson_ids', PostMetaName::TaskBundleChildIds->value );

	/**
	 * Ключи с одиночной ссылкой на запись. `TaskBundleParentId` — обратная
	 * ссылка child → parent, без ремапа указывала бы после импорта на WP ID
	 * исходного сайта (постороннюю или несуществующую запись на целевом).
	 */
	private const array REF_SCALAR_KEYS = array( 'ref', PostMetaName::TaskBundleParentId->value );

	/**
	 * Ключи со списком вложений медиабиблиотеки.
	 */
	private const array MEDIA_LIST_KEYS = array( 'attachment_ids' );

	/**
	 * Ключи с одиночным вложением медиабиблиотеки.
	 */
	private const array MEDIA_SCALAR_KEYS = array( 'attachment_id' );

	/**
	 * Ссылки, которые не удалось разрешить (для отчёта).
	 *
	 * @var array<int, string>
	 */
	private array $dropped = array();

	/**
	 * Экспорт: заменяет WP ID на `_export_id`.
	 *
	 * @param array          $meta   Мета-массив записи
	 * @param ExportIdMapper $mapper Карта, наполненная на этапе сбора
	 *
	 * @return array Мета со ссылками в виде `_export_id`
	 */
	public function toExportIds( array $meta, ExportIdMapper $mapper ): array {
		return $this->walk(
			$meta,
			fn( mixed $value ): ?string => $this->postToExportId( $value, $mapper ),
			fn( mixed $value ): ?string => $this->mediaToExportId( $value ),
		);
	}

	/**
	 * Импорт: заменяет `_export_id` на новые WP ID.
	 *
	 * @param array               $meta   Мета-массив из манифеста
	 * @param ExportIdMapper      $mapper Карта, наполненная по мере вставки записей
	 * @param MediaIdMap          $media  Карта медиа: `_export_id` → новый attachment ID
	 *
	 * @return array Мета с ID целевого сайта
	 */
	public function toPostIds( array $meta, ExportIdMapper $mapper, MediaIdMap $media ): array {
		return $this->walk(
			$meta,
			fn( mixed $value ): ?int => $this->exportIdToPost( $value, $mapper ),
			fn( mixed $value ): ?int => $media->resolve( (string) $value ),
		);
	}

	/**
	 * Ссылки, выброшенные как нерезолвимые.
	 *
	 * @return string[] Человекочитаемые описания
	 */
	public function droppedRefs(): array {
		return $this->dropped;
	}

	/**
	 * Рекурсивный обход мета-структуры с подменой ссылочных значений.
	 *
	 * @param array    $node        Текущий узел
	 * @param callable $mapPostRef  Преобразователь ссылки на запись; null — выбросить
	 * @param callable $mapMediaRef Преобразователь ссылки на вложение; null — выбросить
	 *
	 * @return array
	 */
	private function walk( array $node, callable $mapPostRef, callable $mapMediaRef ): array {
		$result = array();

		foreach ( $node as $key => $value ) {
			$name = (string) $key;

			if ( in_array( $name, self::REF_LIST_KEYS, true ) ) {
				$result[ $key ] = $this->mapList( $value, $mapPostRef, $name );
				continue;
			}

			if ( in_array( $name, self::MEDIA_LIST_KEYS, true ) ) {
				$result[ $key ] = $this->mapList( $value, $mapMediaRef, $name );
				continue;
			}

			if ( in_array( $name, self::REF_SCALAR_KEYS, true ) ) {
				$result[ $key ] = $this->mapScalar( $value, $mapPostRef, $name );
				continue;
			}

			if ( in_array( $name, self::MEDIA_SCALAR_KEYS, true ) ) {
				$result[ $key ] = $this->mapScalar( $value, $mapMediaRef, $name );
				continue;
			}

			$result[ $key ] = is_array( $value )
				? $this->walk( $value, $mapPostRef, $mapMediaRef )
				: $value;
		}

		return $result;
	}

	/**
	 * Преобразует список ссылок, выбрасывая нерезолвимые.
	 *
	 * @param mixed    $value Исходное значение ключа
	 * @param callable $map   Преобразователь одной ссылки
	 * @param string   $field Имя поля (для отчёта)
	 *
	 * @return array Список преобразованных ссылок (переиндексованный)
	 */
	private function mapList( mixed $value, callable $map, string $field ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$mapped = array();
		foreach ( $value as $item ) {
			$resolved = $map( $item );
			if ( null === $resolved ) {
				$this->dropped[] = sprintf( '%s: ссылка «%s» не найдена в пакете', $field, (string) $item );
				continue;
			}
			$mapped[] = $resolved;
		}

		return $mapped;
	}

	/**
	 * Преобразует одиночную ссылку.
	 *
	 * Нерезолвимая ссылка обнуляется, а не удаляется: у шага урока есть тип,
	 * и без ключа `ref` он превратится в структурно битый шаг.
	 *
	 * @param mixed    $value Исходное значение
	 * @param callable $map   Преобразователь
	 * @param string   $field Имя поля (для отчёта)
	 *
	 * @return string|int Преобразованное значение или 0 при неудаче
	 */
	private function mapScalar( mixed $value, callable $map, string $field ): string|int {
		if ( null === $value || '' === $value || 0 === $value ) {
			return 0;
		}

		$resolved = $map( $value );
		if ( null === $resolved ) {
			$this->dropped[] = sprintf( '%s: ссылка «%s» не найдена в пакете', $field, (string) $value );
			return 0;
		}

		return $resolved;
	}

	/**
	 * WP ID записи → `_export_id`.
	 *
	 * @param mixed          $value  Значение из меты
	 * @param ExportIdMapper $mapper Карта экспорта
	 *
	 * @return string|null
	 */
	private function postToExportId( mixed $value, ExportIdMapper $mapper ): ?string {
		$postId = (int) $value;

		return $postId > 0 ? $mapper->toExportId( $postId ) : null;
	}

	/**
	 * `_export_id` → WP ID записи.
	 *
	 * @param mixed          $value  Значение из манифеста
	 * @param ExportIdMapper $mapper Карта импорта
	 *
	 * @return int|null
	 */
	private function exportIdToPost( mixed $value, ExportIdMapper $mapper ): ?int {
		return is_string( $value ) && '' !== $value ? $mapper->toPostId( $value ) : null;
	}

	/**
	 * ID вложения → `_export_id` медиа.
	 *
	 * Медиа не проходит регистрацию в {@see ExportIdMapper}: их ключ выводится
	 * прямо из исходного attachment ID, а физический сбор файлов идёт отдельным
	 * проходом ({@see MediaCollector}).
	 *
	 * @param mixed $value Значение из меты
	 *
	 * @return string|null
	 */
	private function mediaToExportId( mixed $value ): ?string {
		$attachmentId = (int) $value;

		return $attachmentId > 0 ? MediaIdMap::exportId( $attachmentId ) : null;
	}
}
