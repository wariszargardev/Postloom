export type MediaKind = 'image' | 'video' | 'audio' | 'document';

export type LibraryMedia = {
    id: number;
    kind: MediaKind;
    original_name: string;
    url: string;
    thumbnail_url: string | null;
    duration_seconds: number | null;
};

export type PlatformSetting = {
    label: string;
    options: Record<string, string>;
    default: string;
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
    avatar_url: string | null;
    status: 'connected' | 'expiring_soon' | 'reconnect_needed';
    rules: PlatformRules | null;
};

export type TargetForm = {
    social_account_id: number;
    content_override: string | null;
    settings: Record<string, string>;
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
    targets: (TargetForm & { status: string; last_error: string | null })[];
    media_ids: number[];
};
