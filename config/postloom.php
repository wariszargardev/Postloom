<?php

return [

    'allow_registration' => (bool) env('POSTLOOM_ALLOW_REGISTRATION', false),

    'timezone' => env('POSTLOOM_TIMEZONE', 'Asia/Karachi'),

    'media' => [
        'disk' => env('POSTLOOM_MEDIA_DISK', 'local'),
        'max_files_per_upload' => 10,
        'ffmpeg_path' => env('FFMPEG_PATH', 'ffmpeg'),
        'ffprobe_path' => env('FFPROBE_PATH', 'ffprobe'),
        'kinds' => [
            'image' => ['extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'], 'max_kb' => 20 * 1024],
            'video' => ['extensions' => ['mp4', 'mov'], 'max_kb' => 500 * 1024],
            'audio' => ['extensions' => ['mp3', 'm4a', 'wav'], 'max_kb' => 100 * 1024],
            'document' => ['extensions' => ['pdf'], 'max_kb' => 100 * 1024],
        ],
    ],

];
