<?php

namespace Inc\DTO;

class SubjectDTO {
	public function __construct(
		public readonly string $key,
		public readonly string $name
	) {
	}
}