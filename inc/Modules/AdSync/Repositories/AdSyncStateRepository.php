<?php

declare( strict_types=1 );

namespace Inc\Modules\AdSync\Repositories;

/**
 * Class AdSyncStateRepository
 *
 * Рабочее состояние доставки в офис (не настройки): последняя успешная доставка,
 * серия неудачных подключений и пауза до следующей попытки, итог последней сверки.
 * Своя опция модуля `fs_lms_ad_sync_state` (вне core OptionName — изоляция),
 * без автозагрузки: читается только доставкой и страницей настроек.
 *
 * Здесь же — блокировка доставки: `add_option()` атомарен (INSERT по уникальному
 * ключу), поэтому два процесса (cron и отправка после заявки) не возьмут одно
 * задание дважды. Зависшая блокировка (процесс упал) снимается по сроку.
 *
 * @package Inc\Modules\AdSync\Repositories
 */
class AdSyncStateRepository {

	private const string OPTION = 'fs_lms_ad_sync_state';
	private const string LOCK   = 'fs_lms_ad_sync_lock';

	/** Срок блокировки: пачка заданий по 5 с таймаута укладывается с запасом. */
	private const int LOCK_TTL = 180;

	/** @return array<string, mixed> */
	public function get(): array {
		$state = get_option( self::OPTION, array() );

		return is_array( $state ) ? $state : array();
	}

	/** @param array<string, mixed> $partial */
	public function update( array $partial ): void {
		update_option( self::OPTION, array_merge( $this->get(), $partial ), false );
	}

	/** Сервер ответил — серия простоя обнулена, пауза снята. */
	public function markReachable( bool $delivered ): void {
		$partial = array( 'outage_streak' => 0, 'paused_until' => 0, 'last_error' => '' );
		if ( $delivered ) {
			$partial['last_delivery_at'] = time();
		}
		$this->update( $partial );
	}

	/**
	 * Сервер недоступен — пауза с нарастающим шагом: 1м, 2м, 4м … ≤ 1 ч. Задания при
	 * этом не тратят попыток: простой офиса (свет, интернет) не делает их «мёртвыми».
	 */
	public function markUnreachable( string $error ): int {
		$streak = (int) ( $this->get()['outage_streak'] ?? 0 ) + 1;
		$pause  = (int) min( 3600, 60 * ( 2 ** ( $streak - 1 ) ) );

		$this->update( array(
			'outage_streak' => $streak,
			'paused_until'  => time() + $pause,
			'last_error'    => mb_substr( $error, 0, 500 ),
		) );

		return $pause;
	}

	/** До какого времени доставка отложена из-за простоя (0 — не отложена). */
	public function pausedUntil(): int {
		$until = (int) ( $this->get()['paused_until'] ?? 0 );

		return $until > time() ? $until : 0;
	}

	public function acquireLock(): bool {
		if ( add_option( self::LOCK, (string) ( time() + self::LOCK_TTL ), '', false ) ) {
			return true;
		}

		// Процесс, взявший блокировку, упал — снимаем по сроку и пробуем один раз.
		if ( (int) get_option( self::LOCK, 0 ) < time() ) {
			delete_option( self::LOCK );

			return add_option( self::LOCK, (string) ( time() + self::LOCK_TTL ), '', false );
		}

		return false;
	}

	public function releaseLock(): void {
		delete_option( self::LOCK );
	}
}
