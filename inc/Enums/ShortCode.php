<?php

declare( strict_types=1 );

namespace Inc\Enums;

enum ShortCode: string {
	case LoginForm    = 'fs_lms_login_form';
	case RegisterForm = 'fs_lms_register_form';
	case Profile      = 'fs_lms_profile';

	public function tag(): string {
		return '[' . $this->value . ']';
	}
}
