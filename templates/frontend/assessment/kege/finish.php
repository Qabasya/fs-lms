<?php
/**
 * Лист ответов станции КЕГЭ — экран, который рендерится, когда активной попытки
 * нет, но есть последняя сданная ($lastAttempt). Показывает номера КИМ/бланка,
 * сводку баллов и построчный разбор: номер задания, балл, ответ ученика и
 * правильный ответ. Кнопка «Завершить экзамен» уводит со станции (kege-entry.js).
 *
 * Строка — задание работы: №26 и №27 стоят одной строкой (два числа ответа в одной
 * ячейке, до двух баллов); повторы одного типа — отдельными строками, их балл
 * «схлопнут» в единицу зачёта (ScoringUnits), так что максимум остаётся 29. Работа
 * может быть любой длины: первая таблица заполняется до KegeSheetDTO::ROWS_PER_TABLE
 * строк, остаток идёт в следующую. Экран рассчитан на один вид без прокрутки страницы
 * (_finish.scss).
 *
 * Данные листа собирает модуль (сервису с репозиториями шаблон напрямую не
 * доступен) и отдаёт фильтром EgeComputerModule::SHEET_FILTER.
 *
 * @var \Inc\DTO\Assessment\AssessmentDTO   $assessment
 * @var \Inc\DTO\Assessment\AttemptDTO|null $lastAttempt Null только в предпросмотре автора
 * @var array<int, array{template: string, materials: array, taskNumber: int}> $taskViews
 * @var bool                                $previewMode Предпросмотр автора: экран скрыт
 *
 * Предпросмотр (T15.10-preview): страница не перезагружается и попытки в БД
 * нет, поэтому здесь рендерится пустой лист (KegeSheetDTO::blank() — фильтр
 * получает $lastAttempt === null и ничего не находит по нему), а реальные
 * значения после «Завершить экзамен» подставляет kege-entry.js поверх этой же
 * разметки (id-хуки ниже) — см. PreviewResultCallbacks.
 */
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Inc\Enums\Assessment\AssessmentKind;
use Inc\Modules\EgeComputer\DTO\KegeSheetDTO;
use Inc\Modules\EgeComputer\EgeComputerModule;

$examTitle = AssessmentKind::OgeComputer === $assessment->kind
	? 'Основной государственный экзамен'
	: 'Единый государственный экзамен';

// Официальная попытка экзамена: выход ведёт в «Мои экзамены» (backUrl задаёт контроллер страницы).
$isExamAttempt = null !== $lastAttempt && $lastAttempt->isExam();

$kegeSheet = apply_filters( EgeComputerModule::SHEET_FILTER, null, $assessment, $lastAttempt, $taskViews, ! empty( $reviewReveal ) );
if ( ! $kegeSheet instanceof KegeSheetDTO ) {
	$kegeSheet = KegeSheetDTO::blank();
}

// Таблицы рядом (макет): по ROWS_PER_TABLE строк, число таблиц — по длине работы.
$kegeTables = $kegeSheet->tables();

/** Балл строки: «—», пока задание не оценено (ручная проверка или пропуск). */
$kegeScore = static fn( ?float $score ): string => null === $score ? '—' : (string) round( $score, 2 );
?>
<?php
// Просмотр чужой работы (`?attempt=ID`) тем, кто управляет группой: чья работа и что видит ученик.
// Ученику, открывшему свою попытку, плашки нет.
$kegeReviewBar = '';
if ( ! empty( $reviewMode ) && ! empty( $reviewReveal ) && $lastAttempt && ! empty( $person ) ) {
	$kegeReviewBar = 'Работа ученика: ' . $person->fullName();
	if ( $lastAttempt->submittedAt ) {
		$kegeReviewBar .= ' · сдана ' . mysql2date( 'd.m.Y H:i', $lastAttempt->submittedAt );
	}
	if ( AssessmentKind::EgeComputer === $assessment->kind && ! $lastAttempt->isApproved() ) {
		$kegeReviewBar .= ' · ученик пока видит «На проверке» — результат откроется после «Утвердить работу»';
	}
}
?>
<div class="kege-fin" id="kegeFinish" data-attempt-id="<?php echo esc_attr( (string) ( $lastAttempt->id ?? 0 ) ); ?>"<?php echo ( $previewMode || $publicMode ) ? ' hidden' : ''; ?>>
	<?php if ( '' !== $kegeReviewBar ) : ?>
		<div class="kege-fin-review"><?php echo esc_html( $kegeReviewBar ); ?></div>
	<?php endif; ?>
	<div class="kege-fin-head"><?php echo esc_html( $examTitle ); ?> · <b><?php echo esc_html( $assessment->title ); ?></b></div>

	<div class="kege-fin-body">
		<?php // Тренажёрные номера ритуала входа — подставляет kege-entry.js. ?>
		<?php if ( ! $assessment->hideIntro ) : ?>
			<div class="kege-fin-kim">
				<span id="kegeFinKim">КИМ № —</span>
				<span id="kegeFinBr">БР № —</span>
			</div>
		<?php endif; ?>

		<?php if ( ! $kegeSheet->revealed ) : ?>
			<?php
			// D18: результаты видны ученику только после подтверждения учителем —
			// для ОГЭ это факт полной ручной проверки заданий 13-16 (AttemptStatus::Graded),
			// для ЕГЭ — отдельная кнопка «Утвердить работу» в «Сводке по ученику».
			// KegeResultSheetService уже зачистил rows/баллы — этот блок ничего
			// чувствительного не получает даже потенциально.
			?>
			<div class="kege-fin-pending">
				<div class="kege-fin-pending__title">Работа сдана и обрабатывается</div>
				<p class="kege-fin-pending__text">
					<?php if ( $isExamAttempt ) : ?>
						<?php // Официальный экзамен: и ЕГЭ, и ОГЭ раскрывает только явное утверждение (README §8, п. 9). ?>
						Работа сдана и ожидает утверждения преподавателем.
					<?php elseif ( AssessmentKind::OgeComputer === $assessment->kind ) : ?>
						Задания 13-16 проверяются вручную. Ответы и баллы появятся здесь, как только
						преподаватель завершит проверку.
					<?php else : ?>
						Результаты появятся здесь после того, как преподаватель утвердит работу.
					<?php endif; ?>
				</p>
			</div>
		<?php else : ?>
			<div class="kege-fin-cnt" id="kegeFinCnt">
				Дано ответов <b><?php echo esc_html( (string) $kegeSheet->answered ); ?>/<?php echo esc_html( (string) $kegeSheet->total() ); ?></b>
			</div>

			<div class="kege-fin-grid">
				<div class="kege-fin-score">
					<div class="kege-fin-score__lbl">Результаты экзамена</div>
					<?php if ( null !== $kegeSheet->secondary ) : ?>
						<div class="kege-fin-score__val" id="kegeFinScoreVal"><?php echo esc_html( $kegeSheet->secondary . '/' . $kegeSheet->secondaryMax ); ?></div>
						<div class="kege-fin-score__sub" id="kegeFinScoreSub">Первичный балл: <?php echo esc_html( round( $kegeSheet->primary, 2 ) . '/' . round( $kegeSheet->primaryMax, 2 ) ); ?></div>
					<?php else : ?>
						<?php // Таблицы перевода у работы нет — вторичный балл считать не из чего. ?>
						<div class="kege-fin-score__val" id="kegeFinScoreVal"><?php echo esc_html( round( $kegeSheet->primary, 2 ) . '/' . round( $kegeSheet->primaryMax, 2 ) ); ?></div>
						<div class="kege-fin-score__sub" id="kegeFinScoreSub">Первичный балл</div>
					<?php endif; ?>
				</div>

				<?php // Предпросмотр (T15.10-preview) перестраивает содержимое целиком (kege-entry.js) — ?>
				<?php // id на контейнере, разметка строк ниже ему не нужна, только начальный (пустой) вид. ?>
				<div class="kege-fin-tables" id="kegeFinTables">
					<?php foreach ( $kegeTables as $kegeChunk ) : ?>
						<table class="kege-fin-tbl">
							<thead>
								<tr>
									<th>№</th>
									<th>Балл</th>
									<th>Ваш<br>ответ</th>
									<th>Правильный<br>ответ</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $kegeChunk as $kegeRow ) : ?>
									<tr>
										<?php // Разметка ячейки в одну строку: у таблицы white-space: pre-line, любой перенос стал бы пустой строкой. ?>
										<td class="kege-fin-tbl__n"><?php echo '' !== ( $kegeRow['url'] ?? '' ) ? '<a href="' . esc_url( $kegeRow['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $kegeRow['number'] ) . '</a>' : esc_html( $kegeRow['number'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
										<td><?php echo esc_html( $kegeScore( $kegeRow['score'] ) ); ?></td>
										<td class="kege-fin-tbl__ans"><?php echo esc_html( $kegeRow['answer'] ); ?></td>
										<td class="kege-fin-tbl__ans"><?php echo esc_html( $kegeRow['correct'] ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endif; ?>

		<button type="button" class="kege-btn kege-btn--cyan kege-fin-done" id="kegeFinishBtn"><?php echo $isExamAttempt ? 'К моим экзаменам' : 'Выход'; ?></button>
	</div>
</div>