<?php

declare( strict_types=1 );
/**
 * Таб "Заявки с сайта" — лид-формы сайта (имя + телефон), принятые и отклонённые.
 * Тема передаёт заявки фильтром `fs_lms_theme_lead_submitted`; по отклонённым видно паттерн
 * ботов и ложные срабатывания. Рендерится из templates/admin/userlist.php.
 *
 * @package FS LMS
 */

use Inc\Enums\Access\Capability;
use Inc\Enums\Lead\LeadRejectReason;
use Inc\Enums\Lead\LeadVerdict;
use Inc\Repositories\WPDBRepositories\LeadRepository;
use Inc\Services\Security\PiiCryptoService;

require_once FS_LMS_PATH . 'templates/admin/components/UI/ui_renderers.php';

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( Capability::ManageApplications->value ) ) {
	echo '<p>' . esc_html__( 'Доступ запрещён.', 'fs-lms' ) . '</p>';
	return;
}

$leadRepo  = new LeadRepository();
$crypto    = new PiiCryptoService();
$canDelete = current_user_can( Capability::ManageLmsPlatform->value );

$page    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$perPage = 20;

$verdictFilter = LeadVerdict::tryFrom( sanitize_key( wp_unslash( $_GET['lead_verdict'] ?? '' ) ) )?->value ?? '';
$reasonFilter  = LeadRejectReason::tryFrom( sanitize_key( wp_unslash( $_GET['lead_reason'] ?? '' ) ) )?->value ?? '';
$subnetFilter  = sanitize_text_field( wp_unslash( $_GET['subnet'] ?? '' ) );

$sideFilters = array_filter( array(
	'reason' => $reasonFilter,
	'subnet' => $subnetFilter,
) );
$filters     = array_merge( $sideFilters, array_filter( array( 'verdict' => $verdictFilter ) ) );

$leads = $leadRepo->list( $filters, $page, $perPage );
$total = $leadRepo->count( $filters );
$pages = (int) ceil( $total / $perPage );

$pageSlug  = sanitize_key( $_GET['page'] ?? '' );
$baseUrl   = add_query_arg( array( 'page' => $pageSlug, 'tab' => 'tab-6' ), admin_url( 'admin.php' ) );
$urlParams = array_filter( array(
	'lead_reason'  => $reasonFilter,
	'subnet'       => $subnetFilter,
	'lead_verdict' => $verdictFilter,
) );
$filterUrl = add_query_arg( $urlParams, $baseUrl );

$reasonOptions = array();
foreach ( LeadRejectReason::cases() as $reason ) {
	$reasonOptions[ $reason->value ] = $reason->label();
}

$captchaLabels = array(
	'passed'      => 'Пройдена',
	'failed'      => 'Не пройдена',
	'unavailable' => 'Яндекс не ответил',
	'skipped'     => 'Не загрузилась',
	'off'         => 'Выключена',
);

$decrypt = static function ( string $blob ) use ( $crypto ): string {
	if ( '' === $blob ) {
		return '—';
	}
	try {
		return $crypto->decrypt( $blob );
	} catch ( \Throwable ) {
		return '—';
	}
};

// Телефон без форматирования — +79156271597: так его удобно вставлять в мессенджер.
// Недособранный номер из отклонённой заявки («+7 (91») выводится теми же цифрами.
$plainPhone = static function ( string $phone ): string {
	$digits = (string) preg_replace( '/\D+/', '', $phone );

	if ( '' === $digits ) {
		return $phone;
	}

	if ( 11 === strlen( $digits ) && '8' === $digits[0] ) {
		$digits = '7' . substr( $digits, 1 );
	}

	return '+' . $digits;
};

$verdictOptions = array();
foreach ( LeadVerdict::cases() as $verdict ) {
	$verdictOptions[ $verdict->value ] = $verdict->label();
}
$topSubnets = $leadRepo->topSubnets();
?>

<div class="fs-lms-leads fs-logs-tab">

	<?php if ( $canDelete ) : ?>
		<div class="tablenav top fs-students-bulk-bar">
			<div class="alignleft actions bulkactions">
				<label for="js-leads-bulk-action" class="screen-reader-text">Выберите действие</label>
				<select id="js-leads-bulk-action">
					<option value="">— Массовые действия —</option>
					<option value="delete">Удалить выбранные</option>
					<option value="delete_rejected">Удалить все отклонённые</option>
				</select>
				<button type="button" id="js-leads-bulk-apply" class="button action">Применить</button>
			</div>
		</div>
	<?php endif; ?>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="fs-logs-filters fs-logs-filters--wide">
		<input type="hidden" name="page" value="<?php echo esc_attr( $pageSlug ); ?>">
		<input type="hidden" name="tab" value="tab-6">

		<?php render_fs_select( array(
			'name'      => 'lead_verdict',
			'options'   => $verdictOptions,
			'selected'  => $verdictFilter,
			'all_label' => 'Все результаты',
		) ); ?>

		<?php render_fs_select( array(
			'name'      => 'lead_reason',
			'options'   => $reasonOptions,
			'selected'  => $reasonFilter,
			'all_label' => 'Все причины',
		) ); ?>

		<label for="fs-leads-subnet" class="screen-reader-text">Подсеть</label>
		<input type="search" id="fs-leads-subnet" name="subnet"
			value="<?php echo esc_attr( $subnetFilter ); ?>"
			placeholder="Подсеть, напр. 195.209.221.0/24">

		<button type="submit" class="button">Применить</button>

		<?php if ( array() !== $urlParams ) : ?>
			<a href="<?php echo esc_url( $baseUrl ); ?>" class="button">Сбросить</a>
		<?php endif; ?>
	</form>

	<?php if ( array() !== $topSubnets ) : ?>
		<p class="description">
			Больше всего отказов с подсетей:
			<?php foreach ( $topSubnets as $subnet => $count ) : ?>
				<a href="<?php echo esc_url( add_query_arg( array( 'subnet' => $subnet ), $baseUrl ) ); ?>"><?php echo esc_html( $subnet ); ?></a>
				(<?php echo esc_html( (string) $count ); ?>)
			<?php endforeach; ?>
		</p>
	<?php endif; ?>

	<p class="fs-logs-summary">
		Найдено записей: <strong><?php echo number_format_i18n( $total ); ?></strong>
		<?php if ( array() !== $filters ) : ?><em>(с фильтрами)</em><?php endif; ?>
	</p>

	<table class="wp-list-table widefat fixed striped fs-table fs-table--applications">
		<thead>
		<tr>
			<?php if ( $canDelete ) : ?>
				<th class="column-cb check-column"><input type="checkbox" id="js-select-all-leads"></th>
			<?php endif; ?>
			<th class="column-title tw-10">Получена</th>
			<th class="column-title column-primary tw-15">Имя</th>
			<th class="column-title tw-10">Телефон</th>
			<th class="column-title tw-7">Результат</th>
			<th class="column-title tw-7">Страница</th>
			<th class="column-title tw-7">IP / подсеть</th>
			<th class="column-title">Проверки</th>
		</tr>
		</thead>
		<tbody id="the-list">
		<?php if ( empty( $leads ) ) : ?>
			<tr>
				<td colspan="<?php echo $canDelete ? 8 : 7; ?>">
					<div class="notice notice-info inline fs-table__no-items">
						<p><?php esc_html_e( 'Заявок нет.', 'fs-lms' ); ?></p>
					</div>
				</td>
			</tr>
		<?php else : ?>
			<?php foreach ( $leads as $lead ) :
				$captchaText = $captchaLabels[ $lead->captcha ] ?? $lead->captcha;
				if ( $lead->captchaChallenge ) {
					$captchaText .= ', задание';
				}
				$pageLabel = wp_parse_url( $lead->pageUrl, PHP_URL_PATH ) ?: '/';
				if ( '' !== $lead->formId ) {
					$pageLabel .= ' (' . $lead->formId . ')';
				}
				?>
				<tr>
					<?php if ( $canDelete ) : ?>
						<td class="check-column">
							<input type="checkbox" class="js-lead-cb" value="<?php echo esc_attr( (string) $lead->id ); ?>">
						</td>
					<?php endif; ?>
					<td class="column-date"><?php echo esc_html( get_date_from_gmt( $lead->receivedAt, 'd.m.Y H:i:s' ) ); ?></td>
					<td class="column-title"><?php echo esc_html( $decrypt( $lead->nameEnc ) ); ?></td>
					<td class="column-title"><?php echo esc_html( $plainPhone( $decrypt( $lead->phoneEnc ) ) ); ?></td>
					<td>
						<?php render_fs_badge( $lead->verdict->label(), LeadVerdict::Accepted === $lead->verdict ? 'green' : 'red' ); ?>
						<?php if ( null !== $lead->reason ) : ?>
							<span class="fs-logs__subline fs-code-sm fs-text-muted"><?php echo esc_html( $lead->reason->label() ); ?></span>
						<?php endif; ?>
						<?php if ( false === $lead->mailSent ) : ?>
							<span class="fs-logs__subline fs-code-sm fs-text-muted">письмо не ушло</span>
						<?php endif; ?>
					</td>
					<td class="column-title"><?php echo esc_html( $pageLabel ); ?></td>
					<td class="column-title">
						<?php echo esc_html( $lead->ip ); ?>
						<?php if ( '' !== $lead->subnet ) : ?>
							<a class="fs-logs__subline fs-code-sm" href="<?php echo esc_url( add_query_arg( array( 'subnet' => $lead->subnet ), $baseUrl ) ); ?>"><?php echo esc_html( $lead->subnet ); ?></a>
						<?php endif; ?>
					</td>
					<td>
						<?php echo esc_html( sprintf( 'Заполнение %d с · капча: %s · %s', $lead->fillSeconds, $captchaText, $lead->mobile ? 'телефон/планшет' : 'компьютер' ) ); ?>
						<span class="fs-logs__subline fs-code-sm fs-text-muted" title="<?php echo esc_attr( $lead->userAgent ); ?>"><?php echo esc_html( mb_strimwidth( $lead->userAgent, 0, 70, '…' ) ); ?></span>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>

	<?php render_fs_pagination( $page, $pages, add_query_arg( 'paged', '%#%', $filterUrl ) ); ?>

</div>
