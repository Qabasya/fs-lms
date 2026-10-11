<?php
/**
 * Страницы гостевых экзаменов на уже работающих установках (этап 11a.2.1).
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Enums\Wp\PageRoutes;
use Inc\Repositories\OptionsRepositories\ExamPagesVersionRepository;
use Inc\Shared\PluginLogger;
use Inc\Services\System\PageGeneratorService;

/**
 * Страницы гостевых экзаменов на уже работающих установках (этап 11a.2.1).
 *
 * Новая установка получает их из `Activate::generatePages()`; живая — одноразово на обычной загрузке: запись по ссылке школы,
 * вход, результат гостя и школьный отчёт. Data-миграция, version-gated собственной опцией (паттерн {@see DraftLessonOpenMigration}); повторный запуск безвреден.
 *
 * @package Inc\Migrations
 */
class ExamPagesMigration {

	private const VERSION = '3';

	/** Получает генератор и маркер версии; успешная публикация страниц предшествует записи маркера. */
	public function __construct(
		private readonly PageGeneratorService $pages,
		private readonly ExamPagesVersionRepository $versions,
	) {}

	// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- The RuntimeException raised here is caught and logged below, rather than propagated.
	/**
	 * Обеспечивает публикацию всех страниц до записи версии; при отказе следующая загрузка повторяет попытку.
	 */
	public function run(): void {
		if ( $this->versions->get() === self::VERSION ) {
			return;
		}

		try {
			foreach ( PageRoutes::guestExamPages() as $route ) {
				$this->pages->ensurePublished( $route, $route->guestExamTitle(), $route->guestExamShortCode()->tag() );
			}

			if ( ! $this->versions->save( self::VERSION ) ) {
				throw new \RuntimeException( 'Не удалось сохранить версию гостевых страниц экзаменов.' );
			}
		} catch ( \RuntimeException $e ) {
			// Маркер не записан: следующая загрузка повторит обеспечение страниц; сайт продолжает работать.
			PluginLogger::exception( 'ExamPagesMigration', $e, array(), true );
		}
	}
	// phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.Missing
}
