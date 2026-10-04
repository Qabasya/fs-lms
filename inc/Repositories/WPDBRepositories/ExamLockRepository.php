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
	public function acquire( string $name ): bool {
		return 1 === $this->readInt( $this->wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', $this->lockName( $name ) ) );
	}

	public function release( string $name ): void {
		$this->readInt( $this->wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $this->lockName( $name ) ) );
	}

	/** Имя с префиксом базы: несколько сайтов на одном сервере баз данных не делят блокировки. */
	private function lockName( string $name ): string {
		return $this->wpdb->prefix . $name;
	}
}
