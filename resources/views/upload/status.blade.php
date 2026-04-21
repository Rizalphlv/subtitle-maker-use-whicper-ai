<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Processing Status - Subtitle Generator</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            width: 100%;
            max-width: 600px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 40px;
        }

        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 28px;
        }

        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }

        .video-info {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 24px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            font-size: 14px;
        }

        .info-label {
            color: #666;
            font-weight: 600;
        }

        .info-value {
            color: #333;
        }

        .status-section {
            text-align: center;
            margin: 30px 0;
        }

        .status-icon {
            font-size: 64px;
            margin-bottom: 16px;
            display: inline-block;
            animation: pulse 2s infinite;
        }

        .status-icon.done {
            animation: none;
        }

        .status-icon.failed {
            animation: none;
        }

        @keyframes pulse {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: 0.6;
            }
        }

        .status-text {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }

        .status-subtext {
            font-size: 14px;
            color: #666;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-top: 12px;
            text-transform: uppercase;
        }

        .badge-processing {
            background: #d1ecf1;
            color: #0c5460;
        }

        .badge-done {
            background: #d4edda;
            color: #155724;
        }

        .badge-failed {
            background: #f8d7da;
            color: #721c24;
        }

        .download-section {
            display: none;
            text-align: center;
        }

        .download-section.show {
            display: block;
        }

        .download-button {
            display: inline-block;
            padding: 12px 24px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            margin: 6px;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .download-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        .error-section {
            display: none;
            text-align: center;
        }

        .error-section.show {
            display: block;
        }

        .retry-button {
            display: inline-block;
            padding: 12px 24px;
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            margin-top: 16px;
            transition: background 0.3s;
        }

        .retry-button:hover {
            background: #5a6268;
        }

        .language-info {
            background: #f0f4ff;
            border-left: 4px solid #667eea;
            padding: 12px 16px;
            border-radius: 4px;
            font-size: 13px;
            color: #333;
            margin-top: 16px;
        }

        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
            font-size: 12px;
            color: #999;
        }

        @media (max-width: 600px) {
            .container {
                padding: 30px 20px;
            }

            h1 {
                font-size: 24px;
            }

            .status-icon {
                font-size: 48px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📺 Processing Status</h1>
        <p class="subtitle">Your video is being processed</p>

        <!-- Video Information -->
        <div class="video-info">
            <div class="info-row">
                <span class="info-label">📄 Filename:</span>
                <span class="info-value">{{ $video->filename }}</span>
            </div>
            <div class="info-row">
                <span class="info-label">🎯 Target Language:</span>
                <span class="info-value">
                    @if ($video->target_language === 'id')
                        🇮🇩 Indonesian
                    @else
                        🇬🇧 English
                    @endif
                </span>
            </div>
            <div class="info-row">
                <span class="info-label">🕐 Uploaded:</span>
                <span class="info-value">{{ $video->created_at->format('M d, Y H:i') }}</span>
            </div>
        </div>

        <!-- Status Display -->
        <div class="status-section" id="statusContainer">
            <!-- Will be updated by JavaScript -->
        </div>

        <!-- Download Section (shown when done) -->
        <div class="download-section" id="downloadSection">
            <p style="margin-bottom: 16px; color: #333; font-size: 14px;">
                ✅ Your subtitle is ready! Choose a language to download:
            </p>
            <a href="{{ route('upload.download', ['uploadId' => $uploadId, 'lang' => 'en']) }}" class="download-button">
                🇬🇧 Download English (.srt)
            </a>
            @if ($video->target_language !== 'en')
                <a href="{{ route('upload.download', ['uploadId' => $uploadId, 'lang' => $video->target_language]) }}" class="download-button">
                    @if ($video->target_language === 'id')
                        🇮🇩 Download Indonesian (.srt)
                    @else
                        Download {{ $video->target_language }} (.srt)
                    @endif
                </a>
            @endif

            <div class="language-info">
                English version: Original transcription from video
                @if ($video->target_language !== 'en')
                    <br>
                    @if ($video->target_language === 'id')
                        Indonesian version: AI-translated from English
                    @else
                        {{ $video->target_language }} version: AI-translated from English
                    @endif
                @endif
            </div>
        </div>

        <!-- Error Section (shown when failed) -->
        <div class="error-section" id="errorSection">
            <p style="color: #721c24; margin-bottom: 16px;">
                ❌ Processing failed. Please try uploading again.
            </p>
            <a href="{{ route('upload.create') }}" class="retry-button">
                Upload Another Video
            </a>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>Auto-refreshing status... (updates every 3 seconds)</p>
        </div>
    </div>

    <script>
        const uploadId = '{{ $uploadId }}';
        let isPolling = true;

        function renderStatus(status) {
            const container = document.getElementById('statusContainer');

            const statusConfig = {
                'uploaded': {
                    icon: '⏳',
                    text: 'Queued',
                    subtext: 'Waiting to start processing...',
                    badge: 'processing'
                },
                'processing': {
                    icon: '⚙️',
                    text: 'Processing',
                    subtext: 'Your video is being processed. This may take a few minutes...',
                    badge: 'processing'
                },
                'done': {
                    icon: '✨',
                    text: 'Complete',
                    subtext: 'Your subtitle is ready for download!',
                    badge: 'done'
                },
                'failed': {
                    icon: '❌',
                    text: 'Failed',
                    subtext: 'An error occurred during processing.',
                    badge: 'failed'
                }
            };

            const config = statusConfig[status] || statusConfig['processing'];

            container.innerHTML = `
                <div class="status-icon ${status === 'done' ? 'done' : status === 'failed' ? 'failed' : ''}">${config.icon}</div>
                <div class="status-text">${config.text}</div>
                <div class="status-subtext">${config.subtext}</div>
                <span class="status-badge badge-${config.badge}">${status.toUpperCase()}</span>
            `;
        }

        function checkStatus() {
            if (!isPolling) return;

            fetch(`/upload/${uploadId}/status`)
                .then(response => response.json())
                .then(data => {
                    // Update status display
                    renderStatus(data.status);

                    // Show download section if done
                    if (data.status === 'done') {
                        document.getElementById('downloadSection').classList.add('show');
                        document.getElementById('errorSection').classList.remove('show');
                        isPolling = false;
                    }
                    // Show error section if failed
                    else if (data.status === 'failed') {
                        document.getElementById('errorSection').classList.add('show');
                        document.getElementById('downloadSection').classList.remove('show');
                        isPolling = false;
                    }
                    // Keep polling
                    else {
                        document.getElementById('downloadSection').classList.remove('show');
                        document.getElementById('errorSection').classList.remove('show');
                    }
                })
                .catch(error => {
                    console.error('Error checking status:', error);
                });
        }

        // Initial status check
        checkStatus();

        // Poll every 3 seconds
        const pollInterval = setInterval(() => {
            checkStatus();
        }, 3000);

        // Stop polling when page is unloaded
        window.addEventListener('beforeunload', () => {
            clearInterval(pollInterval);
            isPolling = false;
        });
    </script>
</body>
</html>
