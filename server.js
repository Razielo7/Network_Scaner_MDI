// Local Express Server for MDI Network Diagnostics
// This file is IGNORED by Vercel - it's only for local development

const express = require('express');
const path = require('path');
const cors = require('cors');

const app = express();
const PORT = process.env.PORT || 4000;

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

// GET /api/dns-records - Get DNS records (A, MX, SPF, DMARC) for a domain
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
                spf: null,
                dmarc: null,
                isIP: true
            }
        });
    }

    const result = {
        ip: null,
        mx: [],
        spf: null,
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

        // Get SPF record (TXT record containing "v=spf1")
        try {
            const txtRecords = await dns.resolveTxt(domain);
            const spfRecord = txtRecords.flat().find(r => r.startsWith('v=spf1'));
            result.spf = spfRecord || null;
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

// GET /api/email-auth - Comprehensive email authentication check (SPF, DKIM, DMARC)
app.get('/api/email-auth', async (req, res) => {
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
                isIP: true,
                spf: { found: false, record: null, valid: false },
                dkim: { found: false, selector: null, record: null },
                dmarc: { found: false, record: null, policy: null, subdomainPolicy: null, pct: null },
                mx: [],
                smtp: { hasMailServer: false, starttls: 'unknown', ports: [] }
            }
        });
    }

    const result = {
        domain: domain,
        spf: { found: false, record: null, valid: false, mechanisms: [] },
        dkim: { found: false, selector: null, record: null, keyType: null },
        dmarc: { found: false, record: null, policy: null, subdomainPolicy: null, pct: 100, rua: null, ruf: null },
        mx: [],
        smtp: { hasMailServer: false, starttls: 'likely', ports: ['25', '587', '465'] }
    };

    // Common DKIM selectors to try
    const dkimSelectors = ['default', 'google', 'selector1', 'selector2', 'dkim', 'mail', 'k1', 's1', 's2', 'zoho'];

    try {
        // Get SPF record
        try {
            const txtRecords = await dns.resolveTxt(domain);
            const spfRecord = txtRecords.flat().find(r => r.startsWith('v=spf1'));
            if (spfRecord) {
                result.spf.found = true;
                result.spf.record = spfRecord;
                result.spf.valid = true;
                // Parse mechanisms
                const mechanisms = spfRecord.match(/(?:include:|a:|mx:|ip4:|ip6:|all|-all|~all|\+all|\?all)/g);
                result.spf.mechanisms = mechanisms || [];
            }
        } catch { }

        // Try DKIM selectors
        for (const selector of dkimSelectors) {
            try {
                const dkimRecords = await dns.resolveTxt(`${selector}._domainkey.${domain}`);
                const dkimRecord = dkimRecords.flat().join('');
                if (dkimRecord && dkimRecord.includes('v=DKIM1')) {
                    result.dkim.found = true;
                    result.dkim.selector = selector;
                    result.dkim.record = dkimRecord.length > 100 ? dkimRecord.substring(0, 100) + '...' : dkimRecord;
                    // Extract key type
                    const keyMatch = dkimRecord.match(/k=([^;]+)/);
                    result.dkim.keyType = keyMatch ? keyMatch[1] : 'rsa';
                    break; // Found one, stop looking
                }
            } catch { }
        }

        // Get DMARC record
        try {
            const dmarcRecords = await dns.resolveTxt(`_dmarc.${domain}`);
            const dmarcRecord = dmarcRecords.flat().find(r => r.includes('DMARC'));
            if (dmarcRecord) {
                result.dmarc.found = true;
                result.dmarc.record = dmarcRecord;
                // Parse DMARC tags
                const policyMatch = dmarcRecord.match(/p=([^;]+)/);
                const spMatch = dmarcRecord.match(/sp=([^;]+)/);
                const pctMatch = dmarcRecord.match(/pct=(\d+)/);
                const ruaMatch = dmarcRecord.match(/rua=([^;]+)/);
                const rufMatch = dmarcRecord.match(/ruf=([^;]+)/);

                result.dmarc.policy = policyMatch ? policyMatch[1].trim() : null;
                result.dmarc.subdomainPolicy = spMatch ? spMatch[1].trim() : result.dmarc.policy;
                result.dmarc.pct = pctMatch ? parseInt(pctMatch[1]) : 100;
                result.dmarc.rua = ruaMatch ? ruaMatch[1].trim() : null;
                result.dmarc.ruf = rufMatch ? rufMatch[1].trim() : null;
            }
        } catch { }

        // Get MX records for mail server info
        try {
            const mxRecords = await dns.resolveMx(domain);
            result.mx = mxRecords.sort((a, b) => a.priority - b.priority).slice(0, 5).map(r => ({
                priority: r.priority,
                exchange: r.exchange
            }));
            result.smtp.hasMailServer = result.mx.length > 0;

            // If MX records point to known providers, we can infer STARTTLS support
            const mxString = result.mx.map(m => m.exchange.toLowerCase()).join(' ');
            if (mxString.includes('google') || mxString.includes('gmail')) {
                result.smtp.starttls = 'yes (Google Workspace)';
            } else if (mxString.includes('outlook') || mxString.includes('microsoft')) {
                result.smtp.starttls = 'yes (Microsoft 365)';
            } else if (mxString.includes('zoho')) {
                result.smtp.starttls = 'yes (Zoho)';
            } else if (mxString.includes('protonmail')) {
                result.smtp.starttls = 'yes (ProtonMail)';
            } else {
                result.smtp.starttls = 'likely (check manually)';
            }
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

// GET/POST /api/speedtest - Comprehensive speed test with client info and results
app.all('/api/speedtest', async (req, res) => {
    const action = req.query.action || req.body?.action;

    try {
        switch (action) {
            case 'ping':
                return await handlePingTest(req, res);
            case 'client':
                return await handleClientInfo(req, res);
            case 'share':
                return await handleShareImage(req, res);
            default:
                return await handleFullTest(req, res);
        }
    } catch (error) {
        return res.status(500).json({ success: false, error: error.message });
    }
});

// Ping test handler
async function handlePingTest(req, res) {
    const samples = parseInt(req.query.samples) || 10;
    const latencies = [];
    let failed = 0;

    for (let i = 0; i < samples; i++) {
        try {
            const start = performance.now();
            await fetch('https://speed.cloudflare.com/__down?bytes=0', { method: 'HEAD' });
            latencies.push(performance.now() - start);
        } catch { failed++; }
        await new Promise(r => setTimeout(r, 50));
    }

    const avg = latencies.length > 0 ? latencies.reduce((a, b) => a + b, 0) / latencies.length : null;
    const min = latencies.length > 0 ? Math.min(...latencies) : null;
    const max = latencies.length > 0 ? Math.max(...latencies) : null;

    let jitter = null;
    if (latencies.length > 1) {
        const variance = latencies.reduce((a, b) => a + Math.pow(b - avg, 2), 0) / latencies.length;
        jitter = Math.sqrt(variance);
    }

    return res.json({
        success: true,
        data: {
            ping: avg ? avg.toFixed(2) : null,
            min: min ? min.toFixed(2) : null,
            max: max ? max.toFixed(2) : null,
            jitter: jitter ? jitter.toFixed(2) : null,
            packetLoss: ((failed / samples) * 100).toFixed(1),
            samples: latencies.length
        }
    });
}

// Client info handler
async function handleClientInfo(req, res) {
    const clientIp = req.headers['x-forwarded-for']?.split(',')[0]?.trim() ||
        req.headers['x-real-ip'] || req.socket?.remoteAddress || 'Unknown';

    try {
        const geoResponse = await fetch(
            `http://ip-api.com/json/${clientIp}?fields=status,country,countryCode,regionName,city,isp,org,as,query`
        );
        const geoData = await geoResponse.json();

        if (geoData.status === 'success') {
            return res.json({
                success: true,
                data: {
                    ip: geoData.query || clientIp,
                    isp: geoData.isp || 'Unknown',
                    org: geoData.org || '',
                    as: geoData.as || '',
                    country: geoData.country || 'Unknown',
                    countryCode: geoData.countryCode || '',
                    region: geoData.regionName || '',
                    city: geoData.city || ''
                }
            });
        }
    } catch { }

    return res.json({ success: true, data: { ip: clientIp, isp: 'Unknown' } });
}

// Share image handler
async function handleShareImage(req, res) {
    const { download, upload, ping, isp, server } = req.query;
    const resultData = {
        download: parseFloat(download) || 0,
        upload: parseFloat(upload) || 0,
        ping: parseFloat(ping) || 0,
        isp: isp || 'Unknown',
        server: server || 'Cloudflare',
        timestamp: new Date().toISOString()
    };

    const svgImage = `<?xml version="1.0" encoding="UTF-8"?>
<svg width="600" height="300" xmlns="http://www.w3.org/2000/svg">
  <defs><linearGradient id="bg" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" style="stop-color:#0f172a"/><stop offset="100%" style="stop-color:#1e3a5f"/></linearGradient></defs>
  <rect width="600" height="300" fill="url(#bg)"/>
  <text x="300" y="40" text-anchor="middle" fill="#06b6d4" font-family="Arial" font-size="24" font-weight="bold">MDI Network Speed Test</text>
  <rect x="30" y="70" width="160" height="80" rx="10" fill="#1e293b"/>
  <text x="110" y="100" text-anchor="middle" fill="#94a3b8" font-family="Arial" font-size="12">DOWNLOAD</text>
  <text x="110" y="135" text-anchor="middle" fill="#06b6d4" font-family="Arial" font-size="28" font-weight="bold">${resultData.download.toFixed(2)}</text>
  <text x="110" y="150" text-anchor="middle" fill="#64748b" font-family="Arial" font-size="10">Mbps</text>
  <rect x="220" y="70" width="160" height="80" rx="10" fill="#1e293b"/>
  <text x="300" y="100" text-anchor="middle" fill="#94a3b8" font-family="Arial" font-size="12">UPLOAD</text>
  <text x="300" y="135" text-anchor="middle" fill="#a855f7" font-family="Arial" font-size="28" font-weight="bold">${resultData.upload.toFixed(2)}</text>
  <text x="300" y="150" text-anchor="middle" fill="#64748b" font-family="Arial" font-size="10">Mbps</text>
  <rect x="410" y="70" width="160" height="80" rx="10" fill="#1e293b"/>
  <text x="490" y="100" text-anchor="middle" fill="#94a3b8" font-family="Arial" font-size="12">PING</text>
  <text x="490" y="135" text-anchor="middle" fill="#22c55e" font-family="Arial" font-size="28" font-weight="bold">${resultData.ping.toFixed(2)}</text>
  <text x="490" y="150" text-anchor="middle" fill="#64748b" font-family="Arial" font-size="10">ms</text>
  <text x="30" y="195" fill="#64748b" font-family="Arial" font-size="12">ISP: <tspan fill="#94a3b8">${resultData.isp}</tspan></text>
  <text x="30" y="215" fill="#64748b" font-family="Arial" font-size="12">Server: <tspan fill="#94a3b8">${resultData.server}</tspan></text>
  <text x="30" y="235" fill="#64748b" font-family="Arial" font-size="12">Date: <tspan fill="#94a3b8">${new Date(resultData.timestamp).toLocaleString()}</tspan></text>
  <text x="300" y="280" text-anchor="middle" fill="#475569" font-family="Arial" font-size="10">MDI Network Diagnostics</text>
</svg>`;

    const base64 = Buffer.from(svgImage).toString('base64');
    return res.json({
        success: true,
        data: { svg: svgImage, dataUrl: `data:image/svg+xml;base64,${base64}`, results: resultData }
    });
}

// Full test initialization handler
async function handleFullTest(req, res) {
    const clientIp = req.headers['x-forwarded-for']?.split(',')[0]?.trim() ||
        req.headers['x-real-ip'] || 'Unknown';

    let clientInfo = { ip: clientIp, isp: 'Unknown' };
    try {
        const geoResponse = await fetch(`http://ip-api.com/json/${clientIp}?fields=status,country,city,isp,query`);
        const geoData = await geoResponse.json();
        if (geoData.status === 'success') {
            clientInfo = { ip: geoData.query || clientIp, isp: geoData.isp || 'Unknown', country: geoData.country || '', city: geoData.city || '' };
        }
    } catch { }

    return res.json({
        success: true,
        data: {
            timestamp: new Date().toISOString(),
            status: 'ready',
            client: clientInfo,
            server: { name: 'Cloudflare', location: 'Global CDN' },
            message: 'Run speed test from client-side for accurate results'
        }
    });
}

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
