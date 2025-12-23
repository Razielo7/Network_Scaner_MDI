// Details API - Fetch host details with timing
// Vercel Serverless Function

const ALLOWED_TARGETS = {
    'dns.google': 'https://dns.google',
    'cloudflare.com': 'https://cloudflare.com',
    'google.com': 'https://www.google.com',
    'github.com': 'https://github.com',
    '8.8.8.8': 'https://dns.google',
    '1.1.1.1': 'https://cloudflare.com',
    '9.9.9.9': 'https://quad9.net',
};

export default async function handler(req, res) {
    // Handle CORS preflight
    if (req.method === 'OPTIONS') {
        return res.status(200).end();
    }

    const host = req.query.host;

    if (!host || !ALLOWED_TARGETS[host]) {
        return res.status(400).json({ error: 'Invalid or missing host parameter' });
    }

    const targetUrl = ALLOWED_TARGETS[host];

    try {
        const start = performance.now();

        const response = await fetch(targetUrl, {
            method: 'HEAD',
            redirect: 'follow',
            signal: AbortSignal.timeout(10000), // 10 second timeout
        });

        const end = performance.now();
        const totalTime = Math.round(end - start);

        // Get the resolved URL to determine if HTTPS was used
        const isHttps = targetUrl.startsWith('https://');

        return res.status(200).json({
            success: response.status >= 200 && response.status < 400,
            data: {
                http_code: response.status,
                total_time: `${totalTime} ms`,
                namelookup: 'N/A', // Not available in serverless
                connect: 'N/A', // Not available in serverless
                primary_ip: 'N/A', // Not available in serverless
                ssl_verify: isHttps ? 'TLS/SSL' : 'None'
            }
        });
    } catch (error) {
        return res.status(200).json({
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
}
