<?php

namespace Database\Seeders;

use App\Models\Cuisine;
use App\Models\CuisineCategory;
use Illuminate\Database\Seeder;

class CuisineSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Creates cuisine categories and their associated subcategories.
     * Uses firstOrCreate to ensure idempotency — safe to run multiple times.
     */
    public function run(): void
    {
        $categories = $this->getCategoriesData();

        foreach ($categories as $categoryData) {
            $cuisines = $categoryData['cuisines'];
            unset($categoryData['cuisines']);

            $category = CuisineCategory::firstOrCreate(
                ['slug' => $categoryData['slug']],
                $categoryData
            );

            foreach ($cuisines as $cuisineData) {
                Cuisine::firstOrCreate(
                    [
                        'category_id' => $category->id,
                        'slug' => $cuisineData['slug'],
                    ],
                    $cuisineData
                );
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getCategoriesData(): array
    {
        return [
            [
                'name' => 'Asian',
                'slug' => 'asian',
                'description' => 'Diverse culinary traditions spanning East, Southeast, and South Asia, known for bold flavors, aromatic spices, and centuries-old techniques.',
                'icon' => '🍜',
                'sort_order' => 1,
                'cuisines' => [
                    [
                        'name' => 'Chinese',
                        'slug' => 'chinese',
                        'description' => 'Regional styles including Cantonese, Sichuan, Hunan, and Shandong, featuring stir-frying, steaming, and wok-based techniques.',
                        'icon' => '🥡',
                        'sort_order' => 1,
                    ],
                    [
                        'name' => 'Japanese',
                        'slug' => 'japanese',
                        'description' => 'Refined cuisine emphasizing seasonal ingredients, meticulous preparation, and beautiful presentation, from sushi to ramen.',
                        'icon' => '🍣',
                        'sort_order' => 2,
                    ],
                    [
                        'name' => 'Korean',
                        'slug' => 'korean',
                        'description' => 'Bold fermented flavors, grilled meats, and vibrant banchan side dishes centered around gochujang, kimchi, and sesame.',
                        'icon' => '🍖',
                        'sort_order' => 3,
                    ],
                    [
                        'name' => 'Thai',
                        'slug' => 'thai',
                        'description' => 'A harmonious balance of sweet, sour, salty, and spicy flavors with fragrant herbs like lemongrass, galangal, and kaffir lime.',
                        'icon' => '🍛',
                        'sort_order' => 4,
                    ],
                    [
                        'name' => 'Vietnamese',
                        'slug' => 'vietnamese',
                        'description' => 'Light, fresh dishes built around rice, herbs, and fish sauce, featuring pho, banh mi, and fresh spring rolls.',
                        'icon' => '🥣',
                        'sort_order' => 5,
                    ],
                    [
                        'name' => 'Filipino',
                        'slug' => 'filipino',
                        'description' => 'A fusion of Malay, Spanish, Chinese, and American influences featuring adobo, sinigang, lechon, and lumpia.',
                        'icon' => '🥘',
                        'sort_order' => 6,
                    ],
                    [
                        'name' => 'Indian',
                        'slug' => 'indian',
                        'description' => 'Extraordinarily diverse regional cooking with complex spice blends, tandoor techniques, curries, and vegetarian traditions.',
                        'icon' => '🫕',
                        'sort_order' => 7,
                    ],
                    [
                        'name' => 'Malaysian',
                        'slug' => 'malaysian',
                        'description' => 'A multicultural blend of Malay, Chinese, and Indian flavors featuring laksa, nasi lemak, and satay.',
                        'icon' => '🥙',
                        'sort_order' => 8,
                    ],
                    [
                        'name' => 'Indonesian',
                        'slug' => 'indonesian',
                        'description' => 'Rich, aromatic dishes from thousands of islands, featuring rendang, nasi goreng, and peanut-based sambals.',
                        'icon' => '🍚',
                        'sort_order' => 9,
                    ],
                    [
                        'name' => 'Taiwanese',
                        'slug' => 'taiwanese',
                        'description' => 'Night market favorites and comfort food featuring beef noodle soup, bubble tea, braised pork rice, and scallion pancakes.',
                        'icon' => '🥟',
                        'sort_order' => 10,
                    ],
                    [
                        'name' => 'Cambodian',
                        'slug' => 'cambodian',
                        'description' => 'Subtle Southeast Asian flavors featuring amok coconut curry, kuy teav noodle soup, and prahok fermented fish paste.',
                        'icon' => '🍲',
                        'sort_order' => 11,
                    ],
                    [
                        'name' => 'Singaporean',
                        'slug' => 'singaporean',
                        'description' => 'Multicultural hawker center cuisine blending Chinese, Malay, and Indian traditions, featuring Hainanese chicken rice and chili crab.',
                        'icon' => '🦀',
                        'sort_order' => 12,
                    ],
                ],
            ],
            [
                'name' => 'European',
                'slug' => 'european',
                'description' => 'Time-honored culinary traditions from across the European continent, ranging from rustic farmhouse cooking to haute cuisine.',
                'icon' => '🧀',
                'sort_order' => 2,
                'cuisines' => [
                    [
                        'name' => 'Italian',
                        'slug' => 'italian',
                        'description' => 'Regionally diverse cuisine celebrating high-quality ingredients, from handmade pasta and risotto to wood-fired pizza and gelato.',
                        'icon' => '🍕',
                        'sort_order' => 1,
                    ],
                    [
                        'name' => 'French',
                        'slug' => 'french',
                        'description' => 'The cornerstone of Western culinary arts, featuring classic techniques, rich sauces, and an emphasis on terroir and seasonality.',
                        'icon' => '🥐',
                        'sort_order' => 2,
                    ],
                    [
                        'name' => 'Spanish',
                        'slug' => 'spanish',
                        'description' => 'Vibrant flavors from tapas to paella, celebrating olive oil, saffron, and the communal dining tradition.',
                        'icon' => '🥘',
                        'sort_order' => 3,
                    ],
                    [
                        'name' => 'Greek',
                        'slug' => 'greek',
                        'description' => 'Mediterranean diet staples including olive oil, feta, grilled meats, and fresh seafood with oregano and lemon.',
                        'icon' => '🫒',
                        'sort_order' => 4,
                    ],
                    [
                        'name' => 'German',
                        'slug' => 'german',
                        'description' => 'Hearty, comforting dishes featuring sausages, pretzels, schnitzel, and sauerkraut alongside world-class beers.',
                        'icon' => '🥨',
                        'sort_order' => 5,
                    ],
                    [
                        'name' => 'British',
                        'slug' => 'british',
                        'description' => 'Traditional comfort food from Sunday roasts and fish and chips to pies, puddings, and a modern fine-dining renaissance.',
                        'icon' => '☕',
                        'sort_order' => 6,
                    ],
                    [
                        'name' => 'Portuguese',
                        'slug' => 'portuguese',
                        'description' => 'Atlantic-influenced cuisine built around salted cod, grilled seafood, custard tarts, and piri-piri spices.',
                        'icon' => '🐟',
                        'sort_order' => 7,
                    ],
                    [
                        'name' => 'Polish',
                        'slug' => 'polish',
                        'description' => 'Hearty Eastern European comfort food featuring pierogi, kielbasa, borscht, and warming winter stews.',
                        'icon' => '🥟',
                        'sort_order' => 8,
                    ],
                    [
                        'name' => 'Belgian',
                        'slug' => 'belgian',
                        'description' => 'Refined comfort cuisine famous for moules-frites, waffles, chocolate, and a rich brewing tradition.',
                        'icon' => '🧇',
                        'sort_order' => 9,
                    ],
                    [
                        'name' => 'Swiss',
                        'slug' => 'swiss',
                        'description' => 'Alpine comfort food built around cheese and potatoes, including fondue, raclette, and rosti.',
                        'icon' => '🫕',
                        'sort_order' => 10,
                    ],
                ],
            ],
            [
                'name' => 'Latin American',
                'slug' => 'latin-american',
                'description' => 'Bold, colorful cuisines rooted in indigenous, European, and African traditions, celebrating corn, chilies, and vibrant salsas.',
                'icon' => '🌮',
                'sort_order' => 3,
                'cuisines' => [
                    [
                        'name' => 'Mexican',
                        'slug' => 'mexican',
                        'description' => 'UNESCO-recognized cuisine built around corn, beans, chilies, and mole, from street tacos to celebratory dishes.',
                        'icon' => '🌮',
                        'sort_order' => 1,
                    ],
                    [
                        'name' => 'Brazilian',
                        'slug' => 'brazilian',
                        'description' => 'Vast regional diversity from churrasco grilling and feijoada stew to Amazonian ingredients and Bahian Afro-Brazilian flavors.',
                        'icon' => '🥩',
                        'sort_order' => 2,
                    ],
                    [
                        'name' => 'Peruvian',
                        'slug' => 'peruvian',
                        'description' => 'A culinary powerhouse fusing indigenous, Spanish, Chinese, and Japanese influences, featuring ceviche and lomo saltado.',
                        'icon' => '🌽',
                        'sort_order' => 3,
                    ],
                    [
                        'name' => 'Argentine',
                        'slug' => 'argentine',
                        'description' => 'World-renowned beef culture built around asado grilling, empanadas, chimichurri, and Malbec wine.',
                        'icon' => '🥩',
                        'sort_order' => 4,
                    ],
                    [
                        'name' => 'Colombian',
                        'slug' => 'colombian',
                        'description' => 'Diverse regional cooking featuring arepas, bandeja paisa, sancocho soup, and tropical fruit-based beverages.',
                        'icon' => '🫓',
                        'sort_order' => 5,
                    ],
                    [
                        'name' => 'Cuban',
                        'slug' => 'cuban',
                        'description' => 'Caribbean-influenced cuisine with Spanish and African roots, featuring ropa vieja, moros y cristianos, and Cuban sandwiches.',
                        'icon' => '🥪',
                        'sort_order' => 6,
                    ],
                    [
                        'name' => 'Venezuelan',
                        'slug' => 'venezuelan',
                        'description' => 'Corn-based cuisine featuring arepas, cachapas, pabellon criollo, and hallacas during festive celebrations.',
                        'icon' => '🫓',
                        'sort_order' => 7,
                    ],
                    [
                        'name' => 'Chilean',
                        'slug' => 'chilean',
                        'description' => 'Long coastal cuisine blending indigenous Mapuche and Spanish traditions, emphasizing seafood, wine, and empanadas.',
                        'icon' => '🦐',
                        'sort_order' => 8,
                    ],
                ],
            ],
            [
                'name' => 'Middle Eastern',
                'slug' => 'middle-eastern',
                'description' => 'Ancient culinary traditions built around spices, grains, olive oil, and shared mezze, spanning the Eastern Mediterranean to North Africa.',
                'icon' => '🧆',
                'sort_order' => 4,
                'cuisines' => [
                    [
                        'name' => 'Lebanese',
                        'slug' => 'lebanese',
                        'description' => 'Refined mezze culture featuring hummus, tabbouleh, kibbeh, and grilled meats with pita and pickled turnips.',
                        'icon' => '🧆',
                        'sort_order' => 1,
                    ],
                    [
                        'name' => 'Turkish',
                        'slug' => 'turkish',
                        'description' => 'Imperial Ottoman cuisine featuring kebabs, meze, lahmacun, baklava, and the legendary breakfast spread.',
                        'icon' => '🍢',
                        'sort_order' => 2,
                    ],
                    [
                        'name' => 'Persian',
                        'slug' => 'persian',
                        'description' => 'Elegant rice-based dishes with saffron, dried fruits, and herbs, featuring kebabs, stews, and tahdig.',
                        'icon' => '🍚',
                        'sort_order' => 3,
                    ],
                    [
                        'name' => 'Israeli',
                        'slug' => 'israeli',
                        'description' => 'A melting pot of Jewish diaspora cuisines featuring falafel, shakshuka, sabich, and vibrant fresh salads.',
                        'icon' => '🥙',
                        'sort_order' => 4,
                    ],
                    [
                        'name' => 'Moroccan',
                        'slug' => 'moroccan',
                        'description' => 'Aromatic tagines, couscous, and pastilla built around Ras el Hanout, preserved lemons, and harissa.',
                        'icon' => '🕌',
                        'sort_order' => 5,
                    ],
                    [
                        'name' => 'Egyptian',
                        'slug' => 'egyptian',
                        'description' => 'Ancient culinary traditions featuring koshari, ful medames, molokhia, and freshly baked aish baladi.',
                        'icon' => '🫘',
                        'sort_order' => 6,
                    ],
                ],
            ],
            [
                'name' => 'American',
                'slug' => 'american',
                'description' => 'A rich tapestry of regional cooking styles shaped by immigrant traditions, indigenous ingredients, and bold innovation.',
                'icon' => '🍔',
                'sort_order' => 5,
                'cuisines' => [
                    [
                        'name' => 'Southern',
                        'slug' => 'southern',
                        'description' => 'Soul food and Southern hospitality featuring fried chicken, biscuits and gravy, collard greens, and cornbread.',
                        'icon' => '🍗',
                        'sort_order' => 1,
                    ],
                    [
                        'name' => 'Cajun/Creole',
                        'slug' => 'cajun-creole',
                        'description' => 'Louisiana\'s vibrant cooking tradition featuring gumbo, jambalaya, crawfish boils, and the holy trinity of aromatics.',
                        'icon' => '🦞',
                        'sort_order' => 2,
                    ],
                    [
                        'name' => 'Tex-Mex',
                        'slug' => 'tex-mex',
                        'description' => 'Border cuisine blending Mexican and American traditions featuring nachos, queso, fajitas, and chili con carne.',
                        'icon' => '🌯',
                        'sort_order' => 3,
                    ],
                    [
                        'name' => 'BBQ',
                        'slug' => 'bbq',
                        'description' => 'Low-and-slow smoked meat traditions spanning Kansas City, Texas, Carolina, and Memphis regional styles.',
                        'icon' => '🍖',
                        'sort_order' => 4,
                    ],
                    [
                        'name' => 'New American',
                        'slug' => 'new-american',
                        'description' => 'Modern farm-to-table cuisine that reinterprets global techniques with seasonal, locally sourced American ingredients.',
                        'icon' => '🍽️',
                        'sort_order' => 5,
                    ],
                    [
                        'name' => 'Hawaiian',
                        'slug' => 'hawaiian',
                        'description' => 'Island cuisine blending Polynesian, Japanese, Portuguese, and American influences, featuring poke, plate lunches, and spam musubi.',
                        'icon' => '🌺',
                        'sort_order' => 6,
                    ],
                ],
            ],
            [
                'name' => 'African',
                'slug' => 'african',
                'description' => 'The world\'s most diverse culinary continent, from North African spice routes to sub-Saharan grain and stew traditions.',
                'icon' => '🌍',
                'sort_order' => 6,
                'cuisines' => [
                    [
                        'name' => 'Ethiopian',
                        'slug' => 'ethiopian',
                        'description' => 'Communal dining on injera flatbread with vibrant stews, split pea dishes, and berbere-spiced preparations.',
                        'icon' => '🫓',
                        'sort_order' => 1,
                    ],
                    [
                        'name' => 'Nigerian',
                        'slug' => 'nigerian',
                        'description' => 'Bold, peppery flavors featuring jollof rice, suya, pounded yam with egusi soup, and pepper soup.',
                        'icon' => '🌶️',
                        'sort_order' => 2,
                    ],
                    [
                        'name' => 'South African',
                        'slug' => 'south-african',
                        'description' => 'Rainbow nation cuisine featuring braai, bobotie, bunny chow, biltong, and Cape Malay curries.',
                        'icon' => '🥩',
                        'sort_order' => 3,
                    ],
                    [
                        'name' => 'West African',
                        'slug' => 'west-african',
                        'description' => 'Grain and root-based dishes with rich palm oil and groundnut sauces, featuring fufu, attiéké, and thieboudienne.',
                        'icon' => '🥜',
                        'sort_order' => 4,
                    ],
                    [
                        'name' => 'Kenyan',
                        'slug' => 'kenyan',
                        'description' => 'East African staples built around maize, beans, and grilled nyama choma, featuring ugali, sukuma wiki, and chai.',
                        'icon' => '☕',
                        'sort_order' => 5,
                    ],
                ],
            ],
            [
                'name' => 'Caribbean',
                'slug' => 'caribbean',
                'description' => 'Island cuisines blending African, European, Indian, and indigenous influences with tropical fruits, spices, and fiery peppers.',
                'icon' => '🏝️',
                'sort_order' => 7,
                'cuisines' => [
                    [
                        'name' => 'Jamaican',
                        'slug' => 'jamaican',
                        'description' => 'Bold, spicy cuisine famous for jerk seasoning, curried goat, ackee and saltfish, and rum-based drinks.',
                        'icon' => '🌶️',
                        'sort_order' => 1,
                    ],
                    [
                        'name' => 'Puerto Rican',
                        'slug' => 'puerto-rican',
                        'description' => 'Sazon and sofrito-powered cuisine featuring mofongo, arroz con gandules, lechon, and tostones.',
                        'icon' => '🥘',
                        'sort_order' => 2,
                    ],
                    [
                        'name' => 'Trinidadian',
                        'slug' => 'trinidadian',
                        'description' => 'A fusion of Indian, African, and Creole traditions featuring roti, doubles, callaloo, and pelau.',
                        'icon' => '🍛',
                        'sort_order' => 3,
                    ],
                    [
                        'name' => 'Haitian',
                        'slug' => 'haitian',
                        'description' => 'Creole cuisine with French and African roots featuring griot, diri ak djon djon, and pikliz condiment.',
                        'icon' => '🫑',
                        'sort_order' => 4,
                    ],
                ],
            ],
            [
                'name' => 'Oceanian',
                'slug' => 'oceanian',
                'description' => 'Cuisines from Australia, New Zealand, and the Pacific Islands, blending indigenous traditions with colonial and Asian influences.',
                'icon' => '🦘',
                'sort_order' => 8,
                'cuisines' => [
                    [
                        'name' => 'Australian',
                        'slug' => 'australian',
                        'description' => 'Modern cuisine embracing native bush ingredients, multicultural influences, and a thriving cafe and seafood culture.',
                        'icon' => '🥑',
                        'sort_order' => 1,
                    ],
                    [
                        'name' => 'New Zealand',
                        'slug' => 'new-zealand',
                        'description' => 'Pacific Rim cuisine featuring lamb, green-lipped mussels, kumara, hangi earth oven cooking, and pavlova.',
                        'icon' => '🥝',
                        'sort_order' => 2,
                    ],
                    [
                        'name' => 'Polynesian',
                        'slug' => 'polynesian',
                        'description' => 'Island cooking traditions featuring coconut, taro, breadfruit, fresh seafood, and earth oven methods like the umu.',
                        'icon' => '🥥',
                        'sort_order' => 3,
                    ],
                ],
            ],
        ];
    }
}
