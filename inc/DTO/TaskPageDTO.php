<?php

declare( strict_types=1 );

namespace Inc\DTO;

readonly class TaskPageDTO {

	public function __construct(
		public ?PostViewDTO $post,
		public string $subject_key,
		public string $subject_name,
		public array $content,
		public array $files,
		public array $tags,
		public array $articles,
		public array $navigation,
		public array $tabs,
	) {}
}