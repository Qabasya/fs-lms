<?php
/**
 * Лист ответов станции КЕГЭ — экран, который рендерится, когда активной попытки
 * нет, но есть последняя сданная ($lastAttempt). Показывает номера КИМ/бланка,
 * сводку баллов и построчный разбор: номер задания, балл, ответ ученика и
 * правильный ответ. Кнопка «Завершить экзамен» уводит со станции (kege-entry.js).
 *
 * Строка — позиция ответа, а не задание: №26 и №27 требуют по два ответа, и
 * 27 заданий работы дают 29 строк (см. KegeScaleConfig). Экран рассчитан на
 * один вид без прокрутки страницы — обе таблицы держат высоту тела (_finish.scss).
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

$kegeSheet = apply_filters( EgeComputerModule::SHEET_FILTER, null, $assessment, $lastAttempt, $taskViews );
if ( ! $kegeSheet instanceof KegeSheetDTO ) {
	$kegeSheet = KegeSheetDTO::blank();
}

// Две таблицы рядом (макет): первая половина строк — левая, остаток — правая.
$kegeHalf   = (int) ceil( $kegeSheet->total() / 2 );
$kegeTables = $kegeHalf > 0 ? array_chunk( $kegeSheet->rows, $kegeHalf ) : array();

/** Балл строки: «—», пока задание не оценено (ручная проверка или пропуск). */
$kegeScore = static fn( ?float $score ): string => null === $score ? '—' : (string) round( $score, 2 );
?>
<div class="kege-fin" id="kegeFinish" data-attempt-id="<?php echo esc_attr( (string) ( $lastAttempt->id ?? 0 ) ); ?>"<?php echo $previewMode ? ' hidden' : ''; ?>>
	<div class="kege-fin-head"><?php echo esc_html( $examTitle ); ?> · <b><?php echo esc_html( $assessment->title ); ?></b></div>

	<div class="kege-fin-body">
		<?php // Тренажёрные номера ритуала входа — подставляет kege-entry.js. ?>
		<div class="kege-fin-kim">
			<span id="kegeFinKim">КИМ № —</span>
			<span id="kegeFinBr">БР № —</span>
		</div>

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
									<td class="kege-fin-tbl__n"><?php echo esc_html( $kegeRow['number'] ); ?></td>
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

		<button type="button" class="kege-btn kege-btn--cyan kege-fin-done" id="kegeFinishBtn">Завершить экзамен</button>
	</div>
</div>