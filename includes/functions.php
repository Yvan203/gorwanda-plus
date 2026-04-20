<?php
/**
 * GoRwanda+ Helper Functions
 * File: includes/functions.php
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'db.php';

// ============================================
// LANGUAGE FUNCTIONS - MOVED TO TOP FOR EARLY ACCESS
// ============================================

/**
 * Get current language from session, cookie, or browser
 */
function getCurrentLanguage() {
    // Check session first
    if (isset($_SESSION['language'])) {
        return $_SESSION['language'];
    }
    
    // Check cookie
    if (isset($_COOKIE['user_language'])) {
        $_SESSION['language'] = $_COOKIE['user_language'];
        return $_COOKIE['user_language'];
    }
    
    // Detect from browser
    if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        $browserLang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
        $validLangs = ['en', 'fr', 'rw', 'sw'];
        if (in_array($browserLang, $validLangs)) {
            $_SESSION['language'] = $browserLang;
            return $browserLang;
        }
    }
    
    // Default to English
    return 'en';
}

/**
 * Get current currency from session or cookie
 */
function getCurrentCurrency() {
    // Check session first
    if (isset($_SESSION['currency'])) {
        return $_SESSION['currency'];
    }
    
    // Check cookie
    if (isset($_COOKIE['user_currency'])) {
        $_SESSION['currency'] = $_COOKIE['user_currency'];
        return $_COOKIE['user_currency'];
    }
    
    // Default to RWF
    return 'RWF';
}

// Set language and currency for use throughout the site
$currentLang = getCurrentLanguage();
$currentCurrency = getCurrentCurrency();

// Translations array
$translations = [
    'en' => [
        'hero_stays' => 'Find your perfect stay in Rwanda',
        'hero_cars' => 'Drive your own adventure',
        'hero_attractions' => 'Discover unforgettable experiences',
        'hero_restaurants' => "Explore Rwanda's best restaurants",
        'hero_stats' => '+ listings',
        'stays' => 'stays',
        'cars' => 'cars',
        'experiences' => 'experiences',
        'restaurants' => 'restaurants',
        'avg_rating' => 'average rating',
        'verified' => 'Verified listings',
        'support' => '24/7 support',
        'instant' => 'Instant confirmation',
        'trending' => 'Trending destinations',
        'trending_stays_sub' => 'Most popular places to stay in Rwanda',
        'trending_cars_sub' => 'Convenient locations across Rwanda',
        'trending_attractions_sub' => 'Best places for unforgettable experiences',
        'trending_restaurants_sub' => 'Best places to eat in Rwanda',
        'browse' => 'Browse by',
        'featured' => 'Homes guests love',
        'featured_stays_sub' => 'Discover properties with outstanding reviews',
        'featured_cars_sub' => 'Reliable car hire with best rates',
        'featured_attractions_sub' => 'Activities loved by travellers',
        'featured_restaurants_sub' => 'Top-rated dining experiences',
        'offers' => 'Special offers and deals',
        'offers_stays_sub' => 'Save big on your next stay',
        'offers_cars_sub' => 'Limited time deals on car rentals',
        'offers_attractions_sub' => 'Most booked activities this week',
        'offers_restaurants_sub' => 'Limited time deals at restaurants',
        'see_all' => 'See all',
        'from' => 'from',
        'per_night' => 'per night',
        'per_day' => 'per day',
        'per_person' => 'per person',
        'reviews' => 'reviews',
        'new' => 'New',
        'why_book' => 'Why book with GoRwanda+?',
        'why_book_sub' => "We're here to make your travel experience seamless",
        'secure' => 'Secure booking',
        'secure_desc' => 'Your payment info is encrypted and protected',
        'best_price' => 'Best price guarantee',
        'best_price_desc' => 'Found a better price? We\'ll match it',
        '247_support' => '24/7 support',
        '247_desc' => "We're here to help, day or night",
        'verified_reviews' => 'Verified reviews',
        'verified_desc' => 'Real guests, real experiences',
        'newsletter' => 'Save time, save money!',
        'newsletter_desc' => 'Sign up and we\'ll send the best deals to you',
        'no_spam' => '*No spam, only the best deals',
        'subscribe' => 'Subscribe',
        'list_property' => 'List your property'
    ],
    'fr' => [
        'hero_stays' => 'Trouvez votre séjour idéal au Rwanda',
        'hero_cars' => 'Vivez votre propre aventure',
        'hero_attractions' => 'Découvrez des expériences inoubliables',
        'hero_restaurants' => 'Explorez les meilleurs restaurants du Rwanda',
        'hero_stats' => '+ annonces',
        'stays' => 'hébergements',
        'cars' => 'voitures',
        'experiences' => 'expériences',
        'restaurants' => 'restaurants',
        'avg_rating' => 'note moyenne',
        'verified' => 'Annonces vérifiées',
        'support' => 'Support 24/7',
        'instant' => 'Confirmation instantanée',
        'trending' => 'Destinations tendance',
        'trending_stays_sub' => 'Les hébergements les plus populaires au Rwanda',
        'trending_cars_sub' => 'Lieux pratiques à travers le Rwanda',
        'trending_attractions_sub' => 'Meilleurs endroits pour des expériences inoubliables',
        'trending_restaurants_sub' => 'Meilleurs endroits pour manger au Rwanda',
        'browse' => 'Parcourir par',
        'featured' => 'Logements appréciés des voyageurs',
        'featured_stays_sub' => 'Découvrez des propriétés avec des avis exceptionnels',
        'featured_cars_sub' => 'Location de voitures fiable aux meilleurs tarifs',
        'featured_attractions_sub' => 'Activités préférées des voyageurs',
        'featured_restaurants_sub' => 'Expériences culinaires les mieux notées',
        'offers' => 'Offres spéciales',
        'offers_stays_sub' => 'Économisez sur votre prochain séjour',
        'offers_cars_sub' => 'Offres limitées sur la location de voitures',
        'offers_attractions_sub' => 'Activités les plus réservées cette semaine',
        'offers_restaurants_sub' => 'Offres limitées dans les restaurants',
        'see_all' => 'Voir tout',
        'from' => 'à partir de',
        'per_night' => 'par nuit',
        'per_day' => 'par jour',
        'per_person' => 'par personne',
        'reviews' => 'avis',
        'new' => 'Nouveau',
        'why_book' => 'Pourquoi réserver avec GoRwanda+?',
        'why_book_sub' => 'Nous sommes là pour rendre votre voyage fluide',
        'secure' => 'Réservation sécurisée',
        'secure_desc' => 'Vos informations de paiement sont cryptées',
        'best_price' => 'Meilleur prix garanti',
        'best_price_desc' => 'Vous trouvez moins cher? Nous alignons',
        '247_support' => 'Support 24/7',
        '247_desc' => 'Nous sommes là pour vous aider, jour et nuit',
        'verified_reviews' => 'Avis vérifiés',
        'verified_desc' => 'Vrais voyageurs, vraies expériences',
        'newsletter' => 'Gagnez du temps, économisez!',
        'newsletter_desc' => 'Inscrivez-vous pour recevoir les meilleures offres',
        'no_spam' => '*Pas de spam, seulement les meilleures offres',
        'subscribe' => 'S\'abonner',
        'list_property' => 'Listez votre propriété'
    ],
    'rw' => [
        'hero_stays' => 'Shakira aho uzahera mu Rwanda',
        'hero_cars' => 'Twarana imodoka yawe',
        'hero_attractions' => 'Hitamo ibyishimo utazibagirwa',
        'hero_restaurants' => 'Shakira amaresitora meza mu Rwanda',
        'hero_stats' => '+ amatangazo',
        'stays' => 'aho kugara',
        'cars' => 'imodoka',
        'experiences' => 'ubunararibonye',
        'restaurants' => 'amarestora',
        'avg_rating' => 'ibipimo',
        'verified' => 'Amatangazo yemejwe',
        'support' => 'Ubufasha 24/7',
        'instant' => 'Kwemeza ako kanya',
        'trending' => 'Ahantu hiciwe',
        'trending_stays_sub' => 'Ahantu hiciwe cyane mu Rwanda',
        'trending_cars_sub' => 'Ahantu heza hose mu Rwanda',
        'trending_attractions_sub' => 'Ahantu heza hutanga ubunararibonye butazibagirwa',
        'trending_restaurants_sub' => 'Ahantu heza ho kurya mu Rwanda',
        'browse' => 'Hitamo ukurikije',
        'featured' => 'Aho kugara abagenzi bakunda',
        'featured_stays_sub' => 'Hitamo amazu afite ibitekerezo byiza',
        'featured_cars_sub' => 'Imodoka zizewe ku gihembo cyiza',
        'featured_attractions_sub' => 'Ibikorwa abagenzi bakunda',
        'featured_restaurants_sub' => 'Amaresitora meza',
        'offers' => 'Amasezerano y\'umwihariko',
        'offers_stays_sub' => 'Zigama kuri gahunda yawe itaha',
        'offers_cars_sub' => 'Amasezerano y\'igihe gito ku modoka',
        'offers_attractions_sub' => 'Ibikorwa bikunze iki cyumweru',
        'offers_restaurants_sub' => 'Amasezerano y\'igihe gito mu maresitora',
        'see_all' => 'Reba byose',
        'from' => 'kuva',
        'per_night' => 'ku ijoro',
        'per_day' => 'ku munsi',
        'per_person' => 'ku muntu',
        'reviews' => 'ibitekerezo',
        'new' => 'Gishya',
        'why_book' => 'Kuki wahitamo GoRwanda+?',
        'why_book_sub' => 'Turi hano kugira ngo urugendo rwawe rube urwiza',
        'secure' => 'Kwemeza birinda',
        'secure_desc' => 'Amakuru yawe yishyurwa arindwa',
        'best_price' => 'Igiciro cyiza cyemejwe',
        'best_price_desc' => 'Ubonye igiciro kiri hasi? Tuzagihuza',
        '247_support' => 'Ubufasha 24/7',
        '247_desc' => 'Turi hano kugufasha, ijoro n\'amanywa',
        'verified_reviews' => 'Ibitekerezo byemejwe',
        'verified_desc' => 'Abagenzi nyabo, ibyishimo nyabo',
        'newsletter' => 'Zigama igihe, zigama amafaranga!',
        'newsletter_desc' => 'Iyandikishe kugira ngo twongerere amasezerano meza',
        'no_spam' => '*Nta spam, amasezerano meza gusa',
        'subscribe' => 'Kwiyandikisha',
        'list_property' => 'Tangaza aho kugara'
    ],
    'sw' => [
        'hero_stays' => 'Tafuta malazi bora Rwanda',
        'hero_cars' => 'Endesha gari yako mwenyewe',
        'hero_attractions' => 'Gundua matukio ya kukumbukwa',
        'hero_restaurants' => 'Gundua migahawa bora Rwanda',
        'hero_stats' => '+ matangazo',
        'stays' => 'malazi',
        'cars' => 'magari',
        'experiences' => 'matukio',
        'restaurants' => 'migahawa',
        'avg_rating' => 'wastani wa ukadiriaji',
        'verified' => 'Matangazo yaliyothibitishwa',
        'support' => 'Msaada 24/7',
        'instant' => 'Uthibitisho wa papo hapo',
        'trending' => 'Maeneo maarufu',
        'trending_stays_sub' => 'Maeneo maarufu zaidi nchini Rwanda',
        'trending_cars_sub' => 'Maeneo rahisi kote Rwanda',
        'trending_attractions_sub' => 'Maeneo bora kwa matukio ya kukumbukwa',
        'trending_restaurants_sub' => 'Maeneo bora ya kula nchini Rwanda',
        'browse' => 'Vinjari kwa',
        'featured' => 'Makao wanayopenda wasafiri',
        'featured_stays_sub' => 'Gundua mali zilizo na maoni bora',
        'featured_cars_sub' => 'Ukodishaji wa gari unaotegemeka kwa bei bora',
        'featured_attractions_sub' => 'Shughuli zinazopendwa na wasafiri',
        'featured_restaurants_sub' => 'Uzoefu bora wa chakula',
        'offers' => 'Ofa maalum',
        'offers_stays_sub' => 'Okoa kwenye malazi yako yajayo',
        'offers_cars_sub' => 'Ofa za muda mfupi kwa ukodishaji wa magari',
        'offers_attractions_sub' => 'Shughuli zilizohifadhiwa zaidi wiki hii',
        'offers_restaurants_sub' => 'Ofa za muda mfuri kwenye migahawa',
        'see_all' => 'Angalia zote',
        'from' => 'kutoka',
        'per_night' => 'kwa usiku',
        'per_day' => 'kwa siku',
        'per_person' => 'kwa mtu',
        'reviews' => 'maoni',
        'new' => 'Mpya',
        'why_book' => 'Kwa nini uweke nafasi na GoRwanda+?',
        'why_book_sub' => 'Tupo hapa kufanya safari yako iwe laini',
        'secure' => 'Uhifadhi salama',
        'secure_desc' => 'Taarifa zako za malapo zinalindwa kwa usimbaji fiche',
        'best_price' => 'Dhamana ya bei bora',
        'best_price_desc' => 'Umepata bei nafuu? Tutalingana',
        '247_support' => 'Msaada 24/7',
        '247_desc' => 'Tupo hapa kukusaidia, mchana na usiku',
        'verified_reviews' => 'Maoni yaliyothibitishwa',
        'verified_desc' => 'Wageni halisi, uzoefu halisi',
        'newsletter' => 'Okoa muda, okoa pesa!',
        'newsletter_desc' => 'Jisajili ili tukutumie ofa bora',
        'no_spam' => '*Hakuna spam, ofa bora tu',
        'subscribe' => 'Jisajili',
        'list_property' => 'Tangaza mali yako'
    ]
];

// Get translations for current language
$t = $translations[$currentLang];

// ============================================
// CURRENCY CONVERSION WITH UPDATABLE RATES
// ============================================

/**
 * Get current exchange rates (can be cached or fetched from API)
 */
function getExchangeRates() {
    // Base currency is RWF
    $rates = [
        'RWF' => 1,
        'USD' => 0.00077,
        'EUR' => 0.00071,
        'GBP' => 0.00062,
        'KES' => 0.10,
        'UGX' => 2.85,
        'TZS' => 2.00
    ];
    
    // Check if we have cached rates in database
    $db = getDB();
    $stmt = $db->query("SELECT * FROM exchange_rates WHERE date = CURDATE()");
    $cachedRates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($cachedRates)) {
        foreach ($cachedRates as $rate) {
            $rates[$rate['currency_code']] = $rate['rate_to_rwf'];
        }
    }
    
    return $rates;
}

/**
 * Get currency symbol
 */
function getCurrencySymbol($currency = null) {
    if ($currency === null) {
        $currency = getCurrentCurrency();
    }
    
    $symbols = [
        'RWF' => 'FRw',
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'KES' => 'KSh',
        'UGX' => 'USh',
        'TZS' => 'TSh'
    ];
    
    return $symbols[$currency] ?? 'FRw';
}

/**
 * Format price with proper currency conversion
 */
function formatPrice($amount, $currency = null, $decimal = false) {
    if ($currency === null) {
        $currency = getCurrentCurrency();
    }
    
    $rates = getExchangeRates();
    $rate = $rates[$currency] ?? 1;
    $symbol = getCurrencySymbol($currency);
    
    $converted = $amount * $rate;
    
    // Format based on currency type
    if ($currency === 'RWF' || in_array($currency, ['KES', 'UGX', 'TZS'])) {
        return $symbol . ' ' . number_format($converted, 0);
    } else {
        return $symbol . ' ' . number_format($converted, 2);
    }
}

// ============================================
// SECURITY FUNCTIONS
// ============================================

function sanitize($data) {
    if ($data === null || $data === '') {
        return '';
    }
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

function generateToken() {
    return bin2hex(random_bytes(32));
}

function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = generateToken();
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// ============================================
// FORMATTING FUNCTIONS
// ============================================

function formatDate($date, $format = 'd M Y') {
    return date($format, strtotime($date));
}

function formatRating($rating) {
    $fullStars = floor($rating / 2);
    $halfStar = ($rating % 2) >= 1 ? 1 : 0;
    $emptyStars = 5 - $fullStars - $halfStar;
    
    $html = '';
    for ($i = 0; $i < $fullStars; $i++) $html .= '<i class="fas fa-star text-warning"></i>';
    if ($halfStar) $html .= '<i class="fas fa-star-half-alt text-warning"></i>';
    for ($i = 0; $i < $emptyStars; $i++) $html .= '<i class="far fa-star text-warning"></i>';
    
    return $html;
}

function getReviewLabel($rating) {
    if ($rating >= 9) return ['Exceptional', 'bg-success'];
    if ($rating >= 8) return ['Excellent', 'bg-success'];
    if ($rating >= 7) return ['Very Good', 'bg-success'];
    if ($rating >= 6) return ['Good', 'bg-info'];
    if ($rating >= 5) return ['Pleasant', 'bg-info'];
    if ($rating >= 4) return ['Fair', 'bg-warning'];
    return ['Review Score', 'bg-secondary'];
}

// ============================================
// BOOKING REFERENCE GENERATOR
// ============================================

function generateBookingRef() {
    $prefix = 'GRW';
    $year = date('Y');
    $random = strtoupper(substr(uniqid(), -5));
    return "{$prefix}-{$year}-{$random}";
}

// ============================================
// USER AUTHENTICATION
// ============================================

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin';
}

function isBusinessOwner() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'business_owner';
}

function requireLogin() {
    if (!isLoggedIn()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        header('Location: /gorwanda-plus/login.php');
        exit;
    }
}

function getCurrentUser() {
    if (!isLoggedIn()) return null;
    
    $stmt = getDB()->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

// ============================================
// SEARCH & AVAILABILITY FUNCTIONS
// ============================================

function searchStays($location, $checkIn, $checkOut, $guests = 2, $limit = 20) {
    $db = getDB();
    
    $sql = "SELECT s.*, l.name as location_name,
            (SELECT MIN(base_price) FROM stay_rooms WHERE stay_id = s.stay_id AND is_active = 1) as min_price,
            (SELECT MAX(base_price) FROM stay_rooms WHERE stay_id = s.stay_id AND is_active = 1) as max_price
            FROM stays s
            LEFT JOIN locations l ON s.location_id = l.location_id
            WHERE s.is_active = 1 AND s.is_verified = 1";
    
    $params = [];
    
    if ($location) {
        $sql .= " AND (s.stay_name LIKE ? OR l.name LIKE ? OR s.address LIKE ?)";
        $like = "%{$location}%";
        $params = array_merge($params, [$like, $like, $like]);
    }
    
    if ($checkIn && $checkOut) {
        $nights = (strtotime($checkOut) - strtotime($checkIn)) / 86400;
        $sql .= " AND s.stay_id IN (
            SELECT sr.stay_id FROM stay_rooms sr
            WHERE sr.is_active = 1
            AND sr.room_id NOT IN (
                SELECT sa.room_id FROM stay_availability sa
                WHERE sa.date BETWEEN ? AND DATE_SUB(?, INTERVAL 1 DAY)
                AND (sa.rooms_available < 1 OR sa.is_blocked = 1)
            )
            GROUP BY sr.stay_id
            HAVING COUNT(DISTINCT sr.room_id) > 0
        )";
        $params = array_merge($params, [$checkIn, $checkOut]);
    }
    
    $sql .= " ORDER BY s.avg_rating DESC, s.review_count DESC LIMIT " . (int)$limit;
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function searchCars($location, $pickupDate, $returnDate, $limit = 20) {
    $db = getDB();
    
    $sql = "SELECT cf.*, cr.company_name, cr.pickup_locations, cr.dropoff_locations,
            l.name as location_name
            FROM car_fleet cf
            JOIN car_rentals cr ON cf.rental_id = cr.rental_id
            LEFT JOIN locations l ON cr.location_id = l.location_id
            WHERE cf.is_active = 1 AND cr.is_active = 1 AND cr.is_verified = 1";
    
    $params = [];
    
    if ($location) {
        $sql .= " AND (cr.company_name LIKE ? OR l.name LIKE ? OR JSON_CONTAINS(cr.pickup_locations, JSON_QUOTE(?)))";
        $like = "%{$location}%";
        $params = array_merge($params, [$like, $like, $location]);
    }
    
    if ($pickupDate && $returnDate) {
        $sql .= " AND cf.car_id NOT IN (
            SELECT ca.car_id FROM car_availability ca
            WHERE ca.date BETWEEN ? AND DATE_SUB(?, INTERVAL 1 DAY)
            AND (ca.quantity_available < 1 OR ca.is_blocked = 1)
        )";
        $params = array_merge($params, [$pickupDate, $returnDate]);
    }
    
    $sql .= " ORDER BY cf.daily_rate ASC LIMIT " . (int)$limit;
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function searchAttractions($location, $date, $limit = 20) {
    $db = getDB();
    
    $sql = "SELECT a.*, c.name as category_name, c.icon as category_icon,
            l.name as location_name,
            (SELECT MIN(base_price) FROM attraction_tiers WHERE attraction_id = a.attraction_id AND is_active = 1) as min_price
            FROM attractions a
            LEFT JOIN categories c ON a.category_id = c.category_id
            LEFT JOIN locations l ON a.location_id = l.location_id
            WHERE a.is_active = 1 AND a.is_verified = 1";
    
    $params = [];
    
    if ($location) {
        $sql .= " AND (a.attraction_name LIKE ? OR l.name LIKE ?)";
        $like = "%{$location}%";
        $params = array_merge($params, [$like, $like]);
    }
    
    if ($date) {
        $sql .= " AND a.attraction_id IN (
            SELECT at.attraction_id FROM attraction_tiers att
            JOIN attraction_availability aa ON att.tier_id = aa.tier_id
            WHERE aa.date = ? AND (aa.max_bookings - aa.bookings_made) > 0 AND aa.is_blocked = 0
        )";
        $params[] = $date;
    }
    
    $sql .= " ORDER BY a.avg_rating DESC LIMIT " . (int)$limit;
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// ============================================
// IMAGE HELPERS
// ============================================

function getImageUrl($image, $type = 'stay', $size = 'medium') {
    // If no image, return placeholder
    if (!$image || $image === 'null' || $image === '') {
        $placeholders = [
            'stay' => 'https://images.unsplash.com/photo-1566073771259-6a8506099945?w=400&h=300&fit=crop',
            'car' => 'https://images.unsplash.com/photo-1533473359331-0135ef1b58bf?w=400&h=300&fit=crop',
            'attraction' => 'https://images.unsplash.com/photo-1523802004999-6c49b5a3f9b7?w=400&h=300&fit=crop',
            'restaurant' => 'https://images.unsplash.com/photo-1517248135467-4c7edcad34c4?w=400&h=300&fit=crop',
        ];
        return $placeholders[$type] ?? $placeholders['stay'];
    }
    
    // If it's a full URL
    if (strpos($image, 'http') === 0) {
        return $image;
    }
    
    // Define paths
    $paths = [
        'stay' => '/gorwanda-plus/assets/images/stays/',
        'car' => '/gorwanda-plus/assets/images/cars/',
        'attraction' => '/gorwanda-plus/assets/images/attractions/',
        'attraction_gallery' => '/gorwanda-plus/assets/images/attractions/gallery/',
        'restaurant' => '/gorwanda-plus/assets/images/restaurants/',
        'profile' => '/gorwanda-plus/assets/uploads/profiles/',
    ];
    
    // Check main folder first
    $basePath = $paths[$type] ?? $paths['stay'];
    $fullPath = $_SERVER['DOCUMENT_ROOT'] . $basePath . $image;
    
    if (file_exists($fullPath)) {
        return $basePath . $image;
    }
    
    // For attractions, also check gallery folder
    if ($type === 'attraction') {
        $galleryPath = $paths['attraction_gallery'] . $image;
        $fullGalleryPath = $_SERVER['DOCUMENT_ROOT'] . $galleryPath;
        if (file_exists($fullGalleryPath)) {
            return $galleryPath;
        }
    }
    
    // File doesn't exist - return placeholder
    $placeholders = [
        'stay' => 'https://images.unsplash.com/photo-1566073771259-6a8506099945?w=400&h=300&fit=crop',
        'car' => 'https://images.unsplash.com/photo-1533473359331-0135ef1b58bf?w=400&h=300&fit=crop',
        'attraction' => 'https://images.unsplash.com/photo-1523802004999-6c49b5a3f9b7?w=400&h=300&fit=crop',
        'restaurant' => 'https://images.unsplash.com/photo-1517248135467-4c7edcad34c4?w=400&h=300&fit=crop',
    ];
    return $placeholders[$type] ?? $placeholders['stay'];
}

// ============================================
// NOTIFICATION / FLASH MESSAGES
// ============================================

function setFlash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function showFlash() {
    $flash = getFlash();
    if ($flash) {
        $alertClass = [
            'success' => 'alert-success',
            'error' => 'alert-danger',
            'warning' => 'alert-warning',
            'info' => 'alert-info'
        ][$flash['type']] ?? 'alert-info';
        
        echo '<div class="alert ' . $alertClass . ' alert-dismissible fade show" role="alert">';
        echo sanitize($flash['message']);
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }
}

// ============================================
// PASSWORD/SECURITY HELPERS
// ============================================

function generateSecurePassword($length = 10) {
    return bin2hex(random_bytes($length / 2));
}

function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// ============================================
// TIME AGO FUNCTION
// ============================================

function timeAgo($timestamp) {
    if (!$timestamp) return 'Just now';
    
    $time_ago = strtotime($timestamp);
    $current_time = time();
    $time_difference = $current_time - $time_ago;
    $seconds = $time_difference;
    
    $minutes = round($seconds / 60);
    $hours = round($seconds / 3600);
    $days = round($seconds / 86400);
    $weeks = round($seconds / 604800);
    $months = round($seconds / 2629440);
    $years = round($seconds / 31553280);
    
    if ($seconds <= 60) {
        return "Just now";
    } else if ($minutes <= 60) {
        return ($minutes == 1) ? "1 minute ago" : "$minutes minutes ago";
    } else if ($hours <= 24) {
        return ($hours == 1) ? "1 hour ago" : "$hours hours ago";
    } else if ($days <= 7) {
        return ($days == 1) ? "yesterday" : "$days days ago";
    } else if ($weeks <= 4.3) {
        return ($weeks == 1) ? "1 week ago" : "$weeks weeks ago";
    } else if ($months <= 12) {
        return ($months == 1) ? "1 month ago" : "$months months ago";
    } else {
        return ($years == 1) ? "1 year ago" : "$years years ago";
    }
}

// ============================================
// ROLE-BASED REDIRECTION
// ============================================

function getDashboardUrl($user) {
    $userType = $user['user_type'] ?? 'tourist';
    $businessTypes = json_decode($user['business_type'] ?? '[]', true);
    
    error_log("User Type: " . $userType);
    error_log("Business Types: " . print_r($businessTypes, true));
    
    switch($userType) {
        case 'admin':
            return '/gorwanda-plus/admin/dashboard.php';
            
        case 'business_owner':
            if (empty($businessTypes)) {
                return '/gorwanda-plus/partner/onboarding.php';
            }
            
            if (count($businessTypes) > 1) {
                return '/gorwanda-plus/partner/dashboard.php';
            }
            
            $firstType = $businessTypes[0];
            switch($firstType) {
                case 'stay':
                    return '/gorwanda-plus/partner/stays/dashboard.php';
                case 'car_rental':
                    return '/gorwanda-plus/partner/cars/dashboard.php';
                case 'attraction':
                    return '/gorwanda-plus/partner/experiences/dashboard.php';
                default:
                    return '/gorwanda-plus/partner/dashboard.php';
            }
            
        case 'tourist':
        default:
            return '/gorwanda-plus/';
    }
}

function isPartnerProfileComplete($userId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT phone, business_type FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    $businessTypes = json_decode($user['business_type'] ?? '[]', true);
    return !empty($businessTypes) && !empty($user['phone']);
}

function getPartnerOnboardingProgress($userId) {
    $db = getDB();
    $progress = ['complete' => 0, 'next_step' => 'basic_info', 'steps' => []];
    
    $stmt = $db->prepare("SELECT phone, business_type FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    $businessTypes = json_decode($user['business_type'] ?? '[]', true);
    $steps = [
        'basic_info' => ['label' => 'Basic Information', 'complete' => !empty($businessTypes) && !empty($user['phone'])],
        'first_property' => ['label' => 'Add First Property', 'complete' => false],
        'verification' => ['label' => 'Verification', 'complete' => false]
    ];
    
    if (in_array('stay', $businessTypes)) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM stays WHERE owner_id = ?");
        $stmt->execute([$userId]);
        $steps['first_property']['complete'] = $stmt->fetchColumn() > 0;
    } elseif (in_array('car_rental', $businessTypes)) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM car_rentals WHERE owner_id = ?");
        $stmt->execute([$userId]);
        $steps['first_property']['complete'] = $stmt->fetchColumn() > 0;
    } elseif (in_array('attraction', $businessTypes)) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM attractions WHERE owner_id = ?");
        $stmt->execute([$userId]);
        $steps['first_property']['complete'] = $stmt->fetchColumn() > 0;
    }
    
    $completed = 0;
    foreach ($steps as $key => $step) {
        if ($step['complete']) {
            $completed++;
        } else {
            $progress['next_step'] = $key;
            break;
        }
    }
    
    $progress['complete'] = round(($completed / count($steps)) * 100);
    $progress['steps'] = $steps;
    
    return $progress;
}

// ============================================
// TIMEZONE
// ============================================
date_default_timezone_set('Africa/Kigali');

// ============================================
// EMAIL FUNCTIONS
// ============================================
function sendReservationConfirmation($email, $name, $restaurant, $code, $date, $time, $guests) {
    $subject = "Reservation Confirmation - " . $restaurant['restaurant_name'];
    
    $message = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #003b95; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; background: #f5f5f5; }
            .code { font-size: 24px; font-weight: bold; color: #0066ff; text-align: center; padding: 20px; background: white; }
            .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>Reservation Confirmed!</h2>
            </div>
            <div class='content'>
                <p>Dear " . $name . ",</p>
                <p>Your table reservation has been confirmed. Here are the details:</p>
                
                <div class='code'>" . $code . "</div>
                
                <table style='width: 100%%; margin: 20px 0;'>
                    <tr><td><strong>Restaurant:</strong></td><td>" . $restaurant['restaurant_name'] . "</td></tr>
                    <tr><td><strong>Date:</strong></td><td>" . date('l, F j, Y', strtotime($date)) . "</td></tr>
                    <tr><td><strong>Time:</strong></td><td>" . date('g:i A', strtotime($time)) . "</td></tr>
                    <tr><td><strong>Guests:</strong></td><td>" . $guests . " people</td></tr>
                </table>
                
                <p>If you need to cancel or modify your reservation, please contact the restaurant directly.</p>
                <p>Thank you for choosing GoRwanda+!</p>
            </div>
            <div class='footer'>
                <p>&copy; " . date('Y') . " GoRwanda+. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $logFile = __DIR__ . '/../logs/emails.log';
    $logEntry = date('Y-m-d H:i:s') . " - Reservation email to $email for code $code\n";
    
    if (!file_exists(dirname($logFile))) {
        mkdir(dirname($logFile), 0777, true);
    }
    
    file_put_contents($logFile, $logEntry, FILE_APPEND);
    
    return true;
}

// ============================================
// ADMIN FUNCTION TO UPDATE EXCHANGE RATES
// ============================================

function updateExchangeRates() {
    // For now, just return true
    // In production, implement API call
    return true;
}



?>