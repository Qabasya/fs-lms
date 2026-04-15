const $ = jQuery;

export const SubjectModal = {
    init() {
        this.$modal = $('#fs-subject-modal');
        if (!this.$modal.length) return;

        $('#open-subject-modal').on('click', () => this.open());
        this.$modal.on('click', '.fs-close', () => this.close());
        $(window).on('click', (e) => {
            if ($(e.target).is(this.$modal)) this.close();
        });
    },

    open()  { this.$modal.fadeIn(200); },
    close() { this.$modal.fadeOut(200); },
};