/** @type {import('tailwindcss').Config} */
module.exports = {
  // Solo el tema nuevo (UI v2). NO escanea las vistas legacy (Tobuli/Views).
  content: [
    './resources/views/ui/v2/**/*.blade.php',
    './resources/assets/ui-v2/js/**/*.js',
  ],
  // El cambio de tema ya usa <html data-theme="light|dark">.
  darkMode: ['selector', '[data-theme="dark"]'],
  corePlugins: {
    // Coexistencia con el design system .tp-* (tokens.css + components.css).
    // Sin preflight: Tailwind no resetea estilos globales, solo añade utilidades.
    preflight: false,
  },
  theme: {
    extend: {
      // Puente a los tokens CSS existentes para usarlos como clases Tailwind
      // (ej. bg-brand-500, text-neutral-700). Amplía según tokens.css.
      colors: {
        brand: {
          50: 'var(--brand-50)',
          100: 'var(--brand-100)',
          200: 'var(--brand-200)',
          300: 'var(--brand-300)',
          400: 'var(--brand-400)',
          500: 'var(--brand-500)',
          600: 'var(--brand-600)',
          700: 'var(--brand-700)',
          800: 'var(--brand-800)',
          900: 'var(--brand-900)',
        },
      },
    },
  },
  plugins: [],
};
