<?php

declare( strict_types=1 );

namespace Inc\DTO;

/**
 * Class ApplicationInputDTO
 *
 * Входные данные из публичной формы ученика (/lms/apply).
 *
 * @package Inc\DTO
 *
 * ### Основные обязанности:
 *
 * 1. **Типобезопасная передача** — инкапсулирует данные, отправленные учеником при создании заявки.
 *
 * ### Архитектурная роль:
 *
 * Передаётся в ApplicationService::createApplication() для создания новой заявки.
 * Все поля уже санитизированы через Sanitizer trait до создания DTO.
 *
 * ### Примечания:
 *
 * - OTP-код (otpCode) верифицируется через EmailOtpService перед созданием заявки.
 *   Капча проверяется на предыдущем шаге (ajaxSendOtpCode) и здесь не передаётся.
 * - IP и User-Agent фиксируются для аудита и защиты от спама.
 */
readonly class ApplicationInputDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param string $fullName        Полное имя ученика (Фамилия Имя Отчество)
	 * @param string $email           Email ученика (будет использован для связи)
	 * @param string $school          Школа или учебное заведение
	 * @param int    $grade           Класс обучения (1-11)
	 * @param string $birthDate       Дата рождения в формате Y-m-d
	 * @param string $otpCode         6-значный код подтверждения email (шаг B двухэтапной формы)
	 * @param string $ip              IP-адрес отправителя (для аудита)
	 * @param string $userAgent       User-Agent браузера (для аудита)
	 */
	public function __construct(
		public string $fullName,
		public string $email,
		public string $school,
		public int    $grade,
		public string $birthDate,
		public string $otpCode,
		public string $ip,
		public string $userAgent,
	) {}
}