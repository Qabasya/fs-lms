<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Services\Assessment\AttemptRegradeService;
use Inc\Shared\PluginLogger;

/**
 * Class AttemptRegradeMigration
 *
 * Пересчёт автоматически проверяемых заданий уже сданных попыток станции после исправления проверки: табличные ответы
 * №17–27 (`|` между ячейками не засчитывался), дочернее №19 связки 19–21 (шаблон брался по номеру, а не свой), ручное
 * задание без ответа (теперь сразу 0). Оценки преподавателя и ручные задания с ответом не меняются
 * ({@see \Inc\Services\Assessment\AutoGradeService::regradeAutomatic()}).
 *
 * Запускается на `init` (приоритет 99), а не при загрузке плагина: пересчёт читает термы номеров заданий, а таксономии предметов
 * регистрируются на `init`. Data-миграция, version-gated опцией и **порционная**: за одну загрузку страницы обрабатывается не больше {@see self::BATCH}
 * попыток, остальные — на следующих загрузках, пока не кончатся (иначе большая база упёрлась бы в таймаут запроса).
 * Состояние — опция `fs_lms_attempt_regrade_state` (`last_id`, `done`, накопленные счётчики).
 *
 * @package Inc\Migrations
 */
class AttemptRegradeMigration {

	private const STATE_OPTION = 'fs_lms_attempt_regrade_state';

	/** Версия правил пересчёта: новая версия запускает обход заново. */
	private const VERSION = '1';

	/** Попыток за один запуск. */
	private const BATCH = 25;

	public function __construct( private readonly AttemptRegradeService $service ) {}

	public function run(): void {
		$state = get_option( self::STATE_OPTION );
		$state = is_array( $state ) && ( $state['version'] ?? '' ) === self::VERSION
			? $state
			: array( 'version' => self::VERSION, 'last_id' => 0, 'done' => false, 'attempts' => 0, 'answers' => 0 );

		if ( ! empty( $state['done'] ) ) {
			return;
		}

		$result = $this->service->runBatch( (int) $state['last_id'], self::BATCH );

		$state['last_id']   = $result['last_id'];
		$state['attempts'] += $result['attempts'];
		$state['answers']  += $result['answers'];
		if ( 0 === $result['scanned'] ) {
			$state['done'] = true;
			PluginLogger::warning( 'AttemptRegrade', 'Пересчёт сданных попыток завершён', array( 'attempts' => $state['attempts'], 'answers' => $state['answers'] ) );
		}

		update_option( self::STATE_OPTION, $state, false );
	}
}
