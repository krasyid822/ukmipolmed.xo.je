/**
 * cloudflare_worker_redirector.js
 * Cloudflare Worker untuk link preview WhatsApp/Telegram/sosmed dan redirect
 * pengunjung manusia ke halaman laporan di InfinityFree.
 *
 * Mengambil og_meta/<id>.json dan dokumentasi/<id>.webp (atau .jpg) langsung dari Blomp S3
 * menggunakan signed GET (AWS SigV4) agar tidak terblokir JS Bot Challenge
 * InfinityFree maupun blokir GET anonim Blomp.
 *
 * Environment Variables / Secrets di Cloudflare Worker:
 *   S3_ACCESS_KEY (wajib)
 *   S3_SECRET_KEY (wajib)
 *   S3_ENDPOINT   (opsional, default: https://storage.blomp.com)
 *   S3_REGION     (opsional, default: us-east-1)
 *   S3_BUCKET     (opsional, default: krasyid822-at-gmail.com)
 *   S3_PREFIX     (opsional, default: ukmipolmed/terpadu/mai/mentoring/)
 *   VIEW_BASE     (opsional, default: https://ukmipolmed.my.id/terpadu/mai/mentoring/view)
 */

const DEFAULTS = {
    S3_ENDPOINT: "https://storage.blomp.com",
    S3_REGION: "us-east-1",
    S3_BUCKET: "krasyid822-at-gmail.com",
    S3_PREFIX: "ukmipolmed/terpadu/mai/mentoring/",
    VIEW_BASE: "https://ukmipolmed.my.id/terpadu/mai/mentoring/view",
    APP_LAPORAN_URL: "https://ukmipolmed.my.id/terpadu/mai/mentoring/?source=pwa",
    APP_DAFTAR_URL: "https://ukmipolmed.my.id/terpadu/mai/mentoring/daftar?source=pwa",
    BLOG_S3_PREFIX: "ukmipolmed",
    BLOG_BASE: "https://ukmipolmed.my.id",
};

const EMPTY_SHA256 = "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855";

const WORKER_SW_SCRIPT = `self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', (event) => {
    if (event.request.method !== 'GET') return;
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request).catch(() => {
                const url = new URL(event.request.url);
                const isDaftar = url.pathname.includes('daftar') || url.searchParams.get('mode') === 'daftar';
                const target = isDaftar ? ${JSON.stringify(DEFAULTS.APP_DAFTAR_URL)} : ${JSON.stringify(DEFAULTS.APP_LAPORAN_URL)};
                const html = '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8">' +
                    '<meta http-equiv="refresh" content="0; url=' + target + '">' +
                    '<title>Membuka Aplikasi...</title></head><body>' +
                    '<script>window.location.replace(' + JSON.stringify(target) + ');</script>' +
                    '<p>Membuka aplikasi offline... <a href="' + target + '">Lanjutkan</a></p>' +
                    '</body></html>';
                return new Response(html, {
                    status: 200,
                    headers: { 'Content-Type': 'text/html; charset=utf-8' }
                });
            })
        );
        return;
    }
    event.respondWith(fetch(event.request));
});
`;

function isPwaAnalyzer(request, url) {
    if (url.searchParams.get("pwabuilder") === "1") {
        return true;
    }
    const ua = request.headers.get("User-Agent") || "";
    return /PWABuilder|HeadlessChrome|Lighthouse|PageSpeed|GTmetrix/i.test(ua);
}

function buildPwaLandingResponse(url, mode, autoRedirect = false) {
    const isLaporan = mode !== "daftar";
    const targetAppUrl = isLaporan ? DEFAULTS.APP_LAPORAN_URL : DEFAULTS.APP_DAFTAR_URL;
    const manifestUrl = `${url.origin}/pwa/${isLaporan ? "manifest-laporan.webmanifest" : "manifest-daftar.webmanifest"}`;
    const title = isLaporan
        ? "Pelaporan Mentoring Agama Islam UKMI Polmed"
        : "Pendaftaran Mentoring Agama Islam UKMI Polmed";
    const iconUrl = `${url.origin}/pwa/${isLaporan ? "icon-192x192.png" : "icon-daftar-192x192.png"}`;
    const redirectScript = autoRedirect
        ? `
        var redirected = false;
        function goApp() {
            if (redirected) return;
            redirected = true;
            window.location.replace(${JSON.stringify(targetAppUrl)});
        }
        setTimeout(goApp, 800);
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/pwa/sw.js', { scope: '/' })
                .then(goApp)
                .catch(goApp);
        } else {
            goApp();
        }`
        : `
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/pwa/sw.js', { scope: '/' }).catch(function () {});
        }`;
    const html = `<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>${escapeHtml(title)}</title>
    <link rel="manifest" href="${escapeHtml(manifestUrl)}">
    <link rel="icon" type="image/png" href="${escapeHtml(iconUrl)}">
    <link rel="apple-touch-icon" href="${escapeHtml(iconUrl)}">
    <meta name="theme-color" content="#0284c7">
</head>
<body>
    <p>Membuka <a href="${escapeHtml(targetAppUrl)}">${escapeHtml(title)}</a>...</p>
    <script>${redirectScript}
    </script>
</body>
</html>`;
    return new Response(html, {
        status: 200,
        headers: {
            "Content-Type": "text/html; charset=utf-8",
            "Cache-Control": autoRedirect ? "no-cache" : "public, max-age=3600",
        },
    });
}

async function withEdgeCache(request, ctx, ttlSeconds, negativeTtlSeconds, producer) {
    if (request.method !== "GET" || typeof caches === "undefined" || !caches.default) {
        return producer();
    }
    const cache = caches.default;
    const cacheKey = new Request(request.url, { method: "GET" });
    const cached = await cache.match(cacheKey);
    if (cached) {
        return cached;
    }
    const response = await producer();
    if (!response) {
        return response;
    }
    let cacheTtl = 0;
    if (response.status === 200 && ttlSeconds > 0) {
        cacheTtl = ttlSeconds;
    } else if (response.status === 404 && negativeTtlSeconds > 0) {
        cacheTtl = negativeTtlSeconds;
    }
    if (cacheTtl > 0) {
        const headers = new Headers(response.headers);
        if (!headers.has("Cache-Control") || response.status === 404) {
            headers.set("Cache-Control", `public, max-age=${cacheTtl}`);
        }
        const toCache = new Response(response.clone().body, {
            status: response.status,
            statusText: response.statusText,
            headers,
        });
        if (ctx && typeof ctx.waitUntil === "function") {
            ctx.waitUntil(cache.put(cacheKey, toCache));
        } else {
            await cache.put(cacheKey, toCache);
        }
    }
    return response;
}

export default {
    async fetch(request, env, ctx) {
        const url = new URL(request.url);

        // Service Worker ringan pada origin worker agar PWABuilder mendeteksi SW valid
        if (url.pathname === "/pwa/sw.js" || url.pathname === "/sw.js") {
            return new Response(WORKER_SW_SCRIPT, {
                status: 200,
                headers: {
                    "Content-Type": "application/javascript; charset=utf-8",
                    "Service-Worker-Allowed": "/",
                    "Cache-Control": "public, max-age=3600",
                },
            });
        }

        // Route aset & landing PWA: /pwa/<filename> atau /pwa/laporan | /pwa/daftar
        if (url.pathname.startsWith("/pwa/")) {
            const pwaName = url.pathname.slice("/pwa/".length).replace(/^\/+/, "");
            if (pwaName === "laporan" || pwaName === "daftar") {
                return buildPwaLandingResponse(url, pwaName, !isPwaAnalyzer(request, url));
            }

            if (!/^[A-Za-z0-9._-]+\.(webmanifest|png)$/i.test(pwaName)) {
                return new Response("Aset PWA tidak valid", { status: 400 });
            }
            const isManifest = pwaName.toLowerCase().endsWith(".webmanifest");
            const pwaTtl = isManifest ? 3600 : 2592000;
            return withEdgeCache(request, ctx, pwaTtl, 120, async () => {
                const res = await s3Get(env, `pwa/${pwaName}`);
                if (!res || !res.ok) {
                    return new Response("Aset PWA tidak ditemukan", { status: 404 });
                }
                const headers = {
                    "Content-Type": isManifest ? "application/manifest+json; charset=utf-8" : "image/png",
                    "Access-Control-Allow-Origin": "*",
                    "Cache-Control": `public, max-age=${pwaTtl}`,
                    "X-Content-Type-Options": "nosniff",
                };
                const contentLength = res.headers.get("content-length");
                if (contentLength) {
                    headers["Content-Length"] = contentLength;
                }
                return new Response(res.body, { status: 200, headers });
            });
        }

        // Route gambar dokumentasi: ?img=<reportId>
        const imgId = url.searchParams.get("img") || "";
        if (imgId !== "") {
            if (!/^[A-Za-z0-9_-]+$/.test(imgId)) {
                return new Response("ID Gambar tidak valid", { status: 400 });
            }
            return withEdgeCache(request, ctx, 2592000, 120, async () => {
                let res = await s3Get(env, `dokumentasi/${imgId}.webp`);
                let contentType = "image/webp";
                if (!res || !res.ok) {
                    res = await s3Get(env, `dokumentasi/${imgId}.jpg`);
                    contentType = "image/jpeg";
                }
                if (!res || !res.ok) {
                    return new Response("Gambar tidak ditemukan", { status: 404 });
                }
                const headers = {
                    "Content-Type": contentType,
                    "Cache-Control": "public, max-age=2592000",
                    "X-Content-Type-Options": "nosniff",
                };
                const contentLength = res.headers.get("content-length");
                if (contentLength) {
                    headers["Content-Length"] = contentLength;
                }
                return new Response(res.body, {
                    status: 200,
                    headers,
                });
            });
        }

        // Route gambar blog: /blog-img?key=<s3Key> atau /blog-img?slug=<slug>
        if (url.pathname === "/blog-img") {
            const blogPrefix = String(env.BLOG_S3_PREFIX || DEFAULTS.BLOG_S3_PREFIX).replace(/^\/+|\/+$/g, "");
            let imgKey = url.searchParams.get("key") || "";
            if (imgKey !== "") {
                const mime = isAllowedBlogImageKey(imgKey, blogPrefix);
                if (!mime) {
                    return new Response("Kunci gambar tidak valid", { status: 400 });
                }
                return withEdgeCache(request, ctx, 2592000, 120, async () => {
                    const res = await s3GetRaw(env, imgKey);
                    if (!res || !res.ok) {
                        return new Response("Gambar tidak ditemukan", { status: 404 });
                    }
                    const headers = {
                        "Content-Type": mime,
                        "Cache-Control": "public, max-age=2592000",
                        "X-Content-Type-Options": "nosniff",
                    };
                    const contentLength = res.headers.get("content-length");
                    if (contentLength) {
                        headers["Content-Length"] = contentLength;
                    }
                    return new Response(res.body, { status: 200, headers });
                });
            }

            const imgSlug = url.searchParams.get("slug") || "";
            if (!/^[A-Za-z0-9._-]+$/.test(imgSlug)) {
                return new Response("Slug gambar tidak valid", { status: 400 });
            }
            return withEdgeCache(request, ctx, 2592000, 120, async () => {
                const post = await findBlogPost(env, blogPrefix, imgSlug, ctx);
                if (!post) {
                    return new Response("Postingan tidak ditemukan", { status: 404 });
                }
                const rawImage = typeof post?.image === "string" ? post.image.trim() : "";
                const extractedKey = extractFotoKey(rawImage);
                if (extractedKey) {
                    const mime = isAllowedBlogImageKey(extractedKey, blogPrefix);
                    if (mime) {
                        const res = await s3GetRaw(env, extractedKey);
                        if (res && res.ok) {
                            const headers = {
                                "Content-Type": mime,
                                "Cache-Control": "public, max-age=2592000",
                                "X-Content-Type-Options": "nosniff",
                            };
                            const contentLength = res.headers.get("content-length");
                            if (contentLength) {
                                headers["Content-Length"] = contentLength;
                            }
                            return new Response(res.body, { status: 200, headers });
                        }
                    }
                }
                const decoded = decodeDataUriImage(rawImage);
                if (decoded) {
                    return new Response(decoded.bytes, {
                        status: 200,
                        headers: {
                            "Content-Type": decoded.mime,
                            "Cache-Control": "public, max-age=86400",
                            "X-Content-Type-Options": "nosniff",
                        },
                    });
                }
                return new Response("Gambar tidak ditemukan", { status: 404 });
            });
        }

        // Route link preview blog: /blog/<slug> atau /blog?slug=<slug>
        if (url.pathname === "/blog" || url.pathname.startsWith("/blog/")) {
            const blogBase = String(env.BLOG_BASE || DEFAULTS.BLOG_BASE).replace(/\/+$/, "");
            const blogPrefix = String(env.BLOG_S3_PREFIX || DEFAULTS.BLOG_S3_PREFIX).replace(/^\/+|\/+$/g, "");
            let slug = "";
            if (url.pathname.startsWith("/blog/")) {
                const rawSeg = url.pathname.slice("/blog/".length).replace(/^\/+|\/+$/g, "");
                try {
                    slug = decodeURIComponent(rawSeg);
                } catch (_e) {
                    return new Response("Slug tidak valid", { status: 400 });
                }
            } else {
                slug = (url.searchParams.get("slug") || "").trim();
            }

            if (slug === "") {
                return Response.redirect(`${blogBase}/blog`, 302);
            }
            if (!/^[A-Za-z0-9._-]+$/.test(slug)) {
                return new Response("Slug tidak valid", { status: 400 });
            }

            const targetUrl = `${blogBase}/blog/${encodeURIComponent(slug)}`;
            const userAgent = request.headers.get("User-Agent") || "";
            const isBot = /WhatsApp|TelegramBot|facebookexternalhit|Facebot|Twitterbot|LinkedInBot|Slackbot|Discordbot|Pinterest|Embedly|SkypeUriPreview|Viber|Line/i.test(userAgent);

            if (!isBot) {
                return Response.redirect(targetUrl, 302);
            }

            return withEdgeCache(request, ctx, 300, 120, async () => {
                let title = "Blog UKMI POLMED";
                let description = "Baca berita, catatan kegiatan, dan tulisan inspiratif dari UKMI Politeknik Negeri Medan.";
                let imageUrl = `${url.origin}/pwa/icon-512x512.png`;

                try {
                    const post = await findBlogPost(env, blogPrefix, slug, ctx);
                    if (post) {
                        if (typeof post.title === "string" && post.title.trim() !== "") {
                            title = post.title.trim();
                        }
                        const rawSummary = typeof post?.summary === "string" ? post.summary.trim() : "";
                        if (rawSummary !== "") {
                            description = stripMarkdown(rawSummary, 200) || description;
                        } else if (typeof post?.body === "string" && post.body.trim() !== "") {
                            description = stripMarkdown(post.body, 180) || description;
                        }
                        const rawImage = typeof post?.image === "string" ? post.image.trim() : "";
                        const fotoKey = extractFotoKey(rawImage);
                        if (fotoKey && isAllowedBlogImageKey(fotoKey, blogPrefix)) {
                            imageUrl = `${url.origin}/blog-img?key=${encodeURIComponent(fotoKey)}`;
                        } else if (/^data:image\//i.test(rawImage)) {
                            imageUrl = `${url.origin}/blog-img?slug=${encodeURIComponent(slug)}`;
                        } else if (/^https?:\/\//i.test(rawImage)) {
                            try {
                                const imgParsed = new URL(rawImage);
                                const baseParsed = new URL(blogBase);
                                if (imgParsed.host !== baseParsed.host) {
                                    imageUrl = rawImage;
                                }
                            } catch (_e) {
                                // Abaikan URL gambar tidak valid
                            }
                        }
                    }
                } catch (_e) {
                    // Gunakan fallback metadata bila objek belum ada atau gagal dibaca
                }

                return new Response(
                    generateBlogHtml(targetUrl, title, description, imageUrl),
                    {
                        headers: {
                            "Content-Type": "text/html; charset=utf-8",
                            "Cache-Control": "public, max-age=300",
                        },
                    }
                );
            });
        }

        // Route utama: ?id=<reportId> atau landing root (/)
        if (!url.searchParams.has("id")) {
            const isDaftar = url.pathname === "/daftar" || url.searchParams.get("mode") === "daftar";
            return buildPwaLandingResponse(url, isDaftar ? "daftar" : "laporan", !isPwaAnalyzer(request, url));
        }

        const reportId = url.searchParams.get("id") || "";
        if (!/^[A-Za-z0-9_-]+$/.test(reportId)) {
            return new Response("ID Laporan tidak valid", { status: 400 });
        }

        const viewBase = String(env.VIEW_BASE || DEFAULTS.VIEW_BASE);
        const userAgent = request.headers.get("User-Agent") || "";
        const isBot = /WhatsApp|TelegramBot|facebookexternalhit|Twitterbot|LinkedInBot|Slackbot|Discordbot/i.test(userAgent);

        if (!isBot) {
            return Response.redirect(`${viewBase}?id=${encodeURIComponent(reportId)}`, 302);
        }

        return withEdgeCache(request, ctx, 300, 120, async () => {
            const imageUrl = `${url.origin}/?img=${encodeURIComponent(reportId)}`;
            const fallbackTitle = "Laporan Mentoring Agama Islam";
            const fallbackDescription = "Dokumentasi dan laporan kegiatan mentoring UKMI Polmed";

            let title = fallbackTitle;
            let description = fallbackDescription;

            try {
                const metaRes = await s3Get(env, `og_meta/${reportId}.json`);
                if (metaRes && metaRes.ok) {
                    const meta = await metaRes.json();
                    if (meta && typeof meta.title === "string" && meta.title !== "") {
                        title = meta.title;
                    }
                    if (meta && typeof meta.description === "string" && meta.description !== "") {
                        description = meta.description;
                    }
                }
            } catch (_e) {
                // Gunakan fallback title & description bila objek belum ada atau gagal dibaca
            }

            return new Response(
                generateHtml(reportId, viewBase, title, description, imageUrl),
                {
                    headers: {
                        "Content-Type": "text/html; charset=utf-8",
                        "Cache-Control": "public, max-age=300",
                    },
                }
            );
        });
    },
};

async function s3Get(env, relativeKey) {
    const prefix = String(env.S3_PREFIX || DEFAULTS.S3_PREFIX).replace(/^\/+|\/+$/g, "");
    const fullKey = (prefix ? `${prefix}/` : "") + relativeKey.replace(/^\/+/, "");
    return s3GetRaw(env, fullKey);
}

async function s3GetRaw(env, fullKey) {
    const accessKey = String(env.S3_ACCESS_KEY || "");
    const secretKey = String(env.S3_SECRET_KEY || "");
    if (!accessKey || !secretKey) {
        return null;
    }

    const endpoint = String(env.S3_ENDPOINT || DEFAULTS.S3_ENDPOINT).replace(/\/+$/, "");
    const region = String(env.S3_REGION || DEFAULTS.S3_REGION);
    const bucket = String(env.S3_BUCKET || DEFAULTS.S3_BUCKET);
    const cleanKey = String(fullKey || "").replace(/^\/+/, "");

    const parsedEndpoint = new URL(endpoint);
    const hostHeader = parsedEndpoint.host;
    const segments = `${bucket}/${cleanKey}`.split("/").filter(Boolean);
    const canonicalUri = "/" + segments.map((seg) => encodeURIComponent(seg)).join("/");

    const now = new Date();
    const amzDate = now.toISOString().replace(/[:-]|\.\d{3}/g, "");
    const dateStamp = amzDate.slice(0, 8);

    const canonicalHeaders =
        `host:${hostHeader}\n` +
        `x-amz-content-sha256:${EMPTY_SHA256}\n` +
        `x-amz-date:${amzDate}\n`;
    const signedHeaders = "host;x-amz-content-sha256;x-amz-date";

    const canonicalRequest =
        `GET\n${canonicalUri}\n\n${canonicalHeaders}\n${signedHeaders}\n${EMPTY_SHA256}`;

    const credentialScope = `${dateStamp}/${region}/s3/aws4_request`;
    const canonicalHash = await sha256Hex(canonicalRequest);
    const stringToSign = `AWS4-HMAC-SHA256\n${amzDate}\n${credentialScope}\n${canonicalHash}`;

    const kDate = await hmacSha256(encodeUtf8("AWS4" + secretKey), dateStamp);
    const kRegion = await hmacSha256(kDate, region);
    const kService = await hmacSha256(kRegion, "s3");
    const kSigning = await hmacSha256(kService, "aws4_request");
    const signature = toHex(await hmacSha256(kSigning, stringToSign));

    const authorization =
        `AWS4-HMAC-SHA256 Credential=${accessKey}/${credentialScope}, ` +
        `SignedHeaders=${signedHeaders}, Signature=${signature}`;

    return fetch(`${endpoint}${canonicalUri}`, {
        method: "GET",
        signal: typeof AbortSignal !== "undefined" && typeof AbortSignal.timeout === "function" ? AbortSignal.timeout(10000) : undefined,
        headers: {
            "x-amz-content-sha256": EMPTY_SHA256,
            "x-amz-date": amzDate,
            "Authorization": authorization,
        },
    });
}

function encodeUtf8(str) {
    return new TextEncoder().encode(str);
}

async function sha256Hex(str) {
    const digest = await crypto.subtle.digest("SHA-256", encodeUtf8(str));
    return toHex(digest);
}

async function hmacSha256(keyBytes, message) {
    const cryptoKey = await crypto.subtle.importKey(
        "raw",
        keyBytes,
        { name: "HMAC", hash: "SHA-256" },
        false,
        ["sign"]
    );
    return crypto.subtle.sign("HMAC", cryptoKey, encodeUtf8(message));
}

function toHex(buffer) {
    return Array.from(new Uint8Array(buffer))
        .map((b) => b.toString(16).padStart(2, "0"))
        .join("");
}

function generateHtml(id, viewBase, title, description, imageUrl) {
    const targetUrl = `${viewBase}?id=${encodeURIComponent(id)}`;
    return `<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>${escapeHtml(title)}</title>
    <meta name="description" content="${escapeHtml(description)}">
    <meta property="og:type" content="article">
    <meta property="og:title" content="${escapeHtml(title)}">
    <meta property="og:description" content="${escapeHtml(description)}">
    <meta property="og:image" content="${escapeHtml(imageUrl)}">
    <meta property="og:url" content="${escapeHtml(targetUrl)}">
    <meta name="twitter:card" content="summary_large_image">
    <meta http-equiv="refresh" content="0; url=${escapeHtml(targetUrl)}">
</head>
<body>
    <p>Mengalihkan ke laporan: <a href="${escapeHtml(targetUrl)}">${escapeHtml(title)}</a></p>
</body>
</html>`;
}

function escapeHtml(str) {
    return String(str || "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");
}

async function findBlogPost(env, blogPrefix, slug, ctx) {
    try {
        const postRes = await s3GetRaw(env, `${blogPrefix}/data_json/posts/${await sha1Hex(slug)}.json`);
        if (postRes && postRes.ok) {
            const post = await postRes.json();
            if (post && typeof post === "object") {
                return post;
            }
        }
    } catch (_e) {
        // Lanjut ke fallback data.json
    }

    try {
        const cache = typeof caches !== "undefined" && caches.default ? caches.default : null;
        const synthReq = new Request(`https://cache.internal/${blogPrefix}/data/data.json`, { method: "GET" });
        let dataRes = cache ? await cache.match(synthReq) : null;
        if (!dataRes) {
            dataRes = await s3GetRaw(env, `${blogPrefix}/data/data.json`);
            if (dataRes && dataRes.ok && cache) {
                const toCache = new Response(dataRes.clone().body, {
                    status: 200,
                    headers: { "Content-Type": "application/json", "Cache-Control": "public, max-age=300" },
                });
                if (ctx && typeof ctx.waitUntil === "function") {
                    ctx.waitUntil(cache.put(synthReq, toCache));
                } else {
                    await cache.put(synthReq, toCache);
                }
            }
        }
        if (dataRes && dataRes.ok) {
            const data = await dataRes.json();
            const lists = [
                Array.isArray(data?.posts) ? data.posts : [],
                Array.isArray(data?.posts_archived) ? data.posts_archived : [],
            ];
            for (const list of lists) {
                const found = list.find((p) => p && typeof p.slug === "string" && p.slug === slug);
                if (found) return found;
            }
        }
    } catch (_e) {
        // Abaikan jika fallback gagal
    }
    return null;
}

async function sha1Hex(str) {
    const digest = await crypto.subtle.digest("SHA-1", encodeUtf8(str));
    return toHex(digest);
}

function stripMarkdown(text, maxLen = 180) {
    const clean = String(text || "")
        .replace(/!\[[^\]]*\]\([^)]*\)/g, " ")
        .replace(/\[([^\]]+)\]\([^)]*\)/g, "$1")
        .replace(/`{1,3}[^`]*`{1,3}/g, " ")
        .replace(/^#{1,6}\s+/gm, "")
        .replace(/[*_~>-]+/g, " ")
        .replace(/\s+/g, " ")
        .trim();
    if (!maxLen || clean.length <= maxLen) return clean;
    return clean.slice(0, maxLen).trim();
}

function extractFotoKey(imageStr) {
    const raw = String(imageStr || "").trim();
    if (!raw) return "";
    try {
        const parsed = raw.startsWith("/") ? new URL(raw, DEFAULTS.BLOG_BASE) : new URL(raw);
        if (parsed.pathname === "/foto" || parsed.pathname === "/foto.php") {
            return (parsed.searchParams.get("key") || "").trim();
        }
    } catch (_e) {
        // Bukan URL valid
    }
    return "";
}

function isAllowedBlogImageKey(key, blogPrefix) {
    const k = String(key || "");
    if (!k || k.includes("..")) return null;
    const prefix = String(blogPrefix || DEFAULTS.BLOG_S3_PREFIX).replace(/^\/+|\/+$/g, "");
    const allowed = [`${prefix}/uploads/`, `${prefix}/assets/`];
    if (!allowed.some((p) => k.startsWith(p))) return null;
    const match = k.match(/\.([a-z0-9]+)$/i);
    if (!match) return null;
    const ext = match[1].toLowerCase();
    const map = {
        jpg: "image/jpeg",
        jpeg: "image/jpeg",
        png: "image/png",
        gif: "image/gif",
        webp: "image/webp",
    };
    return map[ext] || null;
}

function decodeDataUriImage(dataUri) {
    const match = String(dataUri || "").match(/^data:(image\/(?:jpeg|jpg|png|gif|webp));base64,(.+)$/is);
    if (!match) return null;
    const mime = match[1].toLowerCase() === "image/jpg" ? "image/jpeg" : match[1].toLowerCase();
    try {
        const bin = atob(match[2].replace(/\s+/g, ""));
        const bytes = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i++) {
            bytes[i] = bin.charCodeAt(i);
        }
        return { mime, bytes };
    } catch (_e) {
        return null;
    }
}

function generateBlogHtml(targetUrl, title, description, imageUrl) {
    return `<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>${escapeHtml(title)}</title>
    <meta name="description" content="${escapeHtml(description)}">
    <link rel="canonical" href="${escapeHtml(targetUrl)}">
    <meta property="og:locale" content="id_ID">
    <meta property="og:site_name" content="UKMI POLMED">
    <meta property="og:type" content="article">
    <meta property="og:title" content="${escapeHtml(title)}">
    <meta property="og:description" content="${escapeHtml(description)}">
    <meta property="og:image" content="${escapeHtml(imageUrl)}">
    <meta property="og:url" content="${escapeHtml(targetUrl)}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="${escapeHtml(title)}">
    <meta name="twitter:description" content="${escapeHtml(description)}">
    <meta name="twitter:image" content="${escapeHtml(imageUrl)}">
    <meta http-equiv="refresh" content="0; url=${escapeHtml(targetUrl)}">
</head>
<body>
    <p>Mengalihkan ke artikel: <a href="${escapeHtml(targetUrl)}">${escapeHtml(title)}</a></p>
</body>
</html>`;
}
