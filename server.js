// Local Express Server for MDI Network Diagnostics
// This file is IGNORED by Vercel - it's only for local development

const express = require('express');
const path = require('path');
const cors = require('cors');

const app = express();
const PORT = process.env.PORT || 3000;

// Middleware
app.use(cors());
app.use(express.raw({ type: 'application/octet-stream', limit: '50mb' }));
app.use(express.json());

// Serve static files from 'public' directory
app.use(express.static(path.join(__dirname, 'public')));

// ========================================
// API Routes (matching Vercel functions)
// ========================================

const ALLOWED_TARGETS = {
    'dns.google': 'https://dns.google',
    'cloudflare.com': 'https://cloudflare.com',
    'google.com': 'https://www.google.com',
    'github.com': 'https://github.com',
    '8.8.8.8': 'https://dns.google',
    '1.1.1.1': 'https://cloudflare.com',
    '9.9.9.9': 'https://quad9.net',
};

// GET /api/ping-custom - Ping any custom domain
app.get('/api/ping-custom', async (req, res) => {
    const host = req.query.host;

    if (!host) {
        return res.status(400).json({ error: 'Host parameter required' });
    }

    // Validate hostname format (basic check)
    const hostnameRegex = /^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z]{2,})+$/;
    const ipRegex = /^(\d{1,3}\.){3}\d{1,3}$/;

    if (!hostnameRegex.test(host) && !ipRegex.test(host)) {
        return res.status(400).json({ error: 'Invalid hostname format' });
    }

    // Build target URL - try HTTPS first
    const targetUrl = host.startsWith('http') ? host : `https://${host}`;

    try {
        const start = performance.now();

        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 5000);

        const response = await fetch(targetUrl, {
            method: 'HEAD',
            signal: controller.signal,
        });

        clearTimeout(timeout);

        const end = performance.now();
        const latency = Math.round(end - start);

        return res.json({
            data: {
                latency: latency,
                type: 'HTTP',
                success: response.ok,
                host: host
            }
        });
    } catch (error) {
        // Try HTTP as fallback
        try {
            const httpUrl = `http://${host}`;
            const start = performance.now();

            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 5000);

            const response = await fetch(httpUrl, {
                method: 'HEAD',
                signal: controller.signal,
            });

            clearTimeout(timeout);
            const end = performance.now();
            const latency = Math.round(end - start);

            return res.json({
                data: {
                    latency: latency,
                    type: 'HTTP',
                    success: response.ok,
                    host: host
                }
            });
        } catch {
            return res.json({
                data: {
                    latency: -1,
                    error: error.name === 'AbortError' ? 'Timeout' : 'Unreachable',
                    host: host
                }
            });
        }
    }
});

// GET /api/ping - HTTP-based timing check
app.get('/api/ping', async (req, res) => {
    const host = req.query.host;

    if (!host || !ALLOWED_TARGETS[host]) {
        return res.status(400).json({ error: 'Invalid host' });
    }

    const targetUrl = ALLOWED_TARGETS[host];

    try {
        const start = performance.now();

        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 5000);

        const response = await fetch(targetUrl, {
            method: 'HEAD',
            signal: controller.signal,
        });

        clearTimeout(timeout);

        const end = performance.now();
        const latency = Math.round(end - start);

        return res.json({
            data: {
                latency: latency,
                type: 'HTTP',
                success: response.ok
            }
        });
    } catch (error) {
        return res.json({
            data: {
                latency: -1,
                error: error.name === 'AbortError' ? 'Timeout' : 'Unreachable'
            }
        });
    }
});

// GET /api/system-info - Returns client IP info
app.get('/api/system-info', (req, res) => {
    const clientIp =
        req.headers['x-forwarded-for']?.split(',')[0]?.trim() ||
        req.headers['x-real-ip'] ||
        req.socket?.remoteAddress ||
        'Unknown';

    const userAgent = req.headers['user-agent'] || 'Unknown';

    return res.json({
        success: true,
        data: {
            client_ip: clientIp,
            user_agent: userAgent,
            hostname: 'N/A'
        }
    });
});

// GET /api/public-ip - Get public IP and geo info (proxy to avoid CORS)
app.get('/api/public-ip', async (req, res) => {
    try {
        // Use ip-api.com which provides IP + geo in one call (no rate limit for moderate usage)
        const response = await fetch('http://ip-api.com/json/?fields=status,message,country,countryCode,region,city,isp,query');
        const data = await response.json();

        if (data.status === 'success') {
            return res.json({
                success: true,
                data: {
                    ip: data.query,
                    country: data.country || 'Unknown',
                    country_code: data.countryCode || '',
                    region: data.region || '',
                    city: data.city || '',
                    isp: data.isp || ''
                }
            });
        } else {
            throw new Error(data.message || 'Failed to get IP info');
        }
    } catch (error) {
        return res.status(500).json({
            success: false,
            error: error.message
        });
    }
});

// GET /api/details - Fetch host details with timing
app.get('/api/details', async (req, res) => {
    const host = req.query.host;

    if (!host || !ALLOWED_TARGETS[host]) {
        return res.status(400).json({ error: 'Invalid or missing host parameter' });
    }

    const targetUrl = ALLOWED_TARGETS[host];

    try {
        const start = performance.now();

        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 10000);

        const response = await fetch(targetUrl, {
            method: 'HEAD',
            redirect: 'follow',
            signal: controller.signal,
        });

        clearTimeout(timeout);

        const end = performance.now();
        const totalTime = Math.round(end - start);

        const isHttps = targetUrl.startsWith('https://');

        return res.json({
            success: response.status >= 200 && response.status < 400,
            data: {
                http_code: response.status,
                total_time: `${totalTime} ms`,
                namelookup: 'N/A',
                connect: 'N/A',
                primary_ip: 'N/A',
                ssl_verify: isHttps ? 'TLS/SSL' : 'None'
            }
        });
    } catch (error) {
        return res.json({
            success: false,
            data: {
                http_code: 0,
                total_time: 'Timeout',
                namelookup: 'N/A',
                connect: 'N/A',
                primary_ip: 'N/A',
                ssl_verify: 'N/A'
            }
        });
    }
});

// GET /api/dns-records - Get DNS records (A, MX, DMARC) for a domain
app.get('/api/dns-records', async (req, res) => {
    const host = req.query.host;
    const dns = require('dns').promises;

    if (!host) {
        return res.status(400).json({ error: 'Host parameter required' });
    }

    // Extract domain from potential URL or IP
    let domain = host.replace(/^https?:\/\//, '').replace(/\/.*/g, '');

    // Skip IP addresses
    const ipRegex = /^(\d{1,3}\.){3}\d{1,3}$/;
    if (ipRegex.test(domain)) {
        return res.json({
            success: true,
            data: {
                ip: domain,
                mx: [],
                dmarc: null,
                isIP: true
            }
        });
    }

    const result = {
        ip: null,
        mx: [],
        dmarc: null
    };

    try {
        // Get A records (IP addresses)
        try {
            const addresses = await dns.resolve4(domain);
            result.ip = addresses[0] || null;
        } catch (e) {
            // Try AAAA if no A record
            try {
                const addresses = await dns.resolve6(domain);
                result.ip = addresses[0] || null;
            } catch { }
        }

        // Get MX records
        try {
            const mxRecords = await dns.resolveMx(domain);
            result.mx = mxRecords.sort((a, b) => a.priority - b.priority).slice(0, 3).map(r => ({
                priority: r.priority,
                exchange: r.exchange
            }));
        } catch { }

        // Get DMARC record (TXT record at _dmarc subdomain)
        try {
            const txtRecords = await dns.resolveTxt(`_dmarc.${domain}`);
            const dmarcRecord = txtRecords.flat().find(r => r.startsWith('v=DMARC'));
            result.dmarc = dmarcRecord || null;
        } catch { }

        return res.json({
            success: true,
            data: result
        });
    } catch (error) {
        return res.json({
            success: true,
            data: result
        });
    }
});

// POST /api/upload-proxy - Proxies upload data to Cloudflare speed test
app.post('/api/upload-proxy', async (req, res) => {
    const CLOUDFLARE_UPLOAD_ENDPOINT = 'https://speed.cloudflare.com/__up';
    const MAX_UPLOAD_SIZE = 50 * 1024 * 1024; // 50MB

    try {
        const body = req.body;
        const size = body.length;

        if (!size || size === 0) {
            return res.status(400).json({ error: 'No data received' });
        }

        if (size > MAX_UPLOAD_SIZE) {
            return res.status(413).json({
                error: 'Upload size exceeds maximum allowed',
                max_size: MAX_UPLOAD_SIZE
            });
        }

        const response = await fetch(CLOUDFLARE_UPLOAD_ENDPOINT, {
            method: 'POST',
            body: body,
            headers: {
                'Content-Type': 'application/octet-stream',
                'Content-Length': size.toString(),
            },
        });

        const responseText = await response.text();

        if (response.ok) {
            return res.json({
                success: true,
                bytes: size,
                response: responseText
            });
        } else {
            return res.status(response.status).json({
                success: false,
                error: 'Upload failed',
                http_code: response.status,
                bytes: size
            });
        }
    } catch (error) {
        return res.status(500).json({
            success: false,
            error: error.message
        });
    }
});

// Fallback: serve index.html for any unmatched routes
app.get('*', (req, res) => {
    res.sendFile(path.join(__dirname, 'public', 'index.html'));
});

// Start server
app.listen(PORT, () => {
    console.log(`
╔═══════════════════════════════════════════════════════════╗
║   MDI Network Diagnostics - Local Server                  ║
╠═══════════════════════════════════════════════════════════╣
║   🌐 Server running at: http://localhost:${PORT}             ║
║   📁 Serving files from: ./public                         ║
║   🔌 API endpoints available at /api/*                    ║
╚═══════════════════════════════════════════════════════════╝
  `);
});
