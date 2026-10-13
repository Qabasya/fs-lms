<?php

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Таб «Аватары»: картинка вместо цветного чипа у предметов и групп + логотип кабинета.
 *
 * Цепочка в кабинете: аватар группы → аватар предмета → цветной чип с буквами.
 *
 * @var array{brand: array{id:int,url:string}, subjects: array<int,array<string,mixed>>, groups: array<int,array<string,mixed>>} $avatar_rows
 */

$brand    = $avatar_rows['brand'] ?? array( 'id' => 0, 'url' => '' );
$subjects = $avatar_rows['subjects'] ?? array();
$groups   = $avatar_rows['groups'] ?? array();

/**
 * Ячейка выбора аватара: превью + «Выбрать» + «Убрать». Сохранение — сразу по клику (AJAX).
 */
$avatar_control = static function ( string $entity, string $key, int $id, string $url ): void {
	?>
	<div class="fs-config-logo js-avatar-row"
		data-entity="<?php echo esc_attr( $entity ); ?>"
		data-key="<?php echo esc_attr( $key ); ?>">
		<img src="<?php echo esc_url( $url ); ?>" alt="" class="fs-config-logo__preview js-avatar-preview" <?php echo '' === $url ? 'hidden' : ''; ?>>
		<button type="button" class="button js-avatar-pick">Выбрать из медиатеки</button>
		<button type="button" class="button js-avatar-clear" <?php echo $id > 0 ? '' : 'hidden'; ?>>Убрать</button>
		<span class="fs-config-status js-avatar-status"></span>
	</div>
	<?php
};
?>

<div id="tab-avatars" class="tab-pane active">

	<div class="fs-page-header">
		<div class="fs-page-header__content">
			<h2 class="fs-page-header__title">Аватары</h2>
		</div>
		<p class="fs-page-header__desc">
			Картинка показывается в кабинете вместо цветного значка. У группы свой аватар главнее аватара предмета;
			без картинки остаётся цветной значок с буквами.
		</p>
	</div>

	<div class="fs-card fs-card--flat">
		<div class="fs-card__header">
			<h2 class="fs-card__title">Личный кабинет</h2>
		</div>
		<div class="fs-card__body">
			<div class="fs-field">
				<span class="fs-field__label">Логотип личного кабинета</span>
				<div class="fs-field__control">
					<?php $avatar_control( 'brand', '', (int) $brand['id'], (string) $brand['url'] ); ?>
				</div>
				<p class="fs-field__desc">Показывается в шапке личного кабинета вместо стандартного знака. Пусто — знак по умолчанию.</p>
			</div>
		</div>
	</div>

	<h3>Предметы</h3>
	<?php if ( empty( $subjects ) ) : ?>
		<div class="notice notice-info inline fs-table__no-items"><p>Предметов пока нет.</p></div>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped fs-table">
			<thead>
			<tr>
				<th class="column-title tw-30">Предмет</th>
				<th class="column-title">Аватар</th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $subjects as $row ) : ?>
				<tr>
					<td class="column-title">
						<strong><?php echo esc_html( (string) $row['name'] ); ?></strong>
						<?php if ( ! empty( $row['archived'] ) ) : ?>
							<span class="fs-field__desc"> — в архиве</span>
						<?php endif; ?>
					</td>
					<td><?php $avatar_control( 'subject', (string) $row['key'], (int) $row['id'], (string) $row['url'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<h3>Группы</h3>
	<?php if ( empty( $groups ) ) : ?>
		<div class="notice notice-info inline fs-table__no-items"><p>Групп пока нет.</p></div>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped fs-table">
			<thead>
			<tr>
				<th class="column-title tw-30">Группа</th>
				<th class="column-title">Предмет</th>
				<th class="column-title">Аватар</th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $groups as $row ) : ?>
				<tr>
					<td class="column-title"><strong><?php echo esc_html( (string) $row['name'] ); ?></strong></td>
					<td><?php echo esc_html( (string) $row['subject'] ); ?></td>
					<td><?php $avatar_control( 'group', (string) $row['key'], (int) $row['id'], (string) $row['url'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
