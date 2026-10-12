const js = require("@eslint/js");
const wp = require("@wordpress/eslint-plugin");
const globals = require("globals");

module.exports = [
    {
        ignores: [
            "node_modules/**",
            "vendor/**",
            "build/**",
            "dist/**",
            "**/*.min.js"
        ]
    },

    {
        files: ["src/js/**/*.js"],

        ...js.configs.recommended,

        languageOptions: {
            ecmaVersion: "latest",
            sourceType: "module",
            globals: {
                ...globals.browser,
                // Глобалы WordPress (wp_localize_script и скрипты ядра) и webpack.
                jQuery: "readonly", $: "readonly", wp: "readonly", tinyMCE: "readonly", require: "readonly",
                fs_lms_vars: "readonly", fs_lms_task_data: "readonly", fs_lms_article_data: "readonly",
                fs_lms_applications_vars: "readonly", fsLmsModules: "readonly",
            },
        },

        plugins: {
            "@wordpress": wp,
        },

        rules: {
            ...wp.configs.recommended.rules,
            "no-console": "off",
            "no-alert": "error",
            // Необъявленная переменная в шаблонной строке — ReferenceError только при показе формы (так не открывались сеансы экзамена).
            "no-undef": "error",
        }
    }
];