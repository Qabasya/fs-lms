<?php

declare( strict_types=1 );

namespace Inc\Enums\Wp;

/**
 * Ключи транзиентов плагина — единый источник правды.
 *
 * Значение кейса — префикс; конкретный ключ собирается {@see for()} из
 * идентификатора (токен выгрузки, ID пользователя, ключ предмета). Раньше
 * строки вроде `fs_lms_export_` были продублированы в контроллере и CLI —
 * при переименовании ссылка «протухала» молча.
 */
enum TransientKey: string {

	/** Метаданные одноразовой ссылки на выгрузку ПД / пакета предмета (суффикс — токен). */
	case Export = 'fs_lms_export_';

	/** Причина отказа в удалении контента для нотиса (суффикс — ID пользователя). */
	case DeleteBlocked = 'fs_lms_delete_blocked_';

	/** HTML-таблица последних заданий предмета (суффикс — ключ предмета). */
	case RecentTasks = 'fs_lms_recent_tasks_';

	/** HTML-таблица последних статей предмета (суффикс — ключ предмета). */
	case RecentArticles = 'fs_lms_recent_articles_';

	/** Карточки каталога учебника — весь банк статей предмета (суффикс — ключ предмета). */
	case ArticleCatalog = 'fs_lms_article_catalog_';

	/**
	 * Полный ключ транзиента.
	 *
	 * @param string|int $suffix Идентификатор конкретной записи
	 */
	public function for( string|int $suffix ): string {
		return $this->value . $suffix;
	}
}
