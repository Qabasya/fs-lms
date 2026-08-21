<?php

declare( strict_types=1 );

namespace Inc\Services\Assessment;

use Inc\DTO\Assessment\AssessmentDTO;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Assessment\AttemptStatus;

/**
 * Class AttemptRevealPolicy
 *
 * D18: правильные ответы и критериальные баллы станций КЕГЭ/ОГЭ скрыты от ученика
 * до подтверждения учителем — гейт разный для двух видов станций:
 *
 *  - **ОГЭ**: подтверждение — сама ручная проверка заданий 13-16. Отдельного
 *    действия «утвердить» не требуется: `AttemptStatus::Graded` наступает
 *    автоматически, как только оценено последнее из четырёх заданий
 *    ({@see \Inc\Services\Assessment\AutoGradeService::finalize()}).
 *  - **ЕГЭ**: все задания автопроверяемые, `Graded` наступает сразу при сдаче
 *    (`hasManual = false` с самого начала) — этот статус НЕ говорит «учитель
 *    посмотрел», поэтому нужен отдельный явный шаг: кнопка «Утвердить работу»
 *    в разделе «Сводка по ученику», пишет `assessment_attempts.approved_at`
 *    ({@see \Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository::approve()}).
 *
 * `Control` ответы ученику не отдаёт вообще ({@see AttemptResultService}) —
 * эта политика его не касается, метод для него всегда возвращает `true`.
 *
 * @package Inc\Services\Assessment
 */
class AttemptRevealPolicy {

	/** Можно ли показывать ученику правильные ответы/критериальные баллы этой попытки. */
	public function isRevealed( AssessmentDTO $assessment, AttemptDTO $attempt ): bool {
		return match ( $assessment->kind ) {
			AssessmentKind::OgeComputer => AttemptStatus::Graded === $attempt->status,
			AssessmentKind::EgeComputer => $attempt->isApproved(),
			AssessmentKind::Control     => true,
		};
	}
}
