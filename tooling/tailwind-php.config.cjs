// PHP pages use Tailwind 3 (the React/Vite app has its own Tailwind 4 build).
module.exports = {
  content: { relative: true, files: ['../*.php', '../pages/**/*.php', '../includes/**/*.php', '../assets/js/**/*.js'] },
  theme: {
    extend: {
      colors: {
        brand: {
          50: '#ecfdf5', 100: '#d1fae5', 500: '#10b981',
          600: '#059669', 700: '#047857', 800: '#065f46', 900: '#064e3b',
        },
      },
    },
  },
};
