// System Info API - Returns client IP from request headers
// Vercel Serverless Function

export default async function handler(req, res) {
    // Handle CORS preflight
    if (req.method === 'OPTIONS') {
        return res.status(200).end();
    }

    // Get client IP from Vercel headers
    const clientIp =
        req.headers['x-forwarded-for']?.split(',')[0]?.trim() ||
        req.headers['x-real-ip'] ||
        req.socket?.remoteAddress ||
        'Unknown';

    // Get user agent
    const userAgent = req.headers['user-agent'] || 'Unknown';

    return res.status(200).json({
        success: true,
        data: {
            client_ip: clientIp,
            user_agent: userAgent,
            // Reverse DNS not available in serverless, but can be added via external API
            hostname: 'N/A'
        }
    });
}
