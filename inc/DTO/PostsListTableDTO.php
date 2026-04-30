<?php

declare( strict_types=1 );

namespace Inc\DTO;

/**
 * Class PostsListTableDTO
 *
 * Data Transfer Object для хранения и управления состоянием WP_Posts_List_Table.
 *
 * @package Inc\DTO
 *
 * ### Основные обязанности:
 *
 * 1. **Хранение состояния** — инкапсулирует объект таблицы, тип поста, URL и параметры.
 * 2. **Подмена ссылок** — заменяет стандартные URL таблицы на кастомные (для работы в админ-страницах).
 * 3. **Восстановление URI** — возвращает оригинальный REQUEST_URI после модификации.
 *
 * ### Архитектурная роль:
 *
 * Используется при построении WP_ListTable на кастомных страницах админ-панели
 * (например, на странице предмета). Позволяет корректно работать пагинации и фильтрам.
 */
readonly class PostsListTableDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param \WP_Posts_List_Table $table            Объект таблицы постов
	 * @param \WP_Post_Type        $post_type_object Объект типа поста (метаданные CPT)
	 * @param string               $post_type        Слаг типа поста (например, 'math_tasks')
	 * @param string               $edit_base        Базовый URL для редактирования (обычно "post.php")
	 * @param string               $custom_base      Кастомный базовый URL для подмены (admin.php?page=...)
	 * @param string               $original_uri     Оригинальный REQUEST_URI для восстановления
	 * @param string               $tab              Идентификатор вкладки (tab-2, tab-3)
	 * @param string               $page_slug        Слаг страницы (например, 'fs_subject_math')
	 */
	public function __construct(
		public \WP_Posts_List_Table $table,
		public \WP_Post_Type $post_type_object,
		public string $post_type,
		public string $edit_base,
		public string $custom_base,
		public string $original_uri,
		public string $tab = '',
		public string $page_slug = '',
	) {
	}

	/**
	 * Возвращает HTML представлений (views) таблицы с подменёнными ссылками.
	 * Представления — это ссылки-фильтры (Все | Опубликованные | Черновики | Корзина).
	 *
	 * @return string HTML представлений
	 */
	public function views(): string {
		// ob_start() — включаем буферизацию вывода
		ob_start();
		// views() — выводит HTML-код представлений
		$this->table->views();

		// str_replace() — заменяем стандартные ссылки на кастомные
		// ob_get_clean() — получаем содержимое буфера и очищаем его
		return str_replace( $this->edit_base, $this->custom_base, (string) ob_get_clean() );
	}

	/**
	 * Возвращает HTML таблицы с подменёнными ссылками.
	 * Содержит заголовки колонок, строки с постами и пагинацию.
	 *
	 * @return string HTML таблицы
	 */
	public function display(): string {
		ob_start();
		$this->table->display();

		return str_replace( $this->edit_base, $this->custom_base, (string) ob_get_clean() );
	}

	/**
	 * Возвращает HTML инлайн-редактора для быстрого редактирования.
	 * inline_edit() — форма быстрого редактирования (без перезагрузки страницы).
	 *
	 * @return string HTML инлайн-редактора
	 */
	public function inlineEdit(): string {
		ob_start();
		$this->table->inline_edit();

		return (string) ob_get_clean();
	}

	/**
	 * Восстанавливает оригинальный REQUEST_URI после работы с таблицей.
	 * Необходимо для корректной админ-панели WordPress.
	 *
	 * @return void
	 */
	public function restore(): void {
		$_SERVER['REQUEST_URI'] = $this->original_uri;
	}
}
