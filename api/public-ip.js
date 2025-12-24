// Public IP API - Returns client's public IP and geo info
// Vercel Serverless Function

export default async function handler(req, res) {
    // Handle CORS preflight
    if (req.method === 'OPTIONS') {
        return res.status(200).end();
    }

    try {
        // Use ip-api.com which provides IP + geo in one call
        const response = await fetch('http://ip-api.com/json/?fields=status,message,country,countryCode,region,city,isp,query', {
            headers: {
                // Forward client IP if available (for Vercel)
                'X-Forwarded-For': req.headers['x-forwarded-for'] || req.headers['x-real-ip'] || ''
            }
        });
        const data = await response.json();

        if (data.status === 'success') {
            return res.status(200).json({
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
        // Fallback: try ipify
        try {
            const ipRes = await fetch('https://api.ipify.org?format=json');
            const ipData = await ipRes.json();
            
            return res.status(200).json({
                success: true,
                data: {
                    ip: ipData.ip,
                    country: 'Unknown',
                    country_code: '',
                    region: '',
                    city: '',
                    isp: ''
                }
            });
        } catch (fallbackError) {
            return res.status(500).json({
                success: false,
                error: error.message || 'Failed to get public IP'
            });
        }
    }
}
