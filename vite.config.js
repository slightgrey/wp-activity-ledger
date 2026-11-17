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
    rollupOptions: {
      input: resolve(dir, 'admin-app/main.jsx'),
      output: {
        entryFileNames: 'wpal-admin.js',
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
