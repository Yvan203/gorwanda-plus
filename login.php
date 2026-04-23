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
?>

<style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        min-height: 100vh;
    }

    /* Main Container */
    .login-container {
        min-height: calc(100vh - 60px);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 40px 20px;
    }

    /* Login Card */
    .login-card {
        background: white;
        border-radius: 20px;
        box-shadow: 0 20px 40px -12px rgba(0, 0, 0, 0.25);
        display: flex;
        max-width: 900px;
        width: 100%;
        overflow: hidden;
        transition: transform 0.3s ease;
    }

    .login-card:hover {
        transform: translateY(-2px);
    }

    /* Left Section - Branding */
    .brand-section {
        flex: 1;
        background: linear-gradient(135deg, #003b95 0%, #0066cc 100%);
        padding: 32px 28px;
        color: white;
        position: relative;
        overflow: hidden;
    }

    .brand-section::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255, 255, 255, 0.08) 0%, transparent 70%);
        animation: pulse 8s ease-in-out infinite;
    }

    @keyframes pulse {

        0%,
        100% {
            transform: scale(1);
            opacity: 0.5;
        }

        50% {
            transform: scale(1.1);
            opacity: 0.3;
        }
    }

    .brand-logo {
        position: relative;
        z-index: 1;
        margin-bottom: 32px;
        text-align: center;
    }

    .brand-logo img {
        height: 48px;
        width: auto;
        filter: brightness(0) invert(1);
    }

    .brand-tagline {
        position: relative;
        z-index: 1;
        font-size: 12px;
        text-align: center;
        opacity: 0.85;
        margin-bottom: 32px;
        line-height: 1.4;
    }

    .stats-grid {
        position: relative;
        z-index: 1;
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
        margin-bottom: 28px;
    }

    .stat-card {
        background: rgba(255, 255, 255, 0.1);
        backdrop-filter: blur(10px);
        border-radius: 12px;
        padding: 12px;
        text-align: center;
        transition: transform 0.3s ease;
    }

    .stat-card:hover {
        transform: translateY(-2px);
        background: rgba(255, 255, 255, 0.15);
    }

    .stat-number {
        font-size: 22px;
        font-weight: 700;
        color: #febb02;
        margin-bottom: 2px;
    }

    .stat-label {
        font-size: 10px;
        opacity: 0.9;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .feature-list {
        position: relative;
        z-index: 1;
    }

    .feature-item {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 12px;
        padding: 8px 10px;
        background: rgba(255, 255, 255, 0.06);
        border-radius: 10px;
        transition: transform 0.3s ease;
    }

    .feature-item:hover {
        transform: translateX(4px);
        background: rgba(255, 255, 255, 0.1);
    }

    .feature-icon {
        width: 32px;
        height: 32px;
        background: rgba(255, 255, 255, 0.12);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
    }

    .feature-text h4 {
        font-size: 12px;
        font-weight: 600;
        margin-bottom: 1px;
    }

    .feature-text p {
        font-size: 10px;
        opacity: 0.8;
    }

    /* Right Section - Form */
    .form-section {
        flex: 1;
        padding: 32px 28px;
        background: white;
    }

    .mobile-header {
        display: none;
        text-align: center;
        margin-bottom: 24px;
    }

    .mobile-header img {
        height: 40px;
        width: auto;
    }

    .form-header {
        margin-bottom: 24px;
    }

    .form-header h1 {
        font-size: 22px;
        font-weight: 700;
        color: #1a1a1a;
        margin-bottom: 6px;
        letter-spacing: -0.3px;
    }

    .form-header p {
        font-size: 12px;
        color: #666;
    }

    /* Form Elements */
    .form-group {
        margin-bottom: 18px;
    }

    .form-label {
        display: block;
        font-size: 11px;
        font-weight: 600;
        color: #333;
        margin-bottom: 6px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .input-wrapper {
        position: relative;
    }

    .input-icon {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #999;
        font-size: 14px;
        pointer-events: none;
    }

    .form-input {
        width: 100%;
        padding: 10px 12px 10px 36px;
        border: 1.5px solid #e5e5e5;
        border-radius: 10px;
        font-size: 13px;
        transition: all 0.3s ease;
        font-family: inherit;
    }

    .form-input:focus {
        outline: none;
        border-color: #003b95;
        box-shadow: 0 0 0 3px rgba(0, 59, 149, 0.08);
    }

    .password-toggle {
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        cursor: pointer;
        color: #999;
        font-size: 14px;
        transition: color 0.3s ease;
    }

    .password-toggle:hover {
        color: #003b95;
    }

    /* Options */
    .options-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 18px;
    }

    .checkbox-wrapper {
        display: flex;
        align-items: center;
        gap: 6px;
        cursor: pointer;
    }

    .checkbox-wrapper input[type="checkbox"] {
        width: 14px;
        height: 14px;
        cursor: pointer;
        accent-color: #003b95;
    }

    .checkbox-label {
        font-size: 12px;
        color: #666;
        cursor: pointer;
    }

    .forgot-link {
        font-size: 12px;
        color: #003b95;
        text-decoration: none;
        font-weight: 500;
    }

    .forgot-link:hover {
        text-decoration: underline;
    }

    /* Submit Button */
    .btn-primary {
        width: 100%;
        padding: 11px;
        background: #003b95;
        color: white;
        border: none;
        border-radius: 10px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
    }

    .btn-primary:hover {
        background: #00224f;
        transform: translateY(-1px);
        box-shadow: 0 4px 10px rgba(0, 59, 149, 0.2);
    }

    /* Divider */
    .divider {
        display: flex;
        align-items: center;
        margin: 18px 0;
        color: #999;
        font-size: 11px;
    }

    .divider::before,
    .divider::after {
        content: '';
        flex: 1;
        height: 1px;
        background: #e5e5e5;
    }

    .divider span {
        padding: 0 12px;
    }

    /* Social Button */
    .btn-social {
        width: 100%;
        padding: 10px;
        background: white;
        border: 1.5px solid #e5e5e5;
        border-radius: 10px;
        font-size: 13px;
        font-weight: 500;
        color: #333;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .btn-social:hover {
        background: #f8f8f8;
        border-color: #003b95;
    }

    .google-icon {
        width: 18px;
        height: 18px;
    }

    /* Register Link */
    .register-link {
        text-align: center;
        margin-top: 20px;
        padding-top: 20px;
        border-top: 1px solid #e5e5e5;
        font-size: 12px;
        color: #666;
    }

    .register-link a {
        color: #003b95;
        text-decoration: none;
        font-weight: 600;
        margin-left: 4px;
    }

    .register-link a:hover {
        text-decoration: underline;
    }

    /* Error Message */
    .error-message {
        background: #fee;
        border: 1px solid #fcc;
        border-left: 3px solid #c41c1c;
        color: #c41c1c;
        padding: 10px 12px;
        border-radius: 10px;
        font-size: 12px;
        margin-bottom: 18px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    /* Trust Badges */
    .trust-badges {
        display: flex;
        justify-content: center;
        gap: 20px;
        margin-top: 24px;
    }

    .trust-badge {
        display: flex;
        align-items: center;
        gap: 5px;
        font-size: 10px;
        color: #999;
    }

    .trust-badge i {
        color: #008009;
        font-size: 12px;
    }

    /* Loading State */
    .btn-primary.loading {
        pointer-events: none;
        opacity: 0.7;
    }

    .btn-primary.loading::after {
        content: '';
        width: 16px;
        height: 16px;
        border: 2px solid white;
        border-radius: 50%;
        border-top-color: transparent;
        animation: spin 0.8s linear infinite;
        margin-left: 6px;
    }

    @keyframes spin {
        to {
            transform: rotate(360deg);
        }
    }

    /* Responsive Design */
    @media (max-width: 800px) {
        .brand-section {
            display: none;
        }

        .form-section {
            flex: none;
            width: 100%;
            max-width: 400px;
            margin: 0 auto;
        }

        .mobile-header {
            display: block;
        }

        .login-card {
            max-width: 400px;
        }
    }

    @media (max-width: 480px) {
        .login-container {
            padding: 20px 16px;
        }

        .form-section {
            padding: 24px 20px;
        }

        .form-header h1 {
            font-size: 20px;
        }

        .options-row {
            flex-direction: column;
            gap: 10px;
            align-items: flex-start;
        }

        .trust-badges {
            flex-wrap: wrap;
            gap: 12px;
        }
    }
</style>

<div class="login-container">
    <div class="login-card">
        <!-- Left Section - Branding -->
        <div class="brand-section">
            <div class="brand-logo">
                <img src="/gorwanda-plus/assets/images/go.png" alt="GoRwanda+">
            </div>
            <div class="brand-tagline">
                Discover Rwanda's best stays, cars, experiences, and restaurants
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($totalStays); ?>+</div>
                    <div class="stat-label">Stays</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($totalCars); ?>+</div>
                    <div class="stat-label">Cars</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($totalExperiences); ?>+</div>
                    <div class="stat-label">Experiences</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($totalRestaurants); ?>+</div>
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
                        <p>256-bit SSL encryption</p>
                    </div>
                </div>
                <div class="feature-item">
                    <div class="feature-icon">
                        <i class="bi bi-headset"></i>
                    </div>
                    <div class="feature-text">
                        <h4>24/7 Support</h4>
                        <p>We're here to help</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Section - Form -->
        <div class="form-section">
            <!-- Mobile Header -->
            <div class="mobile-header">
                <img src="/gorwanda-plus/assets/images/go.png" alt="GoRwanda+">
            </div>

            <div class="form-header">
                <h1>Welcome back</h1>
                <p>Sign in to manage your bookings</p>
            </div>

            <?php if ($error): ?>
                <div class="error-message">
                    <i class="bi bi-exclamation-circle-fill"></i>
                    <span><?php echo $error; ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="loginForm">
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <div class="input-wrapper">
                        <i class="bi bi-envelope-fill input-icon"></i>
                        <input type="email" name="email" class="form-input"
                            placeholder="your@email.com"
                            value="<?php echo sanitize($email); ?>"
                            required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Password</label>
                    <div class="input-wrapper">
                        <i class="bi bi-lock-fill input-icon"></i>
                        <input type="password" name="password" id="password" class="form-input"
                            placeholder="Enter your password" required>
                        <i class="bi bi-eye-slash password-toggle" id="togglePassword" onclick="togglePassword()"></i>
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

            <div class="divider">
                <span>OR</span>
            </div>

            <button type="button" class="btn-social" onclick="handleGoogleLogin()">
                <svg class="google-icon" viewBox="0 0 24 24">
                    <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" />
                    <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" />
                    <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" />
                    <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" />
                </svg>
                Continue with Google
            </button>

            <div class="register-link">
                New to GoRwanda+? <a href="register.php">Create account</a>
            </div>

            <div class="trust-badges">
                <div class="trust-badge">
                    <i class="bi bi-shield-check"></i>
                    <span>256-bit SSL</span>
                </div>
                <div class="trust-badge">
                    <i class="bi bi-lock-fill"></i>
                    <span>Secure</span>
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
    function togglePassword() {
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
        alert('Google sign-in coming soon!');
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
</script>

<?php require_once 'includes/footer.php'; ?>