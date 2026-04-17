<?php

declare(strict_types=1);

namespace Inc\DTO;

class PostsListTableDTO {
	public function __construct(
		public readonly \WP_Posts_List_Table $table,
		public readonly \WP_Post_Type $post_type_object,
		public readonly string $post_type,
		public readonly string $edit_base,
		public readonly string $custom_base,
		public readonly string $original_uri,
	) {}

	public function views(): string {
		ob_start();
		$this->table->views();
		return str_replace( $this->edit_base, $this->custom_base, (string) ob_get_clean() );
	}

	public function display(): string {
		ob_start();
		$this->table->display();
		return str_replace( $this->edit_base, $this->custom_base, (string) ob_get_clean() );
	}

	public function restore(): void {
		$_SERVER['REQUEST_URI'] = $this->original_uri;
	}
}
