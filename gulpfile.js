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
    },
    devtool: 'source-map'
};

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
        .pipe(postcss([autoprefixer(), cssnano()]))
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
        .pipe(postcss([autoprefixer(), cssnano()]))
        .pipe(rename('frontend.min.css'))
        .pipe(sourcemaps.write(paths.output.maps))
        .pipe(gulp.dest(paths.output.css));
}

function stylesProfile() {
    return gulp.src(paths.scss.profile)
        .pipe(guard())
        .pipe(sourcemaps.init())
        .pipe(sass())
        .pipe(postcss([autoprefixer(), cssnano()]))
        .pipe(rename('profile.min.css'))
        .pipe(sourcemaps.write(paths.output.maps))
        .pipe(gulp.dest(paths.output.css));
}

function stylesPlayer() {
    return gulp.src(paths.scss.player)
        .pipe(guard())
        .pipe(sourcemaps.init())
        .pipe(sass())
        .pipe(postcss([autoprefixer(), cssnano()]))
        .pipe(rename('player.min.css'))
        .pipe(sourcemaps.write(paths.output.maps))
        .pipe(gulp.dest(paths.output.css));
}

function stylesAssessment() {
    return gulp.src(paths.scss.assessment)
        .pipe(guard())
        .pipe(sourcemaps.init())
        .pipe(sass())
        .pipe(postcss([autoprefixer(), cssnano()]))
        .pipe(rename('assessment.min.css'))
        .pipe(sourcemaps.write(paths.output.maps))
        .pipe(gulp.dest(paths.output.css));
}

function stylesKege() {
    return gulp.src(paths.scss.kege)
        .pipe(guard())
        .pipe(sourcemaps.init())
        .pipe(sass())
        .pipe(postcss([autoprefixer(), cssnano()]))
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