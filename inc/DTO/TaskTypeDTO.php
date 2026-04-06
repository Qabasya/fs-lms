<?php

namespace Inc\DTO;

class TaskTypeDTO {
	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly string $slug,
		public readonly string $description = ''
	) {}
}