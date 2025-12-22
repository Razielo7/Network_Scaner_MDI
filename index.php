<?php
// MDI Network Diagnostics - Production Ready
// REQUIRED: Set environment variable MDI_API_KEY

// ========== INCLUDE MODULES ==========
require_once __DIR__ . '/enduser_info.php';
require_once __DIR__ . '/speed_test.php';
require_once __DIR__ . '/target_system.php';

// ========== GET CLIENT INFORMATION ==========
// Get client IP as seen by the server
$client_ip = function_exists('getClientIP') ? getClientIP() : ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN');

// Get reverse DNS (hostname) for the IP
$client_dns = 'UNKNOWN';
if ($client_ip !== 'UNKNOWN' && filter_var($client_ip, FILTER_VALIDATE_IP)) {
    // Only attempt reverse lookup for public IPs
    if (!filter_var($client_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        $client_dns = @gethostbyaddr($client_ip);
    } else {
        $client_dns = "N/A (Private IP)";
    }
}

// Get user agent string
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';

// Use same IP for rate limiting (ensure consistency)
$clientIp = $client_ip;

// ========== CONFIGURATION ==========
$apiKey = getenv('MDI_API_KEY');
if (!$apiKey || strlen($apiKey) < 32) {
    // SECURITY: No fallback API key. Require environment configuration for production.
    // For development: Set MDI_API_KEY env variable or disable API key validation below.
    $apiKey = null;
}

$config = [
    'api_key' => $apiKey,
    'rate_limit' => 60, // requests per minute per IP
];

// ========== SECURITY ==========
session_start();
// Handle CLI vs Web
if (php_sapi_name() === 'cli' && !isset($_SERVER['REMOTE_ADDR'])) {
   // Allow CLI testing if needed, or exit. 
   // For now, allow basic script execution but API checks might fail without params.
}

$clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

// Rate limiting using file-based storage with atomic flock()
function checkAndUpdateRateLimit($clientIp, $limit) {
    $rateFile = sys_get_temp_dir() . '/mdi_rate_' . md5($clientIp) . '.txt';
    $requests = [];
    
    // Open or create file for atomic operations
    $handle = @fopen($rateFile, 'c+');
    if (!$handle) {
        // Fallback: allow request if file cannot be accessed
        error_log("WARNING: Rate limit file cannot be accessed: $rateFile");
        return true;
    }
    
    // Acquire exclusive lock
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return false;
    }
    
    // Read and parse existing data
    rewind($handle);
    $content = stream_get_contents($handle);
    if ($content) {
        $data = @json_decode($content, true);
        $requests = $data['timestamps'] ?? [];
        $requests = array_filter($requests, fn($t) => time() - $t < 60);
    }
    
    if (count($requests) >= $limit) {
        flock($handle, LOCK_UN);
        fclose($handle);
        return false;
    }
    
    // Add new timestamp and write atomically
    $requests[] = time();
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode(['timestamps' => $requests]));
    
    // Release lock and close
    flock($handle, LOCK_UN);
    fclose($handle);
    return true;
}

if (!checkAndUpdateRateLimit($clientIp, $config['rate_limit'])) {
    http_response_code(429);
    header('Content-Type: application/json');
    exit(json_encode(['error' => 'Rate limit exceeded']));
}

// ========== API HANDLERS ==========
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    
    // Validate API key only if one is configured
    if ($config['api_key']) {
        $providedKey = htmlspecialchars($_GET['api_key'] ?? '');
        if (!hash_equals($config['api_key'], $providedKey)) {
            http_response_code(401);
            exit(json_encode(['error' => 'Unauthorized: Invalid or missing API key']));
        }
    }
    
    $action = htmlspecialchars($_GET['api'] ?? '');
    
    // Check if this is an end user info related API call
    if (in_array($action, ['system_info', 'my_ip', 'local_ip'])) {
        handleEndUserInfoAPI($action);
        exit;
    }
    
    // Check if this is a speed test related API call
    if (in_array($action, ['upload_proxy', 'speed_test_config'])) {
        handleSpeedTestAPI($action);
        exit;
    }
    
    // Check if this is a target system related API call
    if (in_array($action, ['details', 'targets_list', 'ping'])) {
        handleTargetSystemAPI($action, $_GET);
        exit;
    }
    
    // No valid API action found
    http_response_code(404);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

// ========== PAGE AUTH CHECK ==========
// Page-level auth removed per user request.
// The UI will load, but API calls may require the key if enforced elsewhere.
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>MDI Network Diagnostics</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
    /* NEON THEME CSS */
    * { margin: 0; padding: 0; box-sizing: border-box; }
    :root {
      --bg: 220 20% 10%; /* Very dark navy */
      --bg-card: 220 25% 15%; /* Slightly lighter */
      --text: 210 40% 98%;
      --text-muted: 215 20% 65%;
      
      --accent-cyan: 190 100% 50%;
      --accent-green: 150 100% 50%;
      --accent-purple: 270 100% 65%;
      --accent-blue: 210 100% 60%;
      --accent-orange: 30 100% 60%;
      
      --border: 220 25% 25%;
      
      --glass-bg: hsla(220, 25%, 15%, 0.4);
      --glass-border: hsla(220, 25%, 25%, 0.3);
    }
    
    body {
      font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
      background-color: hsl(var(--bg));
      color: hsl(var(--text));
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: 2rem;
    }

    .container {
      width: 100%;
      max-width: 480px; /* Mobile-first/Card width constraint like image */
      margin: 0 auto;
    }

    /* Header Section */
    .st-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 0.5rem;
    }
    
    .st-title {
      font-size: 1.5rem;
      font-weight: 700;
      display: flex;
      align-items: center;
      gap: 0.5rem;
      color: hsl(var(--accent-cyan));
    }
    
    .st-start-btn {
      background: hsl(var(--accent-cyan));
      color: #000;
      border: none;
      padding: 0.75rem 1.5rem;
      border-radius: 12px;
      font-weight: 600;
      font-size: 1rem;
      cursor: pointer;
      box-shadow: 0 0 15px hsl(var(--accent-cyan) / 0.3);
      transition: transform 0.2s, box-shadow 0.2s;
    }
    .st-start-btn:hover:not(:disabled) {
      transform: translateY(-2px);
      box-shadow: 0 0 25px hsl(var(--accent-cyan) / 0.5);
    }
    .st-start-btn:disabled {
      opacity: 0.7;
      cursor: not-allowed;
    }

    /* Progress Bar */
    .st-progress-container {
      margin-bottom: 2rem;
    }
    .st-progress-labels {
      display: flex;
      justify-content: space-between;
      color: hsl(var(--text-muted));
      font-family: monospace;
      font-size: 0.9rem;
      margin-bottom: 0.25rem;
    }
    .st-progress-bar {
      height: 8px;
      background: hsl(var(--bg-card));
      border-radius: 4px;
      overflow: hidden;
      position: relative;
    }
    .st-progress-fill {
      position: absolute;
      left: 0; top: 0; bottom: 0;
      width: 0%;
      background: linear-gradient(90deg, hsl(var(--accent-cyan)), hsl(var(--accent-purple)));
      box-shadow: 0 0 10px hsl(var(--accent-cyan) / 0.5);
      transition: width 0.2s;
    }

    /* 2x2 Grid */
    .st-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1rem;
      margin-bottom: 2rem;
    }
    
    .st-card {
      background: hsl(var(--bg-card));
      border: 1px solid hsl(var(--border));
      border-radius: 16px;
      padding: 1.5rem;
      text-align: center;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
    }
    
    .st-icon {
      width: 24px;
      height: 24px;
      margin-bottom: 0.5rem;
    }
    
    .st-value {
      font-size: 2rem;
      font-weight: 700;
      line-height: 1;
    }
    
    .st-label {
      font-size: 0.7rem;
      text-transform: uppercase;
      letter-spacing: 1px;
      color: hsl(var(--text-muted));
    }
    
    /* Text Colors */
    .text-cyan { color: hsl(var(--accent-cyan)); }
    .text-green { color: hsl(var(--accent-green)); }
    .text-purple { color: hsl(var(--accent-purple)); }
    .text-blue { color: hsl(var(--accent-blue)); }
    .text-orange { color: hsl(var(--accent-orange)); }

    /* Graph Section */
    .st-graph-section {
      margin-top: 1rem;
    }
    .st-graph-header {
      color: hsl(var(--text-muted));
      font-size: 0.8rem;
      text-transform: uppercase;
      letter-spacing: 1px;
      margin-bottom: 1rem;
    }
    .st-graph-container {
      height: 150px;
      width: 100%;
      position: relative;
    }
    .st-graph-container svg {
        width: 100%;
        height: 100%;
        overflow: visible;
    }
    
    /* Device Info Styles */
    .di-card {
      background: hsl(var(--bg-card));
      border: 1px solid hsl(var(--border));
      border-radius: 16px;
      padding: 1.5rem;
      margin-bottom: 2rem;
    }
    .di-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1.5rem;
      padding-bottom: 1rem;
      border-bottom: 1px solid hsl(var(--border));
    }
    .di-title {
      font-size: 1.25rem;
      font-weight: 700;
      color: hsl(var(--accent-cyan));
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    .di-list {
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
    }
    .di-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 0.75rem 1rem;
      background: hsl(var(--bg) / 0.5);
      border-radius: 8px;
    }
    .di-label {
      font-size: 0.8rem;
      text-transform: uppercase;
      letter-spacing: 1px;
      color: hsl(var(--text-muted));
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    .di-value {
      font-family: monospace;
      font-size: 0.95rem;
      color: hsl(var(--accent-cyan));
    }
    .di-footer {
      margin-top: 1.5rem;
      padding-top: 1rem;
      border-top: 1px solid hsl(var(--border) / 0.5);
      display: flex;
      justify-content: space-between;
      font-size: 0.8rem;
      color: hsl(var(--text-muted));
    }
    .di-footer strong { color: hsl(var(--text)); }

    /* Target Systems Styles */
    .ts-card {
      background: hsl(var(--bg-card));
      border: 1px solid hsl(var(--border));
      border-radius: 16px;
      padding: 1.5rem;
    }
    .ts-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1.5rem;
    }
    /* Target Systems Card (New Layout) */
    .ts-card {
      width: 100%;
      max-width: 800px;
      margin: 0 auto 2rem auto;
      background: hsl(var(--bg-card) / 0.6); /* Semi-transparent like image */
      backdrop-filter: blur(10px);
      border: 1px solid hsl(var(--border) / 0.5);
      border-radius: 20px;
      padding: 2rem;
      box-shadow: 0 8px 32px rgba(0,0,0,0.3);
    }
    
    .ts-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 2rem;
    }

    .ts-title {
      font-size: 1.25rem;
      font-weight: 700;
      color: hsl(var(--accent-cyan));
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    .ts-ping-all-btn {
      background: transparent;
      border: 1px solid hsl(var(--accent-cyan));
      color: hsl(var(--accent-cyan));
      padding: 0.5rem 1rem;
      border-radius: 8px;
      cursor: pointer;
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 0.5rem;
      transition: all 0.2s;
    }
    .ts-ping-all-btn:hover {
      background: hsl(var(--accent-cyan) / 0.1);
    }
    
    .ts-search-container {
      display: flex;
      gap: 1rem;
      margin-bottom: 2rem;
    }
    .ts-search-input {
      flex: 1;
      background: hsl(var(--bg) / 0.5);
      border: 1px solid hsl(var(--border));
      color: hsl(var(--text));
      padding: 0.75rem 1rem;
      border-radius: 8px;
      font-family: inherit;
    }
    .ts-go-btn {
      background: hsl(var(--accent-cyan));
      color: #000;
      border: none;
      width: 42px;
      border-radius: 8px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    /* Visual Line */
    .ts-visual-line {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 2rem;
      padding: 0 1rem;
    }
    .ts-node {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 0.5rem;
      position: relative;
      z-index: 2;
    }
    .ts-node-icon {
      width: 48px;
      height: 48px;
      background: hsl(var(--bg) / 0.5);
      border: 1px solid hsl(var(--border));
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: hsl(var(--accent-cyan));
    }
    .ts-line-track {
      flex: 1;
      height: 2px;
      background: linear-gradient(90deg, hsl(var(--accent-cyan)), hsl(var(--accent-purple)), hsl(var(--accent-green)));
      margin: 0 1rem;
      position: relative;
      top: -14px; /* Align with icon center roughly */
      z-index: 1;
    }
    .ts-line-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      position: absolute;
      top: 50%;
      transform: translateY(-50%);
      background: currentColor;
    }

    /* Target List */
    .ts-list {
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
    }
    .ts-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 1rem;
      background: hsl(var(--bg) / 0.3);
      border-radius: 8px;
      cursor: pointer;
      border: 1px solid transparent;
      transition: background 0.2s;
    }
    .ts-item:hover {
      background: hsl(var(--bg) / 0.6);
      border-color: hsl(var(--border) / 0.5);
    }
    .ts-item-info {
      display: flex;
      align-items: center;
      gap: 1rem;
    }
    .ts-check-icon {
      color: hsl(var(--accent-green));
      width: 20px;
      height: 20px;
    }
    .ts-name {
      font-weight: 600;
      color: hsl(var(--text-muted));
    }
    .ts-ping-val {
      font-family: monospace;
      font-weight: 600;
    }
    .ts-ping-val.good { color: hsl(var(--accent-green)); }
    .ts-ping-val.fair { color: hsl(var(--accent-orange)); }
    .ts-ping-val.poor { color: hsl(var(--error)); }

    /* Legend */
    .ts-legend {
      display: flex;
      justify-content: center;
      gap: 2rem;
      margin-top: 2rem;
      font-size: 0.75rem;
      color: hsl(var(--text-muted));
    }
    .ts-legend-item { display: flex; align-items: center; gap: 0.5rem; }
    .ts-dot { width: 8px; height: 8px; border-radius: 50%; }

    @keyframes fade-in { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
  
    /* Wide Top Bar (Device Info) */
    .di-bar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      width: 100%;
      max-width: 800px;
      margin: 0 auto 2rem auto;
      padding: 0.5rem 1rem; /* Smaller padding */
      background: hsl(var(--bg-card) / 0.15); /* More transparent */
      backdrop-filter: blur(4px);
      border: 1px solid hsl(var(--border) / 0.4);
      border-radius: 50px; /* Pill shape */
      box-shadow: 0 4px 20px rgba(0,0,0,0.3);
      font-size: 0.8rem; /* Smaller font */
      gap: 1.5rem;
    }
    .di-bar-item {
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    .di-bar-label {
      color: hsl(var(--text-muted));
      font-weight: 600;
      text-transform: uppercase;
      font-size: 0.65rem; /* Smaller label */
      letter-spacing: 1px;
    }
    .di-bar-value {
      color: hsl(var(--text));
      font-weight: 600;
      white-space: nowrap;
      font-size: 0.85rem;
    }
    /* Separator for items */
    .di-sep { width: 1px; height: 16px; background: hsl(var(--border) / 0.5); }

    /* Speedometer Gauge Layout */
    .st-gauge-container {
      position: relative;
      width: 300px;
      height: 300px; /* Square for full circle gauge */
      margin: 0 auto 2rem auto;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .st-gauge-canvas {
      position: absolute;
      top: 0; left: 0;
      width: 100%; height: 100%;
      z-index: 1;
    }
    .st-start-btn-circle {
      position: relative;
      z-index: 2;
      width: 100px;
      height: 100px;
      border-radius: 50%;
      background: transparent;
      border: 2px solid hsl(var(--accent-cyan));
      color: hsl(var(--accent-cyan));
      font-size: 1.2rem;
      font-weight: 700;
      cursor: pointer;
      text-transform: uppercase;
      box-shadow: 0 0 20px hsl(var(--accent-cyan) / 0.3);
      transition: all 0.3s ease;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .st-start-btn-circle:hover {
      background: hsl(var(--accent-cyan));
      color: hsl(var(--bg));
      box-shadow: 0 0 40px hsl(var(--accent-cyan) / 0.6);
      transform: scale(1.1);
    }
    
    /* Stats Row (Below Gauge) */
    .st-stats-row {
      display: flex;
      justify-content: center;
      gap: 2rem;
      margin-bottom: 2rem;
      flex-wrap: wrap;
    }
    .st-stat-item {
      display: flex;
      flex-direction: column;
      align-items: center;
      min-width: 100px;
    }
    .st-stat-val { font-size: 1.5rem; font-weight: 700; }
    .st-stat-lbl { font-size: 0.75rem; color: hsl(var(--text-muted)); text-transform: uppercase; display:flex; align-items:center; gap:0.25rem; }

    /* Utility */
    .glass {
       /* Applying glass effect dynamically if needed, or ensuring section has it */
       background: var(--glass-bg);
       backdrop-filter: blur(12px);
       border: 1px solid var(--glass-border);
       border-radius: 24px;
       box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
       width: 95%;
       max-width: 800px;
       margin: 0 auto 2rem auto;
    }
    
    /* Small Start Button */
    .st-start-btn-small {
        width: 42px;
        height: 42px;
        border-radius: 50%;
        border: 1px solid hsl(var(--accent-cyan));
        background: transparent;
        color: hsl(var(--accent-cyan));
        font-size: 0.75rem;
        font-weight: 700;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        box-shadow: 0 0 10px hsl(var(--accent-cyan) / 0.2);
    }
    .st-start-btn-small:hover {
        background: hsl(var(--accent-cyan));
        color: hsl(var(--bg));
        box-shadow: 0 0 20px hsl(var(--accent-cyan) / 0.6);
        transform: scale(1.1) rotate(90deg);
    }
    .st-start-btn-small:active {
        transform: scale(0.9);
    }
  </style>
</head>
<body>
  <!-- Header (Full Width) -->
  <header class="main-header">
    <div class="header-content">
        <div class="logo-section">
            <div class="logo-box">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
            </div>
            <div class="title-box">
                <h1><span style="color:hsl(var(--accent-cyan));">Network</span> Diagnostics</h1>
                <p>Real-time network monitoring & speed analysis</p>
            </div>
        </div>
        <div class="status-section">
            <span class="status-pulse"></span> Live Monitoring
        </div>
    </div>
  </header>

  <!-- ANIMATED OCEAN BACKGROUND -->
  <div class="ocean-scene">
    <div class="sky-gradient"></div>
    
    <!-- Sun/Moon Glow -->
    <div class="moon-glow"></div>

    <!-- Background Wave Layer -->
    <div class="wave-layer back"></div>

    <!-- Vessel Layer (Ships) -->
    <div class="vessel-container">
        <div class="vessel v1">
            <svg viewBox="0 0 100 30" fill="currentColor">
                <path d="M5,20 L20,20 L25,10 L75,10 L80,20 L95,20 L90,28 L10,28 Z" opacity="0.8"/>
                <rect x="30" y="5" width="5" height="15" />
                <rect x="40" y="2" width="5" height="18" />
                <circle cx="90" cy="20" r="1" fill="red" class="blink-light"/>
            </svg>
        </div>
    </div>

    <!-- Middle Wave Layer -->
    <div class="wave-layer middle"></div>

    <!-- Fish Layer -->
    <div class="fish-container">
        <div class="fish f1">
            <svg viewBox="0 0 30 15" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M25,7.5 Q20,0 10,2 Q0,7.5 10,13 Q20,15 25,7.5 M25,7.5 L28,2 L28,13 L25,7.5"/>
            </svg>
        </div>
        <div class="fish f2">
             <svg viewBox="0 0 30 15" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M25,7.5 Q20,0 10,2 Q0,7.5 10,13 Q20,15 25,7.5 M25,7.5 L28,2 L28,13 L25,7.5"/>
            </svg>
        </div>
        <div class="fish f3 jumping-fish">
             <svg viewBox="0 0 30 15" fill="currentColor">
                <path d="M25,7.5 Q20,0 10,2 Q0,7.5 10,13 Q20,15 25,7.5 M25,7.5 L28,2 L28,13 L25,7.5"/>
            </svg>
        </div>
    </div>

    <!-- Front Wave Layer -->
    <div class="wave-layer front"></div>
  </div>

  <style>
    /* Ocean Scene Styles */
    .ocean-scene {
        position: fixed;
        top: 0; left: 0;
        width: 100%; height: 100%;
        z-index: -1;
        overflow: hidden;
        background: radial-gradient(circle at 50% 20%, #1a2c4e, #0f172a);
    }
    
    .moon-glow {
        position: absolute;
        top: 10%; left: 50%;
        transform: translateX(-50%);
        width: 200px; height: 200px;
        background: radial-gradient(circle, rgba(6,182,212,0.2) 0%, rgba(0,0,0,0) 70%);
        border-radius: 50%;
    }

    .wave-layer {
        position: absolute;
        left: 0; right: 0;
        background-repeat: repeat-x;
        width: 200%; /* For looping */
    }

    /* Wave Images (using CSS gradients for shapes or inline SVG data for cleaner looping) */
    /* Using a simple SVG data URI for waves */
    :root {
        --wave-shape: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 1440 320' preserveAspectRatio='none'%3E%3Cpath fill='%23ffffff' fill-opacity='1' d='M0,192L48,197.3C96,203,192,213,288,229.3C384,245,480,267,576,250.7C672,235,768,181,864,181.3C960,181,1056,235,1152,234.7C1248,235,1344,181,1392,154.7L1440,128L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z'%3E%3C/path%3E%3C/svg%3E");
    }

    .wave-layer.back {
        bottom: 120px;
        height: 150px;
        background: var(--wave-shape);
        opacity: 0.1;
        animation: wave-bg 20s linear infinite;
        filter: invert(30%) sepia(80%) saturate(200%) hue-rotate(180deg); /* Tint blue */
    }
    
    .wave-layer.middle {
        bottom: 60px;
        height: 180px;
        background: var(--wave-shape);
        opacity: 0.2;
        animation: wave-bg 15s linear infinite reverse;
        filter: invert(20%) sepia(50%) saturate(300%) hue-rotate(190deg);
    }
    
    .wave-layer.front {
        bottom: -20px;
        height: 200px;
        background: var(--wave-shape);
        opacity: 0.4;
        animation: wave-bg 10s linear infinite;
        filter: invert(10%) sepia(60%) saturate(400%) hue-rotate(170deg); /* Cyan/Teen */
    }
    
    @keyframes wave-bg {
        0% { transform: translateX(0); }
        100% { transform: translateX(-50%); } 
    }

    /* Vessels */
    .vessel-container { position: absolute; bottom: 180px; width: 100%; height: 100px; }
    .vessel { position: absolute; width: 100px; height: 30px; color: #1e293b; bottom: 0; }
    /* Flip vessel SVG to face Left (Direction of travel) */
    .vessel svg { transform: scaleX(-1); }
    
    .v1 {
        left: 100vw; /* Start off-screen right */
        animation: sail 45s linear infinite;
        color: rgba(6, 182, 212, 0.4);
    }
    .blink-light { animation: blink 2s infinite; }
    
    @keyframes sail {
        0% { transform: translateX(0); }
        100% { transform: translateX(-120vw); } /* Move Left */
    }
    @keyframes blink { 0%, 100% {opacity:1;} 50% {opacity:0;} }

    /* Fish */
    .fish-container { position: absolute; bottom: 0; width: 100%; height: 300px; pointer-events:none; }
    .fish { position: absolute; width: 40px; height: 20px; color: hsl(var(--accent-cyan)); }
    
    /* Blue Fish (Swimmers) - Route Backward (Left to Right) */
    .f1 { bottom: 100px; left: -10vw; animation: swimRight 15s linear infinite; animation-delay: 2s; opacity: 0.6; }
    .f2 { bottom: 150px; left: -10vw; animation: swimRight 22s linear infinite; animation-delay: 8s; width: 30px; opacity: 0.4; }
    
    /* Flip Blue Fish to face Right */
    .f1 svg, .f2 svg { transform: scaleX(-1); }
    
    .jumping-fish {
        bottom: 20px;
        left: auto; right: 20%; 
        color: hsl(var(--accent-purple));
        animation: jump 10s ease-in-out infinite;
    }
    /* Jumping fish faces Left naturally (matches Jump direction), no flip needed */

    @keyframes swim {
        0% { transform: translateX(0); }
        100% { transform: translateX(-120vw); } /* Move Left */
    }

    @keyframes swimRight {
        0% { transform: translateX(0); }
        100% { transform: translateX(120vw); } /* Move Right */
    }
    
    @keyframes jump {
        0%, 80% { transform: translate(0, 0) rotate(0deg); opacity:0; }
        85% { opacity: 1; }
        90% { transform: translate(-100px, -200px) rotate(-45deg); } /* Jump Up-Left (Nose Up) */
        95% { transform: translate(-200px, 0) rotate(45deg); opacity: 1; } /* Dive Down-Left (Nose Down) */
        100% { transform: translate(-220px, 50px); opacity:0; }
    }
  </style>
    <!-- Old Header Removed -->

    <!-- NEW Device Info Bar -->
    <div class="di-bar">
        <div class="di-bar-item">
            <span class="di-bar-label">Status</span>
            <div class="di-value" style="color:hsl(var(--accent-green)); display:flex; align-items:center; gap:0.5rem;">
                <span style="width:8px; height:8px; background:currentColor; border-radius:50%; display:inline-block;"></span> Online
            </div>
        </div>
        <div class="di-sep"></div>
        <div class="di-bar-item">
            <span class="di-bar-label">Local IP</span>
            <div class="di-bar-value" id="connList">...</div>
        </div>
        <div class="di-sep"></div>
        <div class="di-bar-item">
            <span class="di-bar-label">Public IP</span>
            <div class="di-bar-value" id="publicIP">...</div>
        </div>
        <div class="di-sep"></div>
        <div class="di-bar-item">
            <span class="di-bar-label">ISP/Region</span>
            <div class="di-bar-value" id="country">...</div>
        </div>
        <div class="di-bar-item" style="margin-left:auto;">
             <button class="ts-ping-all-btn" onclick="location.reload()" title="Refresh Info"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg></button>
        </div>
    </div>
    
    <!-- Server-Observed Data (Preserved but restyled slightly or kept hidden if desired?) -->
    <!-- The user image didn't show this, but it's useful. I'll hide it for now to match the clean look, or append it to details. -->
    <!-- Leaving it out as per "do the same for all" implying match images. -->

    <!-- Target Systems -->
    <div class="ts-card">
        <div class="ts-header">
            <div class="ts-title">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                Target Systems
            </div>
            <button class="ts-ping-all-btn" id="refreshBtn">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><activity/></svg> Ping All
            </button>
        </div>

        <div class="ts-search-container">
            <div style="flex:1; position:relative;">
                <input type="text" class="ts-search-input" placeholder="Enter hostname or IP...">
            </div>
            <button class="ts-go-btn">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
            </button>
        </div>

        <div class="ts-visual-line">
            <div class="ts-node">
                <div class="ts-node-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
                </div>
                <div style="font-size:0.75rem; color:hsl(var(--text-muted));">Your Device</div>
            </div>
            <div class="ts-line-track">
                <div class="ts-line-dot" style="left:20%; color:hsl(var(--accent-cyan))"></div>
                <div class="ts-line-dot" style="left:50%; color:hsl(var(--accent-purple))"></div>
                <div class="ts-line-dot" style="left:80%; color:hsl(var(--accent-green))"></div>
            </div>
            <div class="ts-node">
                 <div class="ts-node-icon" style="color:hsl(var(--accent-green)); border-color:hsl(var(--accent-green)/0.5); background:hsl(var(--accent-green)/0.1)">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M22 12h-4"/><path d="M6 12H2"/><path d="M12 6V2"/><path d="M12 22v-4"/></svg>
                </div>
                <div style="font-size:0.75rem; color:hsl(var(--text-muted));">Target</div>
            </div>
        </div>
        
        <div style="font-size:0.7rem; text-transform:uppercase; letter-spacing:1px; color:hsl(var(--text-muted)); margin-bottom:1rem;">Connectivity Status</div>

        <div class="ts-list" id="targetsList">
            <!-- Items injected by JS -->
        </div>
        
        <div class="ts-legend">
            <div class="ts-legend-item"><div class="ts-dot" style="background:hsl(var(--accent-green))"></div> &lt;50ms</div>
            <div class="ts-legend-item"><div class="ts-dot" style="background:hsl(var(--accent-orange))"></div> &lt;150ms</div>
            <div class="ts-legend-item"><div class="ts-dot" style="background:hsl(var(--error))"></div> &gt;150ms</div>
        </div>
    </div>

    <!-- COMPACT SPEED TEST BAR -->
    <div class="di-bar" style="margin-top: -1rem; margin-bottom: 2rem;">
        
        <!-- Left Side Label -->
        <div class="di-bar-item" style="border-right: 1px solid hsl(var(--border) / 0.5); padding-right: 1.5rem; margin-right: 1rem;">
            <div style="font-size: 0.9rem; font-weight: 800; color: hsl(var(--accent-cyan)); letter-spacing: 1px;">INTERNET SPEED</div>
        </div>
        
        <!-- Download -->
        <div class="di-bar-item">
            <span class="di-bar-label">Download</span>
            <div class="di-bar-value" style="color:hsl(var(--accent-green)); min-width:60px;">
                <span id="dlSpeed">--</span> <span style="font-size:0.7em; opacity:0.7;">Mbps</span>
            </div>
        </div>

        <div class="di-sep"></div>

        <!-- Start Button (Center) -->
        <div class="di-bar-item">
            <button id="startSpeed" class="st-start-btn-small" title="Start Speed Test">
                GO
            </button>
        </div>
        
        <div class="di-sep"></div>

        <!-- Upload -->
        <div class="di-bar-item">
            <span class="di-bar-label">Upload</span>
            <div class="di-bar-value" style="color:hsl(var(--accent-purple)); min-width:60px;">
                 <span id="ulSpeed">--</span> <span style="font-size:0.7em; opacity:0.7;">Mbps</span>
            </div>
        </div>



        <!-- Hidden Elements for Logic Compatibility -->
        <div style="display:none;">
            <span id="latency">--</span>
            <canvas id="gaugeCanvas" width="300" height="300"></canvas>
            <div id="gaugeVal">0.0</div>
            <div id="jitter"></div>
            <div id="packetLoss"></div>
            <div id="minLatency"></div>
            <div id="maxLatency"></div>
            <div id="testProgressTxt"></div>
            <div id="testProgressBar"></div>
            <canvas id="downloadGraphInline"></canvas>
            <canvas id="uploadGraphInline"></canvas>
            <div id="uploadGraph"></div>
            <div id="downloadGraph"></div>
            <div id="qualityVideoStreaming"></div>
            <div id="qualityOnlineGaming"></div>
            <div id="qualityVideoChat"></div>
        </div>
    </div>


  </div>

  <script>
    // ========= CONFIGURATION =========
    // API HELPERS are defined globally above

    // ========= API HELPERS =========
    async function apiCall(action, params = {}) {
        // SECURITY: API key removed from client-side code
        // For production: Use session tokens or CSRF-protected endpoints
        // For now, server-side API validation is required
        const qs = new URLSearchParams({...params, api: action});
        const response = await fetch('?' + qs);
        if (!response.ok) throw new Error(`HTTP ${response.status}: ${await response.text()}`);
        return response.json();
    }

    // ========= APP LOGIC =========
    async function init() {
        TargetSystem.renderTargets();
        try {
            await Promise.all([
                EndUserInfo.loadSystemInfo(),
                EndUserInfo.loadPublicInfo(),
                TargetSystem.checkAllTargets()
            ]);
        } catch(e) {
            showError(e.message);
        }
    }

    function showError(message) {
        document.getElementById('errorContainer').innerHTML = `<div class="error-banner">⚠️ ${message}</div>`;
    }

    // --- Network Info ---
    <?php echo getEndUserInfoJavaScript(); ?>

    // --- Target System ---
    <?php echo getTargetSystemJavaScript(); ?>

    // --- Speed Test ---
    <?php echo getSpeedTestJavaScript(); ?>
    
    // Start
    init();

  </script>
</body>
</html>
