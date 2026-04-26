<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Processing Status - Subtitle Generator</title>
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: #0a0a0a;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 24px;
            color: #e5e7eb;
        }

        .brand {
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #6b7280;
            margin-bottom: 32px;
            text-align: center;
        }

        .card {
            width: 100%;
            max-width: 520px;
            background: #111111;
            border: 1px solid #1f1f1f;
            border-radius: 12px;
            padding: 36px;
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 28px;
        }

        .card-title {
            font-size: 20px;
            font-weight: 600;
            color: #f9fafb;
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 500;
            color: #6b7280;
            text-decoration: none;
            padding: 6px 12px;
            border: 1px solid #262626;
            border-radius: 6px;
            transition: color 0.15s, border-color 0.15s;
        }

        .btn-back:hover { color: #d1d5db; border-color: #404040; }

        .btn-back svg { width: 14px; height: 14px; flex-shrink: 0; }

        .meta-table {
            background: #0a0a0a;
            border: 1px solid #1f1f1f;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 28px;
        }

        .meta-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 16px;
            font-size: 13px;
        }

        .meta-row + .meta-row { border-top: 1px solid #1a1a1a; }

        .meta-label { color: #6b7280; font-weight: 500; }
        .meta-value { color: #d1d5db; text-align: right; max-width: 60%; word-break: break-word; }

        /* Status block */
        .status-block {
            text-align: center;
            padding: 28px 0;
        }

        .status-indicator {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            margin-bottom: 16px;
        }

        .status-indicator.processing {
            background: #1a1a1a;
            border: 2px solid #262626;
            animation: spin-ring 1.5s linear infinite;
        }

        .status-indicator.done {
            background: #0d2318;
            border: 2px solid #14532d;
        }

        .status-indicator.failed {
            background: #1c0a0a;
            border: 2px solid #3f1010;
        }

        @keyframes spin-ring {
            0%   { border-top-color: #ffffff; }
            25%  { border-right-color: #ffffff; }
            50%  { border-bottom-color: #ffffff; }
            75%  { border-left-color: #ffffff; }
            100% { border-top-color: #ffffff; }
        }

        .status-indicator svg { width: 24px; height: 24px; }

        .status-title {
            font-size: 16px;
            font-weight: 600;
            color: #f9fafb;
            margin-bottom: 6px;
        }

        .status-desc { font-size: 13px; color: #6b7280; }

        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            margin-top: 12px;
        }

        .badge-processing { background: #1a1a2e; color: #818cf8; border: 1px solid #312e81; }
        .badge-done       { background: #0d2318; color: #4ade80; border: 1px solid #14532d; }
        .badge-failed     { background: #1c0a0a; color: #f87171; border: 1px solid #3f1010; }

        .divider { height: 1px; background: #1f1f1f; margin: 24px 0; }

        /* Action buttons */
        .action-group { display: flex; flex-direction: column; gap: 10px; }

        .download-section { display: none; }
        .download-section.show { display: block; }

        .error-section { display: none; }
        .error-section.show { display: block; }

        .btn-primary {
            display: block;
            width: 100%;
            padding: 11px 20px;
            background: #ffffff;
            color: #0a0a0a;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            text-align: center;
            transition: background 0.15s;
            font-family: inherit;
        }

        .btn-primary:hover { background: #e5e7eb; }

        .btn-secondary {
            display: block;
            width: 100%;
            padding: 11px 20px;
            background: transparent;
            color: #d1d5db;
            border: 1px solid #262626;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            text-align: center;
            transition: border-color 0.15s, color 0.15s;
            font-family: inherit;
        }

        .btn-secondary:hover { border-color: #404040; color: #f9fafb; }

        .btn-danger {
            display: block;
            width: 100%;
            padding: 11px 20px;
            background: transparent;
            color: #f87171;
            border: 1px solid #3f1010;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            text-align: center;
            transition: border-color 0.15s, background 0.15s;
            font-family: inherit;
        }

        .btn-danger:hover { background: #1c0a0a; border-color: #7f1d1d; }

        .poll-note {
            text-align: center;
            margin-top: 20px;
            font-size: 12px;
            color: #374151;
        }

        @media (max-width: 560px) {
            .card { padding: 24px 20px; }
            .card-header { flex-direction: column; align-items: flex-start; gap: 12px; }
        }
    </style>
</head>
<body>
    <div class="brand">Subtitle Generator</div>

    <div class="card">
        <div class="card-header">
            <div class="card-title">Processing Status</div>
            <a href="{{ route('upload.create') }}" class="btn-back">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
                </svg>
                Back
            </a>
        </div>

        <div class="meta-table">
            <div class="meta-row">
                <span class="meta-label">Filename</span>
                <span class="meta-value">{{ $video->filename }}</span>
            </div>
            <div class="meta-row">
                <span class="meta-label">Output Language</span>
                <span class="meta-value">English</span>
            </div>
            <div class="meta-row">
                <span class="meta-label">Uploaded</span>
                <span class="meta-value">{{ $video->created_at->format('M d, Y H:i') }}</span>
            </div>
        </div>

        <!-- Status block -->
        <div class="status-block" id="statusContainer"></div>

        <!-- Download section -->
        <div class="download-section" id="downloadSection">
            <div class="divider"></div>
            <div class="action-group">
                <a href="{{ route('upload.download', ['uploadId' => $uploadId, 'lang' => 'en']) }}" class="btn-primary">
                    Download Subtitle (.srt)
                </a>
                <a href="{{ route('upload.create') }}" class="btn-secondary">
                    Upload Another Video
                </a>
            </div>
        </div>

        <!-- Error section -->
        <div class="error-section" id="errorSection">
            <div class="divider"></div>
            <div class="action-group">
                <a href="{{ route('upload.create') }}" class="btn-danger">
                    Try Again
                </a>
            </div>
        </div>
    </div>

    <div class="poll-note" id="pollNote">Checking status every 3 seconds...</div>

    <script>
        const uploadId = '{{ $uploadId }}';
        let isPolling = true;

        const statusConfig = {
            uploaded: {
                iconHtml: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#9ca3af"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2m6-2a10 10 0 11-20 0 10 10 0 0120 0z"/></svg>`,
                indicatorClass: 'processing',
                title: 'Queued',
                desc: 'Waiting to start processing...',
                badge: 'processing',
            },
            processing: {
                iconHtml: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#818cf8"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12a7.5 7.5 0 0115 0m-15 0a7.5 7.5 0 1115 0"/></svg>`,
                indicatorClass: 'processing',
                title: 'Processing',
                desc: 'Generating subtitles. This may take a few minutes...',
                badge: 'processing',
            },
            done: {
                iconHtml: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="#4ade80"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>`,
                indicatorClass: 'done',
                title: 'Complete',
                desc: 'Your subtitle file is ready for download.',
                badge: 'done',
            },
            failed: {
                iconHtml: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="#f87171"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>`,
                indicatorClass: 'failed',
                title: 'Failed',
                desc: 'An error occurred during processing.',
                badge: 'failed',
            },
        };

        function renderStatus(status) {
            const cfg = statusConfig[status] || statusConfig.processing;
            document.getElementById('statusContainer').innerHTML = `
                <div class="status-indicator ${cfg.indicatorClass}">${cfg.iconHtml}</div>
                <div class="status-title">${cfg.title}</div>
                <div class="status-desc">${cfg.desc}</div>
                <span class="status-badge badge-${cfg.badge}">${status.toUpperCase()}</span>
            `;
        }

        function checkStatus() {
            if (!isPolling) return;
            fetch(`/upload/${uploadId}/status`)
                .then(r => r.json())
                .then(data => {
                    renderStatus(data.status);
                    if (data.status === 'done') {
                        document.getElementById('downloadSection').classList.add('show');
                        document.getElementById('errorSection').classList.remove('show');
                        document.getElementById('pollNote').textContent = '';
                        isPolling = false;
                    } else if (data.status === 'failed') {
                        document.getElementById('errorSection').classList.add('show');
                        document.getElementById('downloadSection').classList.remove('show');
                        document.getElementById('pollNote').textContent = '';
                        isPolling = false;
                    }
                })
                .catch(err => console.error('Status check failed:', err));
        }

        renderStatus('processing');
        checkStatus();
        const pollInterval = setInterval(checkStatus, 3000);
        window.addEventListener('beforeunload', () => { clearInterval(pollInterval); isPolling = false; });
    </script>
</body>
</html>
