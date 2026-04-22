/**
 * Gulp configuration for Future Step LMS
 * * Сохранена оригинальная структура путей и имен файлов.
 * Добавлена поддержка ES6 Модулей (import/export) через Webpack.
 */

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
        watch: './src/scss/**/*.scss'
    },
    js: {
        // Точки входа (основные файлы, которые импортируют модули)
        admin: './src/js/admin/admin.js',
        frontend: './src/js/frontend/frontend.js',
        common: './src/js/common/common.js',
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
 * ОБРАБОТКА CSS (AdminController & Frontend & Common)
 */
function stylesCommon() {
    return gulp.src(paths.scss.common)
        .pipe(plumber({errorHandler}))
        .pipe(sourcemaps.init())
        .pipe(sass())
        .pipe(postcss([autoprefixer(), cssnano()]))
        .pipe(rename('common.min.css'))
        .pipe(sourcemaps.write(paths.output.maps))
        .pipe(gulp.dest(paths.output.css));
}

function stylesAdmin() {
    return gulp.src(paths.scss.admin)
        .pipe(plumber({errorHandler}))
        .pipe(sourcemaps.init())
        .pipe(sass({ includePaths: [paths.scss.common] }))
        .pipe(postcss([autoprefixer(), cssnano()]))
        .pipe(rename('admin.min.css'))
        .pipe(sourcemaps.write(paths.output.maps))
        .pipe(gulp.dest(paths.output.css));
}

function stylesFrontend() {
    return gulp.src(paths.scss.frontend)
        .pipe(plumber({errorHandler}))
        .pipe(sourcemaps.init())
        .pipe(sass({ includePaths: [paths.scss.common] }))
        .pipe(postcss([autoprefixer(), cssnano()]))
        .pipe(rename('frontend.min.css'))
        .pipe(sourcemaps.write(paths.output.maps))
        .pipe(gulp.dest(paths.output.css));
}



/**
 * ОБРАБОТКА JS (AdminController & Frontend)
 */
function scripts() {
    // Берем оба входных файла сразу
    return gulp.src([paths.js.admin, paths.js.frontend, paths.js.common])
        .pipe(plumber({errorHandler}))
        .pipe(named()) // Важно: сохраняет имена 'admin' и 'frontend' для Webpack
        .pipe(webpack(webpackConfig))
        .pipe(sourcemaps.write(paths.output.maps))
        .pipe(gulp.dest(paths.output.js))
        .pipe(notify({message: 'JS Modules processed!', onLast: true}));
}

/**
 * WATCHER
 */
function watchFiles() {
    gulp.watch(paths.scss.watch, gulp.parallel(stylesAdmin, stylesFrontend));
    gulp.watch(paths.js.watch, scripts);
    console.log('Gulp is watching and building modules...');
}

// Экспорт задач
const build = gulp.parallel(stylesCommon, stylesAdmin, stylesFrontend, scripts);

exports['styles:common']   = stylesCommon;
exports['styles:admin']    = stylesAdmin;
exports['styles:frontend'] = stylesFrontend;
exports['scripts'] = scripts;
exports.build = build;
exports.watch = watchFiles;
exports.default = gulp.series(build, watchFiles);