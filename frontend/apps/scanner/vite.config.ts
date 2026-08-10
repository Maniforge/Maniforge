import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';

export default defineConfig({
  plugins: [react()],
  base: '/scanner/',
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'src'),
      '@assets': path.resolve(__dirname, '../../../public/assets'),
    },
  },
  build: {
    outDir: '../../../public/scanner',
    emptyOutDir: true,
  },
  server: {
    port: 5174,
    proxy: {
      '/': {
        target: 'http://127.0.0.1:8092',
        changeOrigin: true,
        bypass(req) {
          const path = (req.url ?? '').split('?')[0];
          if (
            path.startsWith('/scanner') ||
            path.startsWith('/@') ||
            path.startsWith('/src') ||
            path.startsWith('/node_modules')
          ) {
            return req.url;
          }
          return null;
        },
      },
      '/rbac': {
        target: 'http://127.0.0.1:8093',
        changeOrigin: true,
      },
      '/wms': {
        target: 'http://127.0.0.1:8092',
        changeOrigin: true,
      },
      '/warehouses': {
        target: 'http://127.0.0.1:8098',
        changeOrigin: true,
        rewrite: (path) => path.replace(/^\/warehouses/, ''),
      },
    },
  },
});
