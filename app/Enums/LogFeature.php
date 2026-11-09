<?php

namespace App\Enums;

enum LogFeature: string
{
    case System = 'system';
    case Auth = 'auth';
    case Accounts = 'accounts';
    case LinkedIn = 'linkedin';
    case Media = 'media';
    case Posts = 'posts';
    case Calendar = 'calendar';
    case Scheduler = 'scheduler';
    case Publishing = 'publishing';
}
