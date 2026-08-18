export function initCarousel() {
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

    // Ширина карточки фиксирована токеном $subject-card-width (_carousel.scss) —
    // та же, что у карточки каталога учебника и витрины курсов, поэтому шаг
    // прокрутки меряется по факту отрисованного слайда, а не гадается по брейкпоинту:
    // сколько карточек видно в кадре зависит только от его ширины.
    const itemWidth = () => origItems[0].getBoundingClientRect().width;

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
        // Анимация — класс (transition живёт в SCSS), JS задаёт только геометрию сдвига.
        track.classList.toggle('is-animating', animate);
        track.style.transform = `translateX(-${index * itemWidth()}px)`;
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