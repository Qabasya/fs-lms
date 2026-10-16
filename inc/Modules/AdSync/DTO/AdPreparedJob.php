<?php
/**
 * Результат подготовки задания AD. Пароль существует только в payload в памяти.
 *
 * @package Inc\Modules\AdSync\DTO
 */

declare( strict_types=1 );

namespace Inc\Modules\AdSync\DTO;

/** Отделяет устаревшее задание от ошибок данных действующей учётки. */
readonly class AdPreparedJob {
	/**
	 * Создаёт результат подготовки; payload не подлежит журналированию.
	 *
	 * @param array<string, mixed>|null $payload Тело запроса.
	 */
	private function __construct( public ?array $payload, public ?string $error, public bool $obsolete ) {}

	/** Готовое задание; данные живут только на время отправки. */
	public static function ready( array $payload ): self {
		return new self( $payload, null, false ); }

	/** Данные отсутствуют или повреждены; ошибка остаётся видимой администратору. */
	public static function invalid( string $reason ): self {
		return new self( null, $reason, false ); }

	/** Источник удалён или действие уже не требуется — повторять нечего. */
	public static function obsolete( string $reason ): self {
		return new self( null, $reason, true ); }
}
