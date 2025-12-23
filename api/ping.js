// Ping API - HTTP-based timing check (replaces ICMP ping)
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
    return res.status(400).json({ error: 'Invalid host' });
  }

  const targetUrl = ALLOWED_TARGETS[host];

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
        type: 'HTTP',
        success: response.ok
      }
    });
  } catch (error) {
    return res.status(200).json({
      data: {
        latency: -1,
        error: error.name === 'TimeoutError' ? 'Timeout' : 'Unreachable'
      }
    });
  }
}
