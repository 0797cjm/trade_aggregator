const CACHE_NAME = 'bb-insulators-shell-v14';
const SHELL_ASSETS = [
  '/',
  '/index.html',
  '/app.js?v=job-highlights-v1',
  '/style.css?v=job-highlights-v1',
  '/locals.json',
  '/manifest.webmanifest',
  '/apple-touch-icon.png',
  '/icon-192.png',
  '/icon-512.png'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => cache.addAll(SHELL_ASSETS))
  );
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(
        keys
          .filter(key => key !== CACHE_NAME)
          .map(key => caches.delete(key))
      )
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', event => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);

  if (url.origin !== self.location.origin) return;

  if (url.pathname.endsWith('/scrape.php') || url.pathname.endsWith('/jobs.php') || url.pathname.endsWith('/news.php')) {
    event.respondWith(fetch(req));
    return;
  }

  const shellPaths = new Set([
    '/',
    '/index.html',
    '/app.js',
    '/style.css',
    '/locals.json'
  ]);
  if (shellPaths.has(url.pathname)) {
    event.respondWith(
      fetch(req)
        .then(networkRes => {
          if (networkRes && networkRes.status === 200 && networkRes.type === 'basic') {
            const resClone = networkRes.clone();
            caches.open(CACHE_NAME).then(cache => cache.put(req, resClone));
          }
          return networkRes;
        })
        .catch(() => caches.match(req).then(cached => cached || caches.match('/index.html')))
    );
    return;
  }

  event.respondWith(
    caches.match(req).then(cached => {
      if (cached) return cached;
      return fetch(req).then(networkRes => {
        if (networkRes && networkRes.status === 200 && networkRes.type === 'basic') {
          const resClone = networkRes.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(req, resClone));
        }
        return networkRes;
      });
    }).catch(() => caches.match('/index.html'))
  );
});
