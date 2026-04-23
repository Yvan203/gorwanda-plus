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
    $validLangs = ['en', 'fr', 'rw', 'sw'];

    // Check session first
    if (isset($_SESSION['language']) && in_array($_SESSION['language'], $validLangs, true)) {
        return $_SESSION['language'];
    }
    
    // Check cookie
    if (isset($_COOKIE['user_language']) && in_array($_COOKIE['user_language'], $validLangs, true)) {
        $_SESSION['language'] = $_COOKIE['user_language'];
        return $_COOKIE['user_language'];
    }
    
    // Detect from browser
    if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        $browserLang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
        if (in_array($browserLang, $validLangs, true)) {
            $_SESSION['language'] = $browserLang;
            return $browserLang;
        }
    }
    
    // Default to English
    $_SESSION['language'] = 'en';
    return 'en';
}

function setCurrentLanguage($language) {
    $validLangs = ['en', 'fr', 'rw', 'sw'];
    $language = in_array($language, $validLangs, true) ? $language : 'en';

    $_SESSION['language'] = $language;
    $_COOKIE['user_language'] = $language;

    return setcookie('user_language', $language, [
        'expires' => time() + (86400 * 30),
        'path' => '/',
        'domain' => '',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

/**
 * Get current currency from session or cookie
 */
function getCurrentCurrency() {
    $validCurrencies = ['RWF', 'USD', 'EUR', 'GBP', 'KES', 'UGX', 'TZS'];

    // Check session first
    if (isset($_SESSION['currency']) && in_array($_SESSION['currency'], $validCurrencies, true)) {
        return $_SESSION['currency'];
    }
    
    // Check cookie
    if (isset($_COOKIE['user_currency']) && in_array($_COOKIE['user_currency'], $validCurrencies, true)) {
        $_SESSION['currency'] = $_COOKIE['user_currency'];
        return $_COOKIE['user_currency'];
    }
    
    // Default to RWF
    $_SESSION['currency'] = 'RWF';
    return 'RWF';
}

function setCurrentCurrency($currency) {
    $validCurrencies = ['RWF', 'USD', 'EUR', 'GBP', 'KES', 'UGX', 'TZS'];
    $currency = in_array($currency, $validCurrencies, true) ? $currency : 'RWF';

    $_SESSION['currency'] = $currency;
    $_COOKIE['user_currency'] = $currency;

    return setcookie('user_currency', $currency, [
        'expires' => time() + (86400 * 30),
        'path' => '/',
        'domain' => '',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

function getCurrentRequestPath() {
    if (!empty($_SERVER['REQUEST_URI'])) {
        return $_SERVER['REQUEST_URI'];
    }

    if (!empty($_SERVER['PHP_SELF'])) {
        return $_SERVER['PHP_SELF'];
    }

    return '/gorwanda-plus/';
}

function sanitizeLocalRedirect($redirect, $default = '/gorwanda-plus/') {
    if (!is_string($redirect) || $redirect === '') {
        return $default;
    }

    $redirect = str_replace(["\r", "\n"], '', $redirect);

    if (preg_match('#^https?://#i', $redirect)) {
        $path = parse_url($redirect, PHP_URL_PATH) ?: '';
        $query = parse_url($redirect, PHP_URL_QUERY);
        $redirect = $path . ($query ? '?' . $query : '');
    }

    if ($redirect === '' || $redirect[0] !== '/') {
        return $default;
    }

    return $redirect;
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
        'hero_title' => "Discover Rwanda's best stays, cars & experiences",
        'hero_subtitle_main' => 'From gorilla trekking to luxury lodges, find your perfect Rwandan adventure',
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
        'popular_destinations_title' => 'Popular destinations in Rwanda',
        'popular_destinations_sub' => 'Most searched locations by travelers',
        'browse_all_stays' => 'Browse all stays',
        'browse_all_cars' => 'Browse all cars',
        'browse_all_experiences' => 'Browse all experiences',
        'featured_cars_title' => 'Reliable car rentals',
        'featured_cars_sub' => 'Reliable car hire with best rates',
        'featured_experiences_title' => 'Unforgettable experiences',
        'featured_experiences_sub' => 'Top-rated activities loved by travelers',
        'no_stays_available' => 'No stays available at the moment',
        'no_cars_available' => 'No cars available at the moment',
        'no_experiences_available' => 'No experiences available at the moment',
        'listings_word' => 'listings',
        'car_rental' => 'Car Rental',
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
        'hero_title' => 'Decouvrez les meilleurs hebergements, voitures et experiences du Rwanda',
        'hero_subtitle_main' => 'Du trekking des gorilles aux lodges de luxe, trouvez votre aventure ideale au Rwanda',
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
        'popular_destinations_title' => 'Destinations populaires au Rwanda',
        'popular_destinations_sub' => 'Lieux les plus recherches par les voyageurs',
        'browse_all_stays' => 'Voir tous les hebergements',
        'browse_all_cars' => 'Voir toutes les voitures',
        'browse_all_experiences' => 'Voir toutes les experiences',
        'featured_cars_title' => 'Locations de voitures fiables',
        'featured_cars_sub' => 'Location de voitures fiable aux meilleurs tarifs',
        'featured_experiences_title' => 'Experiences inoubliables',
        'featured_experiences_sub' => 'Activites les mieux notees et aimees des voyageurs',
        'no_stays_available' => 'Aucun hebergement disponible pour le moment',
        'no_cars_available' => 'Aucune voiture disponible pour le moment',
        'no_experiences_available' => 'Aucune experience disponible pour le moment',
        'listings_word' => 'annonces',
        'car_rental' => 'Location de voiture',
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
        'hero_title' => 'Menya amacumbi, imodoka n ibikorwa byiza byo mu Rwanda',
        'hero_subtitle_main' => 'Kuva ku gusura ingagi kugera kuri lodges nziza, shaka urugendo rwawe rwiza mu Rwanda',
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
        'popular_destinations_title' => 'Ahantu hakunzwe mu Rwanda',
        'popular_destinations_sub' => 'Ahantu hishakishwa cyane n abagenzi',
        'browse_all_stays' => 'Reba amacumbi yose',
        'browse_all_cars' => 'Reba imodoka zose',
        'browse_all_experiences' => 'Reba ibikorwa byose',
        'featured_cars_title' => 'Imodoka zizewe zo gukodesha',
        'featured_cars_sub' => 'Imodoka zizewe ku gihembo cyiza',
        'featured_experiences_title' => 'Ibyiza bitazibagirana',
        'featured_experiences_sub' => 'Ibikorwa byakunzwe kandi bifite amanota menshi',
        'no_stays_available' => 'Nta macumbi ahari ubu',
        'no_cars_available' => 'Nta modoka zihari ubu',
        'no_experiences_available' => 'Nta bikorwa bihari ubu',
        'listings_word' => 'amatangazo',
        'car_rental' => 'Gukodesha imodoka',
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
        'hero_title' => 'Gundua malazi, magari na matukio bora ya Rwanda',
        'hero_subtitle_main' => 'Kuanzia kutembelea sokwe hadi lodge za kifahari, pata safari yako bora ya Rwanda',
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
        'popular_destinations_title' => 'Maeneo maarufu nchini Rwanda',
        'popular_destinations_sub' => 'Maeneo yanayotafutwa zaidi na wasafiri',
        'browse_all_stays' => 'Tazama malazi yote',
        'browse_all_cars' => 'Tazama magari yote',
        'browse_all_experiences' => 'Tazama matukio yote',
        'featured_cars_title' => 'Ukodishaji wa magari wa kuaminika',
        'featured_cars_sub' => 'Ukodishaji wa gari unaotegemeka kwa bei bora',
        'featured_experiences_title' => 'Matukio yasiyosahaulika',
        'featured_experiences_sub' => 'Shughuli zilizopewa alama nzuri na kupendwa na wasafiri',
        'no_stays_available' => 'Hakuna malazi yanayopatikana kwa sasa',
        'no_cars_available' => 'Hakuna magari yanayopatikana kwa sasa',
        'no_experiences_available' => 'Hakuna matukio yanayopatikana kwa sasa',
        'listings_word' => 'matangazo',
        'car_rental' => 'Ukodishaji wa gari',
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

$translationExtras = [
    'en' => [
        'select_currency' => 'Select your currency',
        'select_language' => 'Select your language',
        'help_center' => 'Help Center',
        'contact_us' => 'Contact us',
        'safety_information' => 'Safety information',
        'cancellation_options' => 'Cancellation options',
        'faq' => 'FAQ',
        'discover' => 'Discover',
        'about_gorwanda' => 'About GoRwanda+',
        'partner_program' => 'Partner program',
        'destinations' => 'Destinations',
        'travel_blog' => 'Travel blog',
        'rwanda_spotlight' => 'Rwanda Spotlight',
        'legal' => 'Legal',
        'privacy_policy' => 'Privacy policy',
        'terms_service' => 'Terms of service',
        'cookie_policy' => 'Cookie policy',
        'accessibility' => 'Accessibility',
        'get_app' => 'Get the app',
        'book_on_the_go' => 'Book on the go with exclusive mobile deals.',
        'we_accept' => 'We accept',
        'proud_partners' => 'Proud partners of East African tourism',
        'eac_partner' => 'EAC Partner',
        'sitemap' => 'Sitemap',
        'privacy' => 'Privacy',
        'terms' => 'Terms',
        'all_rights_reserved' => 'All rights reserved.',
        'made_in_rwanda' => 'Made with in Rwanda',
        'register' => 'Register',
        'sign_in' => 'Sign in',
        'sign_out' => 'Sign out',
        'profile' => 'Profile',
        'bookings' => 'Bookings',
        'wishlist' => 'Wishlist',
        'partner_dashboard' => 'Partner dashboard',
        'list_property' => 'List your property',
        'where_going' => 'Where are you going?',
        'checkin' => 'Check-in',
        'checkout' => 'Check-out',
        'adult_one' => 'adult',
        'adult_many' => 'adults',
        'pickup_location' => 'Pick-up location',
        'pickup_date' => 'Pick-up date',
        'return_date' => 'Return date',
        'date' => 'Date',
        'person_one' => 'person',
        'person_many' => 'people',
        'restaurant_location' => 'Restaurant name or location',
        'search' => 'Search',
        'special_offers' => 'Special offers and deals',
        'offers_stays_sub' => 'Save big on your next stay',
        'view_all_deals' => 'View all deals',
        'price_range' => 'Price range',
        'min' => 'Min',
        'max' => 'Max',
        'property_type' => 'Property type',
        'support_title' => 'Support',
        'kigali' => 'Kigali',
        'musanze_volcanoes' => 'Musanze (Volcanoes)',
        'nyungwe_forest' => 'Nyungwe Forest',
        'akagera_national_park' => 'Akagera National Park',
        'lake_kivu' => 'Lake Kivu',
        'thank_you_subscribe' => 'Thank you for subscribing! You will receive the best deals.',
        'enter_valid_email' => 'Please enter a valid email address.',
        'app_coming_soon' => 'Our mobile app will be released in Q4 2026! Stay tuned for exclusive deals.',
        'scroll_to_top' => 'Scroll to top',
        'just_now' => 'Just now',
        'minute_ago' => '1 minute ago',
        'minutes_ago' => ':count minutes ago',
        'hour_ago' => '1 hour ago',
        'hours_ago' => ':count hours ago',
        'days_ago' => ':count days ago',
        'yesterday' => 'yesterday',
        'week_ago' => '1 week ago',
        'weeks_ago' => ':count weeks ago',
        'month_ago' => '1 month ago',
        'months_ago' => ':count months ago',
        'year_ago' => '1 year ago',
        'years_ago' => ':count years ago',
        'new_label' => 'New',
        'exceptional' => 'Exceptional',
        'excellent' => 'Excellent',
        'very_good' => 'Very Good',
        'good' => 'Good',
        'pleasant' => 'Pleasant',
        'fair' => 'Fair',
        'review_score' => 'Review Score',
    ],
    'fr' => [
        'select_currency' => 'Choisissez votre devise',
        'select_language' => 'Choisissez votre langue',
        'help_center' => 'Centre d aide',
        'contact_us' => 'Contactez-nous',
        'safety_information' => 'Informations de securite',
        'cancellation_options' => 'Options d annulation',
        'faq' => 'FAQ',
        'discover' => 'Decouvrir',
        'about_gorwanda' => 'A propos de GoRwanda+',
        'partner_program' => 'Programme partenaire',
        'destinations' => 'Destinations',
        'travel_blog' => 'Blog voyage',
        'rwanda_spotlight' => 'A la une du Rwanda',
        'legal' => 'Mentions legales',
        'privacy_policy' => 'Politique de confidentialite',
        'terms_service' => 'Conditions d utilisation',
        'cookie_policy' => 'Politique des cookies',
        'accessibility' => 'Accessibilite',
        'get_app' => 'Obtenez l application',
        'book_on_the_go' => 'Reservez partout avec des offres mobiles exclusives.',
        'we_accept' => 'Nous acceptons',
        'proud_partners' => 'Fiers partenaires du tourisme est-africain',
        'eac_partner' => 'Partenaire CAE',
        'sitemap' => 'Plan du site',
        'privacy' => 'Confidentialite',
        'terms' => 'Conditions',
        'all_rights_reserved' => 'Tous droits reserves.',
        'made_in_rwanda' => 'Fait avec au Rwanda',
        'register' => 'S inscrire',
        'sign_in' => 'Se connecter',
        'sign_out' => 'Se deconnecter',
        'profile' => 'Profil',
        'bookings' => 'Reservations',
        'wishlist' => 'Favoris',
        'partner_dashboard' => 'Tableau partenaire',
        'list_property' => 'Lister votre bien',
        'where_going' => 'Ou allez-vous ?',
        'checkin' => 'Arrivee',
        'checkout' => 'Depart',
        'adult_one' => 'adulte',
        'adult_many' => 'adultes',
        'pickup_location' => 'Lieu de prise en charge',
        'pickup_date' => 'Date de prise en charge',
        'return_date' => 'Date de retour',
        'date' => 'Date',
        'person_one' => 'personne',
        'person_many' => 'personnes',
        'restaurant_location' => 'Nom du restaurant ou lieu',
        'search' => 'Rechercher',
        'special_offers' => 'Offres speciales',
        'offers_stays_sub' => 'Economisez sur votre prochain sejour',
        'view_all_deals' => 'Voir toutes les offres',
        'price_range' => 'Fourchette de prix',
        'min' => 'Min',
        'max' => 'Max',
        'property_type' => 'Type de logement',
        'support_title' => 'Assistance',
        'kigali' => 'Kigali',
        'musanze_volcanoes' => 'Musanze (Volcans)',
        'nyungwe_forest' => 'Foret de Nyungwe',
        'akagera_national_park' => 'Parc national de l Akagera',
        'lake_kivu' => 'Lac Kivu',
        'thank_you_subscribe' => 'Merci pour votre inscription ! Vous recevrez les meilleures offres.',
        'enter_valid_email' => 'Veuillez entrer une adresse e-mail valide.',
        'app_coming_soon' => 'Notre application mobile arrivera au quatrieme trimestre 2026 !',
        'scroll_to_top' => 'Retour en haut',
        'just_now' => 'A l instant',
        'minute_ago' => 'il y a 1 minute',
        'minutes_ago' => 'il y a :count minutes',
        'hour_ago' => 'il y a 1 heure',
        'hours_ago' => 'il y a :count heures',
        'days_ago' => 'il y a :count jours',
        'yesterday' => 'hier',
        'week_ago' => 'il y a 1 semaine',
        'weeks_ago' => 'il y a :count semaines',
        'month_ago' => 'il y a 1 mois',
        'months_ago' => 'il y a :count mois',
        'year_ago' => 'il y a 1 an',
        'years_ago' => 'il y a :count ans',
        'new_label' => 'Nouveau',
        'exceptional' => 'Exceptionnel',
        'excellent' => 'Excellent',
        'very_good' => 'Tres bien',
        'good' => 'Bon',
        'pleasant' => 'Agreable',
        'fair' => 'Moyen',
        'review_score' => 'Note',
    ],
    'rw' => [
        'select_currency' => 'Hitamo ifaranga',
        'select_language' => 'Hitamo ururimi',
        'help_center' => 'Ubufasha',
        'contact_us' => 'Tuvugishe',
        'safety_information' => 'Amakuru y umutekano',
        'cancellation_options' => 'Uburyo bwo guhagarika',
        'faq' => 'Ibibazo bikunze kubazwa',
        'discover' => 'Shakisha',
        'about_gorwanda' => 'Ibyerekeye GoRwanda+',
        'partner_program' => 'Gahunda y abafatanyabikorwa',
        'destinations' => 'Aho ujya',
        'travel_blog' => 'Inkuru z urugendo',
        'rwanda_spotlight' => 'Ibidasanzwe by u Rwanda',
        'legal' => 'Amategeko',
        'privacy_policy' => 'Politiki y ibanga',
        'terms_service' => 'Amabwiriza y ikoreshwa',
        'cookie_policy' => 'Politiki ya cookies',
        'accessibility' => 'Ubwisanzure bwo gukoresha',
        'get_app' => 'Bona app',
        'book_on_the_go' => 'Bika aho uri hose ukoresheje amahirwe ya app.',
        'we_accept' => 'Twakira',
        'proud_partners' => 'Abafatanyabikorwa b ubukerarugendo bwa Afurika y Iburasirazuba',
        'eac_partner' => 'Umufatanyabikorwa wa EAC',
        'sitemap' => 'Ikarita y urubuga',
        'privacy' => 'Ibanga',
        'terms' => 'Amabwiriza',
        'all_rights_reserved' => 'Uburenganzira bwose bwabitswe.',
        'made_in_rwanda' => 'Byakozwe mu Rwanda',
        'register' => 'Iyandikishe',
        'sign_in' => 'Injira',
        'sign_out' => 'Sohoka',
        'profile' => 'Umwirondoro',
        'bookings' => 'Ibyo wabitse',
        'wishlist' => 'Ibyifuzo',
        'partner_dashboard' => 'Imbonerahamwe y umufatanyabikorwa',
        'list_property' => 'Tangaza umutungo wawe',
        'where_going' => 'Ujya he?',
        'checkin' => 'Kwinjira',
        'checkout' => 'Gusohoka',
        'adult_one' => 'umuntu mukuru',
        'adult_many' => 'abantu bakuru',
        'pickup_location' => 'Aho gufatira imodoka',
        'pickup_date' => 'Itariki yo gufatira',
        'return_date' => 'Itariki yo kugarura',
        'date' => 'Itariki',
        'person_one' => 'umuntu',
        'person_many' => 'abantu',
        'restaurant_location' => 'Izina rya restaurant cyangwa aho iri',
        'search' => 'Shakisha',
        'special_offers' => 'Amasezerano y umwihariko',
        'offers_stays_sub' => 'Zigama kuri gahunda yawe itaha',
        'view_all_deals' => 'Reba amasezerano yose',
        'price_range' => 'Igipimo cy ibiciro',
        'min' => 'Hasi',
        'max' => 'Hejuru',
        'property_type' => 'Ubwoko bw icumbi',
        'support_title' => 'Ubufasha',
        'kigali' => 'Kigali',
        'musanze_volcanoes' => 'Musanze (ibirunga)',
        'nyungwe_forest' => 'Ishyamba rya Nyungwe',
        'akagera_national_park' => 'Pariki y Igihugu y Akagera',
        'lake_kivu' => 'Ikiyaga cya Kivu',
        'thank_you_subscribe' => 'Murakoze kwiyandikisha! Tuzakohereza amasezerano meza.',
        'enter_valid_email' => 'Andika aderesi ya email iboneye.',
        'app_coming_soon' => 'App yacu ya mobile izasohoka mu gihembwe cya kane cya 2026!',
        'scroll_to_top' => 'Subira hejuru',
        'just_now' => 'Ubu nyene',
        'minute_ago' => 'Umunota 1 ushize',
        'minutes_ago' => 'Iminota :count ishize',
        'hour_ago' => 'Isaha 1 ishize',
        'hours_ago' => 'Amasaha :count ashize',
        'days_ago' => 'Iminsi :count ishize',
        'yesterday' => 'ejo',
        'week_ago' => 'Icyumweru 1 gishize',
        'weeks_ago' => 'Ibyumweru :count bishize',
        'month_ago' => 'Ukwezi 1 gushize',
        'months_ago' => 'Amezi :count ashize',
        'year_ago' => 'Umwaka 1 ushize',
        'years_ago' => 'Imyaka :count ishize',
        'new_label' => 'Gishya',
        'exceptional' => 'Bidasanzwe',
        'excellent' => 'Byiza cyane',
        'very_good' => 'Byiza',
        'good' => 'Ni byiza',
        'pleasant' => 'Birashimishije',
        'fair' => 'Biraringaniye',
        'review_score' => 'Amanota',
    ],
    'sw' => [
        'select_currency' => 'Chagua sarafu yako',
        'select_language' => 'Chagua lugha yako',
        'help_center' => 'Kituo cha msaada',
        'contact_us' => 'Wasiliana nasi',
        'safety_information' => 'Taarifa za usalama',
        'cancellation_options' => 'Chaguo za kughairi',
        'faq' => 'Maswali ya mara kwa mara',
        'discover' => 'Gundua',
        'about_gorwanda' => 'Kuhusu GoRwanda+',
        'partner_program' => 'Mpango wa washirika',
        'destinations' => 'Maeneo',
        'travel_blog' => 'Blogu ya safari',
        'rwanda_spotlight' => 'Vivutio vya Rwanda',
        'legal' => 'Kisheria',
        'privacy_policy' => 'Sera ya faragha',
        'terms_service' => 'Masharti ya huduma',
        'cookie_policy' => 'Sera ya kuki',
        'accessibility' => 'Ufikiaji',
        'get_app' => 'Pata app',
        'book_on_the_go' => 'Weka nafasi popote ulipo kwa ofa maalum za simu.',
        'we_accept' => 'Tunapokea',
        'proud_partners' => 'Washirika wa fahari wa utalii wa Afrika Mashariki',
        'eac_partner' => 'Mshirika wa EAC',
        'sitemap' => 'Ramani ya tovuti',
        'privacy' => 'Faragha',
        'terms' => 'Masharti',
        'all_rights_reserved' => 'Haki zote zimehifadhiwa.',
        'made_in_rwanda' => 'Imefanywa Rwanda',
        'register' => 'Jisajili',
        'sign_in' => 'Ingia',
        'sign_out' => 'Toka',
        'profile' => 'Wasifu',
        'bookings' => 'Uhifadhi',
        'wishlist' => 'Orodha ya matamanio',
        'partner_dashboard' => 'Dashibodi ya mshirika',
        'list_property' => 'Tangaza mali yako',
        'where_going' => 'Unaenda wapi?',
        'checkin' => 'Kuingia',
        'checkout' => 'Kutoka',
        'adult_one' => 'mtu mzima',
        'adult_many' => 'watu wazima',
        'pickup_location' => 'Mahali pa kuchukua',
        'pickup_date' => 'Tarehe ya kuchukua',
        'return_date' => 'Tarehe ya kurudisha',
        'date' => 'Tarehe',
        'person_one' => 'mtu',
        'person_many' => 'watu',
        'restaurant_location' => 'Jina la mgahawa au mahali',
        'search' => 'Tafuta',
        'special_offers' => 'Ofa maalum',
        'offers_stays_sub' => 'Okoa kwenye malazi yako yajayo',
        'view_all_deals' => 'Tazama ofa zote',
        'price_range' => 'Kiwango cha bei',
        'min' => 'Chini',
        'max' => 'Juu',
        'property_type' => 'Aina ya malazi',
        'support_title' => 'Msaada',
        'kigali' => 'Kigali',
        'musanze_volcanoes' => 'Musanze (Volkano)',
        'nyungwe_forest' => 'Msitu wa Nyungwe',
        'akagera_national_park' => 'Hifadhi ya Taifa ya Akagera',
        'lake_kivu' => 'Ziwa Kivu',
        'thank_you_subscribe' => 'Asante kwa kujisajili! Utapokea ofa bora.',
        'enter_valid_email' => 'Tafadhali weka barua pepe sahihi.',
        'app_coming_soon' => 'Programu yetu ya simu itatolewa robo ya nne ya 2026!',
        'scroll_to_top' => 'Rudi juu',
        'just_now' => 'Sasa hivi',
        'minute_ago' => 'Dakika 1 iliyopita',
        'minutes_ago' => 'Dakika :count zilizopita',
        'hour_ago' => 'Saa 1 iliyopita',
        'hours_ago' => 'Masaa :count yaliyopita',
        'days_ago' => 'Siku :count zilizopita',
        'yesterday' => 'jana',
        'week_ago' => 'Wiki 1 iliyopita',
        'weeks_ago' => 'Wiki :count zilizopita',
        'month_ago' => 'Mwezi 1 uliopita',
        'months_ago' => 'Miezi :count iliyopita',
        'year_ago' => 'Mwaka 1 uliopita',
        'years_ago' => 'Miaka :count iliyopita',
        'new_label' => 'Mpya',
        'exceptional' => 'Bora sana',
        'excellent' => 'Bora',
        'very_good' => 'Nzuri sana',
        'good' => 'Nzuri',
        'pleasant' => 'Inapendeza',
        'fair' => 'Wastani',
        'review_score' => 'Alama',
    ],
];

foreach ($translationExtras as $langCode => $extraValues) {
    $translations[$langCode] = array_merge($translations[$langCode] ?? [], $extraValues);
}

// Get translations for current language
$t = $translations[$currentLang] ?? $translations['en'];

function tr($key, $default = null, $replacements = []) {
    global $t;

    $text = $t[$key] ?? $default ?? $key;

    foreach ($replacements as $needle => $value) {
        $text = str_replace(':' . $needle, (string)$value, $text);
    }

    return $text;
}

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
    if ($rating >= 9) return [tr('exceptional'), 'bg-success'];
    if ($rating >= 8) return [tr('excellent'), 'bg-success'];
    if ($rating >= 7) return [tr('very_good'), 'bg-success'];
    if ($rating >= 6) return [tr('good'), 'bg-info'];
    if ($rating >= 5) return [tr('pleasant'), 'bg-info'];
    if ($rating >= 4) return [tr('fair'), 'bg-warning'];
    return [tr('review_score'), 'bg-secondary'];
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
    if (!$timestamp) return tr('just_now');
    
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
        return tr('just_now');
    } else if ($minutes <= 60) {
        return ($minutes == 1) ? tr('minute_ago') : tr('minutes_ago', null, ['count' => $minutes]);
    } else if ($hours <= 24) {
        return ($hours == 1) ? tr('hour_ago') : tr('hours_ago', null, ['count' => $hours]);
    } else if ($days <= 7) {
        return ($days == 1) ? tr('yesterday') : tr('days_ago', null, ['count' => $days]);
    } else if ($weeks <= 4.3) {
        return ($weeks == 1) ? tr('week_ago') : tr('weeks_ago', null, ['count' => $weeks]);
    } else if ($months <= 12) {
        return ($months == 1) ? tr('month_ago') : tr('months_ago', null, ['count' => $months]);
    } else {
        return ($years == 1) ? tr('year_ago') : tr('years_ago', null, ['count' => $years]);
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
