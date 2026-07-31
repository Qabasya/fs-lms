<?php

declare( strict_types=1 );

namespace Inc\Services\Subject;

/**
 * Class ContentKindResolver
 *
 * CPT записи → вид контента (`work`/`lesson`/`course`/`article`/`problem`/`task`),
 * которым оперируют сервисы использования и гарды удаления.
 *
 * @package Inc\Services\Subject
 *
 * Статические хелперы — как у {@see PostTypeResolver}: правило чисто отображающее,
 * без состояния и зависимостей. Раньше жило статикой внутри DI-сервиса
 * `ContentUsageService` (аудит §2.7).
 */
class ContentKindResolver {

	/**
	 * Вид контента для типа записи ('' — не контент банка).
	 *
	 * @param string $postType Слаг типа записи
	 */
	public static function of( string $postType ): string {
		return match ( true ) {
			PostTypeResolver::isWorkPostType( $postType )                  => 'work',
			PostTypeResolver::isLessonPostType( $postType )                => 'lesson',
			PostTypeResolver::isCoursePostType( $postType )                => 'course',
			str_ends_with( $postType, PostTypeResolver::ARTICLES_SUFFIX )  => 'article',
			PostTypeResolver::isProblemPostType( $postType )               => 'problem',
			PostTypeResolver::isTaskPostType( $postType )                  => 'task',
			default                                                        => '',
		};
	}
}
