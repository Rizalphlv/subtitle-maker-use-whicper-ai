# 🚀 Phase 2.2 — Speech Detection & Smart Chunk Skipping

---

# 📌 Objective

Reduce Whisper API cost by **skipping non-speech audio chunks** while:

* Maintaining subtitle timing accuracy (second-by-second)
* Preserving overall subtitle structure
* Avoiding any impact on meaningful dialogue transcription

---

# ⚠️ STRICT RULES

* DO NOT modify subtitle timestamps
* DO NOT shift timing alignment
* DO NOT merge or re-order segments
* DO NOT affect real speech transcription
* ONLY skip chunks that contain NO meaningful speech

---

# 🧠 PROBLEM

Currently:

```text
All audio chunks → sent to Whisper API
```

Even if chunk contains:

* silence
* background noise
* breathing sounds
* "ah", "uh", "hmm"

👉 This causes **unnecessary cost**

---

# 🎯 GOAL

Only process chunks that contain:

```text
REAL HUMAN SPEECH (words, sentences, dialogue)
```

Skip everything else safely.

---

# 🚀 DETECTION STRATEGY

---

# 🔥 1. Pre-Check: Silence Detection

## Use FFmpeg

```bash
ffmpeg -i input.mp3 -af silencedetect=n=-40dB:d=0.5 -f null -
```

---

## Rule

If chunk is:

```text
> 80–90% silence
```

👉 SKIP chunk

---

# 🔥 2. Low Audio Energy Check (Optional)

If RMS / volume is extremely low:

```text
Likely no speech
```

👉 SKIP

---

# 🔥 3. Whisper Post-Filter (CRITICAL)

Even after Whisper:

Filter segments that are NOT meaningful speech.

---

## Skip patterns

```text
ah
uh
hmm
mmm
oh
aa
oo
eh
```

---

## Regex Rule

```php
if (preg_match('/^(ah+|uh+|mm+|oh+|aa+|oo+|eh+)$/i', $text)) {
    skip;
}
```

---

## Length Rule

```php
if (strlen($text) < 3) {
    skip;
}
```

---

# 🔥 4. Chunk-Level Decision

## Rule

If ALL segments in a chunk are:

* empty
* noise
* filler sounds

👉 Mark chunk as:

```text
NON-SPEECH CHUNK
```

👉 DO NOT store subtitle

---

# ⚠️ IMPORTANT

Even if skipped:

* DO NOT adjust timeline
* DO NOT compress timestamps
* DO NOT remove gaps

👉 Silence MUST remain as gap in subtitle

---

# 🧠 TIMING SAFETY RULE

Example:

```text
Chunk 1: speech → processed
Chunk 2: silence → skipped
Chunk 3: speech → processed
```

👉 Final subtitle:

```text
[00:00 - 00:10] speech
[00:10 - 00:20] (no subtitle)
[00:20 - 00:30] speech
```

✅ This is CORRECT behavior

---

# 🔥 5. Minimum Speech Threshold

## Rule

If chunk contains:

```text
< 1 valid sentence OR
< 2 valid words
```

👉 Treat as NON-SPEECH

---

# 🔥 6. Safety Guard (IMPORTANT)

DO NOT skip chunk if:

* contains real words
* contains dialogue
* contains mixed speech + noise

---

## Priority Rule

```text
Speech detected → ALWAYS process
```

---

# ⚙️ IMPLEMENTATION FLOW

```text
Audio Chunk
   ↓
Check Silence (FFmpeg)
   ↓
IF silent → SKIP
   ↓
ELSE → Send to Whisper
   ↓
Filter segments:
   - remove filler words
   - remove noise
   ↓
IF no valid speech → discard chunk result
   ↓
Merge subtitles
```

---

# 📊 EXPECTED RESULT

| Metric            | Before | After   |
| ----------------- | ------ | ------- |
| Whisper Calls     | High   | Reduced |
| Cost              | High   | Lower   |
| Subtitle Accuracy | Same   | Same ✅  |
| Timing Precision  | Same   | Same ✅  |

---

# 🎯 IMPLEMENTATION PRIORITY

1. Silence detection (FFmpeg)
2. Filler word filtering (regex)
3. Chunk-level speech validation
4. Safe skip logic

---

# 🧠 FINAL NOTE

This optimization:

* Targets ONLY non-speech audio
* Preserves subtitle accuracy
* Has high impact on cost reduction

---

# 🤝 AI EXECUTION RULE

* Modify existing chunk processing logic only
* Do NOT redesign pipeline
* Do NOT affect timing system
* Be conservative: when unsure → DO NOT skip

---
