<?php

namespace App\Enums;

enum LogFeature: string
{
    case System = 'system';
    case Auth = 'auth';
    case Workspaces = 'workspaces';
    case Accounts = 'accounts';
    case LinkedIn = 'linkedin';
    case YouTube = 'youtube';
    case Facebook = 'facebook';
    case TikTok = 'tiktok';
    case Media = 'media';
    case Posts = 'posts';
    case Calendar = 'calendar';
    case Scheduler = 'scheduler';
    case Publishing = 'publishing';
}
