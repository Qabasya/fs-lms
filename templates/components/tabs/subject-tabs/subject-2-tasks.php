<?php /** @var \Inc\DTO\SubjectViewDTO $dto */ ?>

<div id="tab-2" class="tab-pane <?php echo $active_tab === 'tab-2' ? 'active' : ''; ?>">
    <?php if ( $dto->tasks_table ) : $t = $dto->tasks_table; ?>

    <div class="wrap">
        <h1 class="wp-heading-inline">
            <?php echo esc_html( $t->post_type_object->labels->name ); ?>
        </h1>
        <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . $t->post_type ) ); ?>"
           class="page-title-action">
            <?php echo esc_html( $t->post_type_object->labels->add_new ); ?>
        </a>
        <hr class="wp-header-end">

        <div class="fs-posts-table-container"
             data-tab="<?php echo esc_attr( $t->tab ); ?>"
             data-subject="<?php echo esc_attr( $dto->subject_key ); ?>"
             data-page="<?php echo esc_attr( $t->page_slug ); ?>">

            <?php echo $t->views(); // phpcs:ignore WordPress.Security.EscapeOutput ?>

            <form id="posts-filter" method="get">
                <input type="hidden" name="page" value="<?php echo esc_attr( $t->page_slug ); ?>" />
                <input type="hidden" name="tab" value="<?php echo esc_attr( $t->tab ); ?>" />
                <input type="hidden" name="post_type" value="<?php echo esc_attr( $t->post_type ); ?>" />

                <?php if ( isset( $_REQUEST['post_status'] ) ) : ?>
                    <input type="hidden" name="post_status"
                           value="<?php echo esc_attr( $_REQUEST['post_status'] ); ?>" />
                <?php endif; ?>

                <?php $t->table->search_box( $t->post_type_object->labels->search_items, 'post' ); ?>

                <?php echo $t->display(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            </form>

            <div id="ajax-response"></div>

            <?php echo $t->inlineEdit(); // phpcs:ignore WordPress.Security.EscapeOutput ?>

        </div>
    </div>

    <?php $t->restore(); endif; ?>
</div>
