/**
 * Tailwind is scoped to the React app so it never restyles the rest of wp-admin:
 * - `important: '#wpal-root'` prefixes every utility with the root id (and wins over wp-admin CSS).
 * - Preflight is off; a reset scoped to #wpal-root lives in admin-app/index.css.
 *
 * @type {import('tailwindcss').Config}
 */
export default {
  content: ['./admin-app/**/*.{js,jsx}'],
  important: '#wpal-root',
  corePlugins: {
    preflight: false,
    container: false, // Its unscoped .container rule would restyle wp-admin elements.
  },
  theme: {
    extend: {
      colors: {
        border: 'hsl(var(--wpal-border))',
        input: 'hsl(var(--wpal-input))',
        ring: 'hsl(var(--wpal-ring))',
        background: 'hsl(var(--wpal-background))',
        foreground: 'hsl(var(--wpal-foreground))',
        primary: {
          DEFAULT: 'hsl(var(--wpal-primary))',
          foreground: 'hsl(var(--wpal-primary-foreground))',
        },
        secondary: {
          DEFAULT: 'hsl(var(--wpal-secondary))',
          foreground: 'hsl(var(--wpal-secondary-foreground))',
        },
        destructive: {
          DEFAULT: 'hsl(var(--wpal-destructive))',
          foreground: 'hsl(var(--wpal-destructive-foreground))',
        },
        muted: {
          DEFAULT: 'hsl(var(--wpal-muted))',
          foreground: 'hsl(var(--wpal-muted-foreground))',
        },
        accent: {
          DEFAULT: 'hsl(var(--wpal-accent))',
          foreground: 'hsl(var(--wpal-accent-foreground))',
        },
        popover: {
          DEFAULT: 'hsl(var(--wpal-popover))',
          foreground: 'hsl(var(--wpal-popover-foreground))',
        },
        card: {
          DEFAULT: 'hsl(var(--wpal-card))',
          foreground: 'hsl(var(--wpal-card-foreground))',
        },
      },
      borderRadius: {
        lg: 'var(--wpal-radius)',
        md: 'calc(var(--wpal-radius) - 2px)',
        sm: 'calc(var(--wpal-radius) - 4px)',
      },
    },
  },
  plugins: [],
};
