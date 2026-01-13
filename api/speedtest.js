// Speed Test API - Comprehensive speed test with client info and shareable results
// Vercel Serverless Function

export default async function handler(req, res) {
    // Handle CORS
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

    if (req.method === 'OPTIONS') {
        return res.status(200).end();
    }

    if (req.method !== 'GET' && req.method !== 'POST') {
        return res.status(405).json({ error: 'Method not allowed' });
    }

    const action = req.query.action || req.body?.action;

    try {
        switch (action) {
            case 'getServers':
                return await getServers(req, res);
            case 'download':
                return await testDownload(req, res);
            case 'upload':
                return await testUpload(req, res);
            case 'ping':
                return await testPing(req, res);
            case 'client':
                return await getClientInfo(req, res);
            case 'share':
                return await generateShareImage(req, res);
            default:
                // Run full test
                return await runFullTest(req, res);
        }
    } catch (error) {
        console.error('Speed test error:', error);
        return res.status(500).json({
            success: false,
            error: error.message
        });
    }
}

// Speed test server endpoints (multiple options for reliability)
const SPEED_TEST_SERVERS = [
    {
        name: 'Cloudflare',
        location: 'Global CDN',
        downloadUrl: 'https://speed.cloudflare.com/__down?bytes=',
        uploadUrl: 'https://speed.cloudflare.com/__up',
        pingUrl: 'https://speed.cloudflare.com/__down?bytes=0'
    },
    {
        name: 'Hetzner',
        location: 'Germany',
        downloadUrl: 'https://speed.hetzner.de/100MB.bin',
        pingUrl: 'https://speed.hetzner.de/'
    },
    {
        name: 'OVH',
        location: 'France',
        downloadUrl: 'http://proof.ovh.net/files/100Mb.dat',
        pingUrl: 'http://proof.ovh.net/'
    }
];

// Get available servers with ping times
async function getServers(req, res) {
    const servers = [];

    for (const server of SPEED_TEST_SERVERS) {
        try {
            const start = performance.now();
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 3000);

            await fetch(server.pingUrl, {
                method: 'HEAD',
                signal: controller.signal,
                mode: 'cors'
            });

            clearTimeout(timeout);
            const latency = Math.round(performance.now() - start);

            servers.push({
                ...server,
                latency,
                available: true
            });
        } catch {
            servers.push({
                ...server,
                latency: null,
                available: false
            });
        }
    }

    // Sort by latency (lowest first)
    servers.sort((a, b) => {
        if (!a.available) return 1;
        if (!b.available) return -1;
        return a.latency - b.latency;
    });

    return res.json({
        success: true,
        servers,
        recommended: servers.find(s => s.available) || servers[0]
    });
}

// Test download speed
async function testDownload(req, res) {
    const downloadSize = parseInt(req.query.size) || 25000000; // 25MB default
    const url = `https://speed.cloudflare.com/__down?bytes=${downloadSize}`;

    const start = performance.now();

    try {
        const response = await fetch(url, { mode: 'cors' });
        if (!response.ok) throw new Error('Download failed');

        const data = await response.arrayBuffer();
        const duration = (performance.now() - start) / 1000; // seconds

        const bitsLoaded = data.byteLength * 8;
        const speedMbps = (bitsLoaded / 1000000) / duration;

        return res.json({
            success: true,
            data: {
                bytes: data.byteLength,
                duration: duration.toFixed(2),
                speedMbps: speedMbps.toFixed(2),
                speedBps: Math.round(bitsLoaded / duration)
            }
        });
    } catch (error) {
        return res.status(500).json({
            success: false,
            error: error.message
        });
    }
}

// Test upload speed
async function testUpload(req, res) {
    // For serverless, we measure the time to receive the upload
    // The actual upload happens client-side
    return res.json({
        success: true,
        message: 'Upload test should be performed client-side with progress tracking'
    });
}

// Test ping/latency
async function testPing(req, res) {
    const samples = parseInt(req.query.samples) || 10;
    const latencies = [];
    let failed = 0;

    for (let i = 0; i < samples; i++) {
        try {
            const start = performance.now();
            await fetch('https://speed.cloudflare.com/__down?bytes=0', {
                method: 'HEAD',
                mode: 'cors'
            });
            latencies.push(performance.now() - start);
        } catch {
            failed++;
        }
        await new Promise(r => setTimeout(r, 50));
    }

    const avg = latencies.length > 0
        ? latencies.reduce((a, b) => a + b, 0) / latencies.length
        : null;

    const min = latencies.length > 0 ? Math.min(...latencies) : null;
    const max = latencies.length > 0 ? Math.max(...latencies) : null;

    // Calculate jitter (average deviation)
    let jitter = null;
    if (latencies.length > 1) {
        const mean = avg;
        const variance = latencies.reduce((a, b) => a + Math.pow(b - mean, 2), 0) / latencies.length;
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

// Get client info (IP, ISP, location)
async function getClientInfo(req, res) {
    try {
        // Get client IP from request
        const clientIp =
            req.headers['x-forwarded-for']?.split(',')[0]?.trim() ||
            req.headers['x-real-ip'] ||
            req.socket?.remoteAddress ||
            'Unknown';

        // Get geo info from ip-api
        const geoResponse = await fetch(
            `http://ip-api.com/json/${clientIp}?fields=status,message,country,countryCode,region,regionName,city,zip,lat,lon,timezone,isp,org,as,query`
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
                    region: geoData.regionName || geoData.region || '',
                    city: geoData.city || '',
                    zip: geoData.zip || '',
                    lat: geoData.lat,
                    lon: geoData.lon,
                    timezone: geoData.timezone || ''
                }
            });
        }

        // Fallback
        return res.json({
            success: true,
            data: {
                ip: clientIp,
                isp: 'Unknown',
                country: 'Unknown'
            }
        });
    } catch (error) {
        return res.json({
            success: true,
            data: {
                ip: 'Unknown',
                isp: 'Unknown',
                error: error.message
            }
        });
    }
}

// Generate shareable result image URL
async function generateShareImage(req, res) {
    const { download, upload, ping, isp, server } = req.query;

    // Create a shareable result URL using a badge service
    // This generates a simple text-based result that can be shared
    const resultData = {
        download: parseFloat(download) || 0,
        upload: parseFloat(upload) || 0,
        ping: parseFloat(ping) || 0,
        isp: isp || 'Unknown',
        server: server || 'Cloudflare',
        timestamp: new Date().toISOString(),
        generator: 'MDI Network Diagnostics'
    };

    // Generate a simple SVG result image
    const svgImage = generateResultSVG(resultData);

    // Return as data URL for easy download
    const base64 = Buffer.from(svgImage).toString('base64');
    const dataUrl = `data:image/svg+xml;base64,${base64}`;

    return res.json({
        success: true,
        data: {
            svg: svgImage,
            dataUrl,
            results: resultData
        }
    });
}

// Generate SVG result image
function generateResultSVG(data) {
    return `<?xml version="1.0" encoding="UTF-8"?>
<svg width="600" height="300" xmlns="http://www.w3.org/2000/svg">
  <defs>
    <linearGradient id="bg" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" style="stop-color:#0f172a"/>
      <stop offset="100%" style="stop-color:#1e3a5f"/>
    </linearGradient>
  </defs>
  <rect width="600" height="300" fill="url(#bg)"/>
  <text x="300" y="40" text-anchor="middle" fill="#06b6d4" font-family="Arial" font-size="24" font-weight="bold">MDI Network Speed Test</text>
  
  <rect x="30" y="70" width="160" height="80" rx="10" fill="#1e293b"/>
  <text x="110" y="100" text-anchor="middle" fill="#94a3b8" font-family="Arial" font-size="12">DOWNLOAD</text>
  <text x="110" y="135" text-anchor="middle" fill="#06b6d4" font-family="Arial" font-size="28" font-weight="bold">${data.download.toFixed(2)}</text>
  <text x="110" y="150" text-anchor="middle" fill="#64748b" font-family="Arial" font-size="10">Mbps</text>
  
  <rect x="220" y="70" width="160" height="80" rx="10" fill="#1e293b"/>
  <text x="300" y="100" text-anchor="middle" fill="#94a3b8" font-family="Arial" font-size="12">UPLOAD</text>
  <text x="300" y="135" text-anchor="middle" fill="#a855f7" font-family="Arial" font-size="28" font-weight="bold">${data.upload.toFixed(2)}</text>
  <text x="300" y="150" text-anchor="middle" fill="#64748b" font-family="Arial" font-size="10">Mbps</text>
  
  <rect x="410" y="70" width="160" height="80" rx="10" fill="#1e293b"/>
  <text x="490" y="100" text-anchor="middle" fill="#94a3b8" font-family="Arial" font-size="12">PING</text>
  <text x="490" y="135" text-anchor="middle" fill="#22c55e" font-family="Arial" font-size="28" font-weight="bold">${data.ping.toFixed(2)}</text>
  <text x="490" y="150" text-anchor="middle" fill="#64748b" font-family="Arial" font-size="10">ms</text>
  
  <text x="30" y="195" fill="#64748b" font-family="Arial" font-size="12">ISP: <tspan fill="#94a3b8">${data.isp}</tspan></text>
  <text x="30" y="215" fill="#64748b" font-family="Arial" font-size="12">Server: <tspan fill="#94a3b8">${data.server}</tspan></text>
  <text x="30" y="235" fill="#64748b" font-family="Arial" font-size="12">Date: <tspan fill="#94a3b8">${new Date(data.timestamp).toLocaleString()}</tspan></text>
  
  <text x="300" y="280" text-anchor="middle" fill="#475569" font-family="Arial" font-size="10">${data.generator}</text>
</svg>`;
}

// Run full speed test (server-side initialization)
async function runFullTest(req, res) {
    const results = {
        timestamp: new Date().toISOString(),
        status: 'initialized'
    };

    try {
        // Get client info
        const clientIp =
            req.headers['x-forwarded-for']?.split(',')[0]?.trim() ||
            req.headers['x-real-ip'] ||
            'Unknown';

        // Get geo/ISP info
        let clientInfo = { ip: clientIp, isp: 'Unknown' };
        try {
            const geoResponse = await fetch(
                `http://ip-api.com/json/${clientIp}?fields=status,country,city,isp,query`
            );
            const geoData = await geoResponse.json();
            if (geoData.status === 'success') {
                clientInfo = {
                    ip: geoData.query || clientIp,
                    isp: geoData.isp || 'Unknown',
                    country: geoData.country || '',
                    city: geoData.city || ''
                };
            }
        } catch { }

        results.client = clientInfo;
        results.server = {
            name: 'Cloudflare',
            location: 'Global CDN'
        };
        results.status = 'ready';
        results.message = 'Run speed test from client-side for accurate results';

        return res.json({
            success: true,
            data: results
        });
    } catch (error) {
        return res.status(500).json({
            success: false,
            error: error.message
        });
    }
}
