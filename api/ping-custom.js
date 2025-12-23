// Custom Ping API - Allows pinging any domain via HTTP
// Vercel Serverless Function

export default async function handler(req, res) {
    // Handle CORS preflight
    if (req.method === 'OPTIONS') {
        return res.status(200).end();
    }

    const host = req.query.host;

    if (!host) {
        return res.status(400).json({ error: 'Host parameter is required' });
    }

    // Clean up the host - remove protocol if provided
    let cleanHost = host.trim();
    if (cleanHost.startsWith('http://') || cleanHost.startsWith('https://')) {
        cleanHost = cleanHost.replace(/^https?:\/\//, '');
    }

    // Remove trailing slashes and paths
    cleanHost = cleanHost.split('/')[0];

    // Build the target URL
    const targetUrl = `https://${cleanHost}`;

    try {
        const start = performance.now();

        // HTTP HEAD request to measure response time
        const response = await fetch(targetUrl, {
            method: 'HEAD',
            signal: AbortSignal.timeout(5000), // 5 second timeout
        });

        const end = performance.now();
        const latency = Math.round(end - start);

        return res.status(200).json({
            data: {
                latency: latency,
                host: cleanHost,
                type: 'HTTP',
                success: response.ok
            }
        });
    } catch (error) {
        // Try HTTP if HTTPS fails
        try {
            const httpUrl = `http://${cleanHost}`;
            const start = performance.now();

            const response = await fetch(httpUrl, {
                method: 'HEAD',
                signal: AbortSignal.timeout(5000),
            });

            const end = performance.now();
            const latency = Math.round(end - start);

            return res.status(200).json({
                data: {
                    latency: latency,
                    host: cleanHost,
                    type: 'HTTP',
                    success: response.ok
                }
            });
        } catch (httpError) {
            return res.status(200).json({
                data: {
                    latency: -1,
                    host: cleanHost,
                    error: error.name === 'TimeoutError' ? 'Timeout' : 'Unreachable'
                }
            });
        }
    }
}
