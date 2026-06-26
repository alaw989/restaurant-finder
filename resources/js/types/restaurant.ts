// Single source of truth for the restaurant result shape shared across
// Welcome, Restaurants/Index, Restaurants/Show, and RestaurantCard.
// `photos` is the gallery image set (hero first); it may be absent on some
// live-source venues, so callers normalize with `?? []`.

export interface Cuisine {
    id: number;
    name: string;
    slug: string;
}

export interface ScoreSignal {
    label: string;
    weight: number;
    normalized: number;
    contribution: number;
}

export interface ScoreBreakdown {
    signals: ScoreSignal[];
    total: number;
}

export interface Restaurant {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    address: string | null;
    city: string | null;
    state: string | null;
    postal_code?: string | null;
    lat: number | null;
    lng: number | null;
    photo_url: string | null;
    photos?: string[];
    price_range: string | null;
    phone: string | null;
    website_url: string | null;
    google_rating: number | null;
    google_review_count: number;
    yelp_rating: number | null;
    yelp_review_count: number;
    popular_times_avg_busyness?: number | null;
    has_award: boolean;
    popularity_score: number;
    distance: number | null;
    cuisines: Cuisine[];
    source: string | null;
    score_breakdown?: ScoreBreakdown;
    google_place_id?: string | null;
}
