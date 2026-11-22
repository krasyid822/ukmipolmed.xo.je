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
};

const EMPTY_SHA256 = "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855";

export default {
    async fetch(request, env) {
        const url = new URL(request.url);

        // Route aset & landing PWA: /pwa/<filename> atau /pwa/laporan | /pwa/daftar
        if (url.pathname.startsWith("/pwa/")) {
            const pwaName = url.pathname.slice("/pwa/".length).replace(/^\/+/, "");
            if (pwaName === "laporan" || pwaName === "daftar") {
                const isLaporan = pwaName === "laporan";
                const targetAppUrl = isLaporan
                    ? "https://ukmipolmed.my.id/terpadu/mai/mentoring/"
                    : "https://ukmipolmed.my.id/terpadu/mai/mentoring/daftar";
                const manifestUrl = `${url.origin}/pwa/${isLaporan ? "manifest-laporan.webmanifest" : "manifest-daftar.webmanifest"}`;
                const title = isLaporan
                    ? "Pelaporan Mentoring Agama Islam UKMI Polmed"
                    : "Pendaftaran Mentoring Agama Islam UKMI Polmed";
                const iconUrl = `${url.origin}/pwa/${isLaporan ? "icon-192x192.png" : "icon-daftar-192x192.png"}`;
                const html = `<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>${escapeHtml(title)}</title>
    <link rel="manifest" href="${escapeHtml(manifestUrl)}">
    <link rel="icon" type="image/png" href="${escapeHtml(iconUrl)}">
    <meta name="theme-color" content="#0284c7">
</head>
<body>
    <p>Membuka <a href="${escapeHtml(targetAppUrl)}">${escapeHtml(title)}</a>...</p>
    <script>location.replace(${JSON.stringify(targetAppUrl)});</script>
</body>
</html>`;
                return new Response(html, {
                    status: 200,
                    headers: {
                        "Content-Type": "text/html; charset=utf-8",
                        "Cache-Control": "public, max-age=3600",
                    },
                });
            }

            if (!/^[A-Za-z0-9._-]+\.(webmanifest|png)$/i.test(pwaName)) {
                return new Response("Aset PWA tidak valid", { status: 400 });
            }
            const res = await s3Get(env, `pwa/${pwaName}`);
            if (!res || !res.ok) {
                return new Response("Aset PWA tidak ditemukan", { status: 404 });
            }
            const isManifest = pwaName.toLowerCase().endsWith(".webmanifest");
            const headers = {
                "Content-Type": isManifest ? "application/manifest+json; charset=utf-8" : "image/png",
                "Access-Control-Allow-Origin": "*",
                "Cache-Control": isManifest ? "public, max-age=3600" : "public, max-age=2592000",
                "X-Content-Type-Options": "nosniff",
            };
            const contentLength = res.headers.get("content-length");
            if (contentLength) {
                headers["Content-Length"] = contentLength;
            }
            return new Response(res.body, { status: 200, headers });
        }

        // Route gambar dokumentasi: ?img=<reportId>
        const imgId = url.searchParams.get("img") || "";
        if (imgId !== "") {
            if (!/^[A-Za-z0-9_-]+$/.test(imgId)) {
                return new Response("ID Gambar tidak valid", { status: 400 });
            }
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
        }

        // Route utama: ?id=<reportId>
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
            { headers: { "Content-Type": "text/html; charset=utf-8" } }
        );
    },
};

async function s3Get(env, relativeKey) {
    const accessKey = String(env.S3_ACCESS_KEY || "");
    const secretKey = String(env.S3_SECRET_KEY || "");
    if (!accessKey || !secretKey) {
        return null;
    }

    const endpoint = String(env.S3_ENDPOINT || DEFAULTS.S3_ENDPOINT).replace(/\/+$/, "");
    const region = String(env.S3_REGION || DEFAULTS.S3_REGION);
    const bucket = String(env.S3_BUCKET || DEFAULTS.S3_BUCKET);
    const prefix = String(env.S3_PREFIX || DEFAULTS.S3_PREFIX).replace(/^\/+|\/+$/g, "");
    const fullKey = (prefix ? `${prefix}/` : "") + relativeKey.replace(/^\/+/, "");

    const parsedEndpoint = new URL(endpoint);
    const hostHeader = parsedEndpoint.host;
    const segments = `${bucket}/${fullKey}`.split("/").filter(Boolean);
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
