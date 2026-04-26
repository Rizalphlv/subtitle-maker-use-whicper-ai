<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Video - Subtitle Generator</title>
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
            margin-bottom: 28px;
        }

        .card-title {
            font-size: 20px;
            font-weight: 600;
            color: #f9fafb;
            margin-bottom: 4px;
        }

        .card-desc {
            font-size: 13px;
            color: #6b7280;
        }

        .alert-error {
            background: #1c0a0a;
            border: 1px solid #3f1010;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 24px;
            font-size: 13px;
            color: #f87171;
        }

        .alert-error div + div { margin-top: 4px; }

        .form-group { margin-bottom: 20px; }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: #d1d5db;
            margin-bottom: 8px;
        }

        .form-hint {
            font-size: 12px;
            color: #4b5563;
            margin-top: 6px;
        }

        input[type="file"],
        input[type="text"] {
            width: 100%;
            padding: 10px 14px;
            background: #0a0a0a;
            border: 1px solid #262626;
            border-radius: 8px;
            font-size: 13px;
            color: #e5e7eb;
            font-family: inherit;
            transition: border-color 0.15s, box-shadow 0.15s;
        }

        input[type="file"]:focus,
        input[type="text"]:focus {
            outline: none;
            border-color: #404040;
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.04);
        }

        input[type="file"] { cursor: pointer; }

        input::placeholder { color: #4b5563; }

        .divider {
            height: 1px;
            background: #1f1f1f;
            margin: 24px 0;
        }

        .btn-primary {
            width: 100%;
            padding: 11px 20px;
            background: #ffffff;
            color: #0a0a0a;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s, transform 0.1s;
            font-family: inherit;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-primary:hover { background: #e5e7eb; }
        .btn-primary:active { transform: scale(0.99); }
        .btn-primary:disabled {
            background: #262626;
            color: #6b7280;
            cursor: not-allowed;
            transform: none;
        }

        .spinner {
            display: none;
            width: 16px;
            height: 16px;
            border: 2px solid #6b7280;
            border-top-color: #d1d5db;
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
            flex-shrink: 0;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        .footer-note {
            text-align: center;
            margin-top: 20px;
            font-size: 12px;
            color: #374151;
        }

        @media (max-width: 560px) {
            .card { padding: 24px 20px; }
        }
    </style>
</head>
<body>
    <div class="brand">Subtitle Generator</div>

    <div class="card">
        <div class="card-header">
            <div class="card-title">Upload Video</div>
            <div class="card-desc">Supports MP4, AVI, MOV, MKV — up to 5 GB</div>
        </div>

        @if ($errors->any())
            <div class="alert-error">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form action="{{ route('upload.store') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <input type="hidden" name="target_language" value="en">

            <div class="form-group">
                <label class="form-label" for="video">Video File</label>
                <input
                    type="file"
                    id="video"
                    name="video"
                    accept="video/mp4,video/avi,video/quicktime,video/x-matroska"
                    required
                    onchange="updateFileName(this)"
                >
            </div>

            <div class="form-group">
                <label class="form-label" for="filename">Video Name <span style="color:#4b5563;font-weight:400">(optional)</span></label>
                <input
                    type="text"
                    id="filename"
                    name="filename"
                    placeholder="Enter a name for this video"
                >
                <div class="form-hint">Leave blank to use the original filename</div>
            </div>

            <div class="divider"></div>

            <button type="submit" id="submitBtn" class="btn-primary">
                <span class="spinner" id="spinner"></span>
                <span id="btnText">Upload &amp; Generate Subtitles</span>
            </button>
        </form>
    </div>

    <div class="footer-note">Subtitles are generated in English</div>

    <script>
        function updateFileName(input) {
            const nameField = document.getElementById('filename');
            if (!nameField.value && input.files && input.files[0]) {
                nameField.value = input.files[0].name.replace(/\.[^.]+$/, '');
            }
        }

        document.querySelector('form').addEventListener('submit', function () {
            const btn     = document.getElementById('submitBtn');
            const spinner = document.getElementById('spinner');
            const text    = document.getElementById('btnText');

            btn.disabled       = true;
            spinner.style.display = 'block';
            text.textContent   = 'Uploading...';
        });
    </script>
</body>
</html>
