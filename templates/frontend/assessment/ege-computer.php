<?php
/**
 * Станция КЕГЭ (Компьютерный ЕГЭ) — Inc\Modules\EgeComputer (T15.10).
 *
 * Bare-документ: свой <html>, без темы сайта и без générique-шелла Эпика 15
 * (attempt-shell-header/footer.php) — по образцу lesson-player/player.php.
 * Переиспользует токены плеера через src/scss/kege/ (@use '../player/variables'),
 * свой изолированный бандл kege.min.css/js (Enqueue::enqueue_kege_assets(),
 * fs_lms_is_kege_route).
 *
 * Ритуал станции (вход/инструкция/регистрация/активация) существует только
 * на клиенте (localStorage) — бэкенду ничего не известно до реального вызова
 * StartAttempt. Экзамен и результат — реальные AttemptDTO/AJAX, как в attempt.php.
 *
 * @var \Inc\DTO\Assessment\AssessmentDTO      $assessment
 * @var \Inc\DTO\Assessment\AttemptDTO|null    $activeAttempt
 * @var \Inc\DTO\Assessment\AttemptDTO|null    $lastAttempt
 * @var array<int, mixed>                      $resultPerTask
 * @var \Inc\DTO\Person\PersonDTO|null         $person
 * @var array<int, array{template: string, materials: array, taskNumber: int}> $taskViews
 * @var string                                 $backUrl
 * @var string                                 $now         Серверное «сейчас» ('Y-m-d H:i:s', пояс сайта)
 * @var bool                                   $previewMode Предпросмотр автора: ученика и попытки нет
 */
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Inc\Enums\Assessment\AttemptStatus;

if ( ! $person && ! $previewMode ) {
	wp_redirect( wp_login_url( get_permalink() ) );
	exit;
}

// Предпросмотр: все три стадии станции отрендерены сразу и переключаются на
// клиенте (kege-entry.js/kege-exam.js), потому что настоящей попытки — а с ней
// и серверного состояния «идёт экзамен» / «сдано» — здесь не существует.
$isRunning  = ! $previewMode && $activeAttempt && AttemptStatus::InProgress === $activeAttempt->status;
$isFinished = ! $previewMode && ! $isRunning && null !== $lastAttempt;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html( $assessment->title ); ?> — Станция КЕГЭ</title>
	<meta name="robots" content="noindex, nofollow">
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
	<?php wp_head(); ?>
</head>
<body class="kege-page">

<div class="kege-app"
	id="kegeApp"
	data-assessment-id="<?php echo esc_attr( (string) $assessment->id ); ?>"
	data-time-limit="<?php echo esc_attr( (string) $assessment->timeLimit ); ?>"
	data-task-count="<?php echo esc_attr( (string) count( $assessment->taskIds ) ); ?>"
	data-back-url="<?php echo esc_url( $backUrl ); ?>"
	<?php if ( $previewMode ) : ?>
		data-preview="1"
	<?php endif; ?>
	<?php if ( $isRunning ) : ?>
		data-attempt-id="<?php echo esc_attr( (string) $activeAttempt->id ); ?>"
		<?php if ( $assessment->timeLimit > 0 ) : ?>
			<?php // Серверное «сейчас» рядом с дедлайном: обе даты в поясе сайта, и отсчёт ?>
			<?php // считается от них, а не от часов браузера (см. startCountdown). ?>
			data-deadline="<?php echo esc_attr( $activeAttempt->deadlineAt ); ?>"
			data-now="<?php echo esc_attr( $now ); ?>"
		<?php endif; ?>
	<?php endif; ?>
>

<?php if ( $previewMode ) : ?>
	<div class="kege-preview-flag">
		<span>Предпросмотр · от лица автора — попытка нигде не сохраняется, лимит попыток и время не учитываются</span>
		<button type="button" class="kege-preview-flag__restart" id="kegePreviewRestart">Начать заново</button>
	</div>
	<?php include __DIR__ . '/kege/exam.php'; ?>
	<?php include __DIR__ . '/kege/finish.php'; ?>
	<?php include __DIR__ . '/kege/entry.php'; ?>
<?php elseif ( $isRunning ) : ?>
	<?php include __DIR__ . '/kege/exam.php'; ?>
<?php else : ?>
	<?php if ( $isFinished ) : ?>
		<?php include __DIR__ . '/kege/finish.php'; ?>
	<?php endif; ?>
	<?php include __DIR__ . '/kege/entry.php'; ?>
<?php endif; ?>

</div>

<div class="kege-toast" id="kegeToast"></div>

<?php wp_footer(); ?>
</body>
</html>
