const $ = jQuery;

export function openModal($modal) {
    const scrollBarWidth = window.innerWidth - document.documentElement.clientWidth;
    document.documentElement.style.setProperty('--scrollbar-width', scrollBarWidth + 'px');
    $('html').addClass('modal-open');
    $modal.removeClass('hidden');
    void $modal[0].offsetHeight;
    $modal.addClass('active');
}

export function closeModal($modal, callback) {
    $modal.removeClass('active');
    setTimeout(() => {
        $modal.addClass('hidden');
        $('html').removeClass('modal-open');
        document.documentElement.style.removeProperty('--scrollbar-width');
        if (typeof callback === 'function') callback();
    }, 200);
}

export function bindEsc(ns, fn) {
    $(document).on('keydown.modal_' + ns, (e) => {
        if (e.key === 'Escape') fn();
    });
}

export function unbindEsc(ns) {
    $(document).off('keydown.modal_' + ns);
}
