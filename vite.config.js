import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'path';
import { fileURLToPath } from 'url';

const dir = fileURLToPath(new URL('.', import.meta.url));

export default defineConfig({
  plugins: [react()],
  root: 'admin-app',
  base: './',
  build: {
    outDir: resolve(dir, 'dist/admin'),
    emptyOutDir: true,
    manifest: true,
    cssCodeSplit: false, // One stylesheet (wpal-admin.css) for the whole app.
    rollupOptions: {
      input: resolve(dir, 'admin-app/main.jsx'),
      output: {
        // Hashed and enqueued without ?ver, so lazy chunks import the very same module URL.
        entryFileNames: 'wpal-admin-[hash].js',
        chunkFileNames: 'wpal-admin-[name]-[hash].js',
        assetFileNames: (info) => {
          if (info.name?.endsWith('.css')) return 'wpal-admin.css';
          return '[name]-[hash][extname]';
        },
      },
    },
  },
  resolve: {
    alias: {
      '@': resolve(dir, 'admin-app'),
    },
  },
  test: {
    root: dir,
    include: ['admin-app/**/*.test.{js,jsx}'],
    environment: 'node',
  },
});
