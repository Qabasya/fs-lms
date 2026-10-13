<?php
/**
 * Страница открытой записи на экзамен: гость без ссылки школы выбирает класс.
 *
 * Выводится шорткодом `[fs_lms_exam_signup]`, когда у посетителя нет приглашения школы. Класс определяет экзамен: 9 — ОГЭ, 11 — ЕГЭ;
 * после выбора открывается обычная форма записи. Разметка карточки — существующая (`fs-join-card`, `fs-join-btn`).
 *
 * @package FS LMS
 *
 * @var list<array{grade: int, label: string, available: bool, url: string}> $options Классы: подпись, доступность, адрес перехода
 * @var bool                       $grade_closed Выбран класс, на который записи сейчас нет
 * @var array<string, string>      $contacts     phone, email, hours, city, street
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$contact_phone = (string) ( $contacts['phone'] ?? '' );
$any_available = array() !== array_filter( $options, static fn ( array $option ): bool => $option['available'] );
?>

<main class="fs-lms-join-page fs-exam-signup" id="fs-exam-signup" data-state="choose">
	<div class="fs-join-card">
		<!-- Заголовок «Запись на экзамен» — h1 страницы (тема); здесь его не дублируем -->
		<p class="fs-join-card__subtitle"><?php esc_html_e( 'Выберите класс, чтобы записаться на пробный экзамен', 'fs-lms' ); ?></p>

		<?php if ( $grade_closed || ! $any_available ) : ?>
			<p class="fs-exam-signup__state" role="status">
				<?php esc_html_e( 'Запись на этот экзамен сейчас закрыта.', 'fs-lms' ); ?>
				<?php if ( '' !== $contact_phone ) : ?>
					<?php echo esc_html( sprintf( 'Обратитесь к сотруднику: %s.', $contact_phone ) ); ?>
				<?php endif; ?>
			</p>
		<?php endif; ?>

		<?php if ( $any_available ) : ?>
			<div class="fs-exam-signup__choices" role="group" aria-label="<?php esc_attr_e( 'Класс', 'fs-lms' ); ?>">
				<?php foreach ( $options as $option ) : ?>
					<?php if ( $option['available'] ) : ?>
						<a class="fs-join-btn" href="<?php echo esc_url( $option['url'] ); ?>"><?php echo esc_html( $option['label'] ); ?></a>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
</main>
