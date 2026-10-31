/** Общая очередь всех предпочтений: правки меню и плиток не перезаписывают друг друга. */
import { createApi } from './api.js';
import { createLayoutSaver } from './home-customization.js';
import { toast } from './utils.js';

const persist = createLayoutSaver(
    async patch => {
        const config = window.fsProfile?.homeLayout || window.fsProfile?.learner;
        if (!config) return;
        const data = await createApi(config)('saveHomeLayout', { layout: JSON.stringify(patch) });
        if (window.fsProfile?.homeLayout && data.layout) window.fsProfile.homeLayout.layout = data.layout;
        return data;
    },
    e => toast(e.message || 'Не удалось сохранить настройки', 'error'),
);

export function persistHomeLayout(patch) { return persist(patch); }
