import js from '@eslint/js';
import pluginVue from 'eslint-plugin-vue';
import tseslint from 'typescript-eslint';
import globals from 'globals';

/*
 * ESLint 9 flat configuration.
 *
 * Replaces .eslintrc.cjs, which ESLint 9 no longer reads at all — `npm run
 * lint` failed outright with "couldn't find an eslint.config file", meaning the
 * frontend had never actually been linted. The legacy file also named
 * @typescript-eslint/parser without it being a dependency, so it could not have
 * run even under ESLint 8; typescript-eslint is now an explicit devDependency.
 *
 * Every rule decision from the old config is carried over deliberately, with
 * the original reasoning preserved.
 */
export default tseslint.config(
    {
        // Build output, dependencies and PHP vendor code are never linted.
        ignores: [
            'public/build/**',
            'public/hot',
            'vendor/**',
            'node_modules/**',
            'storage/**',
            'bootstrap/cache/**',
        ],
    },

    js.configs.recommended,
    ...tseslint.configs.recommended,
    ...pluginVue.configs['flat/recommended'],

    {
        files: ['resources/js/**/*.{ts,vue}'],
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            globals: {
                ...globals.browser,
                ...globals.es2023,
            },
            parserOptions: {
                // vue-eslint-parser owns .vue files and delegates <script lang="ts">
                // to the TypeScript parser.
                parser: tseslint.parser,
                extraFileExtensions: ['.vue'],
            },
        },
        rules: {
            // Page components are named by their directory path (Notifications/
            // Show.vue), so single-word filenames are correct here.
            'vue/multi-word-component-names': 'off',

            // Long Tailwind class strings are wrapped by hand for readability;
            // these two rules fight that formatting.
            'vue/max-attributes-per-line': 'off',
            'vue/singleline-html-element-content-newline': 'off',

            // Physical CSS properties break RTL. Logical properties (ms-/me-/
            // ps-/pe-) are the house style; postcss-rtlcss is only the safety net.
            'no-restricted-syntax': 'off',

            // Inertia props arrive named by the PHP controller, which is PSR-12
            // snake_case. Renaming them in Vue to satisfy a style rule would
            // silently break the server contract, so the wire format wins.
            'vue/prop-name-casing': 'off',

            // .editorconfig mandates 4 spaces for .vue; the plugin defaults to 2.
            // Left at the default this produced 3,796 warnings against code that
            // was correctly following the project's own documented standard.
            'vue/html-indent': ['warn', 4],
            'vue/script-indent': ['warn', 4, { baseIndent: 0 }],

            // Unused arguments are allowed when prefixed with an underscore,
            // which is how deliberately-ignored callback parameters are marked.
            '@typescript-eslint/no-unused-vars': [
                'error',
                { argsIgnorePattern: '^_', varsIgnorePattern: '^_' },
            ],
        },
    },

    {
        // Node-context tooling files: config and CI scripts.
        files: ['*.config.{js,ts}', 'scripts/**/*.{js,mjs}'],
        languageOptions: {
            globals: { ...globals.node },
        },
    },
);
