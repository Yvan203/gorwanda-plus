<?php
require_once __DIR__ . '/../includes/functions.php';

$pageTitle = tr('help_center') . ' - GoRwanda+';
$hideSearch = true;
require_once __DIR__ . '/../includes/header.php';

$db = getDB();
$platformStats = [
    'stays' => (int)$db->query("SELECT COUNT(*) FROM stays WHERE is_active = 1 AND is_verified = 1")->fetchColumn(),
    'cars' => (int)$db->query("SELECT COUNT(*) FROM car_rentals WHERE is_active = 1 AND is_verified = 1")->fetchColumn(),
    'experiences' => (int)$db->query("SELECT COUNT(*) FROM attractions WHERE is_active = 1 AND is_verified = 1")->fetchColumn(),
    'restaurants' => (int)$db->query("SELECT COUNT(*) FROM restaurants WHERE is_active = 1")->fetchColumn(),
];

$helpPageTranslations = [
    'en' => [
        'eyebrow' => 'Help Center',
        'hero_title' => 'Support that feels local, fast, and traveler-friendly',
        'hero_subtitle' => 'Find answers for bookings, payments, partner support, and Rwanda travel questions in one place.',
        'search_placeholder' => 'Search help articles, topics, or support questions',
        'search_button' => 'Search help',
        'popular_topics' => 'Popular topics',
        'topic_manage_booking' => 'Manage a booking',
        'topic_payment' => 'Payment and refunds',
        'topic_airport_pickup' => 'Airport pickup and transfers',
        'topic_partner' => 'Partner support',
        'quick_help' => 'Quick help for every trip stage',
        'quick_help_subtitle' => 'Built with Booking.com-style clarity, but tuned for travel in Rwanda.',
        'before_you_book' => 'Before you book',
        'before_you_book_text' => 'Understand listing details, cancellation terms, and what to expect before confirming.',
        'during_trip' => 'During your trip',
        'during_trip_text' => 'Get support for check-in, car pickup, activity timing, and last-minute changes.',
        'payments_refunds' => 'Payments and refunds',
        'payments_refunds_text' => 'Learn how confirmations, pricing, and refund handling work across the platform.',
        'partner_support' => 'Partner support',
        'partner_support_text' => 'Guidance for property owners, car rental operators, experience hosts, and restaurants.',
        'explore_articles' => 'Explore articles',
        'support_channels' => 'Need direct support?',
        'support_channels_subtitle' => 'Reach the team the way travelers in East Africa actually need it.',
        'live_chat' => 'Live trip support',
        'live_chat_text' => 'Best for urgent booking issues, same-day changes, or check-in coordination.',
        'email_support' => 'Email support',
        'email_support_text' => 'Use for refunds, account questions, document follow-up, and non-urgent requests.',
        'partner_desk' => 'Partner desk',
        'partner_desk_text' => 'For listing setup, availability updates, pricing support, and dashboard help.',
        'response_time' => 'Typical reply',
        'response_live' => 'Within minutes',
        'response_email' => 'Within 24 hours',
        'response_partner' => 'Same business day',
        'faq_title' => 'Frequently asked questions',
        'faq_subtitle' => 'The questions we hear most from guests and local partners.',
        'travel_tips' => 'Rwanda travel support tips',
        'travel_tips_subtitle' => 'A little local context can save a lot of stress.',
        'tip_1_title' => 'Confirm arrival timing clearly',
        'tip_1_text' => 'Traffic in Kigali is usually manageable, but weather and airport timing can shift arrivals. Message hosts early if your ETA changes.',
        'tip_2_title' => 'Carry your booking confirmation',
        'tip_2_text' => 'Some stays, rentals, and attractions may ask for your confirmation details on arrival, especially for pre-arranged pickups.',
        'tip_3_title' => 'Check location details before travel',
        'tip_3_text' => 'Some listings are in central Kigali, while others are near national parks or lakes. Review the map and road distance before you go.',
        'faq_items' => [
            [
                'q' => 'How do I change or cancel a booking?',
                'a' => 'Open your booking details first. Cancellation and change options depend on the listing policy, booking status, and how close you are to arrival. If the listing does not support self-service changes, contact support and include your booking reference.'
            ],
            [
                'q' => 'When will I receive my booking confirmation?',
                'a' => 'Most confirmations are shown immediately after payment or successful reservation. If a booking needs host approval, we will update you as soon as the provider responds.'
            ],
            [
                'q' => 'What should I do if a host or driver is not responding?',
                'a' => 'First check your booking messages and listed contact details. If there is still no response and your trip is close, contact live support so we can help coordinate quickly.'
            ],
            [
                'q' => 'Can I book experiences or transport for someone else?',
                'a' => 'Usually yes, but you should enter the traveler or guest details correctly and make sure the provider knows who will arrive. Some bookings may require ID matching at check-in.'
            ],
            [
                'q' => 'How do partner accounts get help with listings?',
                'a' => 'Partners can use the dashboard tools for common changes, but pricing issues, availability setup, and onboarding questions can be sent to the partner desk for faster assistance.'
            ],
        ],
    ],
    'fr' => [
        'eyebrow' => 'Centre d aide',
        'hero_title' => 'Un support local, rapide et rassurant',
        'hero_subtitle' => 'Trouvez des reponses pour les reservations, paiements, partenaires et questions de voyage au Rwanda.',
        'search_placeholder' => 'Rechercher des articles, sujets ou questions d aide',
        'search_button' => 'Rechercher',
        'popular_topics' => 'Sujets populaires',
        'topic_manage_booking' => 'Gerer une reservation',
        'topic_payment' => 'Paiement et remboursements',
        'topic_airport_pickup' => 'Transferts aeroport',
        'topic_partner' => 'Aide partenaire',
        'quick_help' => 'Une aide rapide a chaque etape du voyage',
        'quick_help_subtitle' => 'Une clarte inspiree de Booking.com, adaptee au voyage au Rwanda.',
        'before_you_book' => 'Avant de reserver',
        'before_you_book_text' => 'Comprenez les details, conditions d annulation et attentes avant de confirmer.',
        'during_trip' => 'Pendant le voyage',
        'during_trip_text' => 'Obtenez de l aide pour l arrivee, la voiture, les horaires et les changements de derniere minute.',
        'payments_refunds' => 'Paiements et remboursements',
        'payments_refunds_text' => 'Comprenez les confirmations, tarifs et remboursements sur la plateforme.',
        'partner_support' => 'Support partenaire',
        'partner_support_text' => 'Conseils pour hebergements, locations de voitures, activites et restaurants.',
        'explore_articles' => 'Voir les articles',
        'support_channels' => 'Besoin d une aide directe ?',
        'support_channels_subtitle' => 'Contactez l equipe par le canal le plus utile pour votre situation.',
        'live_chat' => 'Support voyage en direct',
        'live_chat_text' => 'Ideal pour les urgences, changements le jour meme et coordination d arrivee.',
        'email_support' => 'Support par e-mail',
        'email_support_text' => 'Pour remboursements, comptes, documents et demandes non urgentes.',
        'partner_desk' => 'Bureau partenaire',
        'partner_desk_text' => 'Pour configuration, disponibilite, prix et assistance tableau de bord.',
        'response_time' => 'Delai typique',
        'response_live' => 'En quelques minutes',
        'response_email' => 'Sous 24 heures',
        'response_partner' => 'Le jour ouvrable meme',
        'faq_title' => 'Questions frequentes',
        'faq_subtitle' => 'Les questions les plus frequentes des voyageurs et partenaires.',
        'travel_tips' => 'Conseils utiles pour voyager au Rwanda',
        'travel_tips_subtitle' => 'Un peu de contexte local peut eviter beaucoup de stress.',
        'tip_1_title' => 'Confirmez bien votre heure d arrivee',
        'tip_1_text' => 'La circulation a Kigali reste souvent fluide, mais la meteo et l aeroport peuvent changer les horaires. Prevenez tot si votre ETA bouge.',
        'tip_2_title' => 'Gardez votre confirmation a portee',
        'tip_2_text' => 'Certains hebergements, locations et activites demandent les details de confirmation a l arrivee.',
        'tip_3_title' => 'Verifiez bien l emplacement',
        'tip_3_text' => 'Certaines offres sont au centre de Kigali, d autres pres des parcs ou du lac. Verifiez la distance avant de partir.',
        'faq_items' => [
            ['q' => 'Comment modifier ou annuler une reservation ?', 'a' => 'Ouvrez d abord les details de la reservation. Les options dependent de la politique, du statut et de la proximite de l arrivee.'],
            ['q' => 'Quand vais-je recevoir ma confirmation ?', 'a' => 'La plupart des confirmations apparaissent juste apres le paiement ou la reservation. Si une validation du partenaire est requise, nous vous informons des que possible.'],
            ['q' => 'Que faire si l hote ou le chauffeur ne repond pas ?', 'a' => 'Verifiez vos messages et coordonnees de reservation. Si votre voyage est proche, contactez le support direct.'],
            ['q' => 'Puis-je reserver pour une autre personne ?', 'a' => 'Oui, dans la plupart des cas, mais les bonnes informations voyageur doivent etre renseignees.'],
            ['q' => 'Comment les partenaires obtiennent-ils de l aide ?', 'a' => 'Les partenaires peuvent utiliser le tableau de bord pour les actions courantes et contacter le bureau partenaire pour les cas plus complexes.'],
        ],
    ],
    'rw' => [
        'eyebrow' => 'Ubufasha',
        'hero_title' => 'Ubufasha bwihuse, bworoshye kandi bujyanye n u Rwanda',
        'hero_subtitle' => 'Shakira ibisubizo ku kubika, kwishyura, abafatanyabikorwa n ibibazo by urugendo mu Rwanda.',
        'search_placeholder' => 'Shakisha ingingo z ubufasha cyangwa ibibazo bikunze kubazwa',
        'search_button' => 'Shakisha',
        'popular_topics' => 'Ibyibandwaho cyane',
        'topic_manage_booking' => 'Gucunga ibyo wabitse',
        'topic_payment' => 'Kwishyura no gusubizwa',
        'topic_airport_pickup' => 'Kwakirwa ku kibuga cy indege',
        'topic_partner' => 'Ubufasha bw abafatanyabikorwa',
        'quick_help' => 'Ubufasha bwihuse muri buri cyiciro cy urugendo',
        'quick_help_subtitle' => 'Bwubatswe ku murongo wa Booking.com ariko bwongerwamo uburyo bw u Rwanda.',
        'before_you_book' => 'Mbere yo kubika',
        'before_you_book_text' => 'Sobanukirwa ibisobanuro by aho ugiye, amabwiriza yo guhagarika no ibyo utegerejwe mbere yo kwemeza.',
        'during_trip' => 'Mu rugendo',
        'during_trip_text' => 'Haboneka ubufasha ku kwinjira, gufata imodoka, igihe cy ibikorwa no ku mpinduka zihuse.',
        'payments_refunds' => 'Kwishyura no gusubizwa',
        'payments_refunds_text' => 'Menya uko kwemeza, ibiciro no gusubizwa bikorwa ku rubuga.',
        'partner_support' => 'Ubufasha bw abafatanyabikorwa',
        'partner_support_text' => 'Inama ku bacuruza amacumbi, imodoka, ibikorwa n amaresitora.',
        'explore_articles' => 'Reba ibisobanuro',
        'support_channels' => 'Ukeneye kuvugisha umuntu ako kanya?',
        'support_channels_subtitle' => 'Tuvugishe uko bikoroheye kandi bikubereye.',
        'live_chat' => 'Ubufasha bw urugendo ako kanya',
        'live_chat_text' => 'Bikwiye ku bibazo byihutirwa, impinduka zo kuri uwo munsi cyangwa kwakirwa.',
        'email_support' => 'Ubufasha bwa email',
        'email_support_text' => 'Bukwiriye ku gusubizwa, konti, inyandiko n ibibazo bidahutirwa.',
        'partner_desk' => 'Aho abafatanyabikorwa bafashirizwa',
        'partner_desk_text' => 'Ku gutunganya listings, availability, ibiciro n ubufasha bwa dashboard.',
        'response_time' => 'Igihe gisanzwe cyo gusubiza',
        'response_live' => 'Mu minota mike',
        'response_email' => 'Mu masaha 24',
        'response_partner' => 'Muri uwo munsi w akazi',
        'faq_title' => 'Ibibazo bikunze kubazwa',
        'faq_subtitle' => 'Ibibazo bikunze kubazwa n abagenzi n abafatanyabikorwa.',
        'travel_tips' => 'Inama zifasha abagenzi mu Rwanda',
        'travel_tips_subtitle' => 'Amakuru make y aho uri ashobora kugabanya impungenge nyinshi.',
        'tip_1_title' => 'Emeza neza igihe uzagerera',
        'tip_1_text' => 'Imihanda ya Kigali akenshi iba imeze neza, ariko ikirere n indege bishobora guhindura igihe. Menyesha hakiri kare niba igihe gihindutse.',
        'tip_2_title' => 'Jyana amakuru yo kwemeza booking',
        'tip_2_text' => 'Aho ucumbika, imodoka cyangwa ibikorwa bishobora kubigusaba ukihageze.',
        'tip_3_title' => 'Banza urebe neza aho ujya',
        'tip_3_text' => 'Hari ibiri hagati mu mujyi wa Kigali, ibindi hafi ya pariki cyangwa ku kiyaga. Banza urebe intera n umuhanda.',
        'faq_items' => [
            ['q' => 'Nahindura cyangwa ngahagarika booking nte?', 'a' => 'Banza ufungure ibisobanuro bya booking. Guhindura cyangwa guhagarika biterwa n amabwiriza yaho wabitse n igihe usigaje mbere yo kugerayo.'],
            ['q' => 'Ni ryari nzabona confirmation?', 'a' => 'Kenshi ubibona ako kanya nyuma yo kwishyura cyangwa kubika. Niba bisaba ko host abyemeza, turakumenyesha akimara gusubiza.'],
            ['q' => 'Nkore iki host cyangwa driver atansubiza?', 'a' => 'Banza urebe messages na nimero zihari. Niba urugendo rwegereje, hamagara ubufasha bwihuse.'],
            ['q' => 'Nshobora kubikira undi muntu?', 'a' => 'Yego, akenshi birashoboka, ariko ugomba kuzuza amakuru nyayo y umuntu uzagenda.'],
            ['q' => 'Abafatanyabikorwa bafashwa bate?', 'a' => 'Bashobora gukoresha dashboard ku bintu bisanzwe, hanyuma bakifashisha service y abafatanyabikorwa ku bibazo byimbitse.'],
        ],
    ],
    'sw' => [
        'eyebrow' => 'Kituo cha msaada',
        'hero_title' => 'Msaada wa haraka, wa karibu, na rafiki kwa wasafiri',
        'hero_subtitle' => 'Pata majibu ya uhifadhi, malipo, washirika, na maswali ya safari ya Rwanda mahali pamoja.',
        'search_placeholder' => 'Tafuta makala za msaada, mada au maswali',
        'search_button' => 'Tafuta msaada',
        'popular_topics' => 'Mada maarufu',
        'topic_manage_booking' => 'Dhibiti uhifadhi',
        'topic_payment' => 'Malipo na marejesho',
        'topic_airport_pickup' => 'Usafiri wa uwanja wa ndege',
        'topic_partner' => 'Msaada wa washirika',
        'quick_help' => 'Msaada wa haraka kwa kila hatua ya safari',
        'quick_help_subtitle' => 'Uwazi wa Booking.com, lakini kwa mguso wa Rwanda.',
        'before_you_book' => 'Kabla ya kuweka nafasi',
        'before_you_book_text' => 'Elewa maelezo ya tangazo, masharti ya kughairi, na matarajio kabla ya kuthibitisha.',
        'during_trip' => 'Wakati wa safari',
        'during_trip_text' => 'Pata msaada wa check-in, kuchukua gari, muda wa shughuli, na mabadiliko ya dakika za mwisho.',
        'payments_refunds' => 'Malipo na marejesho',
        'payments_refunds_text' => 'Jifunze jinsi uthibitisho, bei, na marejesho yanavyofanya kazi.',
        'partner_support' => 'Msaada wa washirika',
        'partner_support_text' => 'Mwongozo kwa wamiliki wa mali, wakodishaji wa magari, waandaaji wa shughuli, na migahawa.',
        'explore_articles' => 'Tazama makala',
        'support_channels' => 'Unahitaji msaada wa moja kwa moja?',
        'support_channels_subtitle' => 'Wasiliana nasi kwa njia inayofaa zaidi kwa wasafiri wa Afrika Mashariki.',
        'live_chat' => 'Msaada wa safari moja kwa moja',
        'live_chat_text' => 'Bora kwa matatizo ya haraka, mabadiliko ya siku hiyo, au uratibu wa check-in.',
        'email_support' => 'Msaada kwa barua pepe',
        'email_support_text' => 'Tumia kwa marejesho, akaunti, nyaraka, na maombi yasiyo ya haraka.',
        'partner_desk' => 'Dawati la washirika',
        'partner_desk_text' => 'Kwa mipangilio ya listings, upatikanaji, bei, na msaada wa dashibodi.',
        'response_time' => 'Muda wa kawaida wa kujibu',
        'response_live' => 'Ndani ya dakika chache',
        'response_email' => 'Ndani ya saa 24',
        'response_partner' => 'Siku hiyo ya kazi',
        'faq_title' => 'Maswali ya mara kwa mara',
        'faq_subtitle' => 'Maswali yanayoulizwa zaidi na wageni na washirika.',
        'travel_tips' => 'Vidokezo vya msaada wa safari Rwanda',
        'travel_tips_subtitle' => 'Uelewa mdogo wa eneo unaweza kupunguza msongo mkubwa.',
        'tip_1_title' => 'Thibitisha muda wako wa kuwasili',
        'tip_1_text' => 'Msongamano Kigali huwa wa kawaida, lakini hali ya hewa na ratiba za ndege zinaweza kubadilisha muda. Tuma taarifa mapema kama ETA inabadilika.',
        'tip_2_title' => 'Beba uthibitisho wa uhifadhi',
        'tip_2_text' => 'Baadhi ya malazi, magari, au shughuli zinaweza kuhitaji maelezo ya uthibitisho unapowasili.',
        'tip_3_title' => 'Angalia eneo kabla ya kusafiri',
        'tip_3_text' => 'Baadhi ya sehemu ziko katikati ya Kigali, nyingine karibu na hifadhi au ziwa. Kagua ramani na umbali kabla ya kwenda.',
        'faq_items' => [
            ['q' => 'Ninawezaje kubadilisha au kughairi uhifadhi?', 'a' => 'Fungua maelezo ya uhifadhi kwanza. Mabadiliko na kughairi hutegemea sera ya tangazo, hali ya uhifadhi, na muda uliobaki kabla ya kuwasili.'],
            ['q' => 'Nitapata uthibitisho wangu lini?', 'a' => 'Mara nyingi uthibitisho huonekana mara baada ya malipo au uhifadhi. Ikiwa mwenyeji lazima athibitishe kwanza, tutakujulisha haraka iwezekanavyo.'],
            ['q' => 'Nifanye nini ikiwa mwenyeji au dereva hajibu?', 'a' => 'Kwanza angalia ujumbe na mawasiliano ya uhifadhi. Ikiwa safari iko karibu, wasiliana na msaada wa moja kwa moja.'],
            ['q' => 'Naweza kuweka nafasi kwa mtu mwingine?', 'a' => 'Ndiyo, mara nyingi inawezekana, lakini unapaswa kuweka taarifa sahihi za msafiri.'],
            ['q' => 'Washirika wanapataje msaada wa listings?', 'a' => 'Washirika wanaweza kutumia dashibodi kwa mabadiliko ya kawaida na kuwasiliana na dawati la washirika kwa masuala magumu zaidi.'],
        ],
    ],
];

$lang = getCurrentLanguage();
$helpT = $helpPageTranslations[$lang] ?? $helpPageTranslations['en'];

$faqItems = $helpT['faq_items'];
$supportCards = [
    [
        'icon' => 'bi-chat-dots',
        'title' => $helpT['live_chat'],
        'text' => $helpT['live_chat_text'],
        'response' => $helpT['response_live'],
        'action' => '#faq'
    ],
    [
        'icon' => 'bi-envelope-paper',
        'title' => $helpT['email_support'],
        'text' => $helpT['email_support_text'],
        'response' => $helpT['response_email'],
        'action' => 'mailto:support@gorwanda.com'
    ],
    [
        'icon' => 'bi-building-gear',
        'title' => $helpT['partner_desk'],
        'text' => $helpT['partner_desk_text'],
        'response' => $helpT['response_partner'],
        'action' => '/gorwanda-plus/partner/'
    ],
];

$quickHelpCards = [
    ['icon' => 'bi-search-heart', 'title' => $helpT['before_you_book'], 'text' => $helpT['before_you_book_text']],
    ['icon' => 'bi-compass', 'title' => $helpT['during_trip'], 'text' => $helpT['during_trip_text']],
    ['icon' => 'bi-credit-card-2-front', 'title' => $helpT['payments_refunds'], 'text' => $helpT['payments_refunds_text']],
    ['icon' => 'bi-shop-window', 'title' => $helpT['partner_support'], 'text' => $helpT['partner_support_text']],
];

$travelTips = [
    ['title' => $helpT['tip_1_title'], 'text' => $helpT['tip_1_text']],
    ['title' => $helpT['tip_2_title'], 'text' => $helpT['tip_2_text']],
    ['title' => $helpT['tip_3_title'], 'text' => $helpT['tip_3_text']],
];
?>

<style>
.help-hero {
    position: relative;
    overflow: hidden;
    background:
        radial-gradient(circle at top right, rgba(252, 209, 22, 0.28), transparent 24%),
        radial-gradient(circle at bottom left, rgba(0, 166, 81, 0.22), transparent 30%),
        linear-gradient(135deg, #003580 0%, #001f4d 55%, #0d3d2f 100%);
    color: #fff;
    padding: 56px 0 68px;
    margin-bottom: 36px;
}

.help-hero::after {
    content: "";
    position: absolute;
    inset: 0;
    background-image:
        linear-gradient(rgba(255,255,255,0.05) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,0.05) 1px, transparent 1px);
    background-size: 36px 36px;
    opacity: 0.18;
    pointer-events: none;
}

.help-hero .container {
    position: relative;
    z-index: 1;
}

.help-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 14px;
    border-radius: 999px;
    background: rgba(255,255,255,0.12);
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    margin-bottom: 18px;
}

.help-title {
    font-size: clamp(2rem, 4vw, 3.25rem);
    font-weight: 800;
    line-height: 1.08;
    max-width: 760px;
    margin-bottom: 14px;
}

.help-subtitle {
    max-width: 740px;
    font-size: 1.05rem;
    color: rgba(255,255,255,0.9);
    margin-bottom: 26px;
}

.help-search-shell {
    background: #febb02;
    padding: 5px;
    border-radius: 14px;
    display: flex;
    gap: 5px;
    max-width: 900px;
    box-shadow: 0 18px 44px rgba(0,0,0,0.22);
}

.help-search-input {
    flex: 1;
    border: 0;
    border-radius: 10px;
    height: 56px;
    padding: 0 18px 0 52px;
    font-size: 15px;
    color: #1f2937;
}

.help-search-wrap {
    position: relative;
    flex: 1;
}

.help-search-wrap i {
    position: absolute;
    left: 18px;
    top: 50%;
    transform: translateY(-50%);
    color: #6b7280;
    font-size: 18px;
}

.help-search-btn {
    border: 0;
    border-radius: 10px;
    background: #006ce4;
    color: #fff;
    min-width: 164px;
    font-weight: 700;
    font-size: 15px;
}

.help-topics {
    margin-top: 18px;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
}

.help-topics-label {
    font-size: 13px;
    font-weight: 700;
    color: rgba(255,255,255,0.82);
}

.help-topic-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    border-radius: 999px;
    text-decoration: none;
    background: rgba(255,255,255,0.14);
    color: #fff;
    font-size: 13px;
    font-weight: 600;
}

.help-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 18px;
    margin-top: 30px;
}

.help-stat {
    background: rgba(255,255,255,0.08);
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 18px;
    padding: 18px 20px;
    backdrop-filter: blur(6px);
}

.help-stat-value {
    font-size: 1.65rem;
    font-weight: 800;
    line-height: 1;
    margin-bottom: 8px;
}

.help-stat-label {
    color: rgba(255,255,255,0.8);
    font-size: 0.92rem;
}

.help-section {
    margin-bottom: 36px;
}

.help-section-head {
    display: flex;
    justify-content: space-between;
    align-items: end;
    gap: 20px;
    margin-bottom: 22px;
}

.help-section-title {
    font-size: 1.8rem;
    font-weight: 800;
    color: #1f2937;
    margin: 0 0 6px;
}

.help-section-subtitle {
    margin: 0;
    color: #667085;
    max-width: 720px;
}

.help-grid-4 {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 18px;
}

.help-card {
    background: #fff;
    border: 1px solid #e6ebf2;
    border-radius: 20px;
    padding: 22px;
    box-shadow: 0 10px 24px rgba(16, 24, 40, 0.06);
    height: 100%;
}

.help-card-icon {
    width: 54px;
    height: 54px;
    border-radius: 16px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #ebf3ff 0%, #fff6d5 100%);
    color: #0057b8;
    font-size: 1.35rem;
    margin-bottom: 16px;
}

.help-card-title {
    font-size: 1.1rem;
    font-weight: 800;
    color: #1f2937;
    margin-bottom: 8px;
}

.help-card-text {
    color: #667085;
    margin-bottom: 16px;
    font-size: 0.96rem;
}

.help-card-link {
    color: #006ce4;
    font-weight: 700;
    text-decoration: none;
}

.support-grid {
    display: grid;
    grid-template-columns: 1.25fr 0.95fr;
    gap: 20px;
}

.support-cards {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 18px;
}

.support-card-meta {
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px dashed #d7dee7;
    font-size: 0.92rem;
    color: #344054;
}

.support-side {
    border-radius: 22px;
    padding: 24px;
    background:
        linear-gradient(160deg, rgba(0,108,228,0.96), rgba(0,53,128,0.98)),
        #003580;
    color: #fff;
    position: relative;
    overflow: hidden;
}

.support-side::after {
    content: "";
    position: absolute;
    right: -60px;
    bottom: -60px;
    width: 220px;
    height: 220px;
    border-radius: 50%;
    background: rgba(252, 209, 22, 0.16);
}

.support-side h3 {
    font-size: 1.45rem;
    font-weight: 800;
    margin-bottom: 12px;
}

.support-side ul {
    list-style: none;
    padding: 0;
    margin: 18px 0 0;
}

.support-side li {
    display: flex;
    gap: 12px;
    margin-bottom: 14px;
    color: rgba(255,255,255,0.88);
}

.support-side li i {
    color: #fcd116;
    margin-top: 2px;
}

.faq-layout {
    display: grid;
    grid-template-columns: 1.15fr 0.85fr;
    gap: 20px;
}

.faq-card,
.tips-card {
    background: #fff;
    border: 1px solid #e6ebf2;
    border-radius: 22px;
    box-shadow: 0 10px 24px rgba(16, 24, 40, 0.06);
    padding: 8px;
}

.accordion-item.help-accordion-item {
    border: 0;
    border-bottom: 1px solid #edf1f6;
    border-radius: 0;
}

.accordion-item.help-accordion-item:last-child {
    border-bottom: 0;
}

.accordion-button.help-accordion-button {
    font-weight: 700;
    color: #1f2937;
    background: transparent;
    box-shadow: none;
    padding: 18px 18px;
}

.accordion-button.help-accordion-button:not(.collapsed) {
    color: #0057b8;
    background: #f8fbff;
}

.accordion-body.help-accordion-body {
    padding: 0 18px 18px;
    color: #667085;
    line-height: 1.65;
}

.tips-card-inner {
    padding: 18px;
}

.tip-item {
    padding: 18px 0;
    border-bottom: 1px solid #edf1f6;
}

.tip-item:last-child {
    border-bottom: 0;
}

.tip-item h4 {
    font-size: 1.03rem;
    font-weight: 800;
    margin-bottom: 8px;
    color: #1f2937;
}

.tip-item p {
    margin: 0;
    color: #667085;
}

@media (max-width: 1199px) {
    .help-grid-4,
    .support-cards {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .help-stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 991px) {
    .support-grid,
    .faq-layout {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 767px) {
    .help-search-shell {
        flex-direction: column;
    }

    .help-search-btn {
        height: 52px;
        width: 100%;
    }

    .help-grid-4,
    .support-cards,
    .help-stats {
        grid-template-columns: 1fr;
    }

    .help-hero {
        padding: 40px 0 48px;
    }
}
</style>

<section class="help-hero">
    <div class="container">
        <div class="help-eyebrow">
            <i class="bi bi-life-preserver"></i>
            <span><?php echo sanitize($helpT['eyebrow']); ?></span>
        </div>
        <h1 class="help-title"><?php echo sanitize($helpT['hero_title']); ?></h1>
        <p class="help-subtitle"><?php echo sanitize($helpT['hero_subtitle']); ?></p>

        <form class="help-search-shell" action="#faq" method="get">
            <div class="help-search-wrap">
                <i class="bi bi-search"></i>
                <input type="text" class="help-search-input" name="q" placeholder="<?php echo sanitize($helpT['search_placeholder']); ?>">
            </div>
            <button type="submit" class="help-search-btn"><?php echo sanitize($helpT['search_button']); ?></button>
        </form>

        <div class="help-topics">
            <span class="help-topics-label"><?php echo sanitize($helpT['popular_topics']); ?></span>
            <a class="help-topic-pill" href="#faq"><i class="bi bi-calendar2-check"></i><?php echo sanitize($helpT['topic_manage_booking']); ?></a>
            <a class="help-topic-pill" href="#faq"><i class="bi bi-wallet2"></i><?php echo sanitize($helpT['topic_payment']); ?></a>
            <a class="help-topic-pill" href="#tips"><i class="bi bi-airplane"></i><?php echo sanitize($helpT['topic_airport_pickup']); ?></a>
            <a class="help-topic-pill" href="#support"><i class="bi bi-building-gear"></i><?php echo sanitize($helpT['topic_partner']); ?></a>
        </div>

        <div class="help-stats">
            <div class="help-stat">
                <div class="help-stat-value"><?php echo number_format($platformStats['stays']); ?>+</div>
                <div class="help-stat-label"><?php echo tr('stays'); ?></div>
            </div>
            <div class="help-stat">
                <div class="help-stat-value"><?php echo number_format($platformStats['cars']); ?>+</div>
                <div class="help-stat-label"><?php echo tr('cars'); ?></div>
            </div>
            <div class="help-stat">
                <div class="help-stat-value"><?php echo number_format($platformStats['experiences']); ?>+</div>
                <div class="help-stat-label"><?php echo tr('experiences'); ?></div>
            </div>
            <div class="help-stat">
                <div class="help-stat-value"><?php echo number_format($platformStats['restaurants']); ?>+</div>
                <div class="help-stat-label"><?php echo tr('restaurants'); ?></div>
            </div>
        </div>
    </div>
</section>

<section class="container help-section">
    <div class="help-section-head">
        <div>
            <h2 class="help-section-title"><?php echo sanitize($helpT['quick_help']); ?></h2>
            <p class="help-section-subtitle"><?php echo sanitize($helpT['quick_help_subtitle']); ?></p>
        </div>
    </div>

    <div class="help-grid-4">
        <?php foreach ($quickHelpCards as $card): ?>
            <article class="help-card">
                <div class="help-card-icon"><i class="bi <?php echo $card['icon']; ?>"></i></div>
                <h3 class="help-card-title"><?php echo sanitize($card['title']); ?></h3>
                <p class="help-card-text"><?php echo sanitize($card['text']); ?></p>
                <a class="help-card-link" href="#faq"><?php echo sanitize($helpT['explore_articles']); ?> <i class="bi bi-arrow-right"></i></a>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<section class="container help-section" id="support">
    <div class="help-section-head">
        <div>
            <h2 class="help-section-title"><?php echo sanitize($helpT['support_channels']); ?></h2>
            <p class="help-section-subtitle"><?php echo sanitize($helpT['support_channels_subtitle']); ?></p>
        </div>
    </div>

    <div class="support-grid">
        <div class="support-cards">
            <?php foreach ($supportCards as $card): ?>
                <article class="help-card">
                    <div class="help-card-icon"><i class="bi <?php echo $card['icon']; ?>"></i></div>
                    <h3 class="help-card-title"><?php echo sanitize($card['title']); ?></h3>
                    <p class="help-card-text"><?php echo sanitize($card['text']); ?></p>
                    <div class="support-card-meta">
                        <strong><?php echo sanitize($helpT['response_time']); ?>:</strong>
                        <?php echo sanitize($card['response']); ?>
                    </div>
                    <div class="mt-3">
                        <a class="help-card-link" href="<?php echo sanitize($card['action']); ?>"><?php echo sanitize($helpT['explore_articles']); ?> <i class="bi bi-arrow-right"></i></a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <aside class="support-side">
            <h3>GoRwanda+ Support</h3>
            <p><?php echo sanitize($helpT['hero_subtitle']); ?></p>
            <ul>
                <li><i class="bi bi-check-circle-fill"></i><span><?php echo sanitize($helpT['topic_manage_booking']); ?></span></li>
                <li><i class="bi bi-check-circle-fill"></i><span><?php echo sanitize($helpT['topic_payment']); ?></span></li>
                <li><i class="bi bi-check-circle-fill"></i><span><?php echo sanitize($helpT['topic_airport_pickup']); ?></span></li>
                <li><i class="bi bi-check-circle-fill"></i><span><?php echo sanitize($helpT['topic_partner']); ?></span></li>
            </ul>
        </aside>
    </div>
</section>

<section class="container help-section" id="faq">
    <div class="help-section-head">
        <div>
            <h2 class="help-section-title"><?php echo sanitize($helpT['faq_title']); ?></h2>
            <p class="help-section-subtitle"><?php echo sanitize($helpT['faq_subtitle']); ?></p>
        </div>
    </div>

    <div class="faq-layout">
        <div class="faq-card">
            <div class="accordion accordion-flush" id="helpFaqAccordion">
                <?php foreach ($faqItems as $index => $item): ?>
                    <div class="accordion-item help-accordion-item">
                        <h2 class="accordion-header" id="faq-heading-<?php echo $index; ?>">
                            <button class="accordion-button help-accordion-button <?php echo $index !== 0 ? 'collapsed' : ''; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#faq-collapse-<?php echo $index; ?>" aria-expanded="<?php echo $index === 0 ? 'true' : 'false'; ?>" aria-controls="faq-collapse-<?php echo $index; ?>">
                                <?php echo sanitize($item['q']); ?>
                            </button>
                        </h2>
                        <div id="faq-collapse-<?php echo $index; ?>" class="accordion-collapse collapse <?php echo $index === 0 ? 'show' : ''; ?>" aria-labelledby="faq-heading-<?php echo $index; ?>" data-bs-parent="#helpFaqAccordion">
                            <div class="accordion-body help-accordion-body">
                                <?php echo sanitize($item['a']); ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <aside class="tips-card" id="tips">
            <div class="tips-card-inner">
                <h3 class="help-section-title" style="font-size:1.35rem;"><?php echo sanitize($helpT['travel_tips']); ?></h3>
                <p class="help-section-subtitle"><?php echo sanitize($helpT['travel_tips_subtitle']); ?></p>

                <?php foreach ($travelTips as $tip): ?>
                    <div class="tip-item">
                        <h4><?php echo sanitize($tip['title']); ?></h4>
                        <p><?php echo sanitize($tip['text']); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </aside>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
