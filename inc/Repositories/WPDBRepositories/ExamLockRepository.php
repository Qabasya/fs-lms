<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

/**
 * Именованные блокировки MySQL/MariaDB (`GET_LOCK`) для фоновых тиков экзаменов: два одновременных
 * запуска одного тика (cron + ручной `wp fs-lms exam tick`, два процесса cron) не должны работать параллельно.
 *
 * Блокировка живёт в соединении с базой и сама пропадает вместе с ним, поэтому «зависший» тик
 * не может заблокировать следующие навсегда.
 */
class ExamLockRepository extends AbstractExamRepository {

	/** @return bool true — блокировка взята; false — её уже держит другое соединение. */
	public function acquire( string $name, int $timeout = 0 ): bool {
		return 1 === $this->readInt( $this->wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', $this->lockName( $name ), max( 0, $timeout ) ) );
	}

	public function release( string $name ): void {
		$this->readInt( $this->wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $this->lockName( $name ) ) );
	}

	/** Не допускает неявный COMMIT внешней транзакции при сохранении варианта. */
	public function assertNoActiveTransaction(): void {
		$value = $this->wpdb->get_var( 'SELECT @@session.in_transaction' );
		if ( null === $value || '' !== (string) $this->wpdb->last_error ) {
			throw new \RuntimeException( 'Не удалось проверить состояние транзакции базы данных.' );
		}
		if ( 0 !== (int) $value ) {
			throw new \RuntimeException( 'Вариант нельзя сохранять внутри другой транзакции.' );
		}
	}

	/** Имя с префиксом базы: несколько сайтов на одном сервере баз данных не делят блокировки. */
	private function lockName( string $name ): string {
		return $this->wpdb->prefix . $name;
	}
}
