<?php

/**
 * Single source of truth for cuisine matching.
 *
 * The cuisine TAXONOMY (categories + cuisines) lives in the DB, seeded by
 * `CuisineSeeder`. This file is the matching LEXICON: for each cuisine slug,
 * the regex-ready keyword fragments used to (a) decide whether a venue is
 * ON-cuisine and (b) build the rival-cuisine set that drops off-cuisine rows.
 *
 * Why this exists: the old code carried THREE duplicated, partial keyword maps
 * (`LiveSearchService::allCuisineKeywordMap`, a copy in
 * `RestaurantEnrichmentService::cuisineNameKeywords`, and
 * `OverpassService::CUISINE_SYNONYMS`) covering only ~10 of the 49 seeded
 * cuisines. Any cuisine outside those ~10 — and ALL 8 category-level searches
 * — silently degraded to unfiltered "any cuisine" results. This config is the
 * one place every service consults, covering every seeded cuisine + category.
 *
 * Keyword conventions:
 *  - Lowercase. Use `.` to match a separator so "dim.sum" matches "dim sum",
 *    "dimsum", "dim-sum" (the filter uses these as RAW regex fragments).
 *  - Keep terms cuisine-SPECIFIC. Do NOT add cross-cuisine regional words
 *    (e.g. "mediterranean", "asian") or generic structural words
 *    ("grill", "bar", "kitchen", "house", "restaurant") — those cause false
 *    rival-drops (a "Japanese grill" must not be dropped from a Japanese
 *    search) and false on-matches.
 *  - Avoid short ambiguous substrings (e.g. "wat", "poi") that match unrelated
 *    words ("water", "pointer").
 *
 * `cuisines`  : cuisine slug => keyword fragments (filter on-cuisine / rival).
 * `categories`: category slug => member cuisine slugs (umbrella expansion for
 *               "All <Category>" searches; kept in sync with the seeded DB by
 *               CuisineMatcherTest's drift guard).
 */

return [

    'cuisines' => [
        // ── Asian ──────────────────────────────────────────────────────────
        'chinese' => ['chinese', 'china', 'szechuan', 'sichuan', 'peking', 'beijing', 'cantonese', 'mandarin', 'dim.sum', 'wok', 'dragon', 'shanghai', 'hunan', 'mongolian', 'panda'],
        'japanese' => ['japanese', 'sushi', 'ramen', 'teriyaki', 'bento', 'teppan', 'izakaya', 'hibachi', 'sashimi', 'tempura', 'udon', 'yakitori', 'tonkatsu'],
        'korean' => ['korean', 'seoul', 'kimchi', 'bulgogi', 'bibimbap', 'gochujang', 'galbi'],
        'thai' => ['thai', 'thailand', 'bangkok', 'pad.thai', 'tom.yum', 'lemongrass', 'som.tum', 'massaman'],
        'vietnamese' => ['vietnamese', 'pho', 'saigon', 'hanoi', 'banh.mi', 'bun.cha', 'goi.cuon'],
        'filipino' => ['filipino', 'pinoy', 'adobo', 'lumpia', 'sinigang', 'lechon', 'pancit', 'halo.halo', 'sisig', 'kare.kare'],
        'indian' => ['indian', 'tandoor', 'curry', 'biryani', 'masala', 'korma', 'naan', 'taj', 'raja', 'dosa', 'samosa', 'paneer', 'chaat', 'butter.chicken'],
        'malaysian' => ['malaysian', 'laksa', 'satay', 'nasi.lemak', 'roti.canai', 'char.kway.teow', 'rendang'],
        'indonesian' => ['indonesian', 'nasi.goreng', 'rendang', 'sate', 'sambal', 'gado.gado', 'mie.goreng', 'rijsttafel'],
        'taiwanese' => ['taiwanese', 'bubble.tea', 'boba', 'beef.noodle', 'lu.rou.fan', 'gua.bao', 'scallion.pancake'],
        'cambodian' => ['cambodian', 'kuy.teav', 'prahok', 'num.ban.chok', 'kampot', 'amok'],
        'singaporean' => ['singaporean', 'hainanese', 'chili.crab', 'kaya', 'char.kway.teow', 'laksa', 'bak.kut.teh'],
        'nepalese' => ['nepalese', 'nepali', 'momo', 'dal.bhat', 'sel.roti', 'newari', 'yomari'],
        'tibetan' => ['tibetan', 'thenthuk', 'tsampa', 'shabhaleh', 'phingsha'],
        'burmese' => ['burmese', 'myanmar', 'mohinga', 'laphet', 'khao.swe', 'tea.leaf'],

        // ── European ───────────────────────────────────────────────────────
        'italian' => ['italian', 'pizza', 'pasta', 'trattoria', 'ristorante', 'bella', 'mamma', 'napoli', 'milan', 'risotto', 'gnocchi', 'bruschetta'],
        'french' => ['french', 'bistro', 'brasserie', 'croissant', 'crepe', 'baguette', 'escargot', 'provencal', 'bouchon', 'confit', 'bouillabaisse'],
        'spanish' => ['spanish', 'tapas', 'paella', 'jamon', 'churros', 'gazpacho', 'sangria', 'iberico', 'patatas.bravas'],
        'greek' => ['greek', 'gyro', 'souvlaki', 'moussaka', 'tzatziki', 'spanakopita', 'feta', 'athens', 'santorini', 'dolmades', 'pastitsio'],
        'german' => ['german', 'wurst', 'bratwurst', 'schnitzel', 'pretzel', 'sauerkraut', 'bavarian', 'spatzle', 'weisswurst', 'haxe'],
        'british' => ['british', 'english', 'fish.and.chips', 'bangers', 'toad.in.the.hole', 'yorkshire', 'shepherd', 'steak.and.ale', 'scotch.egg'],
        'portuguese' => ['portuguese', 'bacalhau', 'pastel.de.nata', 'piri.piri', 'francesinha', 'cataplana', 'custard.tart'],
        'polish' => ['polish', 'pierogi', 'kielbasa', 'borscht', 'bigos', 'golabki', 'paczek', 'zapiekanka'],
        'belgian' => ['belgian', 'moules.frites', 'waffle', 'frites', 'carbonnade', 'stoofvlees', 'waterzooi'],
        'swiss' => ['swiss', 'fondue', 'raclette', 'rosti', 'zurich', 'geschnetzeltes', 'nusstorte'],
        'russian' => ['russian', 'borscht', 'pelmeni', 'blini', 'stroganoff', 'solyanka', 'pirozhki'],

        // ── Latin American ─────────────────────────────────────────────────
        'mexican' => ['mexican', 'taqueria', 'taco', 'burrito', 'cantina', 'jalapeno', 'fajita', 'quesadilla', 'enchilada', 'mole', 'tortilla', 'carnitas', 'margarita'],
        'brazilian' => ['brazilian', 'churrasco', 'feijoada', 'pao.de.queijo', 'moqueca', 'caipirinha', 'acai', 'coxinha', 'picanha'],
        'peruvian' => ['peruvian', 'ceviche', 'lomo.saltado', 'anticucho', 'causa', 'pollo.a.la.brasa', 'rocoto'],
        'argentine' => ['argentine', 'argentinian', 'asado', 'empanada', 'chimichurri', 'milanesa', 'alfajor', 'choripan', 'matambre'],
        'colombian' => ['colombian', 'arepa', 'bandeja.paisa', 'sancocho', 'ajiaco', 'aguapanela', 'chicharron', 'buñuelo'],
        'cuban' => ['cuban', 'ropa.vieja', 'moros', 'medianoche', 'lechon', 'mojito', 'picadillo', 'yuca', 'cubano'],
        'venezuelan' => ['venezuelan', 'arepa', 'cachapa', 'pabellon', 'hallaca', 'tequeno', 'mandoca'],
        'chilean' => ['chilean', 'pastel.de.choclo', 'cazuela', 'chorillana', 'pisco', 'curanto', 'humita'],

        // ── Middle Eastern ─────────────────────────────────────────────────
        'lebanese' => ['lebanese', 'shawarma', 'hummus', 'falafel', 'tabbouleh', 'baba.ganoush', 'manakish', 'kibbeh', 'kafta', 'fattoush'],
        'turkish' => ['turkish', 'kebab', 'doner', 'baklava', 'lahmacun', 'pide', 'borek', 'manti', 'meze', 'kofte'],
        'persian' => ['persian', 'iranian', 'saffron', 'tahdig', 'ghormeh', 'koobideh', 'fesenjan', 'zereshk', 'zereshk polo', 'tahchin'],
        'israeli' => ['israeli', 'shakshuka', 'sabich', 'shwarma', 'falafel', 'hummus', 'pita', 'shakshouka', 'bourekas'],
        'moroccan' => ['moroccan', 'tagine', 'couscous', 'harira', 'ras.el.hanout', 'pastilla', 'bastilla', 'zaalouk', 'kefta'],
        'egyptian' => ['egyptian', 'koshari', 'ful.medames', 'molokhia', 'feteer', 'tameya', 'karkadeh', 'om.ali'],
        'afghan' => ['afghan', 'afghani', 'kabul', 'mantu', 'bolani', 'qabili', 'ashak', 'palaw'],

        // ── American ───────────────────────────────────────────────────────
        'southern' => ['southern', 'soul.food', 'fried.chicken', 'biscuit', 'grits', 'collard', 'cornbread', 'catfish', 'shrimp.and.grits', 'lowcountry'],
        'cajun-creole' => ['cajun', 'creole', 'gumbo', 'jambalaya', 'etouffee', 'andouille', 'po.boy', 'beignet', 'maque.choux', 'boudin'],
        'tex-mex' => ['tex.mex', 'nachos', 'queso', 'chili.con.carne', 'fajita', 'enchilada', 'fajitas'],
        'bbq' => ['bbq', 'barbecue', 'smokehouse', 'brisket', 'pulled.pork', 'burnt.ends', 'dry.rub'],
        'new-american' => ['new.american', 'farm.to.table', 'seasonal', 'contemporary', 'craft', 'american'],
        'hawaiian' => ['hawaiian', 'poke', 'spam.musubi', 'plate.lunch', 'loco.moco', 'kalua', 'lomi', 'malasada'],

        // ── African ────────────────────────────────────────────────────────
        'ethiopian' => ['ethiopian', 'injera', 'berbere', 'tibs', 'kitfo', 'doro.wat', 'teff', 'abyssinia', 'shiro', 'misir.wot'],
        'nigerian' => ['nigerian', 'jollof', 'suya', 'egusi', 'pounded.yam', 'fufu', 'ogbono', 'ewedu', 'puff.puff'],
        'south-african' => ['south.african', 'braai', 'boerewors', 'bunny.chow', 'biltong', 'bobotie', 'malva', 'koeksister', 'chakalaka'],
        'west-african' => ['west.african', 'jollof', 'fufu', 'egusi', 'suya', 'waakye', 'groundnut', 'pepper.soup', 'attieke', 'thieboudienne'],
        'kenyan' => ['kenyan', 'nyama.choma', 'ugali', 'sukuma', 'irio', 'chapati', 'mandazi', 'githeri', 'nyama'],

        // ── Caribbean ──────────────────────────────────────────────────────
        'jamaican' => ['jamaican', 'jerk', 'ackee', 'saltfish', 'curry.goat', 'patties', 'escovitch', 'jerk.chicken'],
        'puerto-rican' => ['puerto.rican', 'boricua', 'mofongo', 'arroz.con.gandules', 'lechon', 'tostones', 'pastelon', 'tembleque', 'asopao'],
        'trinidadian' => ['trinidadian', 'trini', 'roti', 'doubles', 'callaloo', 'pelau', 'bake.and.shark', 'pholourie'],
        'haitian' => ['haitian', 'griot', 'legim', 'diri', 'pikliz', 'tasso', 'bouillon', 'legume'],

        // ── Oceanian ───────────────────────────────────────────────────────
        'australian' => ['australian', 'aussie', 'meat.pie', 'barramundi', 'lamington', 'vegemite', 'bush.tucker', 'parma'],
        'new-zealand' => ['new.zealand', 'kiwi', 'hangi', 'kumara', 'pavlova', 'paua', 'pork.belly', 'manuka'],
        'polynesian' => ['polynesian', 'taro', 'breadfruit', 'umu', 'coconut', 'luau', 'kalua', 'ota.ika', 'palusami'],
    ],

    'categories' => [
        'asian' => ['chinese', 'japanese', 'korean', 'thai', 'vietnamese', 'filipino', 'indian', 'malaysian', 'indonesian', 'taiwanese', 'cambodian', 'singaporean', 'nepalese', 'tibetan', 'burmese'],
        'european' => ['italian', 'french', 'spanish', 'greek', 'german', 'british', 'portuguese', 'polish', 'belgian', 'swiss', 'russian'],
        'latin-american' => ['mexican', 'brazilian', 'peruvian', 'argentine', 'colombian', 'cuban', 'venezuelan', 'chilean'],
        'middle-eastern' => ['lebanese', 'turkish', 'persian', 'israeli', 'moroccan', 'egyptian', 'afghan'],
        'american' => ['southern', 'cajun-creole', 'tex-mex', 'bbq', 'new-american', 'hawaiian'],
        'african' => ['ethiopian', 'nigerian', 'south-african', 'west-african', 'kenyan'],
        'caribbean' => ['jamaican', 'puerto-rican', 'trinidadian', 'haitian'],
        'oceanian' => ['australian', 'new-zealand', 'polynesian'],
    ],

];
