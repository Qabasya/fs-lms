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
    const carousel  = document.querySelector('.fs-task-carousel');
    if (!carousel) return;

    const overflow  = carousel.querySelector('.fs-carousel-overflow');
    const track     = carousel.querySelector('.fs-carousel-track');
    const origItems = Array.from(carousel.querySelectorAll('.fs-carousel-item'));
    if (!overflow || !track || origItems.length === 0) return;

    const realCount = origItems.length;

    origItems.forEach(item => track.insertBefore(item.cloneNode(true), track.firstChild));
    origItems.forEach(item => track.appendChild(item.cloneNode(true)));

    let index = realCount;

    const visibleCount = () => {
        if (window.innerWidth <= 600) return 1;
        if (window.innerWidth <= 900) return 2;
        return 3;
    };

    const itemWidth = () => overflow.offsetWidth / visibleCount();

    // ── Dots
    const dotsWrap = document.createElement('div');
    dotsWrap.className = 'fs-carousel-dots';

    for (let i = 0; i < realCount; i++) {
        const dot = document.createElement('button');
        dot.className = 'fs-carousel-dot';
        dot.setAttribute('aria-label', `Статья ${i + 1}`);
        dot.addEventListener('click', () => {
            index = realCount + i;
            update();
        });
        dotsWrap.appendChild(dot);
    }
    carousel.appendChild(dotsWrap);

    const updateDots = () => {
        const realIdx = ((index - realCount) % realCount + realCount) % realCount;
        dotsWrap.querySelectorAll('.fs-carousel-dot').forEach((dot, i) => {
            dot.classList.toggle('is-active', i === realIdx);
        });
    };

    const update = (animate = true) => {
        track.style.transition = animate ? 'transform 0.3s ease' : 'none';
        track.style.transform  = `translateX(-${index * itemWidth()}px)`;
        updateDots();
    };

    track.addEventListener('transitionend', () => {
        if (index >= realCount * 2) {
            index -= realCount;
            update(false);
        } else if (index < realCount) {
            index += realCount;
            update(false);
        }
    });

    carousel.querySelector('.fs-carousel-btn--prev')?.addEventListener('click', () => { index--; update(); });
    carousel.querySelector('.fs-carousel-btn--next')?.addEventListener('click', () => { index++; update(); });

    window.addEventListener('resize', () => update(false));
    requestAnimationFrame(() => update(false));
}

document.addEventListener('DOMContentLoaded', () => {
    initTabs();
    initCarousel();
});