/**
 * Tailwind CSS build configuration for KBF-UK.
 *
 * The pages used to load the Tailwind "play CDN" (cdn.tailwindcss.com, which
 * served v3.4.17) with no inline tailwind.config, so they ran on Tailwind's
 * default theme. This file reproduces that: default theme, default dark mode
 * (media), no plugins, no prefix, no important.
 *
 * Build: see docs/BUILD.md.
 */
module.exports = {
    content: [
        './*.html',
        './admin/**/*.html',
        './clubadmin/**/*.html',
        './js/**/*.js',
        '!./js/vendor/**',
        './assets/js/**/*.js'
    ],
    // Classes that the file scanner cannot see. Today every class in the HTML
    // and JS is written out in full (no 'bg-' + colour), so this only guards
    // the theme swatch colours used by the theme switchers.
    safelist: [
        'bg-emerald-500',
        'bg-cyan-500',
        'bg-yellow-400',
        'bg-orange-500'
    ],
    theme: {
        extend: {}
    },
    plugins: []
};
