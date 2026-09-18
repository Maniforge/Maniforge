import { defineConfig, type Plugin } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';

function deskSpaCssLast(): Plugin {
  return {
    name: 'desk-spa-css-last',
    transformIndexHtml(html) {
      const tag = '<link rel="stylesheet" href="/assets/css/desk-spa.css" />';
      const stripped = html.replace(/\s*<link rel="stylesheet" href="\/assets\/css\/desk-spa\.css"\s*\/?>/g, '');
      return stripped.replace('</head>', `    ${tag}\n  </head>`);
    },
  };
}

export default defineConfig({
  plugins: [react(), deskSpaCssLast()],
  base: '/scanner/',
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'src'),
      '@assets': path.resolve(__dirname, '../../../public/assets'),
      '@maniforge/desk-ui': path.resolve(__dirname, '../../packages/desk-ui/src/index.ts'),
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
