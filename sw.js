// Service Worker para WMS Prime
const CACHE_NAME = 'wms-prime-v2.0';
const STATIC_ASSETS = [
    './',
    './index.html',
    './css/style.css?v=2.0',
    './js/app.js?v=2.0',
    './js/audio.js?v=2.0',
    './js/scanner.js?v=2.0',
    './js/html5-qrcode.min.js',
    './manifest.json',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
    'https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js',
    'https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js'
];

// Instalação: Cache dos arquivos estáticos fundamentais
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(STATIC_ASSETS).catch((err) => {
                console.warn('[SW] Aviso ao pré-carregar alguns assets estáticos:', err);
            });
        }).then(() => self.skipWaiting())
    );
});

// Ativação: Limpeza de caches antigos
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => {
            return Promise.all(
                keys.map((key) => {
                    if (key !== CACHE_NAME) {
                        return caches.delete(key);
                    }
                })
            );
        }).then(() => self.clients.claim())
    );
});

// Interceptação de requisições
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    // Requisições para a API PHP -> Network First (não cacheia mutações ou dados em tempo real)
    if (url.pathname.includes('/api/')) {
        event.respondWith(
            fetch(event.request).catch(() => {
                return new Response(JSON.stringify({
                    success: false,
                    offline: true,
                    error: 'Sem conexão com a rede local. Verifique seu Wi-Fi.'
                }), {
                    headers: { 'Content-Type': 'application/json' },
                    status: 503
                });
            })
        );
        return;
    }

    // Requisições estáticas -> Cache First com Stale-While-Revalidate
    event.respondWith(
        caches.match(event.request).then((cachedResponse) => {
            const fetchPromise = fetch(event.request).then((networkResponse) => {
                if (networkResponse && networkResponse.status === 200) {
                    const responseClone = networkResponse.clone();
                    caches.open(CACHE_NAME).then((cache) => {
                        cache.put(event.request, responseClone);
                    });
                }
                return networkResponse;
            }).catch(() => cachedResponse);

            return cachedResponse || fetchPromise;
        })
    );
});
