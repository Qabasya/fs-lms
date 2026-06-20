<?php

declare( strict_types=1 );

namespace Inc\Services\Import;

use Inc\Enums\Enrollment\EnrollmentStatus;
use Inc\Enums\Enrollment\ExpulsionReasons;

/**
 * Class ExpulsionResolver
 *
 * Резолвит колонку «Причина отчисления» из CSV в статус записи и
 * каноническое значение причины.
 *
 * ### Правила
 *
 * - Пустая строка → `null` (запись остаётся `active`, без отчисления).
 * - Совпало (trim + регистронезависимо) с одной из {@see ExpulsionReasons} →
 *   каноническое значение + маппинг статуса:
 *   `Окончание курса` → `finished`, `Перевод` → `transferred`,
 *   `По собственному желанию` / `Другое` → `expelled`.
 * - Уже начинается с префикса `Другое:` (свободный ввод) → хранится как есть,
 *   статус `expelled` (зеркало логики `ExpulsionCallbacks`).
 * - Любой другой непустой текст → `"Другое: <текст>"`, статус `expelled`.
 */
readonly class ExpulsionResolver {

	/**
	 * Резолвит сырое значение причины.
	 *
	 * @param string $rawReason Значение колонки «Причина отчисления»
	 *
	 * @return array{status: EnrollmentStatus, reason: string}|null
	 *               null — отчисления нет (active); иначе статус + причина
	 */
	public function resolve( string $rawReason ): ?array {
		$trimmed = trim( $rawReason );

		if ( '' === $trimmed ) {
			return null;
		}

		// 1. Совпадение с зашитой причиной (регистронезависимо)
		foreach ( ExpulsionReasons::cases() as $case ) {
			if ( mb_strtolower( $trimmed ) === mb_strtolower( $case->value ) ) {
				return array(
					'status' => $this->statusFor( $case ),
					'reason' => $case->value,
				);
			}
		}

		// 2. Уже оформлено как свободный ввод "Другое: ..."
		$otherPrefix = ExpulsionReasons::Other->value . ':';
		if ( str_starts_with( $trimmed, $otherPrefix ) ) {
			return array(
				'status' => EnrollmentStatus::Expelled,
				'reason' => $trimmed,
			);
		}

		// 3. Произвольный текст → свободный ввод "Другое: <текст>"
		return array(
			'status' => EnrollmentStatus::Expelled,
			'reason' => ExpulsionReasons::Other->value . ': ' . $trimmed,
		);
	}

	/**
	 * Сопоставляет зашитую причину со статусом записи.
	 *
	 * @param ExpulsionReasons $reason Причина отчисления
	 *
	 * @return EnrollmentStatus
	 */
	private function statusFor( ExpulsionReasons $reason ): EnrollmentStatus {
		return match ( $reason ) {
			ExpulsionReasons::End      => EnrollmentStatus::Finished,
			ExpulsionReasons::Transfer => EnrollmentStatus::Transferred,
			default                    => EnrollmentStatus::Expelled,
		};
	}
}
