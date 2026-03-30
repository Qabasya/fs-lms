export const Modal = {
    init() {
        const $ = jQuery;
        const $modal = $('#fs-subject-modal');

        if (!$modal.length) return;

        $('#open-subject-modal').on('click', () => $modal.fadeIn(200));
        $('.fs-close').on('click', () => $modal.fadeOut(200));

        $(window).on('click', (e) => {
            if ($(e.target).is($modal)) $modal.fadeOut(200);
        });
    }
};