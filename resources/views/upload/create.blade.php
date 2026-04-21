<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Video - Subtitle Generator</title>
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

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .form-group {
            margin-bottom: 24px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }

        .file-input-wrapper {
            position: relative;
            display: inline-block;
            width: 100%;
        }

        input[type="file"],
        input[type="text"],
        select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
            font-family: inherit;
        }

        input[type="file"]:focus,
        input[type="text"]:focus,
        select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .file-info {
            margin-top: 8px;
            font-size: 13px;
            color: #666;
        }

        .language-options {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .radio-option {
            position: relative;
        }

        .radio-option input[type="radio"] {
            display: none;
        }

        .radio-label {
            display: block;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 600;
            color: #666;
        }

        .radio-option input[type="radio"]:checked + .radio-label {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }

        .radio-label:hover {
            border-color: #667eea;
        }

        button {
            width: 100%;
            padding: 14px 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        button:active {
            transform: translateY(0);
        }

        .footer {
            text-align: center;
            margin-top: 20px;
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

            .language-options {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📹 Subtitle Generator</h1>
        <p class="subtitle">Upload your video and generate subtitles</p>

        @if ($errors->any())
            <div class="alert alert-error">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form action="{{ route('upload.store') }}" method="POST" enctype="multipart/form-data">
            @csrf

            <!-- Video File Upload -->
            <div class="form-group">
                <label for="video">📤 Video File (MP4, AVI, MOV, MKV)</label>
                <div class="file-input-wrapper">
                    <input
                        type="file"
                        id="video"
                        name="video"
                        accept="video/mp4,video/avi,video/quicktime,video/x-matroska"
                        required
                        onchange="updateFileName(this)"
                    >
                </div>
                <div class="file-info">Maximum file size: 5 GB</div>
            </div>

            <!-- Video Filename (Optional) -->
            <div class="form-group">
                <label for="filename">📝 Video Name (Optional)</label>
                <input
                    type="text"
                    id="filename"
                    name="filename"
                    placeholder="My awesome video"
                >
                <div class="file-info">If not provided, original filename will be used</div>
            </div>

            <!-- Target Language Selection -->
            <div class="form-group">
                <label>🌐 Target Subtitle Language</label>
                <div class="language-options">
                    <div class="radio-option">
                        <input
                            type="radio"
                            id="lang_en"
                            name="target_language"
                            value="en"
                            checked
                        >
                        <label for="lang_en" class="radio-label">🇬🇧 English</label>
                    </div>
                    <div class="radio-option">
                        <input
                            type="radio"
                            id="lang_id"
                            name="target_language"
                            value="id"
                        >
                        <label for="lang_id" class="radio-label">🇮🇩 Indonesian</label>
                    </div>
                </div>
            </div>

            <!-- Submit Button -->
            <button type="submit">Upload & Process</button>
        </form>

        <div class="footer">
            <p>Processing typically takes a few minutes depending on video length</p>
        </div>
    </div>

    <script>
        function updateFileName(input) {
            if (!document.getElementById('filename').value && input.files && input.files[0]) {
                const fileName = input.files[0].name;
                document.getElementById('filename').value = fileName.replace(/\.[^.]+$/, '');
            }
        }
    </script>
</body>
</html>
