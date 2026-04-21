# 🎬 Subtitle System - Video Transcription & Translation

A Laravel-based system for automatically generating, translating, and downloading subtitles from video files. Built with OpenAI Whisper API for speech-to-text and GPT for translation.

## ✨ Features

- **Video Upload** - Support for MP4, AVI, MOV, MKV formats (up to 5GB)
- **Automatic Transcription** - Convert speech to text using OpenAI Whisper API
- **Multi-Language Support** - Translate subtitles between English (EN) and Indonesian (ID)
- **Parallel Processing** - Process audio chunks concurrently for faster transcription
- **SRT Download** - Generate and download subtitles in SRT format
- **Real-time Status Monitoring** - AJAX-based live status updates
- **Resource Optimization** - Skip API calls for empty/placeholder text segments
- **Automatic Cleanup** - Remove temporary files after processing

## 🏗️ Architecture

### Job Pipeline

The system uses a queue-based job pipeline for asynchronous processing:

```
Video Upload
    ↓
ProcessVideoJob (extract audio → chunk audio → dispatch jobs)
    ↓
TranscribeChunkJob × N (parallel: Whisper API → store segments)
    ↓
CheckTranscriptionCompleteJob (poll until all done → dispatch merge)
    ↓
MergeSubtitleJob (merge → SRT gen → translate → SRT gen → cleanup)
    ↓
CleanupVideoProcessingJob (verify → delete temps)
    ↓
✅ Done (subtitles ready for download)
```

### Data Flow

- **chunk_index = -1** marker for merged/translated records
- **Language handling**: Always transcribe in English first, then translate to target
- **Empty segments**: Skip API calls for silent audio/placeholder text

## 🛠️ Tech Stack

- **Backend**: Laravel 12.0 with PHP 8.2
- **Queue**: Redis-based (named queues: `default`, `transcribe`)
- **APIs**: 
  - OpenAI Whisper (whisper-1) for speech-to-text
  - OpenAI GPT (gpt-4o-mini) for translation
- **Storage**: MinIO (S3-compatible)
- **Audio Processing**: FFmpeg
- **Frontend**: Blade templates with Tailwind CSS

## 📋 Requirements

- PHP 8.2+
- Docker & Docker Compose
- OpenAI API key (Whisper + GPT access)
- MinIO S3-compatible storage
- Redis

## 🚀 Quick Start

### 1. Environment Setup

```bash
cp .env.example .env
```

Configure `.env`:
```env
OPENAI_API_KEY=sk_your_key_here
OPENAI_ENDPOINT=https://api.openai.com/v1

MINIO_KEY=minioadmin
MINIO_SECRET=minioadmin
MINIO_ENDPOINT=http://minio:9000
MINIO_USE_PATH_STYLE_ENDPOINT=true

REDIS_HOST=redis
REDIS_PORT=6379

DB_DATABASE=subtitle_system
DB_USERNAME=root
DB_PASSWORD=password
```

### 2. Docker Startup

```bash
# Start all services
docker compose up -d

# Run migrations
docker compose exec app php artisan migrate

# Cache configuration
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
```

### 3. Access Application

- **Upload UI**: http://localhost/upload
- **Queue Status**: `docker compose logs -f queue`

## 📱 User Interface

### Upload Form (`GET /upload`)
- File input (MP4/AVI/MOV/MKV, max 5GB)
- Optional custom filename
- Language selector (English / Indonesian)
- Modern gradient styling

### Status Monitoring (`GET /upload/{uploadId}`)
- Real-time status: queued → processing → done/failed
- Progress tracking
- Download buttons for EN & ID subtitles
- AJAX polling (3s intervals)

### Download (`GET /upload/{uploadId}/download?lang=en|id`)
- Stream SRT files from MinIO
- Browser download

## 📡 API Endpoints

| Method | Route | Purpose |
|--------|-------|---------|
| GET | `/upload` | Display upload form |
| POST | `/upload` | Handle file upload, dispatch ProcessVideoJob |
| GET | `/upload/{uploadId}` | Status monitoring page |
| GET | `/upload/{uploadId}/status` | AJAX status endpoint (JSON) |
| GET | `/upload/{uploadId}/download` | Download SRT file |

### Status Response
```json
{
  "video_id": 61,
  "upload_id": "784155ba-2e14-430f-8ea4-ee9de0802e0e",
  "filename": "Video Title",
  "status": "done",
  "target_language": "id",
  "en_subtitle_path": "videos/61/subtitles/en.srt",
  "id_subtitle_path": "videos/61/subtitles/id_translated.srt",
  "progress": { "transcribed": 3, "total": 3 }
}
```

## 📁 Project Structure

```
app/
├── Http/Controllers/UploadController.php
├── Jobs/
│   ├── ProcessVideoJob.php
│   ├── TranscribeChunkJob.php
│   ├── CheckTranscriptionCompleteJob.php
│   ├── MergeSubtitleJob.php
│   └── CleanupVideoProcessingJob.php
├── Models/
│   ├── Video.php
│   ├── AudioChunk.php
│   └── Subtitle.php
└── Services/
    ├── WhisperService.php
    ├── TranslationService.php
    ├── MergeSubtitleService.php
    ├── SrtGeneratorService.php
    ├── SubtitleNormalizerService.php
    ├── AudioExtractionService.php
    └── AudioChunkService.php

resources/views/upload/
├── create.blade.php  (upload form)
└── status.blade.php  (status monitoring)

database/migrations/
├── create_videos_table
├── create_audio_chunks_table
└── create_subtitles_table

tests/Unit/  (68 tests, 197 assertions)
```

## 🗄️ Database Schema

### Videos
- `id`, `upload_id` (UUID), `filename`, `size`, `target_language`, `status`, `minio_path`

### AudioChunks
- `id`, `video_id`, `chunk_index`, `start_time`, `path`, `status`

### Subtitles
- `id`, `video_id`, `chunk_index` (use -1 for merged), `language`, `raw_transcript`, `path`, `type`, `status`

## 🧪 Testing

```bash
# Run all tests
docker compose exec app php artisan test tests/Unit/ --testdox

# Run specific test
docker compose exec app php artisan test tests/Unit/TranslationServiceTest.php --testdox
```

**Results**: 68 tests, 197 assertions ✅

## 🎯 Processing Example

**Video**: 7.6MB Netflix trailer → Indonesian subtitles

| Step | Time | Action |
|------|------|--------|
| 1 | 04:54:02 | Upload & create Video (status=queued) |
| 2 | 04:54:15-21 | Extract audio (FFmpeg) |
| 3 | 04:54:21-22 | Split into 3 chunks (60s each) |
| 4 | 04:54:22-39 | Parallel transcription (Whisper 3×) |
| 5 | 04:54:46 | Merge 25 segments, verify all chunks done |
| 6 | 04:54:46 | Generate en.srt |
| 7 | 04:54:47-09 | Translate 25 segments to Indonesian |
| 8 | 04:55:09 | Generate id_translated.srt, verify, cleanup |
| ✅ | ~7min | Done - ready for download |

## 🔧 Configuration

### Audio Settings
- Format: MP3, Bitrate: 64kbps
- Chunk Duration: 60 seconds

### OpenAI Models
- Transcription: `whisper-1`
- Translation: `gpt-4o-mini` (temperature: 0.3)

### Language Codes
- `en` = English
- `id` = Indonesian

### MinIO Paths
```
videos/{video_id}/original.{ext}
videos/{video_id}/audio.mp3
videos/{video_id}/chunks/chunk_*.mp3
videos/{video_id}/subtitles/en.srt
videos/{video_id}/subtitles/id_translated.srt
```

## 🚦 Queue Management

### Start Queue Worker
```bash
# Queue starts automatically with Docker
docker compose up -d

# View logs
docker compose logs -f queue

# Restart
docker compose restart queue
```

### Queue Monitoring
- Named queues: `default` (primary), `transcribe` (transcription)
- Redis-based: persistent, survives restarts
- Automatic retries with exponential backoff

## 💡 Key Design Decisions

1. **chunk_index = -1 for merged records**
   - Distinguishes merged from per-chunk subtitles
   - Enables single merged record per language/type combo
   - Critical for idempotency

2. **Always transcribe in English first**
   - Consistent transcription baseline
   - Translation happens in separate step
   - Better error handling per language

3. **Skip API calls for empty segments**
   - Detects silent audio and placeholder text (...)
   - Saves OpenAI API costs
   - Graceful handling of imperfect audio

4. **Parallel chunk processing**
   - Multiple TranscribeChunkJob instances simultaneously
   - Faster overall processing time
   - Scalable architecture

## 📊 Performance Notes

- **Single 7.6MB video**: ~7 minutes end-to-end
- **Parallel transcription**: 3 chunks processed concurrently
- **API skipping**: ~10-20% cost reduction for typical videos
- **Status polling interval**: 24 seconds (configurable)

## 🐛 Error Handling

- Job retries with backoff
- Graceful silent audio handling
- API error logging with context
- Video status tracking (done/failed)
- Final SRT verification before cleanup

## 📝 Logging

All operations logged to `storage/logs/laravel.log`:

```
[timestamp] local.INFO: UploadController: Storing video file
[timestamp] local.INFO: ProcessVideoJob: starting
[timestamp] local.INFO: WhisperService: transcription complete
[timestamp] local.INFO: MergeSubtitleJob: Translation complete
[timestamp] local.INFO: CleanupVideoProcessingJob: Cleanup completed
```

## 🔒 Security

- OpenAI API key in environment variables (never committed)
- File upload size limits (5GB max)
- File type validation (whitelist)
- Automatic cleanup of temporary files
- S3 path-style endpoints for MinIO

## 📜 License

Proprietary software. All rights reserved.

---

**Version**: 1.0.0  
**Status**: Production Ready ✅  
**Last Updated**: April 21, 2026
