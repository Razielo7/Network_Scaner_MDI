// DNS Records API - Get DNS records (A, MX, SPF, DMARC) for a domain
// Vercel Serverless Function

export default async function handler(req, res) {
    // Handle CORS preflight
    if (req.method === 'OPTIONS') {
        return res.status(200).end();
    }

    const host = req.query.host;

    if (!host) {
        return res.status(400).json({ error: 'Host parameter required' });
    }

    // Extract domain from potential URL or IP
    let domain = host.replace(/^https?:\/\//, '').replace(/\/.*/g, '');

    // Skip IP addresses
    const ipRegex = /^(\d{1,3}\.){3}\d{1,3}$/;
    if (ipRegex.test(domain)) {
        return res.status(200).json({
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
        allIPs: [],
        mx: [],
        spf: null,
        dmarc: null
    };

    try {
        // Use DNS-over-HTTPS to resolve DNS records (works in serverless)
        // Try Cloudflare DoH for A records
        try {
            const dohRes = await fetch(`https://cloudflare-dns.com/dns-query?name=${domain}&type=A`, {
                headers: { 'Accept': 'application/dns-json' }
            });
            const dohData = await dohRes.json();
            if (dohData.Answer && dohData.Answer.length > 0) {
                // Get all A records (type 1)
                const aRecords = dohData.Answer.filter(r => r.type === 1);
                if (aRecords.length > 0) {
                    result.ip = aRecords[0].data;
                    result.allIPs = aRecords.map(r => r.data);
                }
            }
        } catch (e) {
            console.error('A record lookup failed:', e);
        }

        // Get MX records
        try {
            const mxRes = await fetch(`https://cloudflare-dns.com/dns-query?name=${domain}&type=MX`, {
                headers: { 'Accept': 'application/dns-json' }
            });
            const mxData = await mxRes.json();
            if (mxData.Answer && mxData.Answer.length > 0) {
                result.mx = mxData.Answer
                    .filter(r => r.type === 15)
                    .map(r => {
                        // MX data format: "priority exchange"
                        const parts = r.data.split(' ');
                        return {
                            priority: parseInt(parts[0]) || 0,
                            exchange: parts[1] || r.data
                        };
                    })
                    .sort((a, b) => a.priority - b.priority)
                    .slice(0, 3);
            }
        } catch (e) {
            console.error('MX record lookup failed:', e);
        }

        // Get SPF record (TXT record containing "v=spf1")
        try {
            const spfRes = await fetch(`https://cloudflare-dns.com/dns-query?name=${domain}&type=TXT`, {
                headers: { 'Accept': 'application/dns-json' }
            });
            const spfData = await spfRes.json();
            if (spfData.Answer && spfData.Answer.length > 0) {
                const spfRecord = spfData.Answer.find(r => r.type === 16 && r.data.includes('v=spf1'));
                if (spfRecord) {
                    // Remove surrounding quotes if present
                    result.spf = spfRecord.data.replace(/^"|"$/g, '');
                }
            }
        } catch (e) {
            console.error('SPF record lookup failed:', e);
        }

        // Get DMARC record (TXT record at _dmarc subdomain)
        try {
            const dmarcRes = await fetch(`https://cloudflare-dns.com/dns-query?name=_dmarc.${domain}&type=TXT`, {
                headers: { 'Accept': 'application/dns-json' }
            });
            const dmarcData = await dmarcRes.json();
            if (dmarcData.Answer && dmarcData.Answer.length > 0) {
                const txtRecord = dmarcData.Answer.find(r => r.type === 16 && r.data.includes('DMARC'));
                if (txtRecord) {
                    // Remove surrounding quotes if present
                    result.dmarc = txtRecord.data.replace(/^"|"$/g, '');
                }
            }
        } catch (e) {
            console.error('DMARC record lookup failed:', e);
        }

        return res.status(200).json({
            success: true,
            data: result
        });
    } catch (error) {
        return res.status(200).json({
            success: true,
            data: result
        });
    }
}
