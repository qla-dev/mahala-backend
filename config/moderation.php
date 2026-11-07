<?php

return [
    'terms_version' => '2026-11-08',
    'moderator_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('MODERATION_USER_IDS', ''))))),
];
