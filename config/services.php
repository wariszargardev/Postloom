<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'linkedin-openid' => [
        'client_id' => env('LINKEDIN_CLIENT_ID'),
        'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
        'redirect' => env('LINKEDIN_REDIRECT_URI', '/accounts/linkedin/callback'),
        'api_base_url' => env('LINKEDIN_API_BASE_URL', 'https://api.linkedin.com/rest'),
        'api_version' => env('LINKEDIN_API_VERSION', '202610'),
        'video_wait_seconds' => (int) env('LINKEDIN_VIDEO_WAIT_SECONDS', 300),
        'video_poll_seconds' => (int) env('LINKEDIN_VIDEO_POLL_SECONDS', 5),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('YOUTUBE_REDIRECT_URI', '/accounts/youtube/callback'),
        'token_url' => env('GOOGLE_TOKEN_URL', 'https://oauth2.googleapis.com/token'),
        'youtube_api_base_url' => env('YOUTUBE_API_BASE_URL', 'https://www.googleapis.com/youtube/v3'),
        'upload_chunk_bytes' => (int) env('YOUTUBE_UPLOAD_CHUNK_BYTES', 8 * 1024 * 1024),
        'processing_wait_seconds' => (int) env('YOUTUBE_PROCESSING_WAIT_SECONDS', 120),
        'processing_poll_seconds' => (int) env('YOUTUBE_PROCESSING_POLL_SECONDS', 10),
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT_URI', '/accounts/facebook/callback'),
        'graph_version' => env('FACEBOOK_GRAPH_VERSION', 'v27.0'),
        'graph_base_url' => env('FACEBOOK_GRAPH_BASE_URL', 'https://graph.facebook.com'),
        'graph_video_base_url' => env('FACEBOOK_GRAPH_VIDEO_BASE_URL', 'https://graph-video.facebook.com'),
        'rupload_base_url' => env('FACEBOOK_RUPLOAD_BASE_URL', 'https://rupload.facebook.com/video-upload'),
    ],

    'tiktok' => [
        'client_key' => env('TIKTOK_CLIENT_KEY'),
        'client_secret' => env('TIKTOK_CLIENT_SECRET'),
        'redirect' => env('TIKTOK_REDIRECT_URI', '/accounts/tiktok/callback'),
        'authorize_url' => env('TIKTOK_AUTHORIZE_URL', 'https://www.tiktok.com/v2/auth/authorize/'),
        'api_base_url' => env('TIKTOK_API_BASE_URL', 'https://open.tiktokapis.com/v2'),
    ],

];
