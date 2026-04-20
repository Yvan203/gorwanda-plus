<?php
ob_start();
require_once 'includes/functions.php';

if (isLoggedIn()) {
    ob_end_clean();
    header('Location: /gorwanda-plus/');
    exit;
}

$pageTitle = 'Sign In';
$hideSearch = true;
require_once 'includes/header.php';

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password';
    } else {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['user_type'] = $user['user_type'];
            $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
            
            $redirect = $_SESSION['redirect_after_login'] ?? '';
            unset($_SESSION['redirect_after_login']);
            
            if ($redirect && strpos($redirect, 'partner-onboarding') === false) {
                ob_end_clean();
                header('Location: ' . $redirect);
                exit;
            }
            
            $dashboardUrl = getDashboardUrl($user);
            
            if ($user['user_type'] === 'business_owner' && !isPartnerProfileComplete($user['user_id'])) {
                $dashboardUrl = '/gorwanda-plus/partner/onboarding.php';
            }
            
            ob_end_clean();
            header('Location: ' . $dashboardUrl);
            exit;
        } else {
            $error = 'Invalid email or password';
        }
    }
}

ob_end_flush();

// Get some stats for the left side
$db = getDB();
$totalStays = $db->query("SELECT COUNT(*) FROM stays WHERE is_active = 1 AND is_verified = 1")->fetchColumn();
$totalCars = $db->query("SELECT COUNT(*) FROM car_rentals WHERE is_active = 1 AND is_verified = 1")->fetchColumn();
$totalExperiences = $db->query("SELECT COUNT(*) FROM attractions WHERE is_active = 1 AND is_verified = 1")->fetchColumn();
$totalRestaurants = $db->query("SELECT COUNT(*) FROM restaurants WHERE is_active = 1")->fetchColumn();
$totalUsers = $db->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();
?>

<style>
/* ===== MODERN TWO-COLUMN LOGIN - Booking.com Inspired ===== */
:root {
    /* Booking.com Color Palette */
    --booking-blue: #003b95;
    --booking-blue-light: #f0f4ff;
    --booking-blue-dark: #00224f;
    --booking-accent: #ffb700;
    --booking-gray: #f5f5f5;
    --booking-white: #ffffff;
    --booking-text: #1a1a1a;
    --booking-text-light: #595959;
    --booking-text-lighter: #a5a5a5;
    --booking-border: #e7e7e7;
    --booking-success: #008009;
    --booking-error: #c41c1c;
    
    /* Spacing & Effects */
    --radius-sm: 4px;
    --radius-md: 8px;
    --radius-lg: 12px;
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.04);
    --shadow-md: 0 4px 16px rgba(0,0,0,0.08);
    --shadow-lg: 0 12px 32px rgba(0,0,0,0.12);
    --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    font-size: 14px;
    line-height: 1.5;
    color: var(--booking-text);
    background: var(--booking-gray);
}

/* ===== MAIN LAYOUT ===== */
.auth-split {
    display: flex;
    min-height: 100vh;
    width: 100%;
}

/* ===== LEFT SIDE - BRAND SHOWCASE ===== */
.auth-showcase {
    flex: 1.2;
    background: linear-gradient(135deg, var(--booking-blue-dark) 0%, var(--booking-blue) 100%);
    color: white;
    position: relative;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 48px;
}

/* Animated Background Pattern */
.showcase-pattern {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-image: 
        radial-gradient(circle at 20% 30%, rgba(255,255,255,0.1) 0%, transparent 30%),
        radial-gradient(circle at 80% 70%, rgba(255,255,255,0.08) 0%, transparent 35%),
        radial-gradient(circle at 40% 80%, rgba(255,183,0,0.1) 0%, transparent 40%),
        radial-gradient(circle at 70% 20%, rgba(255,255,255,0.05) 0%, transparent 30%);
    animation: patternShift 25s ease infinite;
}

@keyframes patternShift {
    0%, 100% { transform: scale(1) rotate(0deg); opacity: 1; }
    50% { transform: scale(1.1) rotate(2deg); opacity: 0.8; }
}

/* Floating Elements */
.floating-element {
    position: absolute;
    background: rgba(255,255,255,0.03);
    border-radius: 50%;
    pointer-events: none;
}

.element-1 {
    width: 300px;
    height: 300px;
    top: -100px;
    right: -50px;
    background: radial-gradient(circle, rgba(255,255,255,0.05) 0%, transparent 70%);
    animation: float1 20s infinite;
}

.element-2 {
    width: 200px;
    height: 200px;
    bottom: -50px;
    left: -50px;
    background: radial-gradient(circle, rgba(255,183,0,0.05) 0%, transparent 70%);
    animation: float2 18s infinite;
}

.element-3 {
    width: 150px;
    height: 150px;
    top: 40%;
    right: 15%;
    background: radial-gradient(circle, rgba(255,255,255,0.04) 0%, transparent 60%);
    animation: float3 22s infinite;
}

@keyframes float1 {
    0%, 100% { transform: translate(0, 0) rotate(0deg); }
    33% { transform: translate(-30px, 20px) rotate(5deg); }
    66% { transform: translate(20px, -30px) rotate(-5deg); }
}

@keyframes float2 {
    0%, 100% { transform: translate(0, 0) scale(1); }
    50% { transform: translate(30px, -20px) scale(1.1); }
}

@keyframes float3 {
    0%, 100% { transform: translate(0, 0) rotate(0deg); }
    33% { transform: translate(20px, 20px) rotate(10deg); }
    66% { transform: translate(-20px, -20px) rotate(-10deg); }
}

/* Showcase Content */
.showcase-content {
    position: relative;
    z-index: 10;
    max-width: 500px;
    animation: fadeInUp 0.8s ease;
}

.brand-large {
    margin-bottom: 40px;
}

.brand-logo-wrapper {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 24px;
}

.brand-logo-icon {
    width: 56px;
    height: 56px;
    background: var(--booking-accent);
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    color: var(--booking-blue-dark);
    box-shadow: 0 10px 20px rgba(0,0,0,0.2);
    transform: rotate(-5deg);
    transition: var(--transition);
}

.brand-logo-icon:hover {
    transform: rotate(0deg) scale(1.05);
}

.brand-name {
    font-size: 2rem;
    font-weight: 800;
    letter-spacing: -0.5px;
}

.brand-name span {
    color: var(--booking-accent);
}

.brand-tagline {
    font-size: 1rem;
    opacity: 0.9;
    max-width: 400px;
    line-height: 1.6;
}

/* Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 24px;
    margin-bottom: 48px;
}

.stat-item {
    background: rgba(255,255,255,0.1);
    backdrop-filter: blur(10px);
    padding: 24px;
    border-radius: var(--radius-lg);
    text-align: center;
    transition: var(--transition);
    border: 1px solid rgba(255,255,255,0.1);
}

.stat-item:hover {
    transform: translateY(-4px);
    background: rgba(255,255,255,0.15);
    border-color: rgba(255,255,255,0.2);
}

.stat-value {
    font-size: 2rem;
    font-weight: 800;
    color: var(--booking-accent);
    margin-bottom: 4px;
}

.stat-label {
    font-size: 0.8125rem;
    opacity: 0.9;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Feature List */
.feature-list {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.feature-item {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px;
    background: rgba(255,255,255,0.05);
    border-radius: var(--radius-md);
    backdrop-filter: blur(5px);
    border: 1px solid rgba(255,255,255,0.1);
    transition: var(--transition);
}

.feature-item:hover {
    background: rgba(255,255,255,0.08);
    transform: translateX(8px);
}

.feature-icon {
    width: 48px;
    height: 48px;
    background: rgba(255,183,0,0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    color: var(--booking-accent);
}

.feature-text h4 {
    font-size: 1rem;
    font-weight: 700;
    margin-bottom: 2px;
}

.feature-text p {
    font-size: 0.8125rem;
    opacity: 0.8;
}

/* ===== RIGHT SIDE - LOGIN FORM ===== */
.auth-form {
    flex: 0.8;
    background: var(--booking-white);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 48px;
    position: relative;
    overflow-y: auto;
}

.form-container {
    max-width: 400px;
    width: 100%;
    animation: fadeInRight 0.8s ease;
}

@keyframes fadeInRight {
    from {
        opacity: 0;
        transform: translateX(30px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

/* Logo on mobile (hidden on desktop) */
.mobile-logo {
    display: none;
    text-align: center;
    margin-bottom: 32px;
}

.mobile-logo .brand-name {
    color: var(--booking-text);
}

.mobile-logo .brand-name span {
    color: var(--booking-blue);
}

/* Form Header */
.form-header {
    margin-bottom: 32px;
}

.form-header h1 {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--booking-text);
    margin-bottom: 8px;
    letter-spacing: -0.5px;
}

.form-header p {
    font-size: 0.9375rem;
    color: var(--booking-text-light);
}

/* Form Groups */
.form-group {
    margin-bottom: 20px;
    position: relative;
}

.form-label {
    display: block;
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--booking-text);
    margin-bottom: 6px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.input-wrapper {
    position: relative;
}

.input-icon {
    position: absolute;
    left: 16px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--booking-text-lighter);
    font-size: 1rem;
    transition: var(--transition);
    pointer-events: none;
}

.form-input {
    width: 100%;
    padding: 14px 16px 14px 48px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-md);
    font-size: 0.9375rem;
    transition: var(--transition);
    background: var(--booking-white);
}

.form-input:hover {
    border-color: #b0b0b0;
}

.form-input:focus {
    outline: none;
    border-color: var(--booking-blue);
    box-shadow: 0 0 0 3px rgba(0,59,149,0.1);
}

.form-input:focus + .input-icon {
    color: var(--booking-blue);
}

/* Password visibility toggle */
.password-toggle {
    position: absolute;
    right: 16px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--booking-text-lighter);
    cursor: pointer;
    font-size: 1rem;
    transition: var(--transition);
}

.password-toggle:hover {
    color: var(--booking-blue);
}

/* Options Row */
.options-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
}

/* Custom Checkbox */
.checkbox-wrapper {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
}

.checkbox-wrapper input[type="checkbox"] {
    width: 18px;
    height: 18px;
    border: 2px solid var(--booking-border);
    border-radius: 4px;
    appearance: none;
    cursor: pointer;
    transition: var(--transition);
    position: relative;
}

.checkbox-wrapper input[type="checkbox"]:checked {
    background: var(--booking-blue);
    border-color: var(--booking-blue);
}

.checkbox-wrapper input[type="checkbox"]:checked::after {
    content: '';
    position: absolute;
    top: 2px;
    left: 5px;
    width: 4px;
    height: 8px;
    border: solid white;
    border-width: 0 2px 2px 0;
    transform: rotate(45deg);
}

.checkbox-label {
    font-size: 0.875rem;
    color: var(--booking-text-light);
    cursor: pointer;
}

.forgot-link {
    font-size: 0.875rem;
    color: var(--booking-blue);
    font-weight: 600;
    text-decoration: none;
    transition: var(--transition);
    position: relative;
}

.forgot-link::after {
    content: '';
    position: absolute;
    bottom: -2px;
    left: 0;
    width: 0;
    height: 2px;
    background: var(--booking-blue);
    transition: width 0.2s ease;
}

.forgot-link:hover::after {
    width: 100%;
}

/* Primary Button */
.btn-primary {
    width: 100%;
    padding: 14px 24px;
    background: var(--booking-blue);
    color: white;
    border: none;
    border-radius: var(--radius-md);
    font-size: 1rem;
    font-weight: 700;
    cursor: pointer;
    transition: var(--transition);
    position: relative;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.btn-primary::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
    transition: left 0.5s ease;
}

.btn-primary:hover {
    background: var(--booking-blue-dark);
    transform: translateY(-1px);
    box-shadow: var(--shadow-md);
}

.btn-primary:hover::before {
    left: 100%;
}

.btn-primary i {
    font-size: 1rem;
    transition: transform 0.3s ease;
}

.btn-primary:hover i {
    transform: translateX(4px);
}

.btn-primary.loading {
    pointer-events: none;
    opacity: 0.8;
}

.btn-primary.loading::after {
    content: '';
    width: 20px;
    height: 20px;
    border: 2px solid white;
    border-radius: 50%;
    border-top-color: transparent;
    animation: spin 0.8s linear infinite;
    margin-left: 8px;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Divider */
.divider {
    display: flex;
    align-items: center;
    margin: 24px 0;
    color: var(--booking-text-lighter);
    font-size: 0.75rem;
}

.divider::before,
.divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--booking-border);
}

.divider span {
    padding: 0 16px;
}

/* Social Login */
.btn-social {
    width: 100%;
    padding: 12px 24px;
    background: white;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-md);
    font-size: 0.9375rem;
    font-weight: 600;
    color: var(--booking-text);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    cursor: pointer;
    transition: var(--transition);
    margin-bottom: 16px;
}

.btn-social:hover {
    background: var(--booking-gray);
    border-color: var(--booking-blue);
    transform: translateY(-1px);
}

.google-icon {
    width: 20px;
    height: 20px;
}

/* Register Link */
.register-link {
    text-align: center;
    margin: 24px 0 16px;
    font-size: 0.9375rem;
    color: var(--booking-text-light);
}

.register-link a {
    color: var(--booking-blue);
    font-weight: 700;
    text-decoration: none;
    margin-left: 4px;
    transition: var(--transition);
}

.register-link a:hover {
    color: var(--booking-blue-dark);
    text-decoration: underline;
}

/* Error Message */
.error-message {
    background: #fee;
    border: 1px solid #fcc;
    border-left: 4px solid var(--booking-error);
    color: var(--booking-error);
    padding: 14px 16px;
    border-radius: var(--radius-md);
    font-size: 0.875rem;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 10px;
    animation: shake 0.5s ease;
}

@keyframes shake {
    0%, 100% { transform: translateX(0); }
    25% { transform: translateX(-5px); }
    75% { transform: translateX(5px); }
}

/* Trust Badges */
.trust-badges {
    display: flex;
    justify-content: center;
    gap: 24px;
    margin-top: 32px;
    padding-top: 24px;
    border-top: 1px solid var(--booking-border);
}

.trust-badge {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.75rem;
    color: var(--booking-text-lighter);
}

.trust-badge i {
    color: var(--booking-success);
    font-size: 1rem;
}

/* ===== RESPONSIVE DESIGN ===== */
@media (max-width: 992px) {
    .auth-split {
        flex-direction: column;
    }
    
    .auth-showcase {
        display: none;
    }
    
    .mobile-logo {
        display: block;
    }
    
    .auth-form {
        padding: 32px 24px;
        min-height: 100vh;
    }
    
    .form-container {
        max-width: 100%;
    }
}

@media (max-width: 480px) {
    .auth-form {
        padding: 24px 16px;
    }
    
    .options-row {
        flex-direction: column;
        gap: 12px;
        align-items: flex-start;
    }
    
    .trust-badges {
        flex-direction: column;
        align-items: center;
        gap: 12px;
    }
}

/* Reduced Motion */
@media (prefers-reduced-motion: reduce) {
    * {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
    }
}
</style>

<div class="auth-split">
    <!-- Left Side - Brand Showcase -->
    <div class="auth-showcase">
        <div class="floating-element element-1"></div>
        <div class="floating-element element-2"></div>
        <div class="floating-element element-3"></div>
        <div class="showcase-pattern"></div>
        
        <div class="showcase-content">
            <div class="brand-large">
                <div class="brand-logo-wrapper">
                    <div class="brand-logo-icon">
                        <i class="bi bi-suitcase-lg-fill"></i>
                    </div>
                    <div class="brand-name">
                        GoRwanda<span>+</span>
                    </div>
                </div>
                <p class="brand-tagline">
                    Discover Rwanda's best stays, cars, experiences, and restaurants. 
                    Join thousands of travelers exploring the land of a thousand hills.
                </p>
            </div>

            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-value"><?php echo number_format($totalStays); ?>+</div>
                    <div class="stat-label">Stays</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo number_format($totalCars); ?>+</div>
                    <div class="stat-label">Cars</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo number_format($totalExperiences); ?>+</div>
                    <div class="stat-label">Experiences</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo number_format($totalRestaurants); ?>+</div>
                    <div class="stat-label">Restaurants</div>
                </div>
            </div>

            <div class="feature-list">
                <div class="feature-item">
                    <div class="feature-icon">
                        <i class="bi bi-star-fill"></i>
                    </div>
                    <div class="feature-text">
                        <h4>Best Price Guarantee</h4>
                        <p>Found a better price? We'll match it</p>
                    </div>
                </div>
                <div class="feature-item">
                    <div class="feature-icon">
                        <i class="bi bi-shield-check"></i>
                    </div>
                    <div class="feature-text">
                        <h4>Secure Booking</h4>
                        <p>256-bit SSL encryption for your safety</p>
                    </div>
                </div>
                <div class="feature-item">
                    <div class="feature-icon">
                        <i class="bi bi-headset"></i>
                    </div>
                    <div class="feature-text">
                        <h4>24/7 Customer Support</h4>
                        <p>We're here to help, day or night</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Side - Login Form -->
    <div class="auth-form">
        <div class="form-container">
            <!-- Mobile Logo (visible on small screens) -->
            <div class="mobile-logo">
                <div class="brand-name">
                    GoRwanda<span>+</span>
                </div>
                <p style="color: var(--booking-text-light); font-size: 0.875rem; margin-top: 8px;">Sign in to continue</p>
            </div>

            <!-- Form Header -->
            <div class="form-header">
                <h1>Welcome back</h1>
                <p>Sign in to manage your bookings and explore Rwanda's best offerings</p>
            </div>

            <!-- Error Message -->
            <?php if ($error): ?>
            <div class="error-message">
                <i class="bi bi-exclamation-circle-fill"></i>
                <span><?php echo $error; ?></span>
            </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form method="POST" action="" id="loginForm">
                <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <div class="input-wrapper">
                        <i class="bi bi-envelope-fill input-icon"></i>
                        <input type="email" name="email" class="form-input" 
                               placeholder="your@email.com" 
                               value="<?php echo sanitize($email); ?>" 
                               required autocomplete="email">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Password</label>
                    <div class="input-wrapper">
                        <i class="bi bi-lock-fill input-icon"></i>
                        <input type="password" name="password" id="password" class="form-input" 
                               placeholder="Enter your password" 
                               required autocomplete="current-password">
                        <i class="bi bi-eye-slash password-toggle" id="togglePassword" onclick="togglePasswordVisibility()"></i>
                    </div>
                </div>

                <div class="options-row">
                    <label class="checkbox-wrapper">
                        <input type="checkbox" name="remember" id="remember">
                        <span class="checkbox-label">Remember me</span>
                    </label>
                    <a href="/gorwanda-plus/forgot-password.php" class="forgot-link">Forgot password?</a>
                </div>

                <button type="submit" class="btn-primary" id="submitBtn">
                    Sign In <i class="bi bi-arrow-right"></i>
                </button>
            </form>

            <!-- Divider -->
            <div class="divider">
                <span>OR</span>
            </div>

            <!-- Social Login -->
            <button type="button" class="btn-social" onclick="handleGoogleLogin()">
                <svg class="google-icon" viewBox="0 0 24 24">
                    <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                    <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                    <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                    <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                </svg>
                Continue with Google
            </button>

            <!-- Register Link -->
            <div class="register-link">
                Don't have an account? <a href="register.php">Create account</a>
            </div>

            <!-- Trust Badges -->
            <div class="trust-badges">
                <div class="trust-badge">
                    <i class="bi bi-shield-check"></i>
                    <span>256-bit SSL</span>
                </div>
                <div class="trust-badge">
                    <i class="bi bi-lock-fill"></i>
                    <span>Secure Login</span>
                </div>
                <div class="trust-badge">
                    <i class="bi bi-check-circle-fill"></i>
                    <span>Verified</span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Password visibility toggle
function togglePasswordVisibility() {
    const passwordInput = document.getElementById('password');
    const toggleIcon = document.getElementById('togglePassword');
    
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        toggleIcon.classList.remove('bi-eye-slash');
        toggleIcon.classList.add('bi-eye');
    } else {
        passwordInput.type = 'password';
        toggleIcon.classList.remove('bi-eye');
        toggleIcon.classList.add('bi-eye-slash');
    }
}

// Form submission loading state
document.getElementById('loginForm').addEventListener('submit', function(e) {
    const btn = document.getElementById('submitBtn');
    btn.classList.add('loading');
    btn.innerHTML = 'Signing in...';
});

// Google login handler
function handleGoogleLogin() {
    showNotification('Google sign-in coming soon!', 'info');
}

// Remember me functionality
const rememberCheckbox = document.getElementById('remember');
if (localStorage.getItem('rememberEmail')) {
    rememberCheckbox.checked = true;
    document.querySelector('input[name="email"]').value = localStorage.getItem('rememberEmail');
}

document.getElementById('loginForm').addEventListener('submit', function() {
    if (rememberCheckbox.checked) {
        localStorage.setItem('rememberEmail', document.querySelector('input[name="email"]').value);
    } else {
        localStorage.removeItem('rememberEmail');
    }
});

// Notification system
function showNotification(message, type = 'success') {
    const notification = document.createElement('div');
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${type === 'success' ? '#e6f4ea' : '#e6f0ff'};
        color: ${type === 'success' ? '#008009' : '#003b95'};
        padding: 16px 24px;
        border-radius: 8px;
        font-weight: 600;
        z-index: 10000;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        animation: slideInRight 0.3s ease;
        border-left: 4px solid ${type === 'success' ? '#008009' : '#003b95'};
        display: flex;
        align-items: center;
        gap: 12px;
    `;
    notification.innerHTML = `
        <i class="bi bi-${type === 'success' ? 'check-circle' : 'info-circle'}-fill"></i>
        <span>${message}</span>
    `;
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.style.animation = 'slideOutRight 0.3s ease';
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}

// Add slide animations
const style = document.createElement('style');
style.textContent = `
    @keyframes slideInRight {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    @keyframes slideOutRight {
        from { transform: translateX(0); opacity: 1; }
        to { transform: translateX(100%); opacity: 0; }
    }
`;
document.head.appendChild(style);
</script>

<?php require_once 'includes/footer.php'; ?>