<?php

declare( strict_types=1 );

namespace Inc\Modules\ArticleBlocks\Enums;

/**
 * Enum ArticleBlock
 *
 * Блоки статьи модуля: значение кейса — тег шорткода.
 *
 * Тег хранится в контенте статей, поэтому менять значение кейса нельзя без миграции контента.
 *
 * @package Inc\Modules\ArticleBlocks\Enums
 */
enum ArticleBlock: string {

	case Code    = 'fs_article_code';
	case Table   = 'fs_article_table';
	case Image   = 'fs_article_image';
	case Heading = 'fs_article_heading';
	case Task    = 'fs_article_task';

	/**
	 * Название элемента в панели «Добавить элемент» WPBakery.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Code    => 'Код',
			self::Table   => 'Таблица',
			self::Image   => 'Изображение',
			self::Heading => 'Заголовок',
			self::Task    => 'Задание',
		};
	}

	/**
	 * Подсказка под названием элемента.
	 */
	public function description(): string {
		return match ( $this ) {
			self::Code    => 'Многострочный код с подсветкой и кнопкой копирования',
			self::Table   => 'Таблица из диапазона LibreOffice Calc / Excel',
			self::Image   => 'Иллюстрация заданного размера с подписью',
			self::Heading => 'Заголовок раздела — попадает в оглавление',
			self::Task    => 'Карточка опубликованного задания из тренажёра',
		};
	}

	/**
	 * Иконка элемента (класс Dashicons).
	 */
	public function icon(): string {
		return match ( $this ) {
			self::Code    => 'dashicons dashicons-editor-code',
			self::Table   => 'dashicons dashicons-editor-table',
			self::Image   => 'dashicons dashicons-format-image',
			self::Heading => 'dashicons dashicons-heading',
			self::Task    => 'dashicons dashicons-welcome-learn-more',
		};
	}
}
