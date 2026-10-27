<?php

return [
    'enabled' => filter_var(env('CAMERA_AI_ENABLED', false), FILTER_VALIDATE_BOOL),
    'url' => env('CAMERA_AI_URL', 'http://ai:8091'),
    'token' => env('CAMERA_AI_TOKEN', ''),
];
