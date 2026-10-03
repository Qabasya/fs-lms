<?php

declare(strict_types=1);

namespace Unit\DTO\Log;

use Inc\DTO\Log\AuthLogDTO;
use Inc\Enums\Auth\AuthAction;
use PHPUnit\Framework\TestCase;

class AuthLogDTOTest extends TestCase {
	public function test_apply_failure_reason_is_visible_and_form_name_is_correct(): void {
		$row = new AuthLogDTO(
			id: 1,
			loginIdentifier: null,
			action: AuthAction::ApplySubmitFailed->value,
			result: 'failure',
			actorIp: '127.0.0.1',
			actorUa: null,
			createdAt: '2026-10-03 15:48:00',
			details: array(
				'form'           => 'apply',
				'failure_reason' => 'Защитная метка формы устарела',
				'note'           => 'этап: данные',
			)
		);

		self::assertSame( 'Защитная метка формы устарела', $row->reasonLabel() );
		self::assertContains( 'Форма: /apply/', $row->detailLines() );
	}

	public function test_old_apply_failure_note_is_shown_in_reason_column(): void {
		$row = new AuthLogDTO(
			id: 2,
			loginIdentifier: null,
			action: AuthAction::ApplySubmitFailed->value,
			result: 'failed',
			actorIp: '127.0.0.1',
			actorUa: null,
			createdAt: '2026-10-03 15:48:00',
			details: array( 'note' => 'ответ: «Не удалось подтвердить отправку формы.» · попытка №1 · этап: данные' )
		);

		self::assertSame( 'Не удалось подтвердить отправку формы.', $row->reasonLabel() );
	}
}
