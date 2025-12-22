<?php
// Target System Module for MDI Network Diagnostics
// Handles target connectivity checks and details

// ========== CONFIGURATION ==========
$targetSystemConfig = [
    'allowed_targets' => [
        'dns.google' => 'https://dns.google',
        'cloudflare.com' => 'https://cloudflare.com',
        'google.com' => 'https://www.google.com',
        'github.com' => 'https://github.com',
        '8.8.8.8' => 'https://dns.google',
        '1.1.1.1' => 'https://cloudflare.com',
        '9.9.9.9' => 'https://quad9.net',
    ],
    'timeout' => 10,
    'max_redirects' => 3,
];

// ========== TARGET SYSTEM API HANDLER ==========
function handleTargetSystemAPI($action, $params = []) {
    global $targetSystemConfig;
    
    header('Content-Type: application/json');
    
    switch($action) {
        case 'ping':
            $hostKey = isset($params['host']) ? htmlspecialchars($params['host']) : null;
            if (!$hostKey || !isset($targetSystemConfig['allowed_targets'][$hostKey])) {
                 // Try to find by key or value, or just use the key if it matches a known target
                 // For security, only allow targets defined in config
                 http_response_code(400); 
                 echo json_encode(['error' => 'Invalid host']);
                 exit;
            }
            
            // Extract hostname from URL or Key
            $targetUrl = $targetSystemConfig['allowed_targets'][$hostKey];
            $host = parse_url($targetUrl, PHP_URL_HOST);
            if(!$host) $host = $hostKey; // Fallback if key is IP
            
            echo json_encode(['data' => pingHost($host)]);
            break;

        case 'details':
            $host = isset($params['host']) ? htmlspecialchars($params['host']) : null;
            
            if (!$host || !isset($targetSystemConfig['allowed_targets'][$host])) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid or missing host parameter']);
                exit;
            }
            echo json_encode(getHostDetails($targetSystemConfig['allowed_targets'][$host]));
            break;
            
        case 'targets_list':
            // Return list of all configured targets
            echo json_encode([
                'targets' => array_map(function($key, $url) {
                    return [
                        'key' => $key,
                        'url' => $url,
                        'name' => ucfirst(str_replace('.', ' ', $key))
                    ];
                }, array_keys($targetSystemConfig['allowed_targets']), $targetSystemConfig['allowed_targets'])
            ]);
            break;
            
        default:
            http_response_code(404);
            echo json_encode(['error' => 'Unknown target system action']);
    }
    exit;
}

// ========== PING FUNCTION (ICMP -> TCP Fallback) ==========
function pingHost($host) {
    // 1. Try ICMP Ping (exec)
    // Linux: ping -c 1 -W 1 host
    // Windows: ping -n 1 -w 1000 host
    $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    $cmd = $isWindows 
        ? "ping -n 1 -w 1000 " . escapeshellarg($host) 
        : "ping -c 1 -W 1 " . escapeshellarg($host);
    
    $output = [];
    $status = -1;
    exec($cmd, $output, $status);
    
    if ($status === 0) {
        // Parse time
        foreach ($output as $line) {
            // Windows: "time=35ms" or "time<1ms"
            // Linux: "time=35.0 ms"
            if (preg_match('/time[=<]([\d\.]+)\s*(ms)?/i', $line, $matches)) {
                return ['latency' => floatval($matches[1]), 'type' => 'ICMP'];
            }
        }
    }
    
    // 2. Fallback: TCP Ping (fsockopen)
    $start = microtime(true);
    $port = 80;
    // Simple heuristic: if likely DNS/HTTPS, try 443
    if ($host === 'dns.google' || $host === '1.1.1.1' || $host === '8.8.8.8' || strpos($host, 'google') !== false || strpos($host, 'cloudflare') !== false) {
         // Some might block 80
         // Actually DNS (53) is UDP, might fail fsockopen.
         // Let's stick to 80/443 for web targets
         $port = 443;
    }
    
    $fp = @fsockopen($host, $port, $errno, $errstr, 1); // 1s timeout
    $end = microtime(true);
    
    if ($fp) {
        fclose($fp);
        return ['latency' => round(($end - $start) * 1000, 1), 'type' => 'TCP'];
    }
    
    return ['latency' => -1, 'error' => 'Unreachable']; // Error
}

// ========== HOST DETAILS FUNCTION ==========
function getHostDetails(string $url): array {
    global $targetSystemConfig;
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_NOBODY => true,
        CURLOPT_TIMEOUT => $targetSystemConfig['timeout'],
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => $targetSystemConfig['max_redirects'],
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);
    
    $info = curl_getinfo($ch); // Init
    
    $start = microtime(true);
    curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    
    return [
        'success' => $info['http_code'] >= 200 && $info['http_code'] < 400,
        'data' => [
            'http_code' => $info['http_code'],
            'total_time' => round(($info['total_time'] * 1000), 1) . ' ms',
            'namelookup' => round(($info['namelookup_time'] * 1000), 1) . ' ms',
            'connect' => round(($info['connect_time'] * 1000), 1) . ' ms',
            'primary_ip' => $info['primary_ip'] ?? 'N/A',
            'ssl_verify' => ($info['scheme'] === 'https') ? 'TLS/SSL' : 'None'
        ]
    ];
}

// ========== TARGET SYSTEM JAVASCRIPT ==========
function getTargetSystemJavaScript() {
    global $targetSystemConfig;
    
    // Convert PHP targets to JavaScript array
    $jsTargets = [];
    foreach ($targetSystemConfig['allowed_targets'] as $key => $url) {
        $name = $key;
        $host = $key;
        
        // Beautify names
        if ($key === 'dns.google') {
            $name = 'Google DNS';
            $host = '8.8.8.8';
        } elseif ($key === 'cloudflare.com') {
            $name = 'Cloudflare';
            $host = '1.1.1.1';
        } elseif ($key === 'google.com') {
            $name = 'Google';
            $host = 'google.com';
        } elseif ($key === 'github.com') {
            $name = 'GitHub';
            $host = 'github.com';
        } elseif ($key === '9.9.9.9') {
            $name = 'Quad9';
            $host = '9.9.9.9';
        }
        
        $jsTargets[] = [
            'key' => $key,
            'name' => $name,
            'host' => $host,
            'url' => $url
        ];
    }
    
    $targetsJson = json_encode($jsTargets);
    
    return <<<JAVASCRIPT
// ========== TARGET SYSTEM MODULE ==========
const TargetSystem = {
    // Configuration
    targets: {$targetsJson},
    activeIntervals: [],

    // Render target list
    renderTargets() {
        document.getElementById('targetsList').innerHTML = this.targets.map((t, i) => `
            <div class="ts-item" id="row-\${i}" onclick="TargetSystem.toggleDetails(\${i}, '\${t.key}')">
                <div class="ts-item-info">
                    <svg class="ts-check-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="16 9 12 15 8 11"/> <!-- Checkmark -->
                    </svg>
                    <div style="font-weight:600; font-size:0.9rem; text-transform:uppercase;">\${t.name}</div>
                </div>
                <!-- Ping Status -->
                <div class="ts-ping-val" id="ping-\${i}">...</div>
            </div>
            <div class="ts-details-panel" id="details-\${i}" style="display:none; padding:1rem; background:hsl(var(--bg)/0.2); margin-bottom:0.5rem; border-radius:8px;">
                <div class="detail-grid" id="grid-\${i}">Loading...</div>
            </div>
        `).join('');
    },
    
    // Check all targets
    async checkAllTargets() {
        this.targets.forEach((t, i) => this.checkTarget(i));
    },
    
    // Check single target with Server-Side Ping
    async checkTarget(i) {
        const pingEl = document.getElementById(`ping-\${i}`);
        const row = document.getElementById(`row-\${i}`);
        const icon = row ? row.querySelector('.ts-check-icon') : null;
        
        if (pingEl && pingEl.textContent === '...') {
             // Keep '...' if initial load, otherwise keep old value while updating
        } else if (pingEl) {
             pingEl.style.opacity = '0.5'; // Visual cue of updating
        }
        
        try {
            // Call server-side ping
            const json = await apiCall('ping', { host: this.targets[i].key });
            const res = json.data;
            
            if (pingEl) pingEl.style.opacity = '1';
            
            let latency = 999;
            let displayTxt = 'Err';
            
            if (res && res.latency !== -1) {
                latency = res.latency;
                displayTxt = latency.toFixed(0) + 'ms';
            }
            
            if (pingEl) pingEl.textContent = displayTxt;
            
            // Color Logic
            if (latency < 50) { 
                if (pingEl) pingEl.className = 'ts-ping-val good'; 
                if (icon) icon.style.color = 'hsl(var(--accent-green))'; 
            } else if (latency < 150) { 
                if (pingEl) pingEl.className = 'ts-ping-val fair'; 
                if (icon) icon.style.color = 'hsl(var(--accent-orange))'; 
            } else { 
                if (pingEl) pingEl.className = 'ts-ping-val poor'; 
                if (icon) icon.style.color = 'hsl(var(--error))'; 
            }
        } catch (e) {
            console.error(e);
            if (pingEl) {
                pingEl.textContent = 'Err';
                pingEl.className = 'ts-ping-val poor';
                pingEl.style.opacity = '1';
            }
            if (icon) icon.style.color = 'hsl(var(--error))';
        }
    },
    
    // Toggle target details
    async toggleDetails(i, hostKey) {
        const details = document.getElementById(`details-\${i}`);
        if(details.style.display === 'block') { 
            details.style.display = 'none'; 
            return; 
        }
        
        // Hide others
        document.querySelectorAll('.ts-details-panel').forEach(d => d.style.display = 'none');
        details.style.display = 'block';
        
        try {
            document.getElementById(`grid-\${i}`).innerHTML = '<div class="detail-item">Loading server diagnostics...</div>';
            const json = await apiCall('details', { host: hostKey });
            const d = json.data;
            document.getElementById(`grid-\${i}`).innerHTML = `
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.5rem; font-size:0.8rem;">
                    <div><span style="color:hsl(var(--text-muted))">Server Ping:</span> <span style="color:hsl(var(--accent-cyan))">\${d.total_time}</span></div>
                    <div><span style="color:hsl(var(--text-muted))">HTTP Code:</span> <span style="color:hsl(var(--accent-cyan))">\${d.http_code}</span></div>
                    <div><span style="color:hsl(var(--text-muted))">Primary IP:</span> <span style="color:hsl(var(--accent-cyan))">\${d.primary_ip}</span></div>
                    <div><span style="color:hsl(var(--text-muted))">Security:</span> <span style="color:hsl(var(--accent-cyan))">\${d.ssl_verify}</span></div>
                </div>
            `;
        } catch {
             document.getElementById(`grid-\${i}`).innerHTML = `<div style="color:hsl(var(--error))">Server Check Failed</div>`;
        }
    },
    
    // Refresh all targets
    refresh() {
        this.renderTargets();
        this.checkAllTargets();
    }
};

// Attach refresh button
document.getElementById('refreshBtn').onclick = () => TargetSystem.refresh();
JAVASCRIPT;
}
?>
