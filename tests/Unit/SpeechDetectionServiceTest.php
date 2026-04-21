<?php

namespace Tests\Unit;

use App\Services\SpeechDetectionService;
use Tests\TestCase;

class SpeechDetectionServiceTest extends TestCase
{
    protected SpeechDetectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SpeechDetectionService();
    }

    public function test_parse_silence_ratio_calculates_total_silence(): void
    {
        $output = implode("\n", [
            '[silencedetect @ 000001] silence_start: 0',
            '[silencedetect @ 000001] silence_end: 30.0 | silence_duration: 30.0',
            '[silencedetect @ 000001] silence_start: 45.0',
            '[silencedetect @ 000001] silence_end: 60.0 | silence_duration: 15.0',
        ]);

        $ratio = $this->service->parseSilenceRatio($output, 60.0);

        $this->assertEquals(0.75, $ratio);
    }

    public function test_filter_meaningful_segments_removes_fillers_and_noise(): void
    {
        $segments = [
            ['start' => 0.0, 'end' => 0.5, 'text' => 'uh'],
            ['start' => 0.5, 'end' => 1.0, 'text' => 'hmm...'],
            ['start' => 1.0, 'end' => 1.3, 'text' => '...'],
        ];

        $filtered = $this->service->filterMeaningfulSegments($segments);

        $this->assertSame([], $filtered);
    }

    public function test_filter_meaningful_segments_keeps_real_speech(): void
    {
        $segments = [
            ['start' => 0.0, 'end' => 0.5, 'text' => 'uh'],
            ['start' => 1.0, 'end' => 3.5, 'text' => '  Hello   world  '],
        ];

        $filtered = $this->service->filterMeaningfulSegments($segments);

        $this->assertCount(1, $filtered);
        $this->assertEquals('Hello world', $filtered[0]['text']);
        $this->assertEquals(1.0, $filtered[0]['start']);
        $this->assertEquals(3.5, $filtered[0]['end']);
    }

    public function test_parse_volume_metric_reads_ffmpeg_output(): void
    {
        $output = '[Parsed_volumedetect_0 @ 000001] mean_volume: -46.5 dB' . "\n"
            . '[Parsed_volumedetect_0 @ 000001] max_volume: -24.1 dB';

        $this->assertEquals(-46.5, $this->service->parseVolumeMetric($output, 'mean_volume'));
        $this->assertEquals(-24.1, $this->service->parseVolumeMetric($output, 'max_volume'));
        $this->assertNull($this->service->parseVolumeMetric($output, 'unknown_metric'));
    }
}