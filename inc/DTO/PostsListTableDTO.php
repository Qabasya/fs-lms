<?php

declare( strict_types=1 );

namespace Inc\DTO;

/**
 * Class PostsListTableDTO
 *
 * Data Transfer Object для хранения и управления состоянием WP_Posts_List_Table.
 * Позволяет безопасно работать с таблицей постов, подменяя URL для корректной
 * работы ссылок в кастомных админ-страницах.
 *
 * @package Inc\DTO
 */
class PostsListTableDTO {
	/**
	 * Конструктор DTO.
	 *
	 * @param \WP_Posts_List_Table $table            Объект таблицы постов
	 * @param \WP_Post_Type        $post_type_object Объект типа поста
	 * @param string               $post_type        Слаг типа поста
	 * @param string               $edit_base        Базовый URL для редактирования (обычно "post.php")
	 * @param string               $custom_base      Кастомный базовый URL для подмены
	 * @param string               $original_uri     Оригинальный REQUEST_URI для восстановления
	 * @param string               $tab              Идентификатор вкладки (опционально)
	 * @param string               $page_slug        Слаг страницы (опционально)
	 */
	public function __construct(
		public readonly \WP_Posts_List_Table $table,
		public readonly \WP_Post_Type $post_type_object,
		public readonly string $post_type,
		public readonly string $edit_base,
		public readonly string $custom_base,
		public readonly string $original_uri,
		public readonly string $tab = '',
		public readonly string $page_slug = '',
	) {
	}
	
	/**
	 * Возвращает HTML представлений (views) таблицы с подменёнными ссылками.
	 *
	 * @return string HTML представлений
	 */
	public function views(): string {
		ob_start();
		$this->table->views();
		
		// Заменяем стандартные ссылки на кастомные
		return str_replace( $this->edit_base, $this->custom_base, (string) ob_get_clean() );
	}
	
	/**
	 * Возвращает HTML таблицы с подменёнными ссылками.
	 *
	 * @return string HTML таблицы
	 */
	public function display(): string {
		ob_start();
		$this->table->display();
		
		// Заменяем стандартные ссылки на кастомные
		return str_replace( $this->edit_base, $this->custom_base, (string) ob_get_clean() );
	}
	
	/**
	 * Возвращает HTML инлайн-редактора для быстрого редактирования.
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
	 *
	 * @return void
	 */
	public function restore(): void {
		$_SERVER['REQUEST_URI'] = $this->original_uri;
	}
}