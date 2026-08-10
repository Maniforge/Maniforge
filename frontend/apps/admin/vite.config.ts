import { defineConfig, type Plugin } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';

/** GET / → /app/ (base: '/app/' в dev и prod). */
function redirectRootToApp(): Plugin {
  return {
    name: 'redirect-root-to-app',
    configureServer(server) {
      server.middlewares.use((req, res, next) => {
        if (req.method !== 'GET' && req.method !== 'HEAD') {
          next();
          return;
        }
        const url = (req.url ?? '').split('?')[0];
        if (url === '/' || url === '') {
          res.writeHead(302, { Location: '/app/' });
          res.end();
          return;
        }
        next();
      });
    },
  };
}

export default defineConfig({
  plugins: [redirectRootToApp(), react()],
  base: '/app/',
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'src'),
      '@assets': path.resolve(__dirname, '../../../public/assets'),
    },
  },
  build: {
    outDir: '../../../public/app',
    emptyOutDir: true,
  },
  server: {
    host: '127.0.0.1',
    port: 5173,
    proxy: {
      // Go-сервисы — ДО catch-all '/', иначе /rbac уходит на PHP :8092 → HTTP 500
      '/rbac': {
        target: 'http://127.0.0.1:8093',
        changeOrigin: true,
      },
      '/manifest-engine': {
        target: 'http://127.0.0.1:8095',
        changeOrigin: true,
        rewrite: (path) => path.replace(/^\/manifest-engine/, ''),
      },
      '/realtime': {
        target: 'http://127.0.0.1:8097',
        changeOrigin: true,
        rewrite: (path) => path.replace(/^\/realtime/, ''),
        ws: true,
      },
      '/warehouses': {
        target: 'http://127.0.0.1:8098',
        changeOrigin: true,
        rewrite: (path) => path.replace(/^\/warehouses/, ''),
      },
      // Лендинг и PHP-страницы при открытии :5173/ (не /app)
      '/': {
        target: 'http://127.0.0.1:8092',
        changeOrigin: true,
        bypass(req) {
          const path = (req.url ?? '').split('?')[0];
          if (path === '/' || path === '') {
            return req.url;
          }
          if (
            path.startsWith('/app') ||
            path.startsWith('/@') ||
            path.startsWith('/src') ||
            path.startsWith('/node_modules') ||
            path.startsWith('/rbac') ||
            path.startsWith('/manifest-engine') ||
            path.startsWith('/realtime') ||
            path.startsWith('/warehouses')
          ) {
            return req.url;
          }
          return null;
        },
      },
    },
  },
});
