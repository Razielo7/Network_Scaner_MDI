// Upload Proxy API - Proxies upload data to Cloudflare speed test
// Vercel Serverless Function

const CLOUDFLARE_UPLOAD_ENDPOINT = 'https://speed.cloudflare.com/__up';
const MAX_UPLOAD_SIZE = 50 * 1024 * 1024; // 50MB

export const config = {
    api: {
        bodyParser: {
            sizeLimit: '50mb',
        },
    },
};

export default async function handler(req, res) {
    // Handle CORS preflight
    if (req.method === 'OPTIONS') {
        return res.status(200).end();
    }

    if (req.method !== 'POST') {
        return res.status(405).json({ error: 'Method not allowed' });
    }

    try {
        // Get raw body as buffer
        const chunks = [];
        for await (const chunk of req) {
            chunks.push(chunk);
        }
        const body = Buffer.concat(chunks);
        const size = body.length;

        if (size === 0) {
            return res.status(400).json({ error: 'No data received' });
        }

        if (size > MAX_UPLOAD_SIZE) {
            return res.status(413).json({
                error: 'Upload size exceeds maximum allowed',
                max_size: MAX_UPLOAD_SIZE
            });
        }

        // Proxy to Cloudflare
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
            return res.status(200).json({
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
}
