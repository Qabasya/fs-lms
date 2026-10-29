<?php
/**
 * Печатная форма флаеров мастер-класса: свой HTML-документ (отдаёт admin-post обработчик), 4 флаера на лист A4.
 *
 * @var list<\Inc\Modules\Masterclass\DTO\MasterclassFlyerDTO> $flyers      Флаеры (живые аккаунты с читаемым паролем)
 * @var string                                                 $school_name Школа
 * @var string                                                 $stylesheet  Адрес flyers.css
 * @var string                                                 $script      Адрес flyers.js
 *
 * @package Inc\Modules\Masterclass
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/** Флаеров на листе A4 (2 × 2); размеры ячеек в mm задаёт flyers.css. */
$flyers_per_sheet = 4;
?>
<!doctype html>
<html lang="ru">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title>Флаеры · <?php echo esc_html( $school_name ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( $stylesheet ); ?>">
</head>
<body class="flyers">
	<header class="flyers__bar">
		<p class="flyers__summary">Флаеров: <?php echo esc_html( (string) count( $flyers ) ); ?>. Печатайте на A4, по 4 на лист.</p>
		<button type="button" class="flyers__print" id="flyers-print">Печать</button>
	</header>

	<?php if ( array() === $flyers ) : ?>
		<p class="flyers__empty">Нет аккаунтов для печати: все удалены, подали заявку или уже сменили пароль.</p>
	<?php endif; ?>

	<?php foreach ( array_chunk( $flyers, $flyers_per_sheet ) as $sheet ) : ?>
		<section class="sheet">
			<?php foreach ( $sheet as $flyer ) : ?>
				<article class="flyer">
					<h2 class="flyer__school"><?php echo esc_html( $flyer->schoolName ); ?></h2>
					<p class="flyer__lead">Твой вход в урок</p>
					<dl class="flyer__data">
						<dt>Сайт</dt>
						<dd><?php echo esc_html( $flyer->loginUrl ); ?></dd>
						<dt>Логин</dt>
						<dd class="flyer__secret"><?php echo esc_html( $flyer->login ); ?></dd>
						<dt>Пароль</dt>
						<dd class="flyer__secret"><?php echo esc_html( $flyer->password ); ?></dd>
					</dl>
					<p class="flyer__number">№ <?php echo esc_html( (string) $flyer->number ); ?></p>
				</article>
			<?php endforeach; ?>
		</section>
	<?php endforeach; ?>

	<script src="<?php echo esc_url( $script ); ?>"></script>
</body>
</html>
