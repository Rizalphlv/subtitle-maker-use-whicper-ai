# 🎬 Video Player & Subtitle Integration Skill Plan

## 📌 Objective

Menambahkan fitur:
1. **Video Library (Grid View)** seperti Windows Explorer (9 item per page, double-click to open)
2. **Video Player Page** menggunakan Video.js
   - Source video dari MinIO
   - Support attach & switch subtitle dari MinIO
3. **Subtitle Compatibility Layer**
   - Convert `.srt` → `.vtt`
   - Simpan ke MinIO dalam directory yang sama

---

## 🧠 Scope Overview

Feature ini akan extend dari sistem existing:
- Subtitle di-generate dalam format `.srt`
- Akan ditambahkan `.vtt` sebagai format untuk player

---

# 🔍 PHASE 0 — Environment Audit (WAJIB)

## 0.1 Docker Services

```bash
docker compose ps

Pastikan:

app
queue
redis
minio
0.2 Check MinIO Config
MINIO_ENDPOINT=http://minio:9000
MINIO_KEY=...
MINIO_SECRET=...

Test:

docker compose exec app php artisan tinker
Storage::disk('s3')->files('videos');
0.3 Verify Directory Structure
videos/{video_id}/
├── original.mp4
├── audio.mp3
├── chunks/
├── subtitles/
│   ├── en.srt
│   ├── en.vtt ✅
│   ├── id_translated.srt
│   └── id_translated.vtt ✅
0.4 Naming Convention
en.srt → en.vtt
id_translated.srt → id_translated.vtt
🧩 PHASE 1 — Video Library (Grid View)
🎯 Goal
Grid 3x3 (9 video)
Pagination
Double click → redirect ke player
1.1 Route
GET /videos
GET /videos/{id}
1.2 Controller
VideoController@index
VideoController@show
1.3 Query
Video::where('status', 'done')->paginate(9);
1.4 Thumbnail

Optional:

videos/{video_id}/thumbnail.jpg
1.5 Double Click
ondblclick="window.location='/videos/{id}'"
🎬 PHASE 2 — Video Player Page
🎯 Goal
Stream video dari MinIO
Load subtitle .vtt
Switch subtitle
2.1 Generate Signed URL
$videoUrl = Storage::disk('s3')->temporaryUrl(...);
2.2 Subtitle Mapping (VTT ONLY)
$subtitles = [
    [
        'label' => 'English',
        'srclang' => 'en',
        'path' => "videos/{$video->id}/subtitles/en.vtt"
    ],
    [
        'label' => 'Indonesia',
        'srclang' => 'id',
        'path' => "videos/{$video->id}/subtitles/id_translated.vtt"
    ]
];
2.3 Player (Video.js)
<video class="video-js" controls>
    <source src="{{ $videoUrl }}" type="video/mp4">

    @foreach ($subtitles as $sub)
        <track
            kind="subtitles"
            src="{{ $sub['url'] }}"
            srclang="{{ $sub['srclang'] }}"
            label="{{ $sub['label'] }}"
        >
    @endforeach
</video>
🔁 PHASE 2.5 — SRT → VTT Conversion (WAJIB)
🎯 Goal
Convert hasil subtitle .srt → .vtt
Simpan di MinIO path yang sama
2.5.1 Integration Point

Tambahkan di:

MergeSubtitleJob

Flow:

Generate SRT
    ↓
Convert to VTT ✅
    ↓
Upload to MinIO
2.5.2 Conversion Logic
function srtToVtt(string $srt): string
{
    $vtt = "WEBVTT\n\n";
    $vtt .= preg_replace(
        '/(\d{2}:\d{2}:\d{2}),(\d{3})/',
        '$1.$2',
        $srt
    );

    return $vtt;
}
2.5.3 Store to MinIO
$vttPath = "videos/{$videoId}/subtitles/en.vtt";

Storage::disk('s3')->put($vttPath, $vttContent);
2.5.4 Dual Format Strategy
Format	Purpose
.srt	Download
.vtt	Player
⚙️ PHASE 3 — MinIO Integration
3.1 Signed URL
Storage::disk('s3')->temporaryUrl(...)
3.2 CORS (WAJIB)
[
  {
    "AllowedOrigin": ["*"],
    "AllowedMethod": ["GET"],
    "AllowedHeader": ["*"]
  }
]
⚠️ COMMON PITFALLS
❌ Subtitle tidak muncul
masih pakai .srt
harus .vtt
❌ Video tidak load
CORS MinIO salah
❌ 403 error
signed URL expired
🧪 TESTING PLAN
1. Conversion
.srt berhasil jadi .vtt
2. Storage
.vtt tersimpan di MinIO
3. Player
subtitle muncul
bisa switch EN/ID
🧠 FINAL ARCHITECTURE
Laravel
 ├── MergeSubtitleJob (SRT)
 ├── VTT Converter ✅
 ├── MinIO Upload
 └── VideoController
        ↓
MinIO
 ├── video
 ├── .srt
 └── .vtt
        ↓
Browser
 └── Video.js
✅ SUCCESS CRITERIA
 SRT di-generate
 VTT di-generate
 VTT tersimpan di MinIO
 Player load subtitle dari VTT
 Subtitle bisa di-switch
📌 NOTES
Jangan pakai .srt langsung di player
.vtt adalah standard web
Conversion wajib dilakukan di backend

Status: Planned
Next Step: Implement Conversion Layer


---

## 🔥 Yang berubah dari sebelumnya

- ✅ Ditambah **Phase 2.5 (conversion layer)**
- ✅ Struktur MinIO update (include `.vtt`)
- ✅ Player hanya pakai `.vtt`
- ✅ Pipeline lo sekarang jadi **production-grade**

---