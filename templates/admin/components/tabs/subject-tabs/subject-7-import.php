<?php

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Вкладка «Импорт»: разовый перенос заданий со старой версии сайта в текущий предмет.
 * Форма — та же, что на скрытой странице `fs_lms_legacy_task_import`.
 *
 * @var \Inc\DTO\Subject\SubjectViewDTO $dto
 */

$subjects   = array( $dto->subject_data );
$taxonomies = array(
	$dto->subject_key => array_values(
		array_map(
			static fn( $tax ): array => array( 'slug' => $tax->slug, 'name' => $tax->name ),
			array_filter( $dto->taxonomies, static fn( $tax ): bool => $tax->slug !== $dto->protected_tax )
		)
	),
);
$batch_size = \Inc\Services\Task\LegacyTaskImportService::BATCH_SIZE;
?>

<div class="fs-lms-import">
	<h1 class="wp-heading-inline">Перенос заданий со старой версии сайта</h1>
	<p class="description">
		Разовый инструмент: читает файл <code>legacy_tasks_import.json</code> и создаёт черновики в банке заданий этого предмета —
		номер и шаблон присваиваются так же, как при обычном создании задания.
	</p>

	<?php require dirname( __DIR__, 3 ) . '/partials/legacy-import-form.php'; ?>
</div>
