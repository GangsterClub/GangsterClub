/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './src/View/*.{html,twig,js}',
    './src/View/*/*.{html,twig,js}',
    './web/js/*.{html,js}',
  ],
  theme: {
    screens: {
      sm: '480px',
      md: '768px',
      lg: '976px',
      xl: '1440px',
    },
    fontFamily: {
      sans: ['Graphik', 'sans-serif'],
      serif: ['Merriweather', 'serif'],
    },
    extend: {
      spacing: {
        '128': '32rem',
        '144': '36rem',
      },
      borderRadius: {
        '4xl': '2rem',
      },
      backgroundImage: {
        'background': "url('../images/background.webp')",
        'logo': "url('../images/logo.webp')",
        'flag-de': "url('../images/flags/DE.png')",
        'flag-en': "url('../images/flags/EN.png')",
        'flag-fr': "url('../images/flags/FR.png')",
        'flag-nl': "url('../images/flags/NL.png')",
      }
    }
  },
  plugins: [],
}

