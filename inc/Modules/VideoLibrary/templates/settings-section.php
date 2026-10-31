<?php
/**
 * Секция настроек модуля VideoLibrary в табе «Конфигурация».
 * Рендерится через generic-хук ядра `fs_lms_config_sections` (ядро о модуле не знает).
 *
 * @var \Inc\Modules\VideoLibrary\Config\VideoLibraryConfig $config
 * @var array                                               $subjects Список предметов (объекты с ->key/->name).
 * @var array<int, array<string, mixed>>                    $access_recent Последние строки журнала доступа.
 * @var array<int, array<string, mixed>>                    $access_top    Топ просмотров за неделю.
 * @var array<int, string>                                  $access_names  user_id => имя.
 *
 * @package Inc\Modules\VideoLibrary
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

require_once FS_LMS_PATH . 'templates/admin/components/UI/ui_renderers.php';

$access_recent = is_array( $access_recent ?? null ) ? $access_recent : array();
$access_top    = is_array( $access_top ?? null ) ? $access_top : array();
$access_names  = is_array( $access_names ?? null ) ? $access_names : array();

/** Секунды → «1 ч 23 мин» для журнала доступа. */
$video_watch_fmt = static function ( int $seconds ): string {
	if ( $seconds < 60 ) {
		return $seconds . ' с';
	}
	$minutes = intdiv( $seconds, 60 );
	if ( $minutes < 60 ) {
		return $minutes . ' мин';
	}
	return intdiv( $minutes, 60 ) . ' ч ' . ( $minutes % 60 ) . ' мин';
};

/** Имя пользователя журнала доступа: display_name или «#ID». */
$video_user_fmt = static function ( array $row ) use ( $access_names ): string {
	$id   = (int) ( $row['wp_user_id'] ?? 0 );
	$name = trim( (string) ( $access_names[ $id ] ?? '' ) );

	return '' !== $name ? $name : '#' . $id;
};

$video_secret_set = '' !== $config->hmacSecret();
$video_s3         = $config->s3();
$video_s3_consts  = array(
	'FS_LMS_S3_ENDPOINT' => array( defined( 'FS_LMS_S3_ENDPOINT' ), $video_s3['endpoint'], 'Endpoint S3 (по умолчанию Beget)' ),
	'FS_LMS_S3_REGION'   => array( defined( 'FS_LMS_S3_REGION' ), $video_s3['region'], 'Регион для SigV4 (по умолчанию ru-1)' ),
	'FS_LMS_S3_BUCKET'   => array( '' !== $video_s3['bucket'], $video_s3['bucket'], 'Имя приватного бакета (Beget генерирует с префиксом)' ),
	'FS_LMS_S3_KEY'      => array( '' !== $video_s3['key'], '', 'Access key (достаточно read-only ключа)' ),
	'FS_LMS_S3_SECRET'   => array( '' !== $video_s3['secret'], '', 'Secret key' ),
);
?>

<form id="fs-videolib-form" class="fs-config-form">
	<div class="fs-card fs-card--flat">

		<div class="fs-card__header">
			<h2 class="fs-card__title">Видеозаписи занятий (S3)</h2>
		</div>

		<div class="fs-card__body">
			<p class="fs-card__desc">
				Сервис <code>fs-video-uploader</code> после загрузки записи в S3 <strong>сам присылает</strong> регистрацию (push):
				<code>POST /wp-json/fs-lms/v1/videos</code> (аутентификация — HMAC по секрету <code>FS_LMS_VIDEO_HMAC_SECRET</code>).
				Плагин привязывает запись к занятию по дате/времени и отдаёт ученикам временную presigned-ссылку из приватного бакета.
			</p>

			<div class="fs-config-key-row">
				<div class="fs-config-key-row__header">
					<span class="fs-config-key-row__name">FS_LMS_VIDEO_HMAC_SECRET</span>
					<?php render_fs_badge( $video_secret_set ? 'Задан' : 'Не задан', $video_secret_set ? 'green' : 'red' ); ?>
				</div>
				<p class="description">Секрет для HMAC-подписи запросов сервиса ↔ WP (отдельный от AD-синка). В БД не хранится. Сгенерируйте — получите обе строки ниже.</p>
				<div class="fs-config-key-row__actions">
					<button type="button" class="button" data-videolib-generate-secret>
						<?php echo $video_secret_set ? 'Перегенерировать' : 'Сгенерировать'; ?>
					</button>
				</div>
				<div class="fs-config-key-row__output" id="fs-videolib-secret-output" hidden>
					<p class="description">Эту строку вставьте в <code>wp-config.php</code>:</p>
					<textarea class="fs-config-key-output" id="fs-videolib-secret-value" rows="2" readonly></textarea>
					<button type="button" class="button js-copy-key" data-target="fs-videolib-secret-value">Скопировать</button>

					<p class="description fs-config-key-row__env-label">Этот секрет вставьте в <code>.env</code> сервиса fs-video-uploader (<code>LMS_HMAC_SECRET</code>):</p>
					<input type="text" class="fs-config-key-output" id="fs-videolib-secret-raw" readonly>
					<button type="button" class="button js-copy-key" data-target="fs-videolib-secret-raw">Скопировать</button>
				</div>
			</div>

			<?php foreach ( $video_s3_consts as $const_name => list( $const_set, $const_value, $const_desc ) ) : ?>
				<div class="fs-config-key-row">
					<div class="fs-config-key-row__header">
						<span class="fs-config-key-row__name"><?php echo esc_html( $const_name ); ?></span>
						<?php render_fs_badge( $const_set ? 'Задан' : 'Не задан', $const_set ? 'green' : 'red' ); ?>
						<?php if ( '' !== $const_value ) : ?>
							<code><?php echo esc_html( $const_value ); ?></code>
						<?php endif; ?>
					</div>
					<p class="description"><?php echo esc_html( $const_desc ); ?></p>
				</div>
			<?php endforeach; ?>

			<?php // З3: алёрт «занятие прошло, записи нет» — список наполняет admin.js модуля. ?>
			<div class="fs-config-key-row" id="fs-videolib-pending">
				<div class="fs-config-key-row__header">
					<span class="fs-config-key-row__name">Занятия без записи</span>
					<span data-videolib-pending-badge></span>
				</div>
				<p class="description">
					Проведённые и завершившиеся по расписанию занятия без записи. Выберите
					подходящую запись группы (для индивидуального занятия — также из папки преподавателя) или вставьте ссылку вручную
					(<code>https://…</code> либо указатель <code>s3://bucket/key</code>).
				</p>
				<div class="fs-videolib-pending-list" data-videolib-pending-list>
					<p class="description">Загрузка…</p>
				</div>
			</div>

			<div class="fs-config-key-row">
				<div class="fs-config-key-row__header">
					<span class="fs-config-key-row__name">groups.yaml для fs-video-uploader</span>
				</div>
				<p class="description">
					Группы с назначенным курсом и преподавателем + личные папки всех преподавателей
					(для индивидуальных занятий) — в формате конфига сервиса.
				</p>
				<div class="fs-config-key-row__actions">
					<button type="button" class="button" data-videolib-export-groups>Экспорт YAML</button>
				</div>
			</div>

		</div>

	</div>
</form>

<div class="fs-card fs-card--flat">
	<div class="fs-card__header">
		<h2 class="fs-card__title">Журнал доступа к записям</h2>
	</div>
	<div class="fs-card__body">
		<p class="fs-card__desc">
			Кто запрашивает ссылки на записи и сколько фактически смотрит. Повторные запросы подряд
			суммируются в одну сессию (окно 30 минут): «Выдач» — сколько раз выдана ссылка,
			«Просмотр» — реальное время воспроизведения.
		</p>

		<h3>Топ просмотров за 7 дней</h3>
		<?php if ( array() === $access_top ) : ?>
			<p class="description">Пока пусто — журнал начал писаться с этой версии.</p>
		<?php else : ?>
			<table class="fs-table">
				<thead>
					<tr>
						<th>Пользователь</th>
						<th>Запись</th>
						<th>Сессий</th>
						<th>Выдач</th>
						<th>Просмотр</th>
						<th>Последний раз</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $access_top as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $video_user_fmt( $row ) ); ?></td>
							<td><code><?php echo esc_html( basename( (string) $row['s3_key'] ) ); ?></code></td>
							<td><?php echo (int) $row['sessions']; ?></td>
							<td><?php echo (int) $row['requests']; ?></td>
							<td><?php echo esc_html( $video_watch_fmt( (int) $row['watch_seconds'] ) ); ?></td>
							<td><?php echo esc_html( (string) $row['last_at'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h3>Последние обращения</h3>
		<?php if ( array() === $access_recent ) : ?>
			<p class="description">Пока пусто.</p>
		<?php else : ?>
			<table class="fs-table">
				<thead>
					<tr>
						<th>Время</th>
						<th>Пользователь</th>
						<th>Запись</th>
						<th>Занятие</th>
						<th>Выдач</th>
						<th>Просмотр</th>
						<th>IP</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $access_recent as $row ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $row['last_at'] ); ?></td>
							<td><?php echo esc_html( $video_user_fmt( $row ) ); ?></td>
							<td><code><?php echo esc_html( basename( (string) $row['s3_key'] ) ); ?></code></td>
							<td><?php echo null !== $row['group_lesson_id'] ? (int) $row['group_lesson_id'] : '—'; ?></td>
							<td><?php echo (int) $row['requests_count']; ?></td>
							<td><?php echo esc_html( $video_watch_fmt( (int) $row['watch_seconds'] ) ); ?></td>
							<td><?php echo esc_html( (string) $row['ip'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
</div>
