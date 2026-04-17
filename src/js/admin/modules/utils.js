export const Utils = {
    toggleButton($btn, isLoading, loadingText = '...') {
        if (isLoading) {
            $btn.data('original-text', $btn.html())
                .prop('disabled', true)
                .text(loadingText);
        } else {
            $btn.prop('disabled', false)
                .html($btn.data('original-text'));
        }
    },

    apiError(error) {
        console.error('FS-LMS API Error:', error);
        alert('Произошла ошибка при связи с сервером.');
    },
};
