export type MediaKind = 'image' | 'video' | 'audio' | 'document';

export type LibraryMedia = {
    id: number;
    kind: MediaKind;
    original_name: string;
    url: string;
    thumbnail_url: string | null;
    duration_seconds: number | null;
};

export type SettingValue = string | number | boolean | null;

export type PlatformSetting = {
    type?: 'select' | 'text' | 'tags' | 'boolean' | 'image';
    label: string;
    options?: Record<string, string>;
    default: SettingValue;
    max_length?: number;
    required?: boolean;
    help?: string;
};

export type UploadSettings = {
    accept: string;
    max_files: number;
};

export type PlatformRules = {
    max_text_length: number;
    media_rules: string;
    settings: Record<string, PlatformSetting>;
};

export type EditorAccount = {
    id: number;
    provider: string;
    provider_label: string;
    name: string;
    handle?: string | null;
    avatar_url: string | null;
    status: 'connected' | 'expiring_soon' | 'reconnect_needed';
    rules: PlatformRules | null;
};

export type TargetForm = {
    social_account_id: number;
    content_override: string | null;
    settings: Record<string, SettingValue>;
};

export type EditorPost = {
    id: number;
    title: string | null;
    content: string;
    timezone: string;
    scheduled_at: string | null;
    status: string;
    status_label: string;
    is_editable: boolean;
    can_retry: boolean;
    targets: (TargetForm & TargetDelivery)[];
    media_ids: number[];
};

export type PublishAttemptRow = {
    attempt: number;
    status: 'running' | 'succeeded' | 'failed';
    error_message: string | null;
    started_at: string;
    duration_ms: number | null;
};

export type TargetDelivery = {
    status: 'pending' | 'publishing' | 'published' | 'ready_to_post' | 'failed';
    status_label: string;
    attempts: number;
    last_error: string | null;
    external_url: string | null;
    published_at: string | null;
    history: PublishAttemptRow[];
};
