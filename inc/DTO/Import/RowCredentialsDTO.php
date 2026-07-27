<?php

declare( strict_types=1 );

namespace Inc\DTO\Import;

/**
 * Учётные данные, созданные при импорте одной строки (режим «полное зачисление»).
 *
 * Собираются оркестратором в отчёт импорта: администратор видит таблицу
 * ученик → логин/пароль (+ родитель) и может скачать её CSV-файлом.
 * Логин/пароль — фактические (при переиспользовании существующей учётки
 * логин берётся из неё, а не из CSV).
 */
readonly class RowCredentialsDTO {

	public function __construct(
		public string  $studentName,
		public string  $studentLogin,
		public string  $studentPassword,
		public ?string $parentLogin = null,
		public ?string $parentPassword = null,
	) {}

	/**
	 * Плоский массив для отчёта импорта (ключи — как в JS-рендере отчёта).
	 *
	 * @return array<string, ?string>
	 */
	public function toArray(): array {
		return array(
			'student_name'     => $this->studentName,
			'student_login'    => $this->studentLogin,
			'student_password' => $this->studentPassword,
			'parent_login'     => $this->parentLogin,
			'parent_password'  => $this->parentPassword,
		);
	}
}
