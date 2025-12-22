<?php
// Speed Test Module for MDI Network Diagnostics
// Handles upload proxy and speed test related API endpoints

// ========== CONFIGURATION ==========
$speedTestConfig = [
    'cloudflare_upload_endpoint' => 'https://speed.cloudflare.com/__up',
    'cloudflare_download_endpoint' => 'https://speed.cloudflare.com/__down',
    'timeout' => 30,
    'max_upload_size' => 50 * 1024 * 1024, // 50MB max
];

// ========== SPEED TEST API HANDLER ==========
function handleSpeedTestAPI($action) {
    global $speedTestConfig;
    
    header('Content-Type: application/json');
    
    switch($action) {
        case 'upload_proxy':
            handleUploadProxy($speedTestConfig);
            break;
            
        case 'speed_test_config':
            // Return configuration for client-side speed test
            echo json_encode([
                'download_endpoint' => $speedTestConfig['cloudflare_download_endpoint'],
                'max_upload_size' => $speedTestConfig['max_upload_size']
            ]);
            break;
            
        default:
            http_response_code(404);
            echo json_encode(['error' => 'Unknown speed test action']);
    }
    exit;
}

// ========== UPLOAD PROXY FUNCTION ==========
function handleUploadProxy($config) {
    // Read the uploaded data
    $input = file_get_contents('php://input');
    $size = strlen($input);
    
    // Validate upload size
    if ($size > $config['max_upload_size']) {
        http_response_code(413);
        echo json_encode([
            'error' => 'Upload size exceeds maximum allowed',
            'max_size' => $config['max_upload_size']
        ]);
        return;
    }
    
    if ($size === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'No data received']);
        return;
    }
    
    // Proxy the upload to Cloudflare to avoid CORS issues
    $ch = curl_init($config['cloudflare_upload_endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $input,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/octet-stream',
            'Content-Length: ' . $size
        ],
        CURLOPT_TIMEOUT => $config['timeout'],
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    // Return response
    http_response_code($httpCode);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        echo json_encode([
            'success' => true,
            'bytes' => $size,
            'response' => $response
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => $curlError ?: 'Upload failed',
            'http_code' => $httpCode,
            'bytes' => $size
        ]);
    }
}

// ========== JITTER CALCULATION ==========
function calculateJitter($latencies) {
    if (count($latencies) < 2) return null;
    
    $mean = array_sum($latencies) / count($latencies);
    $variance = 0;
    foreach ($latencies as $latency) {
        $variance += pow($latency - $mean, 2);
    }
    $variance /= count($latencies);
    $stdDev = sqrt($variance);
    
    return round($stdDev, 2);
}

// ========== PACKET LOSS DETECTION ==========
function detectPacketLoss($latencies, $totalAttempts = 10) {
    $successCount = count($latencies);
    $lossCount = $totalAttempts - $successCount;
    $lossPercentage = ($lossCount / $totalAttempts) * 100;
    
    return round($lossPercentage, 2);
}

// ========== QUALITY ASSESSMENT ==========
function assessQuality($downloadMbps, $uploadMbps, $latencyMs, $jitterMs, $packetLoss) {
    $quality = [
        'video_streaming' => ['status' => 'Poor', 'color' => '#ef4444'],
        'online_gaming' => ['status' => 'Poor', 'color' => '#ef4444'],
        'video_chatting' => ['status' => 'Poor', 'color' => '#ef4444']
    ];
    
    // Video Streaming: Download ≥25 Mbps + Latency <100ms
    if ($downloadMbps >= 25 && $latencyMs < 100) {
        $quality['video_streaming'] = ['status' => 'Good', 'color' => '#22c55e'];
    } elseif ($downloadMbps >= 10 && $latencyMs < 150) {
        $quality['video_streaming'] = ['status' => 'Fair', 'color' => '#eab308'];
    }
    
    // Online Gaming: Latency <50ms + Jitter <20ms + Packet Loss <1%
    if ($latencyMs < 50 && $jitterMs < 20 && $packetLoss < 1) {
        $quality['online_gaming'] = ['status' => 'Good', 'color' => '#22c55e'];
    } elseif ($latencyMs < 100 && $jitterMs < 30 && $packetLoss < 2) {
        $quality['online_gaming'] = ['status' => 'Fair', 'color' => '#eab308'];
    }
    
    // Video Chatting: Upload ≥5 Mbps + Download ≥5 Mbps + Latency <150ms
    if ($uploadMbps >= 5 && $downloadMbps >= 5 && $latencyMs < 150) {
        $quality['video_chatting'] = ['status' => 'Good', 'color' => '#22c55e'];
    } elseif ($uploadMbps >= 2.5 && $downloadMbps >= 2.5 && $latencyMs < 200) {
        $quality['video_chatting'] = ['status' => 'Fair', 'color' => '#eab308'];
    }
    
    return $quality;
}

// ========== SPEED TEST JAVASCRIPT ==========
function getSpeedTestJavaScript() {
    return <<<'JAVASCRIPT'
// ========== SPEED TEST MODULE (PARALLEL) ==========
const SpeedTest = {
    // Configuration
    config: {
        downloadSize: 25000000, // 25MB per stream x 4 = ~100MB total
        uploadSize: 20 * 1024 * 1024, // 20MB per stream x 4 = ~80MB total
        uploadProxyEndpoint: '?api=upload_proxy', // Local PHP Proxy
        concurrentStreams: 4, // Number of parallel connections for max throughput
        minTestDuration: 3000 // 3s minimum warmup/test time
    },
    
    // State tracking
    state: {
        latencies: [],
        minLatency: Infinity,
        maxLatency: 0,
        currentJitter: 0,
        packetLoss: 0,
        downloadHistory: [],
        uploadHistory: [],
        // Stream tracking
        streamProgress: [], // Stores bytes loaded for each active stream
    },
    
    // Reset state
    resetState() {
        this.state = {
            latencies: [],
            minLatency: Infinity,
            maxLatency: 0,
            currentJitter: 0,
            packetLoss: 0,
            downloadHistory: [],
            uploadHistory: [],
            streamProgress: new Array(this.config.concurrentStreams).fill(0)
        };
    },
    
    // Measure latency with "Warm Up"
    async measureLatency(samples = 10) {
        // 1. Warm up connection (eliminates initial SSL/TCP handshake latency skew)
        try {
            await fetch('https://speed.cloudflare.com/__down?bytes=0', { 
                cache: 'no-store', 
                mode: 'cors' 
            });
        } catch(e) {}
        
        const latencies = [];
        
        for (let i = 0; i < samples; i++) {
            try {
                const start = performance.now();
                const response = await fetch('https://speed.cloudflare.com/__down?bytes=0', { 
                    cache: 'no-store',
                    mode: 'cors'
                });
                const end = performance.now();
                
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                
                const latency = end - start;
                latencies.push(latency);
                
                // Update state
                this.state.latencies.push(latency);
                if (latency < this.state.minLatency) this.state.minLatency = latency;
                if (latency > this.state.maxLatency) this.state.maxLatency = latency;
                
                // Update UI immediately (if element exists)
                const latEl = document.getElementById('latency');
                if (latEl) latEl.textContent = latency.toFixed(0) + ' ms';
                
                // Delay between samples
                await new Promise(resolve => setTimeout(resolve, 50));
            } catch(e) {
                console.error('Latency measurement error:', e);
            }
        }
        
        // Calculate jitter
        if (latencies.length > 1) {
            const mean = latencies.reduce((a, b) => a + b, 0) / latencies.length;
            const variance = latencies.reduce((a, b) => a + Math.pow(b - mean, 2), 0) / latencies.length;
            this.state.currentJitter = Math.sqrt(variance);
        }
        
        this.state.packetLoss = ((samples - latencies.length) / samples) * 100;
        
        return latencies.length > 0 ? Math.min(...latencies) : null;
    },
    
    // ========== PARALLEL DOWNLOAD TEST ==========
    async testDownload() {
        const dlEl = document.getElementById('dlSpeed');
        if (dlEl) dlEl.textContent = '0.0'; // Start at 0.0
        
        this.state.streamProgress = new Array(this.config.concurrentStreams).fill(0);
        const startTime = performance.now();
        const streams = [];
        
        // Spawn workers
        for(let i=0; i<this.config.concurrentStreams; i++) {
            streams.push(this.runDownloadStream(i));
        }
        
        // Monitoring loop (updates UI while streams run)
        return new Promise((resolve, reject) => {
            const interval = setInterval(() => {
                const now = performance.now();
                const totalLoaded = this.state.streamProgress.reduce((a, b) => a + b, 0);
                const elapsed = (now - startTime) / 1000;
                
                if (elapsed > 0) {
                    const currentMbps = ((totalLoaded * 8) / 1000000) / elapsed;
                    
                    if (dlEl) dlEl.textContent = currentMbps.toFixed(1);
                    
                    this.state.downloadHistory.push({ time: now - startTime, speed: currentMbps });
                    this.drawCanvasGraph('downloadGraphInline', this.state.downloadHistory, '#00ff80');

                    // Animate Gauge
                    this.drawGauge(currentMbps, 100);

                    // Progress Update (15-55%)
                    const totalExpected = this.config.downloadSize * this.config.concurrentStreams;
                    const percentComplete = Math.min((totalLoaded / totalExpected), 1.0);
                    this.updateProgress(15 + (percentComplete * 40));
                }
            }, 60); // 60fps-ish updates
            
            Promise.all(streams).then(() => {
                clearInterval(interval);
                const totalTime = (performance.now() - startTime) / 1000;
                const finalLoaded = this.state.streamProgress.reduce((a, b) => a + b, 0);
                const mbps = ((finalLoaded * 8) / 1000000) / totalTime;
                if (dlEl) dlEl.textContent = mbps.toFixed(1);
                resolve(mbps);
            }).catch(e => {
                clearInterval(interval);
                console.error('Download test failed', e);
                if (dlEl) dlEl.textContent = 'Err';
                reject(e);
            });
        });
    },
    
    async runDownloadStream(index) {
        try {
            const response = await fetch(`https://speed.cloudflare.com/__down?bytes=${this.config.downloadSize}`, { 
                cache: 'no-store',
                mode: 'cors'
            });
            if (!response.ok) throw new Error('Network response was not ok');
            
            const reader = response.body.getReader();
            let received = 0;
            
            while(true) {
                const {done, value} = await reader.read();
                if (done) break;
                received += value.length;
                this.state.streamProgress[index] = received;
            }
        } catch(e) {
            console.error(`Stream ${index} failed`, e);
            // We don't re-throw to allow other streams to continue, 
            // effectively failing gracefully with reduced speed?
            // Or should we fail the whole test? Let's treat valid streams as valid throughput.
        }
    },
    
    // ========== PARALLEL UPLOAD TEST ==========
    async testUpload() {
        const ulEl = document.getElementById('ulSpeed');
        if (ulEl) ulEl.textContent = '0.0';
        
        this.state.streamProgress = new Array(this.config.concurrentStreams).fill(0);
        this.state.isUploading = true;
        const startTime = performance.now();
        
        // Prepare payload: 1MB chunk to be safe with default PHP limits (usually 2MB)
        const chunkSize = 1024 * 1024; 
        const payload = new Uint8Array(chunkSize); 
        for (let i=0; i<1024; i++) payload[i] = i % 255;
        let filled = 1024;
        while (filled < chunkSize) {
            payload.copyWithin(filled, 0, Math.min(filled, chunkSize - filled));
            filled *= 2;
        }

        const streams = [];
        for(let i=0; i<this.config.concurrentStreams; i++) {
            streams.push(this.runUploadLoop(i, payload));
        }
        
        return new Promise((resolve, reject) => {
            const interval = setInterval(() => {
                const now = performance.now();
                const totalLoaded = this.state.streamProgress.reduce((a, b) => a + b, 0);
                const elapsed = (now - startTime) / 1000;
                
                if (elapsed > 0.2) {
                    const currentMbps = ((totalLoaded * 8) / 1000000) / elapsed;
                    if (ulEl) ulEl.textContent = currentMbps.toFixed(1);
                    this.state.uploadHistory.push({ time: now - startTime, speed: currentMbps });
                    this.drawCanvasGraph('uploadGraphInline', this.state.uploadHistory, '#b366ff');
                    
                    // Animate Gauge
                    this.drawGauge(currentMbps, 50); // Scale might be different for upload
                    
                    // Progress Update (55-95%)
                    // Estimate based on time since we don't know exact total bytes for upload
                    const timeProgress = Math.min(elapsed / (this.config.minTestDuration / 1000), 1.0);
                    this.updateProgress(55 + (timeProgress * 40));
                }

                // Stop after enough data/time (fallback if loop doesn't check often enough?)
                // Actually the loops check isUploading. We just need to stop them.
                if (elapsed > (this.config.minTestDuration / 1000) && totalLoaded > 5 * 1024 * 1024) {
                     this.state.isUploading = false;
                }
            }, 50);
            
            Promise.all(streams).then(() => {
                clearInterval(interval);
                const endTime = performance.now();
                const totalTime = (endTime - startTime) / 1000;
                const finalLoaded = this.state.streamProgress.reduce((a, b) => a + b, 0);
                
                const mbps = ((finalLoaded * 8) / 1000000) / totalTime;
                
                if (ulEl) ulEl.textContent = mbps.toFixed(1);
                resolve(mbps);
            }).catch(e => {
                clearInterval(interval);
                this.state.isUploading = false;
                console.error('Upload test failed', e);
                if (ulEl) ulEl.textContent = 'Err';
                reject(e);
            });
        });
    },
    
    async runUploadLoop(index, payload) {
        let streamTotal = 0;
        while (this.state.isUploading) {
            try {
                await this.performSingleUpload(payload);
                streamTotal += payload.byteLength;
                this.state.streamProgress[index] = streamTotal;
            } catch (e) {
                console.error(`Stream ${index} error`, e);
                break; // Stop stream on error
            }
        }
    },

    performSingleUpload(payload) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.addEventListener('load', () => {
                if (xhr.status >= 200 && xhr.status < 300) resolve();
                else reject(new Error(`HTTP ${xhr.status}`));
            });
            xhr.addEventListener('error', () => reject(new Error('Network error')));
            xhr.open('POST', this.config.uploadProxyEndpoint);
            xhr.setRequestHeader('Content-Type', 'application/octet-stream');
            xhr.send(payload);
        });
    },

    // ========== METRICS & UI ==========
    
    updateMetricsUI(downloadMbps, uploadMbps) {
        // Simple passthrough to update quality badges at the end
        // reused logic from previous version
        
        const latency = this.state.minLatency; // Use best latency
        const jitter = this.state.currentJitter;
        const packetLoss = this.state.packetLoss;
        
        const videoStreamingEl = document.getElementById('qualityVideoStreaming');
        const onlineGamingEl = document.getElementById('qualityOnlineGaming');
        const videoChatEl = document.getElementById('qualityVideoChat');
        
        // Update Latency / Jitter text metrics
        if (document.getElementById('jitter')) document.getElementById('jitter').textContent = jitter.toFixed(2) + ' ms';
        if (document.getElementById('packetLoss')) document.getElementById('packetLoss').textContent = packetLoss.toFixed(2) + '%';
        
        // Video Streaming
        if (videoStreamingEl) {
            if (downloadMbps >= 25 && latency < 100) {
                videoStreamingEl.innerHTML = '✓ Good'; videoStreamingEl.style.color = '#22c55e';
            } else if (downloadMbps >= 10 && latency < 150) {
                videoStreamingEl.innerHTML = '~ Fair'; videoStreamingEl.style.color = '#eab308';
            } else {
                videoStreamingEl.innerHTML = '✗ Poor'; videoStreamingEl.style.color = '#ef4444';
            }
        }
        
        // Online Gaming
        if (onlineGamingEl) {
            if (latency < 50 && jitter < 20 && packetLoss < 1) {
                onlineGamingEl.innerHTML = '✓ Good'; onlineGamingEl.style.color = '#22c55e';
            } else if (latency < 100 && jitter < 30 && packetLoss < 2) {
                onlineGamingEl.innerHTML = '~ Fair'; onlineGamingEl.style.color = '#eab308';
            } else {
                onlineGamingEl.innerHTML = '✗ Poor'; onlineGamingEl.style.color = '#ef4444';
            }
        }
        
        // Video Chatting
        if (videoChatEl) {
            if (uploadMbps >= 5 && downloadMbps >= 5 && latency < 150) {
                videoChatEl.innerHTML = '✓ Good'; videoChatEl.style.color = '#22c55e';
            } else if (uploadMbps >= 2.5 && downloadMbps >= 2.5 && latency < 200) {
                videoChatEl.innerHTML = '~ Fair'; videoChatEl.style.color = '#eab308';
            } else {
                videoChatEl.innerHTML = '✗ Poor'; videoChatEl.style.color = '#ef4444';
            }
        }
        
        this.drawGraphs();
    },
    
    drawInlineGraphs() {
        this.drawCanvasGraph('downloadGraphInline', this.state.downloadHistory, '#00ff80');
        this.drawCanvasGraph('uploadGraphInline', this.state.uploadHistory, '#b366ff');
    },
    
    drawCanvasGraph(canvasId, data, color) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;
        const rect = canvas.getBoundingClientRect();
        // Only set dims if they change to avoid clearing? No, we clear anyway.
        // We must set width/height to match display for sharp rendering
        const dpr = window.devicePixelRatio || 1;
        if (canvas.width !== rect.width * dpr) {
            canvas.width = rect.width * dpr;
            canvas.height = rect.height * dpr;
        }
        
        const ctx = canvas.getContext('2d');
        // Reset transform to avoid stacking scales
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        
        const width = rect.width;
        const height = rect.height;
        ctx.clearRect(0, 0, width, height);
        
        if (data.length < 2) return;
        
        const maxTime = Math.max(...data.map(d => d.time), 1);
        const maxSpeed = Math.max(...data.map(d => d.speed), 1) * 1.1;
        
        ctx.beginPath();
        const gradient = ctx.createLinearGradient(0, 0, 0, height);
        gradient.addColorStop(0, color + '40'); // Hex + alpha
        gradient.addColorStop(1, color + '00');
        ctx.fillStyle = gradient;
        
        ctx.moveTo(0, height);
        data.forEach(p => {
            const x = (p.time / maxTime) * width;
            const y = height - (p.speed / maxSpeed) * height;
            ctx.lineTo(x, y);
        });
        ctx.lineTo(width, height);
        ctx.fill();
        
        ctx.beginPath();
        ctx.strokeStyle = color;
        ctx.lineWidth = 2;
        data.forEach((p, i) => {
            const x = (p.time / maxTime) * width;
            const y = height - (p.speed / maxSpeed) * height;
            if (i===0) ctx.moveTo(x, y);
            else ctx.lineTo(x, y);
        });
        ctx.stroke();
    },
    
    drawGraphs() {
        // Full SVG graphs at the bottom
        this.drawSVGGraph('downloadGraph', this.state.downloadHistory, '#00ff80');
        this.drawSVGGraph('uploadGraph', this.state.uploadHistory, '#b366ff');
    },
    
    drawSVGGraph(elementId, data, color) {
        const div = document.getElementById(elementId);
        if (!div || data.length < 1) return;
        
        // Similar to previous implementation
        const width = div.clientWidth || 300;
        const height = div.clientHeight || 100;
        const maxTime = Math.max(...data.map(d => d.time), 1);
        const maxSpeed = Math.max(...data.map(d => d.speed), 1) * 1.1;
        
        let pathD = `M 0 ${height}`;
        data.forEach(p => {
             const x = (p.time / maxTime) * width;
             const y = height - (p.speed / maxSpeed) * height;
             pathD += ` L ${x} ${y}`;
        });
        
        const fillPath = pathD + ` L ${width} ${height} Z`;
        
        div.innerHTML = `
        <svg width="100%" height="100%" viewBox="0 0 ${width} ${height}" preserveAspectRatio="none">
            <defs>
                <linearGradient id="g-${elementId}" x1="0" x2="0" y1="0" y2="1">
                    <stop offset="0%" stop-color="${color}" stop-opacity="0.2"/>
                    <stop offset="100%" stop-color="${color}" stop-opacity="0.0"/>
                </linearGradient>
            </defs>
            <path d="${fillPath}" fill="url(#g-${elementId})" stroke="none"/>
            <path d="${pathD}" fill="none" stroke="${color}" stroke-width="2"/>
        </svg>`;
    },
    
    // Update Progress Bar
    updateProgress(percent) {
        const bar = document.getElementById('testProgressBar');
        const txt = document.getElementById('testProgressTxt');
        if (bar && txt) {
            bar.style.width = percent + '%';
            txt.textContent = Math.round(percent) + '%';
        }
    },

    // Draw Gauge (Speedometer)
    drawGauge(value, max) {
        const canvas = document.getElementById('gaugeCanvas');
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        const cx = canvas.width / 2;
        const cy = canvas.height / 2;
        const radius = Math.min(cx, cy) - 20;

        ctx.clearRect(0, 0, canvas.width, canvas.height);

        // Background Arc
        ctx.beginPath();
        ctx.arc(cx, cy, radius, Math.PI * 0.75, Math.PI * 2.25);
        ctx.lineWidth = 15;
        ctx.strokeStyle = 'rgba(255, 255, 255, 0.1)';
        ctx.stroke();

        // Value Arc
        const startAngle = Math.PI * 0.75;
        const endAngle = Math.PI * 2.25;
        const totalAngle = endAngle - startAngle;
        const percent = Math.min(value / max, 1);
        const currentAngle = startAngle + (totalAngle * percent);

        ctx.beginPath();
        ctx.arc(cx, cy, radius, startAngle, currentAngle);
        ctx.lineWidth = 15;
        // Gradient stroke
        const grd = ctx.createLinearGradient(0, 0, canvas.width, 0);
        grd.addColorStop(0, "hsl(var(--accent-cyan))");
        grd.addColorStop(1, "hsl(var(--accent-purple))");
        ctx.strokeStyle = grd;
        ctx.stroke();
        
        // Needle logic (optional, circle dot at tip)
        const tipX = cx + Math.cos(currentAngle) * radius;
        const tipY = cy + Math.sin(currentAngle) * radius;
        
        ctx.beginPath();
        ctx.arc(tipX, tipY, 8, 0, Math.PI*2);
        ctx.fillStyle = '#fff';
        ctx.shadowBlur = 10;
        ctx.shadowColor = '#fff';
        ctx.fill();
        ctx.shadowBlur = 0;
        
        // Update center text if active
        const gaugeVal = document.getElementById('gaugeVal');
        if (gaugeVal && this.state.isRunning) {
            gaugeVal.textContent = value.toFixed(1);
            // Color based on phase
            gaugeVal.style.color = this.state.isUploading ? 'hsl(var(--accent-purple))' : 'hsl(var(--accent-green))';
        }
    },

    async runFullTest() {
        const btn = document.getElementById('startSpeed');
        const gaugeVal = document.getElementById('gaugeVal');
        
        // UI State: Running
        if(btn) {
            btn.disabled = true;
            // Set to Download Color (Green)
            btn.style.borderColor = 'hsl(var(--accent-green))';
            btn.style.color = 'hsl(var(--accent-green))';
            btn.style.boxShadow = '0 0 15px hsl(var(--accent-green)/0.4)';
            // Make it spin or pulse? Just color change for now per request
            btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>';
            btn.classList.add('spin-fast'); // We need to add this class in CSS or just animate via JS? 
            // Actually, keep it simple first: Text or Icon?
            // User just said "turn to green", keeping "GO" text might be confusing while running.
            // Let's use a simple spinner or just keep "GO"? 
            // "turn to green" implies the circle itself. 
            // Let's keep it clean: Color change is the main request.
            btn.textContent = '...'; 
        }
        
        if(gaugeVal) { gaugeVal.style.opacity = '1'; gaugeVal.textContent = '0.0'; }
        
        this.state.isRunning = true;
        this.resetState();
        
        try {
            // Latency
            if(gaugeVal) gaugeVal.textContent = '...';
            await this.measureLatency();
            
            // Download (Green) -- already set above
            await this.testDownload();
            
            // Upload (Purple)
            if(btn) {
                btn.style.borderColor = 'hsl(var(--accent-purple))';
                btn.style.color = 'hsl(var(--accent-purple))';
                btn.style.boxShadow = '0 0 15px hsl(var(--accent-purple)/0.4)';
            }
            await this.testUpload();
            
        } catch(e) {
            console.error(e);
            // alert('Speed test failed. Check console.');
        } finally {
            this.state.isRunning = false;
            // UI State: Finished
            if(btn) { 
                btn.disabled = false;
                btn.innerHTML = 'GO'; // Reset text
                // Reset styles to default (remove inline overrides)
                btn.style.borderColor = '';
                btn.style.color = '';
                btn.style.boxShadow = '';
                btn.textContent = 'AGAIN'; // Or 'GO' again? user likely wants to re-test.
            }
            if(gaugeVal) gaugeVal.style.opacity = '0'; 
            
            this.drawGauge(0, 100); 
        }
    }
};

document.getElementById('startSpeed').onclick = () => SpeedTest.runFullTest();
// Initial Draw
setTimeout(() => SpeedTest.drawGauge(0, 100), 100); 
JAVASCRIPT;
}
?>
