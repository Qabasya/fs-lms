<?php
/**
 * @var array[]                    $rows       Массив строк: title, edit_link, terms[slug => string]
 * @var \Inc\DTO\TaxonomyDataDTO[] $taxonomies Видимые таксономии (без "Номер задания")
 */
?>
<table class="wp-list-table widefat fixed striped fs-table fs-table--task js-task-manager-table">
    <thead>
    <tr>
        <th class="column-primary tw-10">Номер</th>
        <th class="column-primary tw-10">Название задания</th>
        <?php foreach ( $taxonomies as $tax ) : ?>
            <th scope="col" class="sortable"><?php echo esc_html( $tax->name ); ?></th>
        <?php endforeach; ?>
        <th class="column-actions tw-10">Действия</th>
    </tr>
    </thead>
    <tbody>
    <?php if ( ! empty( $rows ) ) : ?>
        <?php foreach ( $rows as $row ) : ?>
            <tr>
                <td>
                    <span class="fs-badge badge-gray"><?php echo esc_html( $row['number'] ); ?></span>
                </td>
                <td class="column-title">
                    <strong><?php echo esc_html( $row['title'] ); ?></strong>
                </td>
                <?php foreach ( $taxonomies as $tax ) :
                    $val = $row['terms'][ $tax->slug ] ?? '';
                    ?>
                    <td><?php echo $val !== '' ? esc_html( $val ) : '—'; ?></td>
                <?php endforeach; ?>
                <td class="column-actions">
                    <div class="row-actions visible">
						<span class="edit">
							<a href="<?php echo esc_url( $row['edit_link'] ); ?>" target="_blank">Редактировать</a>
						</span>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php else : ?>
        <tr>
            <td colspan="<?php echo count( $taxonomies ) + 3; ?>">Заданий данного типа не найдено</td>
        </tr>
    <?php endif; ?>
    </tbody>
</table>
