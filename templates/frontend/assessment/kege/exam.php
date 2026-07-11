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
 */
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$taskCount = count( $assessment->taskIds );
?>
<div class="kege-ex" id="kegeExam">
	<div class="kege-ex-head">
		<?php if ( $assessment->timeLimit > 0 ) : ?>
			<span class="kege-timer-chip" id="kegeTimer">—:—:—</span>
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
			<button type="button" class="kege-sq-arrow" id="kegeScrUp" aria-label="Прокрутить вверх">▲</button>
			<div class="kege-nums" id="kegeNums">
				<button type="button" class="kege-numb" data-kege-n="i">i</button>
				<?php foreach ( $assessment->taskIds as $i => $taskId ) : ?>
					<button type="button" class="kege-numb" data-kege-n="<?php echo esc_attr( (string) ( $i + 1 ) ); ?>"><?php echo esc_html( (string) ( $i + 1 ) ); ?></button>
				<?php endforeach; ?>
			</div>
			<button type="button" class="kege-sq-arrow" id="kegeScrDn" aria-label="Прокрутить вниз">▼</button>
		</div>

		<div class="kege-ex-main">
			<div class="kege-task-scroll" id="kegeTaskScroll">

				<div class="kege-t-body" data-kege-panel="i">
					<p>Инструкция к заданиям КИМ доступна на этой странице. В условиях заданий используются
					   обозначения логических операций (¬ — отрицание, ∧ — конъюнкция, ∨ — дизъюнкция,
					   → — следование, ≡ — тождество). Обозначения Кбайт/Мбайт — в традиционном для
					   информатики смысле (соотношение с байтом — степень двойки).</p>
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
						'condition'  => '',
						'subparts'   => array(),
					);
					$subparts = is_array( $view['subparts'] ?? null ) ? $view['subparts'] : array();
					$isTriple = ! empty( $subparts );
					$isTable  = ! $isTriple && in_array( $view['taskNumber'], array( 25, 27 ), true );
					$shape    = $isTriple ? 'triple' : ( $isTable ? 'table' : 'text' );
					$n        = $i + 1;
					// Задача 3: составное — заголовок показывает диапазон номеров (19–21),
					// ключи под-ответов уходят в JS для сбора JSON на родительский task_id.
					$headNum  = $isTriple
						? ( (int) $subparts[0]['number'] . '–' . (int) $subparts[ count( $subparts ) - 1 ]['number'] )
						: ( $view['taskNumber'] > 0 ? '№' . (int) $view['taskNumber'] : '' );
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
								<?php foreach ( $subparts as $sub ) : ?>
									<div class="kege-t-subpart">
										<div class="kege-t-subpart-tag">Задание <?php echo esc_html( (string) $sub['number'] ); ?></div>
										<?php echo wp_kses_post( $sub['condition'] ); ?>
									</div>
								<?php endforeach; ?>
							<?php else : ?>
								<?php echo wp_kses_post( $view['condition'] ); ?>
							<?php endif; ?>
						</div>
						<?php if ( ! empty( $view['materials'] ) ) : ?>
							<div class="kege-t-materials">
								<?php foreach ( $view['materials'] as $material ) : ?>
									<a class="kege-f-chip" href="<?php echo esc_url( $material['url'] ); ?>" target="_blank" rel="noopener noreferrer">
										📎 <?php echo esc_html( $material['name'] ); ?>
									</a>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>

			</div>

			<button type="button" class="kege-nav-c kege-nav-c--left" id="kegeExPrev">‹</button>
			<button type="button" class="kege-nav-c kege-nav-c--right" id="kegeExNext">›</button>

			<div class="kege-ex-bottom" id="kegeExBottom"></div>
		</div>

		<div class="kege-ans-panel" id="kegeAnsPanel" hidden></div>
	</div>
</div>
