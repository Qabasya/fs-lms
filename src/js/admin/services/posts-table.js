import '../_types.js';

export const PostsTable = {
    init() {
        const $ = jQuery;

        if ( ! $('.fs-posts-table-container').length ) {
            return;
        }

        // Filter links: All / Published / Draft / Trash
        $(document).on('click', '.fs-posts-table-container .subsubsub a', (e) => {
            e.preventDefault();
            const $link = $(e.currentTarget);
            this._load( $link.closest('.fs-posts-table-container'), $link.attr('href') );
        });

        // Pagination links
        $(document).on('click', '.fs-posts-table-container .tablenav-pages a', (e) => {
            e.preventDefault();
            const $link = $(e.currentTarget);
            this._load( $link.closest('.fs-posts-table-container'), $link.attr('href') );
        });

        // Search form submit
        $(document).on('submit', '.fs-posts-table-container #posts-filter', (e) => {
            e.preventDefault();
            const $form = $(e.currentTarget);
            const $container = $form.closest('.fs-posts-table-container');
            const s = $form.find('[name="s"]').val() || '';
            this._load( $container, null, { s, paged: 1, post_status: '' } );
        });
    },

    /**
     * @param {JQuery} $container
     * @param {string|null} url
     * @param {Object} [overrides]
     */
    _load($container, url, overrides = {}) {
        const params = url ? new URLSearchParams(new URL(url, window.location.href).search) : new URLSearchParams();

        const data = {
            action:      fs_lms_vars.ajax_actions.getPostsTable,
            security:    fs_lms_vars.subject_nonce,
            subject_key: $container.data('subject'),
            tab:         $container.data('tab'),
            page_slug:   $container.data('page'),
            post_status: overrides.post_status ?? (params.get('post_status') || ''),
            paged:       overrides.paged       ?? (params.get('paged')       || 1),
            s:           overrides.s           ?? (params.get('s')           || ''),
        };

        $container.css('opacity', '0.5');

        jQuery.ajax({
            url:  fs_lms_vars.ajaxurl,
            type: 'POST',
            data,
            success(response) {
                $container.css('opacity', '');
                if ( response.success ) {
                    $container.html(response.data.html);
                    if ( window.inlineEditPost ) {
                        window.inlineEditPost.init();
                    }
                }
            },
            error() {
                $container.css('opacity', '');
            },
        });
    },
};
