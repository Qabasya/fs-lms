<?php

declare( strict_types=1 );

namespace Inc\DTO\Subject;

use Inc\Enums\Subject\BundleSection;

/**
 * Class BundleOptionsDTO
 *
 * Объём пакета переноса: какие разделы включать.
 *
 * @package Inc\DTO\Subject
 *
 * ### Зачем выбор объёма
 *
 * Максимальный охват нужен не всегда: типовой сценарий «перенести банк заданий
 * на другой сайт» не требует ни курсов, ни уроков, ни медиа — и заметно дешевле
 * по времени и размеру архива.
 *
 * ### Что подтягивается принудительно
 *
 * `problems` (глобальный банк) не является отдельным флагом: задачи попадают в
 * пакет автоматически и только те, на которые реально ссылаются включённые
 * работы и контрольные. Иначе пакет либо тянул бы весь глобальный банк, либо
 * приезжал с битыми ссылками.
 */
readonly class BundleOptionsDTO {

	/**
	 * @param bool $includeCurriculum Включать работы, контрольные, уроки, курсы
	 * @param bool $includeMedia      Включать физические медиафайлы
	 * @param bool $includeStudents   Включать учеников, их учётки и группы (без прогресса)
	 */
	public function __construct(
		public bool $includeCurriculum = true,
		public bool $includeMedia = true,
		public bool $includeStudents = false,
	) {}

	/**
	 * Собирает объём из POST-запроса.
	 *
	 * @param array<string, mixed> $request Сырые значения запроса
	 *
	 * @return self
	 */
	public static function fromRequest( array $request ): self {
		$flag = static fn( string $key, bool $default ): bool => isset( $request[ $key ] )
			? in_array( $request[ $key ], array( '1', 1, 'true', true, 'on' ), true )
			: $default;

		return new self(
			includeCurriculum: $flag( 'include_curriculum', true ),
			includeMedia:      $flag( 'include_media', true ),
			includeStudents:   $flag( 'include_students', false ),
		);
	}

	/**
	 * Разделы записей, попадающие в пакет при таком объёме.
	 *
	 * @return BundleSection[] В топологическом порядке
	 */
	public function sections(): array {
		return $this->includeCurriculum
			? BundleSection::cases()
			: BundleSection::bankOnly();
	}

	/**
	 * Представление для манифеста (чтобы импорт знал, чего в пакете нет).
	 *
	 * @return array<string, bool>
	 */
	public function toArray(): array {
		return array(
			'curriculum' => $this->includeCurriculum,
			'media'      => $this->includeMedia,
			'students'   => $this->includeStudents,
		);
	}
}
