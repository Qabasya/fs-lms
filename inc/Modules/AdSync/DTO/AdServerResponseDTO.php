<?php

declare( strict_types=1 );

namespace Inc\Modules\AdSync\DTO;

/**
 * Class AdServerResponseDTO
 *
 * Ответ сервера AdSync в офисе. `code = 0` — до сервера не достучались
 * (сеть, таймаут, отказ TLS): это простой офиса, а не ошибка задания.
 *
 * @package Inc\Modules\AdSync\DTO
 */
readonly class AdServerResponseDTO {

	/**
	 * @param array<string, mixed>|null $data Разобранный JSON ответа (null — не JSON)
	 */
	public function __construct(
		public int     $code,
		public ?array  $data,
		public string  $error = '',
		public float   $seconds = 0.0,
	) {}

	/** До сервера не достучались. */
	public function isUnreachable(): bool {
		return 0 === $this->code;
	}

	/** Сервер ответил, но недоступен для работы (5xx) или отверг подпись (401/403). */
	public function isServerSideProblem(): bool {
		return $this->code >= 500 || 401 === $this->code || 403 === $this->code;
	}

	public function isSuccess(): bool {
		return $this->code >= 200 && $this->code < 300;
	}

	/** Короткое описание для журнала очереди и админки. */
	public function describe(): string {
		if ( $this->isUnreachable() ) {
			return 'Сервер недоступен: ' . ( '' !== $this->error ? $this->error : 'нет ответа' );
		}
		$message = (string) ( $this->data['error'] ?? $this->data['detail'] ?? '' );

		return 'HTTP ' . $this->code . ( '' !== $message ? ': ' . $message : '' );
	}
}
