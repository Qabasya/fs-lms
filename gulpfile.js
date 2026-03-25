/**
 * Gulp configuration file for Future Step LMS plugin asset processing
 *
 * Handles compilation, minification, and optimization of SCSS and JavaScript assets
 * for both admin and frontend sections of the Future Step LMS WordPress plugin.
 *
 * @fileOverview Gulp build system configuration
 * @version 0.0.1
 * @license MIT
 */

const gulp = require('gulp');
const sass = require('gulp-sass')(require('sass'));
const postcss = require('gulp-postcss');
const autoprefixer = require('autoprefixer');
const cssnano = require('cssnano');
const sourcemaps = require('gulp-sourcemaps');
const uglify = require('gulp-uglify');
const concat = require('gulp-concat');
const notify = require('gulp-notify');
const plumber = require('gulp-plumber');
const rename = require('gulp-rename');

/**
 * File paths configuration for separated admin and frontend assets
 *
 * @namespace Paths
 */
const paths = {
    // Source directories
    scss: {
        admin: './src/scss/admin/admin.scss',
        frontend: './src/scss/frontend/frontend.scss',
        common: './src/scss/common/',
        watch: './src/scss/**/*.scss'
    },
    js: {
        admin: './src/js/admin/**/*.js',
        frontend: './src/js/frontend/**/*.js',
        common: './src/js/common/**/*.js',
        watch: './src/js/**/*.js'
    },

    // Output directories
    output: {
        css: './assets/css/',
        js: './assets/js/',
        maps: './maps/'
    }
};

/**
 * Browser support configuration for Autoprefixer
 */
const browserSupport = ['last 2 versions', '> 1%', 'ie >= 11'];

/**
 * Error handler function for Gulp pipelines
 */
const errorHandler = function (err) {
    notify.onError({
        title: "Gulp error in " + err.plugin,
        message: err.toString()
    })(err);
    this.emit('end');
};

/**
 * Processes Admin SCSS files
 *
 * Compiles admin-specific SCSS with WordPress admin styling considerations
 */
function adminStyles() {
    return gulp
        .src(paths.scss.admin)
        .pipe(plumber({errorHandler: errorHandler}))
        .pipe(sourcemaps.init())
        .pipe(sass({
            outputStyle: 'expanded',
            includePaths: [paths.scss.common]
        }).on('error', sass.logError))
        .pipe(postcss([
            autoprefixer({overrideBrowserslist: browserSupport}),
            cssnano({
                preset: ['default', {
                    discardComments: {removeAll: true}
                }]
            })
        ]))
        .pipe(rename('admin.min.css'))
        .pipe(sourcemaps.write(paths.output.maps))
        .pipe(gulp.dest(paths.output.css))
        .pipe(notify({message: 'Admin CSS processed!', onLast: true}));
}

/**
 * Processes Frontend SCSS files
 *
 * Compiles frontend-specific SCSS with responsive design considerations
 */
function frontendStyles() {
    return gulp
        .src(paths.scss.frontend)
        .pipe(plumber({errorHandler: errorHandler}))
        .pipe(sourcemaps.init())
        .pipe(sass({
            outputStyle: 'expanded',
            includePaths: [paths.scss.common]
        }).on('error', sass.logError))
        .pipe(postcss([
            autoprefixer({overrideBrowserslist: browserSupport}),
            cssnano({
                preset: ['default', {
                    discardComments: {removeAll: true}
                }]
            })
        ]))
        .pipe(rename('frontend.min.css'))
        .pipe(sourcemaps.write(paths.output.maps))
        .pipe(gulp.dest(paths.output.css))
        .pipe(notify({message: 'Frontend CSS processed!', onLast: true}));
}

/**
 * Processes Admin JavaScript files
 *
 * Handles admin-specific JavaScript with WordPress admin dependencies
 */
function adminScripts() {
    return gulp
        .src([
            paths.js.common,
            paths.js.admin
        ])
        .pipe(plumber({errorHandler: errorHandler}))
        .pipe(sourcemaps.init())
        .pipe(concat('admin.min.js'))
        .pipe(uglify())
        .pipe(sourcemaps.write(paths.output.maps))
        .pipe(gulp.dest(paths.output.js))
        .pipe(notify({message: 'Admin JS processed!', onLast: true}));
}

/**
 * Processes Frontend JavaScript files
 *
 * Handles frontend-specific JavaScript with responsive behavior
 */
function frontendScripts() {
    return gulp
        .src([
            paths.js.common,
            paths.js.frontend
        ])
        .pipe(plumber({errorHandler: errorHandler}))
        .pipe(sourcemaps.init())
        .pipe(concat('frontend.min.js'))
        .pipe(uglify())
        .pipe(sourcemaps.write(paths.output.maps))
        .pipe(gulp.dest(paths.output.js))
        .pipe(notify({message: 'Frontend JS processed!', onLast: true}));
}

/**
 * Watches all source files for changes
 */
function watchFiles() {
    // Watch SCSS files
    gulp.watch(paths.scss.watch, gulp.parallel(adminStyles, frontendStyles));

    // Watch JS files
    gulp.watch(paths.js.watch, gulp.parallel(adminScripts, frontendScripts));

    console.log('Gulp is watching for file changes in admin and frontend...');
}

/**
 * Builds all assets without watching
 */
const build = gulp.parallel(adminStyles, frontendStyles, adminScripts, frontendScripts);

/**
 * Default task
 */
const defaultTask = gulp.series(build, watchFiles);

// Export tasks
exports['styles:admin'] = adminStyles;
exports['styles:frontend'] = frontendStyles;
exports['scripts:admin'] = adminScripts;
exports['scripts:frontend'] = frontendScripts;
exports.watch = watchFiles;
exports.build = build;
exports.default = defaultTask;