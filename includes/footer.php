<!-- ===== ENHANCED FOOTER - BOOKING.COM STYLE WITH RWANDA TOUCH ===== -->
<footer class="bkg-footer">
    <div class="container">
        <!-- Top Footer - Save money banner (Booking.com style) -->
        <div class="bkg-save-banner">
            <div class="row align-items-center">
                <div class="col-lg-8">
                    <h4 class="bkg-save-title"><?php echo tr('newsletter'); ?></h4>
                    <p class="bkg-save-text"><?php echo tr('newsletter_desc'); ?></p>
                </div>
                <div class="col-lg-4">
                    <div class="bkg-newsletter">
                        <input type="email" class="bkg-newsletter-input" placeholder="<?php echo tr('email_address', 'Your email address'); ?>">
                        <button class="bkg-newsletter-btn" onclick="subscribeNewsletter()"><?php echo tr('subscribe'); ?></button>
                    </div>
                    <p class="bkg-newsletter-note"><?php echo tr('no_spam'); ?></p>
                </div>
            </div>
        </div>

        <!-- Main Footer Grid -->
        <div class="bkg-footer-grid">
            <!-- Column 1: Support -->
            <div class="bkg-footer-col">
                <h6 class="bkg-footer-title"><?php echo tr('support_title'); ?></h6>
                <ul class="bkg-footer-links">
                    <li><a href="/gorwanda-plus/help" class="bkg-footer-link"><i class="bi bi-question-circle"></i> <?php echo tr('help_center'); ?></a></li>
                    <li><a href="/gorwanda-plus/contact" class="bkg-footer-link"><i class="bi bi-envelope"></i> <?php echo tr('contact_us'); ?></a></li>
                    <li><a href="/gorwanda-plus/safety" class="bkg-footer-link"><i class="bi bi-shield-check"></i> <?php echo tr('safety_information'); ?></a></li>
                    <li><a href="/gorwanda-plus/cancellation" class="bkg-footer-link"><i class="bi bi-calendar-x"></i> <?php echo tr('cancellation_options'); ?></a></li>
                    <li><a href="/gorwanda-plus/faq" class="bkg-footer-link"><i class="bi bi-question-lg"></i> <?php echo tr('faq'); ?></a></li>
                </ul>
            </div>

            <!-- Column 2: Discover -->
            <div class="bkg-footer-col">
                <h6 class="bkg-footer-title"><?php echo tr('discover'); ?></h6>
                <ul class="bkg-footer-links">
                    <li><a href="/gorwanda-plus/about" class="bkg-footer-link"><i class="bi bi-info-circle"></i> <?php echo tr('about_gorwanda'); ?></a></li>
                    <li><a href="/gorwanda-plus/partner/onboarding.php" class="bkg-footer-link"><i class="bi bi-plus-circle"></i> <?php echo tr('list_property'); ?></a></li>
                    <li><a href="/gorwanda-plus/partner" class="bkg-footer-link"><i class="bi bi-building"></i> <?php echo tr('partner_program'); ?></a></li>
                    <li><a href="/gorwanda-plus/destinations" class="bkg-footer-link"><i class="bi bi-geo-alt"></i> <?php echo tr('destinations'); ?></a></li>
                    <li><a href="/gorwanda-plus/blog" class="bkg-footer-link"><i class="bi bi-pencil"></i> <?php echo tr('travel_blog'); ?></a></li>
                </ul>
            </div>

            <!-- Column 3: Rwanda Spotlight (Unique) -->
            <div class="bkg-footer-col">
                <h6 class="bkg-footer-title">
                    <span class="fi fi-rw me-1"></span> <?php echo tr('rwanda_spotlight'); ?>
                </h6>
                <ul class="bkg-footer-links">
                    <li><a href="/gorwanda-plus/destinations/kigali" class="bkg-footer-link"><i class="bi bi-building"></i> <?php echo tr('kigali'); ?></a></li>
                    <li><a href="/gorwanda-plus/destinations/musanze" class="bkg-footer-link"><i class="bi bi-tree"></i> <?php echo tr('musanze_volcanoes'); ?></a></li>
                    <li><a href="/gorwanda-plus/destinations/nyungwe" class="bkg-footer-link"><i class="bi bi-water"></i> <?php echo tr('nyungwe_forest'); ?></a></li>
                    <li><a href="/gorwanda-plus/destinations/akagera" class="bkg-footer-link"><i class="bi bi-safari"></i> <?php echo tr('akagera_national_park'); ?></a></li>
                    <li><a href="/gorwanda-plus/destinations/lake-kivu" class="bkg-footer-link"><i class="bi bi-droplet"></i> <?php echo tr('lake_kivu'); ?></a></li>
                </ul>
            </div>

            <!-- Column 4: Legal -->
            <div class="bkg-footer-col">
                <h6 class="bkg-footer-title"><?php echo tr('legal'); ?></h6>
                <ul class="bkg-footer-links">
                    <li><a href="/gorwanda-plus/privacy" class="bkg-footer-link"><i class="bi bi-shield-lock"></i> <?php echo tr('privacy_policy'); ?></a></li>
                    <li><a href="/gorwanda-plus/terms" class="bkg-footer-link"><i class="bi bi-file-text"></i> <?php echo tr('terms_service'); ?></a></li>
                    <li><a href="/gorwanda-plus/cookies" class="bkg-footer-link"><i class="bi bi-cookie"></i> <?php echo tr('cookie_policy'); ?></a></li>
                    <li><a href="/gorwanda-plus/accessibility" class="bkg-footer-link"><i class="bi bi-universal-access"></i> <?php echo tr('accessibility'); ?></a></li>
                </ul>
            </div>

            <!-- Column 5: App & Payments -->
            <div class="bkg-footer-col">
                <h6 class="bkg-footer-title"><?php echo tr('get_app'); ?></h6>
                <p class="bkg-footer-text"><?php echo tr('book_on_the_go'); ?></p>
                <div class="bkg-app-buttons">
                    <a href="#" class="bkg-app-button" onclick="showAppAlert(); return false;">
                        <img src="https://upload.wikimedia.org/wikipedia/commons/3/3c/Download_on_the_App_Store_Badge.svg"
                            alt="App Store" loading="lazy">
                    </a>
                    <a href="#" class="bkg-app-button" onclick="showAppAlert(); return false;">
                        <img src="https://upload.wikimedia.org/wikipedia/commons/7/78/Google_Play_Store_badge_EN.svg"
                            alt="Google Play" loading="lazy">
                    </a>
                </div>

                <h6 class="bkg-footer-title mt-4"><?php echo tr('we_accept'); ?></h6>
                <div class="bkg-payment-icons">
                    <div class="bkg-payment-icon" title="Visa">
                        <div class="payment-icon-wrapper">
                            <img src="https://upload.wikimedia.org/wikipedia/commons/5/5c/Visa_Inc._logo_%282021%E2%80%93present%29.svg"
                                alt="Visa" class="bkg-payment-img visa" loading="lazy">
                        </div>
                        <span>Visa</span>
                    </div>
                    <div class="bkg-payment-icon" title="Mastercard">
                        <div class="payment-icon-wrapper">
                            <img src="https://upload.wikimedia.org/wikipedia/commons/f/f0/Mastercard_logo.svg"
                                alt="Mastercard" class="bkg-payment-img mastercard" loading="lazy">
                        </div>
                        <span>Mastercard</span>
                    </div>
                    <div class="bkg-payment-icon" title="MTN MoMo">
                        <div class="payment-icon-wrapper">
                            <img src="https://upload.wikimedia.org/wikipedia/commons/a/af/MTN_Logo.svg"
                                alt="MTN MoMo" class="bkg-payment-img mtn" loading="lazy">
                        </div>
                        <span>MTN MoMo</span>
                    </div>
                    <div class="bkg-payment-icon" title="Airtel Money">
                        <div class="payment-icon-wrapper">
                            <img src="https://upload.wikimedia.org/wikipedia/commons/f/fb/Bharti_Airtel_Logo.svg"
                                alt="Airtel Money" class="bkg-payment-img airtel" loading="lazy">
                        </div>
                        <span>Airtel Money</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Regional Partners (East African Identity) -->
        <div class="bkg-partners">
            <h6 class="bkg-partners-title"><?php echo tr('proud_partners'); ?></h6>
            <div class="bkg-partner-logos">
                <!-- Rwanda Development Board -->
                <div class="partner-logo-wrapper">
                    <img src="https://rdb.rw/wp-content/uploads/2026/02/rdb-251X55.jpg"
                        alt="Rwanda Development Board"
                        onerror="this.src='https://via.placeholder.com/150x40?text=RDB'"
                        loading="lazy">
                </div>

                <div class="partner-logo-wrapper">
                    <img src="https://www.kenyatourism.or.ke/wp-content/themes/kenya-tourism/images/logo.png"
                        alt="Magical Kenya"
                        onerror="this.style.display='none'; this.parentElement.innerHTML='🇰🇪 Magical Kenya'"
                        loading="lazy">
                </div>

                <!-- Uganda Wildlife Authority -->
                <div class="partner-logo-wrapper">
                    <img src="https://ugandawildlife.org/wp-content/uploads/2022/05/logo-whitwbg-2.svg"
                        alt="Uganda Wildlife Authority"
                        onerror="this.src='https://via.placeholder.com/150x40?text=Uganda+Wildlife'"
                        loading="lazy">
                </div>

                <!-- Tanzania Tourism -->
                <div class="partner-logo-wrapper">
                    <img src="https://www.tanzaniatourism.go.tz/wp-content/uploads/2024/09/logo.svg"
                        alt="Tanzania Tourism"
                        onerror="this.src='https://via.placeholder.com/150x40?text=Tanzania+Tourism'"
                        loading="lazy">
                </div>

                <!-- East African Community Partner Badge -->
                <span class="bkg-partner-text"><?php echo tr('eac_partner'); ?></span>
            </div>
        </div>

        <!-- Footer Divider -->
        <hr class="bkg-divider">

        <!-- Bottom Footer -->
        <div class="bkg-bottom">
            <div class="bkg-copyright">
                &copy; <?php echo date('Y'); ?> GoRwanda+ (Rwanda). <?php echo tr('all_rights_reserved'); ?>
            </div>
            <div class="bkg-bottom-links">
                <a href="/gorwanda-plus/sitemap"><?php echo tr('sitemap'); ?></a>
                <span class="mx-2">·</span>
                <a href="/gorwanda-plus/privacy"><?php echo tr('privacy'); ?></a>
                <span class="mx-2">·</span>
                <a href="/gorwanda-plus/terms"><?php echo tr('terms'); ?></a>
            </div>
            <div class="bkg-made">
                <span><?php echo tr('made_in_rwanda'); ?> <i class="bi bi-heart-fill text-danger"></i></span>
                <span class="bkg-developer">by Yves Niyibizi</span>
            </div>
        </div>
    </div>
</footer>

<!-- Scroll to Top Button -->
<button class="bkg-scroll-top" onclick="scrollToTop()" aria-label="<?php echo tr('scroll_to_top'); ?>">
    <i class="bi bi-arrow-up"></i>
</button>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Scroll to top
    function scrollToTop() {
        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    }

    // Show/hide scroll button
    window.addEventListener('scroll', function() {
        const scrollBtn = document.querySelector('.bkg-scroll-top');
        if (window.scrollY > 300) {
            scrollBtn.classList.add('visible');
        } else {
            scrollBtn.classList.remove('visible');
        }
    });

    // Newsletter subscription
    function subscribeNewsletter() {
        const email = document.querySelector('.bkg-newsletter-input').value;
        if (email && email.includes('@')) {
            alert('<?php echo addslashes(tr('thank_you_subscribe')); ?>');
            document.querySelector('.bkg-newsletter-input').value = '';
        } else {
            alert('<?php echo addslashes(tr('enter_valid_email')); ?>');
        }
    }

    // App download alert
    function showAppAlert() {
        alert('Our mobile app will be released in Q4 2026! Stay tuned for exclusive deals! 🚀');
        return false;
    }
</script>

<!-- Footer Styles -->
<style>
    /* ===== BOOKING.COM STYLE FOOTER ===== */
    .bkg-footer {
        background: #003b95;
        color: #ffffff;
        padding: 48px 0 24px;
        margin-top: 60px;
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }

    /* Save Banner */
    .bkg-save-banner {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 12px;
        padding: 24px 32px;
        margin-bottom: 48px;
        backdrop-filter: blur(4px);
    }

    .bkg-save-title {
        font-size: 1.25rem;
        font-weight: 700;
        margin-bottom: 8px;
        color: #ffffff;
    }

    .bkg-save-text {
        font-size: 0.875rem;
        color: rgba(255, 255, 255, 0.9);
        margin-bottom: 0;
    }

    .bkg-newsletter {
        display: flex;
        gap: 8px;
    }

    .bkg-newsletter-input {
        flex: 1;
        padding: 12px 16px;
        border: none;
        border-radius: 8px;
        font-size: 0.875rem;
        background: #ffffff;
        color: #1a1a1a;
    }

    .bkg-newsletter-input:focus {
        outline: none;
        box-shadow: 0 0 0 2px #febb02;
    }

    .bkg-newsletter-btn {
        padding: 12px 24px;
        background: #febb02;
        color: #003b95;
        border: none;
        border-radius: 8px;
        font-weight: 700;
        font-size: 0.875rem;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .bkg-newsletter-btn:hover {
        background: #ffcd33;
        transform: translateY(-1px);
    }

    .bkg-newsletter-note {
        font-size: 0.625rem;
        color: rgba(255, 255, 255, 0.7);
        margin-top: 8px;
        margin-bottom: 0;
    }

    /* Footer Grid */
    .bkg-footer-grid {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        gap: 32px;
        margin-bottom: 48px;
    }

    .bkg-footer-col {
        min-width: 0;
    }

    .bkg-footer-title {
        font-size: 0.875rem;
        font-weight: 700;
        margin-bottom: 20px;
        color: #ffffff;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .bkg-footer-links {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .bkg-footer-links li {
        margin-bottom: 12px;
    }

    .bkg-footer-link {
        color: rgba(255, 255, 255, 0.8);
        text-decoration: none;
        font-size: 0.75rem;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .bkg-footer-link:hover {
        color: #febb02;
        transform: translateX(4px);
    }

    .bkg-footer-link i {
        font-size: 0.875rem;
    }

    .bkg-footer-text {
        font-size: 0.75rem;
        color: rgba(255, 255, 255, 0.8);
        margin-bottom: 12px;
    }

    /* App Buttons */
    .bkg-app-buttons {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .bkg-app-button {
        display: inline-block;
        transition: transform 0.2s ease;
    }

    .bkg-app-button:hover {
        transform: translateY(-2px);
    }

    .bkg-app-button img {
        height: 40px;
        width: auto;
        border-radius: 6px;
    }

    /* Payment Icons - Fixed for visibility */
    .bkg-payment-icons {
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
        margin-top: 12px;
    }

    .bkg-payment-icon {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 6px;
        font-size: 0.625rem;
        color: rgba(255, 255, 255, 0.9);
        font-weight: 500;
    }

    /* Payment icon containers with white background for dark logos */
    .bkg-payment-icon .payment-icon-wrapper {
        background: #ffffff;
        padding: 8px 12px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        min-width: 60px;
        transition: transform 0.2s ease;
    }

    .bkg-payment-icon:hover .payment-icon-wrapper {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
    }

    .bkg-payment-img {
        height: 24px;
        width: auto;
    }

    /* Specific sizing for different cards */
    .bkg-payment-img.visa {
        height: 18px;
    }

    .bkg-payment-img.mastercard {
        height: 22px;
    }

    .bkg-payment-img.mtn {
        height: 20px;
    }

    .bkg-payment-img.airtel {
        height: 20px;
    }

    /* Partners Section - Fixed for visibility */
    .bkg-partners {
        text-align: center;
        padding: 32px 0;
        border-top: 1px solid rgba(255, 255, 255, 0.1);
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        margin-bottom: 24px;
    }

    .bkg-partners-title {
        font-size: 0.75rem;
        font-weight: 600;
        color: rgba(255, 255, 255, 0.8);
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-bottom: 24px;
    }

    .bkg-partner-logos {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 40px;
        flex-wrap: wrap;
    }

    /* Partner logo containers with white background for visibility */
    .bkg-partner-logos .partner-logo-wrapper {
        background: #ffffff;
        padding: 12px 20px;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s ease;
    }

    .bkg-partner-logos .partner-logo-wrapper:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }

    .bkg-partner-logos img {
        height: 40px;
        width: auto;
        display: block;
    }

    /* Specific partner logo sizing */
    .bkg-partner-logos img.rdb-logo {
        height: 35px;
    }

    .bkg-partner-logos img.kenya-logo {
        height: 45px;
    }

    .bkg-partner-logos img.uganda-logo {
        height: 35px;
    }

    .bkg-partner-logos img.tanzania-logo {
        height: 35px;
    }

    .bkg-partner-text {
        font-size: 0.75rem;
        color: #febb02;
        font-weight: 600;
        background: rgba(0, 0, 0, 0.2);
        padding: 8px 16px;
        border-radius: 20px;
    }

    /* Divider */
    .bkg-divider {
        border-color: rgba(255, 255, 255, 0.1);
        margin: 24px 0;
    }

    /* Bottom Footer */
    .bkg-bottom {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 16px;
        font-size: 0.6875rem;
        color: rgba(255, 255, 255, 0.7);
    }

    .bkg-copyright {
        font-size: 0.6875rem;
    }

    .bkg-bottom-links {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .bkg-bottom-links a {
        color: rgba(255, 255, 255, 0.7);
        text-decoration: none;
        transition: color 0.2s ease;
    }

    .bkg-bottom-links a:hover {
        color: #febb02;
    }

    .bkg-made {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .bkg-developer {
        font-size: 0.625rem;
        opacity: 0.7;
    }

    /* Scroll to Top Button */
    .bkg-scroll-top {
        position: fixed;
        bottom: 30px;
        right: 30px;
        width: 44px;
        height: 44px;
        border-radius: 50%;
        background: #003b95;
        color: white;
        border: none;
        cursor: pointer;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        z-index: 1000;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .bkg-scroll-top.visible {
        opacity: 1;
        visibility: visible;
    }

    .bkg-scroll-top:hover {
        background: #febb02;
        color: #003b95;
        transform: translateY(-3px);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
    }

    .bkg-scroll-top i {
        font-size: 1.25rem;
    }

    /* Responsive Design */
    @media (max-width: 1200px) {
        .bkg-footer-grid {
            gap: 24px;
        }
    }

    @media (max-width: 992px) {
        .bkg-footer-grid {
            grid-template-columns: repeat(3, 1fr);
            gap: 32px;
        }

        .bkg-save-banner {
            text-align: center;
        }

        .bkg-newsletter {
            margin-top: 16px;
        }

        .bkg-partner-logos {
            gap: 20px;
        }

        .bkg-partner-logos .partner-logo-wrapper {
            padding: 8px 16px;
        }

        .bkg-partner-logos img {
            height: 32px;
        }
    }

    @media (max-width: 768px) {
        .bkg-footer {
            padding: 32px 0 20px;
        }

        .bkg-footer-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
        }

        .bkg-save-banner {
            padding: 20px;
        }

        .bkg-newsletter {
            flex-direction: column;
        }

        .bkg-newsletter-btn {
            width: 100%;
        }

        .bkg-bottom {
            flex-direction: column;
            text-align: center;
        }

        .bkg-partner-logos {
            gap: 16px;
        }

        .bkg-partner-logos .partner-logo-wrapper {
            padding: 6px 12px;
        }

        .bkg-partner-logos img {
            height: 28px;
        }

        .bkg-payment-icons {
            justify-content: center;
        }

        .bkg-scroll-top {
            bottom: 20px;
            right: 20px;
            width: 40px;
            height: 40px;
        }
    }

    @media (max-width: 576px) {
        .bkg-footer-grid {
            grid-template-columns: 1fr;
            gap: 28px;
        }

        .bkg-footer-col {
            text-align: center;
        }

        .bkg-footer-link {
            justify-content: center;
        }

        .bkg-app-buttons {
            align-items: center;
        }

        .bkg-payment-icons {
            justify-content: center;
        }

        .bkg-partner-logos {
            flex-direction: column;
            align-items: center;
        }

        .bkg-save-title {
            font-size: 1.125rem;
        }
    }

    /* Flag icon fallback */
    .fi {
        display: inline-block;
        width: 20px;
        height: 15px;
        background-size: cover;
        background-position: center;
    }

    .fi-rw {
        background-image: url('https://flagcdn.com/w20/rw.png');
    }

    /* Animation for newsletter button */
    @keyframes pulse {
        0% {
            transform: scale(1);
        }

        50% {
            transform: scale(1.05);
        }

        100% {
            transform: scale(1);
        }
    }

    .bkg-newsletter-btn:active {
        animation: pulse 0.3s ease;
    }

    /* Hover effect for footer links */
    .bkg-footer-link {
        position: relative;
    }

    .bkg-footer-link::after {
        content: '';
        position: absolute;
        bottom: -2px;
        left: 0;
        width: 0;
        height: 1px;
        background: #febb02;
        transition: width 0.2s ease;
    }

    .bkg-footer-link:hover::after {
        width: 100%;
    }

    /* Country flag styling for Rwanda spotlight */
    .bkg-footer-title .fi {
        vertical-align: middle;
        margin-right: 4px;
    }
</style>

</body>

</html>
<?php if (ob_get_level()) ob_end_flush(); ?>