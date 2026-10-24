<?php

$maximumPlaybackRate = max(1.0, min(2.5, (float) env('LEARNING_MAXIMUM_PLAYBACK_RATE', 2.0)));
$maximumHeartbeatGapSeconds = max(10, (int) env('LEARNING_MAXIMUM_HEARTBEAT_GAP_SECONDS', 45));

return [
    'minimum_verified_seconds' => (int) env('LEARNING_MINIMUM_VERIFIED_SECONDS', 20),
    'required_fraction' => (float) env('LEARNING_REQUIRED_WATCH_FRACTION', 0.80),
    'maximum_playback_rate' => $maximumPlaybackRate,
    'maximum_heartbeat_gap_seconds' => $maximumHeartbeatGapSeconds,
    // Content seconds, not wall-clock seconds. A legitimate 30 s heartbeat
    // at 2x covers 60 s of media; the gap allowance also covers delivery jitter.
    'maximum_credit_per_heartbeat' => (int) env('LEARNING_MAXIMUM_CREDIT_PER_HEARTBEAT',
        (int) ceil($maximumHeartbeatGapSeconds * $maximumPlaybackRate)),
];
