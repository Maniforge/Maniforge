import { cpSync, existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '../../../..');
const built = join(root, 'deploy/www-desk-react');
const dest = join(root, 'deploy/www-desk');
const htmlPath = join(built, 'index.html');

if (!existsSync(htmlPath)) {
  console.error('desk SPA build missing:', htmlPath);
  process.exit(1);
}

const html = readFileSync(htmlPath, 'utf8');
const assetSrc = join(built, 'assets/desk-app');
if (existsSync(assetSrc)) {
  cpSync(assetSrc, join(dest, 'assets/desk-app'), { recursive: true });
}

const routes = ['', 'about', 'about-us', 'api', 'desk', 'desk/login', 'desk/users'];
for (const route of routes) {
  const dir = route ? join(dest, route) : dest;
  mkdirSync(dir, { recursive: true });
  writeFileSync(join(dir, 'index.html'), html);
}

const catalogSrc = join(dest, 'assets/api-docs-catalog.json');
if (existsSync(catalogSrc) && existsSync(join(built, 'assets'))) {
  mkdirSync(join(built, 'assets'), { recursive: true });
}

for (const spa of ['app', 'scanner']) {
  const from = join(root, 'public', spa);
  const to = join(dest, spa);
  if (existsSync(join(from, 'index.html'))) {
    cpSync(from, to, { recursive: true });
  }
}

console.log('desk SPA indexes copied');
