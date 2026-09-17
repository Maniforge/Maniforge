import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';

export default defineConfig({
  plugins: [react()],
  base: '/',
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'src'),
      '@maniforge/desk-ui': path.resolve(__dirname, '../../packages/desk-ui/src/index.ts'),
    },
  },
  build: {
    outDir: '../../../deploy/www-desk-react',
    emptyOutDir: true,
    rollupOptions: {
      output: {
        entryFileNames: 'assets/desk-app/[name]-[hash].js',
        chunkFileNames: 'assets/desk-app/[name]-[hash].js',
        assetFileNames: 'assets/desk-app/[name]-[hash][extname]',
      },
    },
  },
  server: {
    host: '127.0.0.1',
    port: 5175,
  },
});
