<?php

declare( strict_types=1 );

namespace Inc\Enums\Wp;

/**
 * Enum SubjectPageType
 *
 * Разделы публичного лендинга предмета — WP-страницы, которые создаются вместе
 * с предметом и уезжают в корзину вместе с ним.
 *
 * @package Inc\Enums\Wp
 *
 * ### Основные обязанности:
 *
 * 1. **Состав лендинга** — какие страницы заводятся предмету (см. forSubject()).
 * 2. **Адрес и подпись раздела** — слаг, путь и заголовок страницы.
 * 3. **Связь с рендером** — шорткод, который наполняет страницу содержимым.
 *
 * ### Архитектурная роль:
 *
 * Единственный источник истины о структуре лендинга: им пользуются
 * SubjectPagesService (создание/переименование), SubjectPagesRepository
 * (ключи хранения) и SubjectLandingController (регистрация шорткодов).
 * Слаги здесь динамические (зависят от предмета), поэтому PageRoutes —
 * перечисление фиксированных служебных страниц — для них не подходит.
 */
enum SubjectPageType: string {

	/** Корневая страница предмета `/{key}/`: описание, наполняется вручную. */
	case Overview = 'overview';

	/** Тренажёр `/{key}/trainer/` — задания предмета. */
	case Trainer = 'trainer';

	/** Учебник `/{key}/articles/` — статьи предмета. */
	case Articles = 'articles';

	/** Курсы `/{key}/courses/` — витрина опубликованных курсов. */
	case Courses = 'courses';

	/**
	 * Слаг страницы (post_name): у корневой — ключ предмета, у разделов —
	 * собственный; полный путь даёт иерархия страниц.
	 *
	 * @param string $subjectKey Ключ предмета.
	 *
	 * @return string
	 */
	public function slug( string $subjectKey ): string {
		return self::Overview === $this ? $subjectKey : $this->value;
	}

	/**
	 * Путь страницы для поиска по get_page_by_path().
	 *
	 * @param string $subjectKey Ключ предмета.
	 *
	 * @return string Например, 'math' или 'math/trainer'.
	 */
	public function path( string $subjectKey ): string {
		return self::Overview === $this ? $subjectKey : "{$subjectKey}/{$this->value}";
	}

	/**
	 * Заголовок страницы. Название предмета входит в заголовок раздела:
	 * в списке страниц админки разделы разных предметов иначе неразличимы.
	 *
	 * @param string $subjectName Название предмета.
	 *
	 * @return string
	 */
	public function title( string $subjectName ): string {
		return match ( $this ) {
			self::Overview => $subjectName,
			self::Trainer  => "{$subjectName} — тренажёр",
			self::Articles => "{$subjectName} — учебник",
			self::Courses  => "{$subjectName} — курсы",
		};
	}

	/**
	 * Шорткод, наполняющий страницу. У корневой его нет — её содержимое
	 * пишет редактор.
	 *
	 * @return ShortCode|null
	 */
	public function shortcode(): ?ShortCode {
		return match ( $this ) {
			self::Overview => null,
			self::Trainer  => ShortCode::SubjectTrainer,
			self::Articles => ShortCode::SubjectArticles,
			self::Courses  => ShortCode::SubjectCourses,
		};
	}

	/**
	 * Раздел по тегу его шорткода; null — шорткод не наш.
	 *
	 * @param string $tag Тег шорткода из add_shortcode().
	 *
	 * @return self|null
	 */
	public static function fromShortcode( string $tag ): ?self {
		foreach ( self::cases() as $type ) {
			if ( $type->shortcode()?->value === $tag ) {
				return $type;
			}
		}

		return null;
	}

	/**
	 * Нужен ли разделу собственный банк заданий/статей предмета (Эпик 18).
	 *
	 * @return bool
	 */
	public function requiresBank(): bool {
		return in_array( $this, array( self::Trainer, self::Articles ), true );
	}

	/**
	 * Состав лендинга предмета: корневая и курсы — всегда, тренажёр и учебник —
	 * только у предмета с банком (без банка CPT заданий/статей не существует).
	 *
	 * @param bool $hasBank Есть ли у предмета собственный банк.
	 *
	 * @return self[]
	 */
	public static function forSubject( bool $hasBank ): array {
		return array_values(
			array_filter(
				self::cases(),
				static fn( self $type ): bool => $hasBank || ! $type->requiresBank()
			)
		);
	}
}