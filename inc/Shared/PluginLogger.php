<?php

declare( strict_types=1 );

namespace Inc\Shared;

/**
 * Централизованное логирование плагина.
 *
 * Формат записи: [FS LMS] CONTEXT: message | Context: {json}
 *
 * debug()   — только при WP_DEBUG = true
 * warning() — всегда (для операционных предупреждений в production)
 * exception() — удобная обёртка над debug/warning для Throwable
 */
final class PluginLogger {

	public static function debug( string $context, string $message, array $data = array() ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		self::write( $context, $message, $data );
	}

	public static function warning( string $context, string $message, array $data = array() ): void {
		self::write( $context, $message, $data );
	}

	public static function exception( string $context, \Throwable $e, array $extra = array(), bool $always = false ): void {
		$data = array_merge(
			array(
				'file'  => $e->getFile(),
				'line'  => $e->getLine(),
				'trace' => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? $e->getTraceAsString() : null,
			),
			$extra
		);

		if ( $always ) {
			self::warning( $context, $e->getMessage(), $data );
		} else {
			self::debug( $context, $e->getMessage(), $data );
		}
	}

	private static function write( string $context, string $message, array $data ): void {
		$payload = array_merge(
			array(
				'timestamp' => current_time( 'mysql' ),
				'user_id'   => get_current_user_id(),
				'ip'        => $_SERVER['REMOTE_ADDR'] ?? 'unknown', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			),
			$data
		);

		// Убираем null-значения (например, trace вне WP_DEBUG)
		$payload = array_filter( $payload, static fn( $v ) => $v !== null );

		error_log( sprintf(
			'[FS LMS] %s: %s | Context: %s',
			strtoupper( $context ),
			$message,
			json_encode( $payload, JSON_UNESCAPED_UNICODE )
		) );
	}
}
