<?php
// End User Info Module for MDI Network Diagnostics
// Handles system network information, public IP, and DNS detection

// ---------- Secure client IP detection with proxy validation ----------
function getClientIP() {
    $trustedProxies = explode(',', getenv('TRUSTED_PROXIES') ?: '');
    $trustedProxies = array_filter($trustedProxies);
    
    $ip = null;
    
    // Only trust proxy headers if server is behind a known trusted proxy
    if (!empty($trustedProxies) && in_array($_SERVER['REMOTE_ADDR'], $trustedProxies)) {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = $_SERVER['HTTP_X_REAL_IP'];
        }
    }
    
    // Fall back to direct connection IP if no trusted proxy headers available
    if (!$ip) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    }
    
    // Validate IP format
    if ($ip !== 'UNKNOWN' && !filter_var(trim($ip), FILTER_VALIDATE_IP)) {
        $ip = 'UNKNOWN';
    }
    
    return trim($ip);
}

$client_ip = getClientIP();

// ---------- Reverse DNS lookup (hostname) ----------
$client_dns = ($client_ip !== 'UNKNOWN')
    ? gethostbyaddr($client_ip)
    : 'UNKNOWN';

// ---------- Optional: user agent ----------
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';

// ========== CONFIGURATION ==========
$endUserInfoConfig = [
    'public_ip_service' => 'https://api.ipify.org',
    'geo_service' => 'http://ip-api.com/json/',
    'timeout' => 5,
];

// ========== END USER INFO API HANDLER ==========
function handleEndUserInfoAPI($action) {
    header('Content-Type: application/json');
    
    switch($action) {
        case 'system_info':
            echo json_encode(getSystemInfo());
            break;
            
        case 'my_ip':
            echo json_encode(getPublicIp());
            break;
            
        case 'local_ip':
            echo json_encode(getLocalNetworkIP());
            break;
            
        default:
            http_response_code(404);
            echo json_encode(['error' => 'Unknown end user info action']);
    }
    exit;
}

// ========== SYSTEM INFO FUNCTION (DEPRECATED - Returns empty) ==========
function getSystemInfo(): array {
    // SECURITY: Server-side network collection removed.
    // Client-side network information cannot be reliably determined by the server.
    // Clients should use WebRTC, Browser APIs, or similar for local network detection.
    
    error_log("WARNING: getSystemInfo() called but disabled per security audit. Use client-side methods instead.");
    
    return [
        'adapters' => [],
        'dns' => [],
        'warning' => 'Server-side network detection is not available. Use client-side methods (WebRTC, DoH) for accurate local network information.'
    ];
}

// ========== WINDOWS NETWORK INFO (DEPRECATED) ==========
function getWindowsNetworkInfo(): array {
    // DEPRECATED: Removed per security audit. Server cannot reliably determine client network configuration.
    // Robust PowerShell script - filters out Hyper-V, Virtual, VMware, Docker, WSL adapters
    $psScript = base64_encode(utf8_encode(<<<PS
Get-NetAdapter | Where-Object { 
    \$_.Status -eq 'Up' -and 
    \$_.InterfaceDescription -notmatch 'Hyper-V|vEthernet|Virtual|VMware|Docker|WSL|Loopback|RAS|Dial-up'
} | ForEach-Object { 
    \$ip = Get-NetIPAddress -InterfaceIndex \$_.InterfaceIndex -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object { 
        \$_.IPAddress -notmatch '^169\\.254' -and 
        \$_.PrefixOrigin -ne 'Other'
    } | Select-Object -ExpandProperty IPAddress -First 1
    
    if (-not \$ip) { return }
    
    \$dns = Get-DnsClientServerAddress -InterfaceIndex \$_.InterfaceIndex -AddressFamily IPv4 -ErrorAction SilentlyContinue | Select-Object -ExpandProperty ServerAddresses
    \$type = if (\$_.InterfaceDescription -match 'Wi-Fi|Wireless|802\\.11|WLAN') { 'WLAN' } else { 'LAN' }
    
    [PSCustomObject]@{ 
        Name = \$_.Name
        Description = \$_.InterfaceDescription
        Type = \$type
        IP = \$ip
        DNS = \$dns
    }
} | ConvertTo-Json -Compress
PS
    ));
    
    $cmd = "powershell -NoProfile -EncodedCommand $psScript";
    exec($cmd, $output, $ret);
    
    if ($ret !== 0) throw new Exception("PowerShell failed");
    
    $json = implode('', $output);
    $decoded = json_decode($json, true);
    
    if (empty($decoded)) return ['adapters' => [], 'dns' => []];
    if (isset($decoded['IP'])) $decoded = [$decoded]; // Single result
    
    return processAdapterData($decoded);
}

// ========== MACOS NETWORK INFO (DEPRECATED) ==========
function getMacOsNetworkInfo(): array {
    // DEPRECATED: Removed per security audit. Server cannot reliably determine client network configuration.
    $ports = shell_exec('networksetup -listallhardwareports 2>/dev/null');
    preg_match_all('/Hardware Port: (Wi-Fi|Ethernet).*?Device: (en\d+)/s', $ports, $matches, PREG_SET_ORDER);
    
    $adapters = [];
    $dns = [];
    
    foreach ($matches as $m) {
        $iface = $m[2];
        $ip = trim(shell_exec("ipconfig getifaddr $iface 2>/dev/null"));
        if (!$ip) continue;
        
        $dnsRaw = shell_exec("networksetup -getdnsservers \"$m[1]\" 2>/dev/null");
        preg_match_all('/\d+\.\d+\.\d+\.\d+/', $dnsRaw, $dnsMatches);
        
        $adapters[] = [
            'name' => $iface,
            'type' => $m[1] === 'Wi-Fi' ? 'WLAN' : 'LAN',
            'ip' => $ip
        ];
        if (!empty($dnsMatches[0])) {
            $dns = array_merge($dns, $dnsMatches[0]);
        }
    }
    return ['adapters' => $adapters, 'dns' => $dns];
}

// ========== LINUX NETWORK INFO (DEPRECATED) ==========
function getLinuxNetworkInfo(): array {
    // DEPRECATED: Removed per security audit. Server cannot reliably determine client network configuration.
    $adapters = [];
    $dns = [];
    
    // Get primary interface via routing
    $route = shell_exec('ip route get 1.1.1.1 2>/dev/null');
    if (preg_match('/src ([0-9.]+).* dev (\S+)/', $route, $m)) {
        $iface = $m[2];
        $type = preg_match('/^wl/', $iface) ? 'WLAN' : 'LAN';
        $adapters[] = ['name' => $iface, 'type' => $type, 'ip' => $m[1]];
    }
    
    // DNS detection - multiple methods
    $dnsFound = false;
    foreach (['/usr/bin/resolvectl', '/usr/bin/systemd-resolve'] as $cmd) {
        if (is_executable($cmd)) {
            $dnsOut = shell_exec("$cmd dns 2>/dev/null");
            if (preg_match_all('/DNS Servers:\s+([0-9.]+)/', $dnsOut, $m)) {
                $dns = array_merge($dns, $m[1] ?? []);
                $dnsFound = true;
                break;
            }
        }
    }
    
    if (!$dnsFound && file_exists('/etc/resolv.conf')) {
        $dnsRaw = file_get_contents('/etc/resolv.conf');
        preg_match_all('/nameserver\s+([0-9.]+)/', $dnsRaw, $m);
        // Exclude local loopbacks
        $dns = array_filter($m[1], fn($d) => !in_array($d, ['127.0.0.1', '127.0.0.53']));
    }
    
    return ['adapters' => $adapters, 'dns' => $dns];
}

// ========== PROCESS ADAPTER DATA ==========
function processAdapterData(array $decoded): array {
    $adapters = [];
    $dns = [];
    foreach ($decoded as $net) {
        $adapters[] = [
            'name' => $net['Name'],
            'type' => $net['Type'],
            'ip' => $net['IP']
        ];
        if (!empty($net['DNS'])) {
            $d = is_array($net['DNS']) ? $net['DNS'] : [$net['DNS']];
            $dns = array_merge($dns, $d);
        }
    }
    return ['adapters' => $adapters, 'dns' => $dns];
}

// ========== PUBLIC IP FUNCTION ==========
function getPublicIp(): array {
    global $endUserInfoConfig;
    
    $context = stream_context_create(['http' => ['timeout' => $endUserInfoConfig['timeout']]]);
    $ip = @file_get_contents($endUserInfoConfig['public_ip_service'], false, $context);
    if (!$ip) $ip = $_SERVER['REMOTE_ADDR'];
    
    $geo = @json_decode(@file_get_contents($endUserInfoConfig['geo_service'] . "{$ip}?fields=country,countryCode", false, $context), true);
    return [
        'ip' => $ip,
        'country' => $geo['country'] ?? 'Unknown',
        'code' => strtolower($geo['countryCode'] ?? '')
    ];
}

// ========== LOCAL NETWORK IP DETECTION ==========
function getLocalNetworkIP(): array {
    // Get the actual client connection IP (their real IP connecting to the server)
    $clientIP = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    
    // Check for proxy headers (if behind load balancer, reverse proxy, etc)
    $possibleHeaders = [
        'HTTP_CF_CONNECTING_IP',  // Cloudflare
        'HTTP_X_FORWARDED_FOR',   // Standard proxy header
        'HTTP_X_REAL_IP',         // Nginx
        'HTTP_CLIENT_IP',         // Various proxies
        'HTTP_X_FORWARDED',       // Generic
    ];
    
    foreach ($possibleHeaders as $header) {
        if (!empty($_SERVER[$header])) {
            $ips = explode(',', $_SERVER[$header]);
            $realIP = trim($ips[0]);
            if (filter_var($realIP, FILTER_VALIDATE_IP)) {
                $clientIP = $realIP;
                break;
            }
        }
    }
    
    return [
        'ip' => $clientIP,
        'method' => 'server_connection',
        'success' => $clientIP !== 'UNKNOWN' && filter_var($clientIP, FILTER_VALIDATE_IP),
        'type' => 'client_connection_ip'
    ];
}

// ========== END USER INFO JAVASCRIPT ==========
function getEndUserInfoJavaScript() {
    return <<<'JAVASCRIPT'
// ========== END USER INFO MODULE ==========
const EndUserInfo = {
    // Load system network information
    async loadSystemInfo() {
        try {
            const listEl = document.getElementById('connList');
            listEl.innerHTML = ''; // Clear loading state
            
            // 1. Try to get Local IP via WebRTC (Client-side real IP)
            const localIPs = await this.getWebRTCLocalIPs();
            let hasLocalParams = false;

            if (localIPs.length > 0) {
                hasLocalParams = true;
                localIPs.forEach(ip => {
                    // Update connection list with new structure (we append to existing or clear first? The code clears first)
                    // We are targeting 'connList' which is now a div.di-value. 
                    // Actually, the new structure has `id="connList"` on a specific .di-value div.
                    // So we should just set textContent or simple HTML.
                    // Since it might be multiple IPs, let's just join them.
                    
                    if (listEl.textContent === 'Detecting...') listEl.innerHTML = '';
                    listEl.innerHTML += `<div style="margin-bottom:2px;">${ip} <span style="font-size:0.7rem; color:hsl(var(--text-muted))">(WiFi/LAN)</span></div>`;
                });
            }

            // 2. Fallback/Augment with Server-Seen IP
            const clientData = await this.getClientConnectionIP();
            if (clientData && clientData.ip && clientData.success) {
                if (!localIPs.includes(clientData.ip)) {
                     if (listEl.textContent === 'Detecting...') listEl.innerHTML = '';
                     listEl.innerHTML += `<div style="margin-bottom:2px;">${clientData.ip} <span style="font-size:0.7rem; color:hsl(var(--text-muted))">(Gateway)</span></div>`;
                }
            } else if (localIPs.length === 0) {
                 listEl.innerHTML = '<span style="color:hsl(var(--error))">Unknown</span>';
            }

            // 3. Handle DNS (Browser Limitation)
            const dnsEl = document.getElementById('dnsServer');
            if (dnsEl) {
                dnsEl.innerHTML = '<span style="font-size:0.8rem; color:hsl(var(--text-muted))">Hidden (Browser Security)</span>';
            }

        } catch(e) {
            console.error('Failed to load network information:', e.message);
            document.getElementById('connList').innerHTML = '<div style="color:hsl(var(--text-muted))">Error retrieving connection IP</div>';
        }
    },

    // Get Local IP using WebRTC
    async getWebRTCLocalIPs() {
        return new Promise((resolve) => {
            const ips = new Set();
            try {
                const pc = new RTCPeerConnection({iceServers: []});
                pc.createDataChannel('');
                pc.onicecandidate = (e) => {
                    if (!e.candidate) {
                        pc.close();
                        resolve(Array.from(ips));
                        return;
                    }
                    const ipRegex = /([0-9]{1,3}(\.[0-9]{1,3}){3})/;
                    const match = e.candidate.candidate.match(ipRegex);
                    if (match) {
                        const ip = match[1];
                        if (ip !== '0.0.0.0' && ip !== '127.0.0.1') {
                            ips.add(ip);
                        }
                    }
                };
                pc.createOffer().then(sdp => pc.setLocalDescription(sdp)).catch(() => resolve([]));
                // Timeout after 1s if no candidates
                setTimeout(() => {
                    resolve(Array.from(ips));
                }, 1000);
            } catch (err) {
                resolve([]);
            }
        });
    },
    
    // Get the client's connection IP from server
    async getClientConnectionIP() {
        try {
            return await apiCall('local_ip');
        } catch (e) {
            console.warn('Failed to get connection IP:', e.message);
        }
        return null;
    },
    
    // Load public IP and geolocation
    async loadPublicInfo() {
        try {
            const data = await apiCall('my_ip');
            document.getElementById('publicIP').textContent = data.ip;
            document.getElementById('country').innerHTML = 
                `${data.country} ${data.code ? `<img src="https://flagcdn.com/16x12/${data.code}.png">` : ''}`;
        } catch {
             document.getElementById('publicIP').textContent = 'Error';
        }
    }
};
JAVASCRIPT;
}
?>
