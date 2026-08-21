<?php
/**
 * Экран экзамена станции КЕГЭ: таймер, боковой навигатор заданий, контент
 * текущего задания, панель ответа (текст/таблица). Задания отрендерены
 * сервером в скрытые панели (как .pstep плеера) — kege-exam.js только
 * переключает видимость и шлёт AJAX на уже существующие эндпоинты
 * (SaveAttemptAnswer/SubmitAttempt/GetAttemptResult — те же, что и attempt.php).
 *
 * `data-answer-shape="table"` (T15.10) — задания №25/№27 (см. buildTaskViews()
 * в AssessmentPageController, номер берётся из существующей таксономии
 * {key}_task_number, новых полей не заводит). Значения такой таблицы
 * сериализуются в единственную текстовую колонку answerText — авто-проверка
 * для этих двух заданий не выполняется (нет чекера под составной формат),
 * они всегда идут на ручную проверку преподавателем, как и раньше.
 *
 * @var \Inc\DTO\Assessment\AssessmentDTO $assessment
 * @var \Inc\DTO\Assessment\AttemptDTO    $activeAttempt
 * @var array<int, array{template: string, materials: array, taskNumber: int}> $taskViews
 * @var bool                              $previewMode Предпросмотр автора: экран отрендерен скрытым
 */
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Subject\TaskTemplate;
use Inc\Enums\Ui\Icon;
use Inc\Modules\EgeComputer\Config\KegeInstructionConfig;
use Inc\Modules\EgeComputer\Config\OgeInstructionConfig;

$isOge     = AssessmentKind::OgeComputer === $assessment->kind;
$taskCount = count( $assessment->taskIds );

// «Скачать все файлы» на вкладке «i»: сводный список материалов всей попытки,
// собранный из тех же taskViews, что рендерят панели заданий (ключ — URL, чтобы
// один и тот же файл, приложенный к нескольким заданиям, не дублировался).
$allMaterials = array();
foreach ( $taskViews as $view ) {
	foreach ( ( $view['materials'] ?? array() ) as $material ) {
		$allMaterials[ $material['url'] ] = $material;
	}
}
?>
<div class="kege-ex" id="kegeExam"<?php echo $previewMode ? ' hidden' : ''; ?>>
	<div class="kege-ex-head">
		<?php if ( $assessment->timeLimit > 0 ) : ?>
			<span class="kege-timer-chip" id="kegeTimer">—:—:—</span>
			<?php // Предпросмотр: таймер по умолчанию статичный (лимит без отсчёта) — ?>
			<?php // включатель нужен, чтобы автор мог проверить и сам отсчёт, и авто-сдачу по истечении времени. ?>
			<?php if ( $previewMode ) : ?>
				<button type="button" class="kege-head-link" id="kegePreviewTimerToggle">Запустить отсчёт</button>
			<?php endif; ?>
		<?php endif; ?>
		<span class="kege-kim-t" id="kegeHeadKim"></span>
		<span class="kege-kim-t" id="kegeHeadBr"></span>
		<span class="kege-hd-sp"></span>
		<button type="button" class="kege-head-link" id="kegeFinishEarly">Завершить экзамен досрочно</button>
	</div>

	<div class="kege-ex-body">
		<div class="kege-ex-side">
			<div class="kege-cnt-l">Дано ответов</div>
			<div class="kege-cnt-v"><b id="kegeCnt">0</b>/<?php echo esc_html( (string) $taskCount ); ?></div>
			<button type="button" class="kege-sq-arrow" id="kegeScrUp" aria-label="Прокрутить вверх"><?php echo Icon::ArrowUp->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
			<div class="kege-nums" id="kegeNums">
				<button type="button" class="kege-numb" data-kege-n="i">i</button>
				<?php foreach ( $assessment->taskIds as $i => $taskId ) : ?>
					<button type="button" class="kege-numb" data-kege-n="<?php echo esc_attr( (string) ( $i + 1 ) ); ?>"><?php echo esc_html( (string) ( $i + 1 ) ); ?></button>
				<?php endforeach; ?>
			</div>
			<button type="button" class="kege-sq-arrow" id="kegeScrDn" aria-label="Прокрутить вниз"><?php echo Icon::ArrowDown->svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
		</div>

		<div class="kege-ex-main">
			<div class="kege-task-tools">
				<button type="button" class="kege-tool-b" id="kegeFsUp" aria-label="Увеличить текст задания">A+</button>
				<button type="button" class="kege-tool-b" id="kegeFsDown" aria-label="Уменьшить текст задания">A−</button>
				<button type="button" class="kege-tool-b" id="kegeFsReset" aria-label="Обычный размер текста задания">A</button>
			</div>

			<div class="kege-task-scroll" id="kegeTaskScroll">

				<div class="kege-t-body" data-kege-panel="i">
					<div class="kege-t-content">
						<?php foreach ( ( $isOge ? OgeInstructionConfig::paragraphs() : KegeInstructionConfig::paragraphs() ) as $paragraph ) : ?>
							<p<?php echo $paragraph['indent'] ? ' class="kege-ind"' : ''; ?>><?php echo esc_html( $paragraph['text'] ); ?></p>
						<?php endforeach; ?>
					</div>
					<?php if ( ! empty( $allMaterials ) ) : ?>
						<button type="button" class="kege-dl-all" id="kegeDlAll" aria-expanded="false" aria-controls="kegeDlList">Скачать все файлы</button>
						<div class="kege-files kege-dl-list" id="kegeDlList" hidden>
							<?php foreach ( $allMaterials as $material ) : ?>
								<a class="kege-f-chip" href="<?php echo esc_url( $material['url'] ); ?>" target="_blank" rel="noopener noreferrer" download>
									<?php echo Icon::File->svg( 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<?php echo esc_html( $material['name'] ); ?>
								</a>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>

				<?php foreach ( $assessment->taskIds as $i => $taskId ) : ?>
					<?php
					$task = get_post( $taskId );
					if ( ! $task ) {
						continue;
					}
					$view    = $taskViews[ (int) $taskId ] ?? array(
						'template'   => '',
						'materials'  => array(),
						'taskNumber' => 0,
						'bankNumber' => '',
						'condition'  => '',
						'subparts'   => array(),
					);
					$subparts = is_array( $view['subparts'] ?? null ) ? $view['subparts'] : array();
					$isTriple = ! empty( $subparts );
					// Табличный ответ — особенность №25/№27 настоящего КЕГЭ; у ОГЭ таких
					// позиций нет вовсе, поэтому проверка гасится по kind.
					$isTable  = ! $isOge && ! $isTriple && in_array( $view['taskNumber'], array( 25, 27 ), true );
					// Задания 13-16 ОГЭ — только загрузка файла, без текстового поля (решено
					// с пользователем 2026-08-18): «Развёрнутый ответ» (14-16) и «Два условия
					// на выбор» (№13, консолидация 13.1/13.2 в один пост) — общий предикат
					// TaskTemplate::isFileAnswerShape().
					$isFile   = ! $isTriple && ! $isTable
						&& TaskTemplate::fromDatabase( (string) ( $view['template'] ?? '' ) )->isFileAnswerShape();
					$shape    = $isTriple ? 'triple' : ( $isTable ? 'table' : ( $isFile ? 'file' : 'text' ) );
					$n        = $i + 1;
					// Номер в банке — для всех заданий одинаково, включая составное:
					// диапазон «19–21» в скобках ничего не говорит о самой записи.
					// Номера в банке нет (легаси-задание с нечисловым слагом) — номер
					// задания из таксономии: пустая скобка хуже любого из двух номеров.
					$bankNumber = (string) ( $view['bankNumber'] ?? '' );
					$taskNumber = (int) ( $view['taskNumber'] ?? 0 );
					$headNum    = match ( true ) {
						'' !== $bankNumber => '№' . $bankNumber,
						$taskNumber > 0    => '№' . $taskNumber,
						default            => '',
					};
					?>
					<div class="kege-t-body"
						data-kege-panel="<?php echo esc_attr( (string) $n ); ?>"
						data-task-id="<?php echo esc_attr( (string) $taskId ); ?>"
						data-answer-shape="<?php echo esc_attr( $shape ); ?>"
						data-task-number="<?php echo esc_attr( (string) $view['taskNumber'] ); ?>"
						<?php echo $isTriple ? 'data-triple-subs="' . esc_attr( implode( ',', array_map( static fn( $s ) => (string) $s['key'], $subparts ) ) ) . '"' : ''; ?>
						hidden>
						<div class="kege-t-head">
							Задание <?php echo esc_html( (string) $n ); ?><?php echo '' !== $headNum ? ' (' . esc_html( $headNum ) . ')' : ''; ?>.
						</div>
						<div class="kege-t-content">
							<?php if ( $isTriple ) : ?>
								<?php // Запись отвечает за один номер блока 19-21 — метка подпункта ?>
								<?php // дублировала бы заголовок панели, поэтому только у блока целиком. ?>
								<?php foreach ( $subparts as $sub ) : ?>
									<div class="kege-t-subpart">
										<?php if ( count( $subparts ) > 1 ) : ?>
											<div class="kege-t-subpart-tag">Задание <?php echo esc_html( (string) $sub['number'] ); ?></div>
										<?php endif; ?>
										<?php echo wp_kses_post( $sub['condition'] ); ?>
									</div>
								<?php endforeach; ?>
							<?php else : ?>
								<?php echo wp_kses_post( $view['condition'] ); ?>
							<?php endif; ?>
						</div>
						<?php if ( ! empty( $view['materials'] ) ) : ?>
							<?php // Чипы файлов живут в нижней панели рядом с полем ответа (макет): ?>
							<?php // kege-exam.js клонирует этот блок в .kege-ex-bottom при показе панели. ?>
							<div class="kege-files" data-kege-files hidden>
								<?php foreach ( $view['materials'] as $material ) : ?>
									<a class="kege-f-chip" href="<?php echo esc_url( $material['url'] ); ?>" target="_blank" rel="noopener noreferrer" download>
										<?php echo Icon::File->svg( 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										<?php echo esc_html( $material['name'] ); ?>
									</a>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>

			</div>

			<button type="button" class="kege-nav-c kege-nav-c--left" id="kegeExPrev" aria-label="Предыдущее задание"><?php echo Icon::ChevronLeft->svg( 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
			<button type="button" class="kege-nav-c kege-nav-c--right" id="kegeExNext" aria-label="Следующее задание"><?php echo Icon::ChevronRight->svg( 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>

			<div class="kege-ex-bottom" id="kegeExBottom"></div>
		</div>

		<div class="kege-ans-panel" id="kegeAnsPanel" hidden></div>
	</div>
</div>
