<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Enums\Wp\PageRoutes;
use Inc\Enums\Wp\ShortCode;
use Inc\Services\System\PageGeneratorService;

/**
 * Страницы гостевых экзаменов на уже работающих установках (этап 11a.2.1).
 *
 * Новая установка получает их из `Activate::generatePages()`; живая — одноразово на обычной загрузке: запись по ссылке школы,
 * вход и результат гостя. Data-миграция, version-gated собственной опцией (паттерн {@see DraftLessonOpenMigration}); повторный запуск безвреден.
 *
 * @package Inc\Migrations
 */
class ExamPagesMigration {

	private const VERSION_OPTION = 'fs_lms_exam_pages_version';
	private const VERSION        = '2';

	public function __construct(
		private readonly PageGeneratorService $pages,
	) {}

	public function run(): void {
		if ( get_option( self::VERSION_OPTION ) === self::VERSION ) {
			return;
		}

		$this->pages->ensurePublished( PageRoutes::ExamSignup, 'Запись на экзамен', ShortCode::ExamSignup->tag() );
		$this->pages->ensurePublished( PageRoutes::ExamEntry, 'Вход на экзамен', ShortCode::ExamEntry->tag() );
		$this->pages->ensurePublished( PageRoutes::ExamResult, 'Результат экзамена', ShortCode::ExamResult->tag() );
		$this->pages->ensurePublished( PageRoutes::ExamReport, 'Отчёт по экзамену', ShortCode::ExamReport->tag() );

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}
}
