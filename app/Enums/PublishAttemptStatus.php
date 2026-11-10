<?php

namespace App\Enums;

enum PublishAttemptStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
