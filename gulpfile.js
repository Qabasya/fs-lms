/**
 * Gulp configuration for Future Step LMS
 * * Сохранена оригинальная структура путей и имен файлов.
 * Добавлена поддержка ES6 Модулей (import/export) через Webpack.
 */

const { PassThrough } = require('node:stream');

const gulp = require('gulp');
const sass = require('gulp-sass')(require('sass'));
const postcss = require('gulp-postcss');
const autoprefixer = require('autoprefixer');
const cssnano = require('cssnano');
const sourcemaps = require('gulp-sourcemaps');
const notify = require('gulp-notify');
const plumber = require('gulp-plumber');
const rename = require('gulp-rename');

// Модули для работы с JS (Webpack заменяет concat и uglify для лучшей сборки)
const webpack = require('webpack-stream');
const named = require('vinyl-named');

/**
 * ПУТИ
 */
const paths = {
    scss: {
        admin: './src/scss/admin/admin.scss',
        frontend: './src/scss/frontend/frontend.scss',
        common: './src/scss/common/common.scss',
        profile: './src/scss/profile/profile.scss',
        player: './src/scss/player/player.scss',
        assessment: './src/scss/assessment/assessment.scss',
        kege: './src/scss/kege/kege.scss',
        watch: './src/scss/**/*.scss'
    },
    js: {
        // Точки входа (основные файлы, которые импортируют модули)
        admin: './src/js/admin/admin.js',
        frontend: './src/js/frontend/frontend.js',
        common: './src/js/common/common.js',
        profile: './src/js/profile/profile.js',
        player: './src/js/player/player.js',
        assessment: './src/js/assessment/assessment.js',
        kege: './src/js/kege/kege.js',
        watch: './src/js/**/*.js'
    },
    output: {
        css: './assets/css/',
        js: './assets/js/',
        maps: './maps/'
    }
};

/**
 * Настройки Webpack
 * Собирает модули в один файл, минифицирует и делает код понятным старым браузерам
 */
const webpackConfig = {
    mode: 'production',
    module: {
        rules: [
            {
                test: /\.js$/,
                // Добавляем эту строку, чтобы Webpack не падал на импортах до обработки Babel
                type: 'javascript/auto',
                exclude: /node_modules/,
                use: {
                    loader: 'babel-loader',
                    options: {
                        presets: [
                            ['@babel/preset-env', {
                                modules: false
                            }]
                        ],
                        sourceType: 'module'
                    }
                }
            }
        ]
    },
    resolve: {
        extensions: ['.js', '.json'],
        modules: ['node_modules', 'src/js/admin']
    },
    output: {
        filename: '[name].min.js',
        // Чанки динамических import() (конструкторы админки): хэш в имени вместо ?ver= — хостинг
        // кеширует статику на неделю, а URL чанка webpack строит сам.
        chunkFilename: '[name].[contenthash:8].chunk.js',
        // Каталог чанков — тот же, откуда загружен бандл (document.currentScript).
        publicPath: 'auto',
    },
    devtool: 'source-map'
};

/**
 * Кегли публичных бандлов — целыми пикселями на шкале темы fs-lms-theme
 * (2026-09-13).
 *
 * Тема ставит корню 120% (19.2px), и макетные `rem()` давали дробные кегли:
 * rem(13) → 15.6px, rem(22) → 26.4px. Сама тема держит шкалу целыми
 * пикселями (14/15/16/18/19/21/22/24/36/56, `theme.json` темы), а плагин
 * рисует на тех же страницах. Поэтому каждый `rem`-кегль бандла
 * оборачивается в CSS `round()`:
 *   - до 19px макета — `round(down, calc(Xrem + .05px), 1px)`: ×1.2 с
 *     округлением вниз, те же ступени, что у темы (13 → 15, 14 → 16,
 *     16 → 19). При корне 16px (админка грузит `common`) — ровно макетный
 *     кегль. `.05px` — страховка: `rem × 19.2` в плавающей точке может
 *     недобрать до целого (17.9999 → 17);
 *   - крупнее — макет +2px (22 → 24, 28 → 30): тема тоже уменьшила
 *     заголовки против простого ×1.2.
 * Значения остаются в `rem` и следуют за корнем и размером шрифта из
 * настроек браузера — `round()` лишь снимает дробь. Исходники SCSS и
 * `rem()` не меняются. `admin.min.css` не обрабатывается: там корень
 * WordPress (16px) и дробей нет.
 */
const THEME_ROOT_PX = 19.2;
const DESIGN_ROOT_PX = 16;
const REM_TOKEN = /(^|\s)(\d*\.?\d+)rem(?=$|[\s/])/g;

function themeFontSize(rem) {
    const designPx = rem * DESIGN_ROOT_PX;

    if (designPx <= 19) {
        return `round(down, calc(${rem}rem + .05px), 1px)`;
    }

    const targetRem = Number(((Math.round(designPx) + 2) / THEME_ROOT_PX).toFixed(6));

    return `round(${targetRem}rem, 1px)`;
}

// Повторный проход PostCSS по изменённой декларации ничего не трогает:
// внутри `round(`/`calc(` перед числом стоит скобка, а не пробел.
function fontScale() {
    return {
        postcssPlugin: 'fs-lms-font-scale',
        Declaration(decl) {
            if (decl.prop !== 'font-size' && decl.prop !== 'font') {
                return;
            }

            decl.value = decl.value.replace(REM_TOKEN, (match, lead, value) => lead + themeFontSize(parseFloat(value)));
        },
    };
}
fontScale.postcss = true;

const errorHandler = function (err) {
    notify.onError({
        title: "Gulp error in " + err.plugin,
        message: err.toString()
    })(err);
    this.emit('end');
};

/**
 * Watch-режим: watcher должен пережить опечатку в SCSS, иначе после первой же
 * ошибки перестают собираться ВСЕ бандлы до ручного перезапуска.
 */
let watching = false;

/**
 * Перехват ошибок — ТОЛЬКО в watch-режиме.
 *
 * В разовой сборке plumber недопустим: он гасит ошибку и задача рапортует
 * «Finished», а бандл молча остаётся прежним. Так frontend.min.css неделями
 * собирался из старого кода при сломанном SCSS. Без plumber ошибка роняет
 * задачу (exit != 0) — поломку видно сразу и в CI, и локально.
 */
function guard() {
    return watching ? plumber({ errorHandler }) : new PassThrough({ objectMode: true });
}

/**
 * ОБРАБОТКА CSS (AdminController & Frontend & Common)
 */
function stylesCommon() {
    return gulp.src(paths.scss.common)
        .pipe(guard())
        .pipe(sourcemaps.init())
        .pipe(sass())
        .pipe(postcss([fontScale(), autoprefixer(), cssnano()]))
        .pipe(rename('common.min.css'))
        .pipe(sourcemaps.write(paths.output.maps))
        .pipe(gulp.dest(paths.output.css));
}

function stylesAdmin() {
    return gulp.src(paths.scss.admin)
        .pipe(guard())
        .pipe(sourcemaps.init())
        .pipe(sass({ includePaths: [paths.scss.common] }))
        .pipe(postcss([autoprefixer(), cssnano()]))
        .pipe(rename('admin.min.css'))
        .pipe(sourcemaps.write(paths.output.maps))
        .pipe(gulp.dest(paths.output.css));
}

function stylesFrontend() {
    return gulp.src(paths.scss.frontend)
        .pipe(guard())
        .pipe(sourcemaps.init())
        .pipe(sass({ includePaths: [paths.scss.common] }))
        .pipe(postcss([fontScale(), autoprefixer(), cssnano()]))
        .pipe(rename('frontend.min.css'))
        .pipe(sourcemaps.write(paths.output.maps))
        .pipe(gulp.dest(paths.output.css));
}

function stylesProfile() {
    return gulp.src(paths.scss.profile)
        .pipe(guard())
        .pipe(sourcemaps.init())
        .pipe(sass())
        .pipe(postcss([fontScale(), autoprefixer(), cssnano()]))
        .pipe(rename('profile.min.css'))
        .pipe(sourcemaps.write(paths.output.maps))
        .pipe(gulp.dest(paths.output.css));
}

function stylesPlayer() {
    return gulp.src(paths.scss.player)
        .pipe(guard())
        .pipe(sourcemaps.init())
        .pipe(sass())
        .pipe(postcss([fontScale(), autoprefixer(), cssnano()]))
        .pipe(rename('player.min.css'))
        .pipe(sourcemaps.write(paths.output.maps))
        .pipe(gulp.dest(paths.output.css));
}

function stylesAssessment() {
    return gulp.src(paths.scss.assessment)
        .pipe(guard())
        .pipe(sourcemaps.init())
        .pipe(sass())
        .pipe(postcss([fontScale(), autoprefixer(), cssnano()]))
        .pipe(rename('assessment.min.css'))
        .pipe(sourcemaps.write(paths.output.maps))
        .pipe(gulp.dest(paths.output.css));
}

function stylesKege() {
    return gulp.src(paths.scss.kege)
        .pipe(guard())
        .pipe(sourcemaps.init())
        .pipe(sass())
        .pipe(postcss([fontScale(), autoprefixer(), cssnano()]))
        .pipe(rename('kege.min.css'))
        .pipe(sourcemaps.write(paths.output.maps))
        .pipe(gulp.dest(paths.output.css));
}

/**
 * GUARD: строгая проверка сборки всех SCSS-бандлов.
 * Без plumber/errorHandler — любая ошибка SASS роняет процесс (exit != 0),
 * чтобы CI/`npm run build:check` ловил поломки стилей (см. историю с _assessment.scss).
 * Вывод — во временный каталог (.scss-check, в .gitignore), реальные бандлы не трогаются.
 */
function stylesCheck() {
    return gulp.src([paths.scss.admin, paths.scss.frontend, paths.scss.common, paths.scss.profile, paths.scss.player, paths.scss.assessment, paths.scss.kege])
        .pipe(sass({ includePaths: [paths.scss.common] }))
        .pipe(gulp.dest('./.scss-check'));
}

/**
 * ОБРАБОТКА JS (AdminController & Frontend)
 */
function scripts() {
    return gulp.src([paths.js.admin, paths.js.frontend, paths.js.common, paths.js.profile, paths.js.player, paths.js.assessment, paths.js.kege])
        .pipe(guard())
        .pipe(named())
        .pipe(webpack(webpackConfig))
        .pipe(sourcemaps.write(paths.output.maps))
        .pipe(gulp.dest(paths.output.js))
        .pipe(notify({message: 'JS Modules processed!', onLast: true}));
}

/**
 * WATCHER
 */
function watchFiles() {
    watching = true;
    gulp.watch(paths.scss.watch, gulp.parallel(stylesAdmin, stylesFrontend, stylesProfile, stylesPlayer, stylesAssessment, stylesKege));
    gulp.watch(paths.js.watch, scripts);
    console.log('Gulp is watching and building modules...');
}

// Экспорт задач
const build = gulp.parallel(stylesCommon, stylesAdmin, stylesFrontend, stylesProfile, stylesPlayer, stylesAssessment, stylesKege, scripts);

exports['styles:common']     = stylesCommon;
exports['styles:admin']      = stylesAdmin;
exports['styles:frontend']   = stylesFrontend;
exports['styles:profile']    = stylesProfile;
exports['styles:player']     = stylesPlayer;
exports['styles:assessment'] = stylesAssessment;
exports['styles:kege']       = stylesKege;
exports['styles:check']      = stylesCheck;
exports['scripts'] = scripts;
exports.build = build;
exports.watch = watchFiles;
exports.default = gulp.series(build, watchFiles);