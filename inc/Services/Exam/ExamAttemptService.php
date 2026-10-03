<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Exam\AttemptContext;
use Inc\Enums\Auth\ErrorCode;
use Inc\Shared\CodedException;

/**
 * Управление попытками экзамена: старт, продолжение, сохранение, сдача (6.1–6.2).
 */
class ExamAttemptService {

	/**
	 * Контекст участника для старта попытки (6.1.4).
	 */
	public function contextForStudent( int $wpUserId, int $registrationId ): AttemptContext {
		throw new CodedException( ErrorCode::ExamAccess, 'Экзамен недоступен.' );
	}

	/**
	 * Старт попытки экзамена (6.1.5).
	 */
	public function start( AttemptContext $ctx ): AttemptDTO {
		throw new CodedException( ErrorCode::ExamAccess, 'Экзамен недоступен.' );
	}
}
