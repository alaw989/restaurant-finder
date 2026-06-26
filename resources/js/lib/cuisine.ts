// Per-cuisine gradient used as the graceful "no photo" / loading backdrop
// behind every result image. Single source (was duplicated in RestaurantCard
// + Show).

const gradients: Record<string, string> = {
    italian: 'linear-gradient(135deg, #e63946 0%, #f1faee 50%, #457b9d 100%)',
    mexican: 'linear-gradient(135deg, #f77f00 0%, #fcbf49 20%, #d62828 100%)',
    chinese: 'linear-gradient(135deg, #d90429 0%, #ef233c 30%, #8d0801 100%)',
    japanese: 'linear-gradient(135deg, #e63946 0%, #f4a261 40%, #264653 100%)',
    thai: 'linear-gradient(135deg, #e63946 0%, #e9c46a 40%, #2a9d8f 100%)',
    indian: 'linear-gradient(135deg, #e76f51 0%, #f4a261 30%, #264653 100%)',
    american: 'linear-gradient(135deg, #457b9d 0%, #1d3557 50%, #e63946 100%)',
    greek: 'linear-gradient(135deg, #457b9d 0%, #a8dadc 40%, #f1faee 100%)',
    korean: 'linear-gradient(135deg, #d62828 0%, #e76f51 40%, #264653 100%)',
    vietnamese: 'linear-gradient(135deg, #2a9d8f 0%, #e9c46a 40%, #f4a261 100%)',
    pizza: 'linear-gradient(135deg, #e63946 0%, #f1faee 40%, #a8dadc 100%)',
    burger: 'linear-gradient(135deg, #d62828 0%, #f77f00 50%, #fcbf49 100%)',
    sushi: 'linear-gradient(135deg, #264653 0%, #2a9d8f 40%, #e9c46a 100%)',
};

export const FOOD_FALLBACK_GRADIENT =
    'linear-gradient(135deg, #1d3557 0%, #457b9d 30%, #a8dadc 100%)';

export function cuisineGradient(slug?: string | null): string {
    if (!slug) return FOOD_FALLBACK_GRADIENT;
    return gradients[slug] ?? FOOD_FALLBACK_GRADIENT;
}
