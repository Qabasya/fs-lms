function initTabs() {
    const container = document.querySelector('.fs-task-tabs');
    if (!container) return;

    const buttons = container.querySelectorAll('.fs-tab-btn');
    const panels  = container.querySelectorAll('.fs-tab-panel');

    buttons.forEach(btn => {
        btn.addEventListener('click', () => {
            const target   = btn.dataset.tab;
            const isActive = btn.classList.contains('is-active');

            buttons.forEach(b => b.classList.remove('is-active'));
            panels.forEach(p => p.classList.remove('is-active'));

            if (!isActive) {
                btn.classList.add('is-active');
                container.querySelector(`[data-panel="${target}"]`)?.classList.add('is-active');
            }
        });
    });
}

function initCarousel() {
    const carousel = document.querySelector('.fs-task-carousel');
    if (!carousel) return;

    const overflow = carousel.querySelector('.fs-carousel-overflow');
    const track    = carousel.querySelector('.fs-carousel-track');
    const items    = carousel.querySelectorAll('.fs-carousel-item');
    if (!overflow || !track || items.length === 0) return;

    let index = 0;

    const visibleCount = () => {
        if (window.innerWidth <= 600) return 1;
        if (window.innerWidth <= 900) return 2;
        return 3;
    };

    const clampIndex = () => {
        const max = Math.max(0, items.length - visibleCount());
        if (index > max) index = max;
        if (index < 0)   index = 0;
    };

    const update = () => {
        clampIndex();
        const itemWidth = overflow.offsetWidth / visibleCount();
        track.style.transform = `translateX(-${index * itemWidth}px)`;
    };

    carousel.querySelector('.fs-carousel-btn--prev')?.addEventListener('click', () => {
        const max = Math.max(0, items.length - visibleCount());
        index = index <= 0 ? max : index - 1;
        update();
    });

    carousel.querySelector('.fs-carousel-btn--next')?.addEventListener('click', () => {
        const max = Math.max(0, items.length - visibleCount());
        index = index >= max ? 0 : index + 1;
        update();
    });

    window.addEventListener('resize', update);

    // Wait one frame so layout is complete before measuring
    requestAnimationFrame(update);
}

document.addEventListener('DOMContentLoaded', () => {
    initTabs();
    initCarousel();
});