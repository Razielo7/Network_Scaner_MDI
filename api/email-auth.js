// Email Authentication API - Comprehensive SPF, DKIM, DMARC check
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
        // Get SPF record via DNS-over-HTTPS
        try {
            const spfRes = await fetch(`https://cloudflare-dns.com/dns-query?name=${domain}&type=TXT`, {
                headers: { 'Accept': 'application/dns-json' }
            });
            const spfData = await spfRes.json();
            if (spfData.Answer && spfData.Answer.length > 0) {
                const spfRecord = spfData.Answer.find(r => r.type === 16 && r.data.includes('v=spf1'));
                if (spfRecord) {
                    const record = spfRecord.data.replace(/^"|"$/g, '');
                    result.spf.found = true;
                    result.spf.record = record;
                    result.spf.valid = true;
                    // Parse mechanisms
                    const mechanisms = record.match(/(?:include:|a:|mx:|ip4:|ip6:|all|-all|~all|\+all|\?all)/g);
                    result.spf.mechanisms = mechanisms || [];
                }
            }
        } catch (e) {
            console.error('SPF lookup failed:', e);
        }

        // Try DKIM selectors
        for (const selector of dkimSelectors) {
            try {
                const dkimRes = await fetch(`https://cloudflare-dns.com/dns-query?name=${selector}._domainkey.${domain}&type=TXT`, {
                    headers: { 'Accept': 'application/dns-json' }
                });
                const dkimData = await dkimRes.json();
                if (dkimData.Answer && dkimData.Answer.length > 0) {
                    const txtRecord = dkimData.Answer.find(r => r.type === 16);
                    if (txtRecord && txtRecord.data.includes('DKIM1')) {
                        const dkimRecord = txtRecord.data.replace(/^"|"$/g, '');
                        result.dkim.found = true;
                        result.dkim.selector = selector;
                        result.dkim.record = dkimRecord.length > 100 ? dkimRecord.substring(0, 100) + '...' : dkimRecord;
                        // Extract key type
                        const keyMatch = dkimRecord.match(/k=([^;]+)/);
                        result.dkim.keyType = keyMatch ? keyMatch[1] : 'rsa';
                        break; // Found one, stop looking
                    }
                }
            } catch { }
        }

        // Get DMARC record
        try {
            const dmarcRes = await fetch(`https://cloudflare-dns.com/dns-query?name=_dmarc.${domain}&type=TXT`, {
                headers: { 'Accept': 'application/dns-json' }
            });
            const dmarcData = await dmarcRes.json();
            if (dmarcData.Answer && dmarcData.Answer.length > 0) {
                const txtRecord = dmarcData.Answer.find(r => r.type === 16 && r.data.includes('DMARC'));
                if (txtRecord) {
                    const dmarcRecord = txtRecord.data.replace(/^"|"$/g, '');
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
            }
        } catch (e) {
            console.error('DMARC lookup failed:', e);
        }

        // Get MX records for mail server info
        try {
            const mxRes = await fetch(`https://cloudflare-dns.com/dns-query?name=${domain}&type=MX`, {
                headers: { 'Accept': 'application/dns-json' }
            });
            const mxData = await mxRes.json();
            if (mxData.Answer && mxData.Answer.length > 0) {
                result.mx = mxData.Answer
                    .filter(r => r.type === 15)
                    .map(r => {
                        const parts = r.data.split(' ');
                        return {
                            priority: parseInt(parts[0]) || 0,
                            exchange: parts[1] || r.data
                        };
                    })
                    .sort((a, b) => a.priority - b.priority)
                    .slice(0, 5);

                result.smtp.hasMailServer = result.mx.length > 0;

                // Infer STARTTLS support from known providers
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
            }
        } catch (e) {
            console.error('MX lookup failed:', e);
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
