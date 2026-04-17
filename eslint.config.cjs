const js = require("@eslint/js");
const wp = require("@wordpress/eslint-plugin");

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

        plugins: {
            "@wordpress": wp,
        },

        rules: {
            ...wp.configs.recommended.rules,
            "no-console": "off",
        }
    }
];