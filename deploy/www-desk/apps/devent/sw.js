const CACHE_NAME = 'store-wms-v13';

/** Только shell; версии ?v= должны совпадать с HTML (иначе precache бесполезен). */
const PRECACHE = [
  '/app/store',
  '/app/manifest.webmanifest',
];

function isApiRequest(url) {
  return url.pathname.startsWith('/api/') || url.pathname === '/network';
}

function isAppStatic(url) {
  return url.pathname.startsWith('/app/');
}

function isDocument(request, url) {
  if (request.mode === 'navigate') return true;
  if (url.pathname === '/app/store' || url.pathname.endsWith('.html')) return true;
  return false;
}

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => cache.addAll(PRECACHE))
      .then(() => self.skipWaiting()),
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') return;

  const url = new URL(event.request.url);
  if (url.origin !== self.location.origin) return;

  if (isApiRequest(url)) {
    event.respondWith(
      fetch(event.request).catch(() => new Response(
        JSON.stringify({ detail: 'Нет сети' }),
        { status: 503, headers: { 'Content-Type': 'application/json' } },
      )),
    );
    return;
  }

  if (!isAppStatic(url)) return;

  // HTML: network-first — иначе залипают старые ?v= на CSS/JS
  if (isDocument(event.request, url)) {
    event.respondWith(
      fetch(event.request)
        .then((res) => {
          if (res.ok) {
            const clone = res.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
          }
          return res;
        })
        .catch(() => caches.match(event.request).then((c) => c || caches.match('/app/store'))),
    );
    return;
  }

  // CSS/JS: stale-while-revalidate
  event.respondWith(
    caches.match(event.request).then((cached) => {
      const network = fetch(event.request).then((res) => {
        if (res.ok) {
          caches.open(CACHE_NAME).then((cache) => cache.put(event.request, res.clone()));
        }
        return res;
      }).catch(() => cached);
      return cached || network;
    }),
  );
});
