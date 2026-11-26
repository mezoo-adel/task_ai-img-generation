export type GenerationSize = '1024x1024' | '1536x1024' | '1024x1536';

export type GenerationQuality = 'low' | 'medium' | 'high';

export type GenerationStatus = 'queued' | 'processing' | 'succeeded' | 'failed';

export type GenerationInput = {
    prompt: string;
    size: GenerationSize;
    quality: GenerationQuality;
    reference_images: string[];
};

export type EstimateBounds = {
    estimated_min_usd: string;
    estimated_max_usd: string;
};

export type GenerationHistoryRow = {
    id: string;
    prompt: string;
    size: string;
    quality: string;
    reference_count: number;
    status: GenerationStatus;
    estimated_min_usd: string;
    estimated_max_usd: string;
    charge_usd: string | null;
    failure_message: string | null;
    image_url: string | null;
    image_expired: boolean;
    image_expires_at: string | null;
    is_delayed: boolean;
    needs_attention: boolean;
    created_at: string | null;
};

export type GenerationHistoryPage = {
    data: GenerationHistoryRow[];
    prev_page_url: string | null;
    next_page_url: string | null;
};
