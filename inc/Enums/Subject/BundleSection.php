<?php

declare( strict_types=1 );

namespace Inc\Enums\Subject;

use Inc\Services\Subject\PostTypeResolver;

/**
 * Разделы записей в пакете переноса предмета.
 *
 * @package Inc\Enums\Subject
 *
 * ### Порядок = граф зависимостей
 *
 * Порядок кейсов — это топологическая сортировка ссылок между сущностями:
 * каждый следующий раздел ссылается только на предыдущие.
 *
 * ```
 * tasks, articles → problems → works → assessments → lessons → courses
 * ```
 *
 * Импорт обязан идти строго в этом порядке — тогда к моменту вставки записи
 * все её ссылки уже есть в карте `_export_id → новый WP ID`, и отдельный
 * резолвер зависимостей не нужен ({@see cases()} возвращает кейсы в порядке
 * объявления).
 */
enum BundleSection: string {

	/** Задания предмета (`{key}_tasks`). */
	case Tasks = 'tasks';

	/** Статьи предмета (`{key}_articles`). */
	case Articles = 'articles';

	/**
	 * Глобальный банк задач (`fs_lms_problems`).
	 *
	 * Не привязан к предмету: в пакет попадают только те задачи, на которые
	 * реально ссылаются работы/контрольные этого предмета.
	 */
	case Problems = 'problems';

	/** Работы (`{key}_works`) — ссылаются на tasks | problems. */
	case Works = 'works';

	/** Контрольные (`{key}_assessments`) — ссылаются на tasks | problems. */
	case Assessments = 'assessments';

	/** Уроки (`{key}_lessons`) — ссылаются на tasks | works | assessments | problems. */
	case Lessons = 'lessons';

	/** Курсы (`{key}_courses`) — ссылаются на lessons. */
	case Courses = 'courses';

	/**
	 * CPT раздела для конкретного предмета.
	 *
	 * @param string $subjectKey Ключ предмета
	 *
	 * @return string Slug типа записи
	 */
	public function postType( string $subjectKey ): string {
		return match ( $this ) {
			self::Tasks       => PostTypeResolver::tasks( $subjectKey ),
			self::Articles    => PostTypeResolver::articles( $subjectKey ),
			self::Problems    => PostTypeResolver::problems(),
			self::Works       => PostTypeResolver::works( $subjectKey ),
			self::Assessments => PostTypeResolver::assessments( $subjectKey ),
			self::Lessons     => PostTypeResolver::lessons( $subjectKey ),
			self::Courses     => PostTypeResolver::courses( $subjectKey ),
		};
	}

	/**
	 * Человекочитаемое название раздела (для превью импорта и логов).
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Tasks       => 'Задания',
			self::Articles    => 'Статьи',
			self::Problems    => 'Задачи глобального банка',
			self::Works       => 'Работы',
			self::Assessments => 'Контрольные',
			self::Lessons     => 'Уроки',
			self::Courses     => 'Курсы',
		};
	}

	/**
	 * Раздел глобальный (не принадлежит предмету).
	 *
	 * Такие записи на целевом сайте могут уже существовать — их нельзя слепо
	 * создавать заново при каждом импорте ({@see \Inc\Services\Subject\Bundle\ProblemDeduplicator}).
	 *
	 * @return bool
	 */
	public function isGlobal(): bool {
		return self::Problems === $this;
	}

	/**
	 * Разделы «базового» экспорта — банк заданий и статей.
	 *
	 * Соответствует объёму старого JSON-экспорта: иногда нужно перенести только
	 * банк, без курсов и уроков.
	 *
	 * @return self[]
	 */
	public static function bankOnly(): array {
		return array( self::Tasks, self::Articles );
	}

	/**
	 * Разделы учебной программы — работы, контрольные, уроки, курсы
	 * (плюс подтягиваемые автоматически problems).
	 *
	 * @return self[]
	 */
	public static function curriculum(): array {
		return array( self::Problems, self::Works, self::Assessments, self::Lessons, self::Courses );
	}
}
