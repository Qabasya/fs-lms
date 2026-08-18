<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Enums\Wp\PageRoutes;
use Inc\Enums\Wp\ShortCode;
use Inc\Services\System\PageGeneratorService;

/**
 * Class RoutingPagesMigration
 *
 * Догоняющее восстановление служебных WP-страниц (`/apply/`, `/profile/`,
 * `/lesson/`, `/course-preview/`), от которых зависит маршрутизация плагина.
 *
 * ### Что чинит
 *
 * `Activate::generatePages()` создаёт эти страницы только при (ре)активации
 * плагина. Если маршрут добавили в код уже после того, как установка была
 * активирована (или страницу случайно удалили/увели в корзину), страница
 * никогда не появится сама — а без неё контроллер маршрута
 * (`is_page($slug)`) не срабатывает вообще, и WP отдаёт обычный 404 на любую
 * ссылку вида `/lesson/?gid=&gl=` — неотличимо от «данные битые», хотя
 * причина в отсутствующей странице, а не в контенте.
 *
 * ### Почему отдельный класс, а не `Migration_1_0_0`/`Activate`
 *
 * Та выполняется только при (ре)активации — паттерн {@see BroadcastStepMigration}:
 * гейт собственной опцией, вызов из {@see \Inc\Init::run()} на обычной загрузке.
 *
 * `/sign-in/` тоже здесь: раньше страницей владел модуль SocialAuth и
 * восстанавливал её сам при включении, но модуль снят, а форма входа переехала
 * в ядро ({@see \Inc\Controllers\Person\AuthPageController}).
 *
 * Идемпотентна: {@see PageGeneratorService::ensurePublished()} не трогает уже
 * опубликованные страницы.
 *
 * @package Inc\Migrations
 */
class RoutingPagesMigration {

	/** Опция-гейт (значение = версия выполненной миграции). */
	private const VERSION_OPTION = 'fs_lms_routing_pages_migration';

	/** Версия миграции. Поднята до '2' вместе с добавлением `/sign-in/`. */
	private const VERSION = '2';

	public function __construct( private readonly PageGeneratorService $pages ) {}

	/**
	 * Восстанавливает недостающие страницы один раз (version-gated). Вызывать
	 * на обычной загрузке.
	 *
	 * @return void
	 */
	public function ensure(): void {
		if ( self::VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}

		$this->pages->ensurePublished( PageRoutes::SignIn, 'Авторизация', ShortCode::LoginForm->tag() );
		$this->pages->ensurePublished( PageRoutes::Apply, 'Подать заявку', ShortCode::ApplyForm->tag() );
		$this->pages->ensurePublished( PageRoutes::UserProfile, 'Личный кабинет', ShortCode::Profile->tag() );
		$this->pages->ensurePublished( PageRoutes::LessonPlayer, 'Урок', '' );
		$this->pages->ensurePublished( PageRoutes::CoursePreview, 'Просмотр курса', '' );

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}
}
