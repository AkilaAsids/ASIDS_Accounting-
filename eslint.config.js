import js from '@eslint/js'
import vueTs from '@vue/eslint-config-typescript'
import pluginVue from 'eslint-plugin-vue'

export default [
  {
    ignores: ['public/build/**', 'vendor/**', 'node_modules/**', 'coverage/**'],
  },
  js.configs.recommended,
  ...pluginVue.configs['flat/recommended'],
  ...vueTs(),
  {
    rules: {
      '@typescript-eslint/no-explicit-any': 'error',
      '@typescript-eslint/consistent-type-imports': ['error', { prefer: 'type-imports' }],
      '@typescript-eslint/no-unused-vars': ['error', { argsIgnorePattern: '^_' }],
      'vue/multi-word-component-names': 'off',
      'vue/component-api-style': ['error', ['script-setup']],
      'vue/define-macros-order': ['error', { order: ['defineOptions', 'defineProps', 'defineEmits', 'defineSlots'] }],
      'vue/no-undef-components': 'off',
      eqeqeq: ['error', 'always'],
      'no-console': ['error', { allow: ['warn', 'error'] }],

      // Template *formatting* belongs to Prettier, which runs over the same files with a
      // printWidth of 100. `eslint-plugin-vue`'s recommended set also has opinions about attribute
      // wrapping, tag-content line breaks and self-closing tags, and they disagree with Prettier's
      // — so every file Prettier had formatted produced warnings, 385 of them, and `npm run lint`
      // failed on `--max-warnings 0` while the code was correctly formatted. Turning these off
      // makes the two tools agree on who owns what; nothing here changes what is *checked*, only
      // which tool checks it.
      'vue/max-attributes-per-line': 'off',
      'vue/singleline-html-element-content-newline': 'off',
      'vue/multiline-html-element-content-newline': 'off',
      'vue/html-self-closing': 'off',
      'vue/html-closing-bracket-newline': 'off',
      'vue/html-indent': 'off',
    },
  },
]
