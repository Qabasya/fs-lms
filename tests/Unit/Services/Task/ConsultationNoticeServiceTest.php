<?php

declare( strict_types=1 );

namespace Unit\Services\Task;

use Inc\Enums\Subject\TaskTemplate;
use Inc\Services\Shared\PluginConfig;
use Inc\Services\Task\ConsultationNoticeService;
use PHPUnit\Framework\TestCase;

/**
 * Приглашение на консультацию показывается вместо правильного ответа —
 * только у заданий, которые проверяет преподаватель (ОГЭ №13–15 и т.п.).
 */
class ConsultationNoticeServiceTest extends TestCase {

	private function service( string $url = 'https://example.com/#hero-form' ): ConsultationNoticeService {
		$config = $this->createStub( PluginConfig::class );
		$config->method( 'consultationUrl' )->willReturn( $url );

		return new ConsultationNoticeService( $config );
	}

	public function test_manual_check_task_gets_notice(): void {
		$notice = $this->service()->forTask( TaskTemplate::FileAnswer, '' );

		self::assertNotNull( $notice );
		self::assertSame( 'https://example.com/#hero-form', $notice['url'] );
		self::assertSame( 'Записаться', $notice['label'] );
		self::assertStringContainsString( 'консультацию', $notice['text'] );
	}

	public function test_alternative_conditions_task_gets_notice(): void {
		self::assertNotNull( $this->service()->forTask( TaskTemplate::AlternativeConditions, '' ) );
	}

	public function test_task_with_regular_answer_keeps_its_answer(): void {
		// У обычного задания ответ есть — приглашение его не подменяет.
		self::assertNull( $this->service()->forTask( TaskTemplate::Standard, '42' ) );
	}

	public function test_manual_check_task_with_filled_answer_shows_answer(): void {
		// Автор всё же заполнил ответ у задания с ручной проверкой — показываем его.
		self::assertNull( $this->service()->forTask( TaskTemplate::FileAnswer, '42' ) );
	}

	public function test_whitespace_answer_counts_as_empty(): void {
		self::assertNotNull( $this->service()->forTask( TaskTemplate::FileAnswer, "  \n" ) );
	}

	public function test_without_configured_url_there_is_no_notice(): void {
		// Ссылка не настроена — поведение прежнее: блока ответа нет вовсе.
		self::assertNull( $this->service( '' )->forTask( TaskTemplate::FileAnswer, '' ) );
	}
}
