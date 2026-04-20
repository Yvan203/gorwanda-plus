<?php
ob_start();
require_once 'includes/functions.php';

if (isLoggedIn()) {
    ob_end_clean();
    header('Location: /gorwanda-plus/');
    exit;
}

// Get counts for social proof
$db = getDB();
$totalUsers = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalListings = $db->query("SELECT COUNT(*) FROM stays")->fetchColumn() + 
                 $db->query("SELECT COUNT(*) FROM attractions")->fetchColumn() + 
                 $db->query("SELECT COUNT(*) FROM car_rentals")->fetchColumn();
$totalReviews = $db->query("SELECT COUNT(*) FROM reviews")->fetchColumn();

$pageTitle = 'Create Account - GoRwanda+';
$hideSearch = true;
require_once 'includes/header.php';

$error = '';
$success = '';
$formData = [
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'phone' => '',
    'user_type' => 'tourist'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData = [
        'first_name' => sanitize($_POST['first_name'] ?? ''),
        'last_name' => sanitize($_POST['last_name'] ?? ''),
        'email' => sanitize($_POST['email'] ?? ''),
        'phone' => sanitize($_POST['phone'] ?? ''),
        'password' => $_POST['password'] ?? '',
        'confirm_password' => $_POST['confirm_password'] ?? '',
        'user_type' => sanitize($_POST['user_type'] ?? 'tourist')
    ];
    
    if (empty($formData['first_name']) || empty($formData['last_name'])) {
        $error = 'Please enter your full name';
    } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address';
    } elseif (strlen($formData['password']) < 6) {
        $error = 'Password must be at least 6 characters';
    } elseif ($formData['password'] !== $formData['confirm_password']) {
        $error = 'Passwords do not match';
    } else {
        $db = getDB();
        $stmt = $db->prepare("SELECT user_id FROM users WHERE email = ?");
        $stmt->execute([$formData['email']]);
        if ($stmt->fetch()) {
            $error = 'Email address already registered';
        } else {
            $passwordHash = password_hash($formData['password'], PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW())");
            
            try {
                $stmt->execute([
                    $formData['first_name'],
                    $formData['last_name'],
                    $formData['email'],
                    $formData['phone'],
                    $passwordHash,
                    $formData['user_type']
                ]);
                $success = 'Account created successfully! Please sign in.';
                $formData = [];
            } catch (PDOException $e) {
                $error = 'Registration failed. Please try again.';
            }
        }
    }
}

ob_end_flush();
?>

<style>
:root {
    --primary: #0066ff;
    --primary-dark: #003b95;
    --primary-light: #f0f4ff;
    --accent: #ffb700;
    --bg: #ffffff;
    --bg-secondary: #f5f7fa;
    --text: #1a1a1a;
    --text-secondary: #595959;
    --text-tertiary: #8c8c8c;
    --border: #e6e6e6;
    --success: #008009;
    --error: #e21111;
    --radius-sm: 4px;
    --radius-md: 8px;
    --radius-lg: 16px;
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.05);
    --shadow-md: 0 4px 16px rgba(0,0,0,0.08);
    --shadow-lg: 0 8px 32px rgba(0,0,0,0.12);
    --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
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
    color: var(--text);
    background: white;
}

/* Split Layout */
.auth-split {
    min-height: 100vh;
    display: grid;
    grid-template-columns: 1fr 1fr;
    background: var(--bg-secondary);
}

/* ============================================
   LEFT SIDE - BRAND SHOWCASE
   ============================================ */
.auth-showcase {
    position: relative;
    background: linear-gradient(145deg, var(--primary-dark) 0%, #002d73 100%);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    padding: 48px;
}

/* Logo */
.showcase-logo {
    position: relative;
    z-index: 10;
    margin-bottom: 60px;
}

.logo-link {
    display: flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
}

.logo-icon {
    width: 40px;
    height: 40px;
    background: white;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: var(--primary-dark);
    font-weight: 700;
    transform: rotate(-5deg);
    transition: var(--transition);
}

.logo-link:hover .logo-icon {
    transform: rotate(0deg);
}

.logo-text {
    font-size: 1.5rem;
    font-weight: 700;
    color: white;
    letter-spacing: -0.5px;
}

.logo-text span {
    color: var(--accent);
}

/* Background Pattern */
.showcase-pattern {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-image: 
        radial-gradient(circle at 20% 30%, rgba(255,255,255,0.1) 0%, transparent 20%),
        radial-gradient(circle at 80% 70%, rgba(255,255,255,0.1) 0%, transparent 25%),
        radial-gradient(circle at 40% 80%, rgba(255,255,255,0.1) 0%, transparent 30%),
        radial-gradient(circle at 70% 20%, rgba(255,255,255,0.1) 0%, transparent 20%);
    z-index: 1;
}

/* Floating Shapes */
.showcase-shape {
    position: absolute;
    background: rgba(255,255,255,0.03);
    border-radius: 50%;
    z-index: 2;
}

.shape-1 {
    width: 300px;
    height: 300px;
    top: -100px;
    right: -100px;
    background: radial-gradient(circle, rgba(255,255,255,0.05) 0%, transparent 70%);
    animation: float 20s infinite;
}

.shape-2 {
    width: 200px;
    height: 200px;
    bottom: 50px;
    left: -50px;
    background: radial-gradient(circle, rgba(255,255,255,0.05) 0%, transparent 70%);
    animation: float 15s infinite reverse;
}

.shape-3 {
    width: 150px;
    height: 150px;
    top: 40%;
    right: 20%;
    background: radial-gradient(circle, rgba(255,255,255,0.05) 0%, transparent 70%);
    animation: float 12s infinite 2s;
}

@keyframes float {
    0%, 100% { transform: translate(0, 0) rotate(0deg); }
    33% { transform: translate(30px, -30px) rotate(5deg); }
    66% { transform: translate(-20px, 20px) rotate(-5deg); }
}

/* Testimonials Carousel */
.testimonials-container {
    position: relative;
    z-index: 10;
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
    max-width: 500px;
}

.testimonial-slide {
    opacity: 0;
    visibility: hidden;
    transition: all 0.5s ease;
    position: absolute;
}

.testimonial-slide.active {
    opacity: 1;
    visibility: visible;
    position: relative;
}

.testimonial-rating {
    display: flex;
    gap: 4px;
    margin-bottom: 20px;
}

.testimonial-rating i {
    color: var(--accent);
    font-size: 1.25rem;
}

.testimonial-quote {
    font-size: 1.75rem;
    font-weight: 600;
    line-height: 1.3;
    color: white;
    margin-bottom: 24px;
    letter-spacing: -0.5px;
}

.testimonial-author {
    display: flex;
    align-items: center;
    gap: 16px;
}

.author-avatar {
    width: 56px;
    height: 56px;
    background: rgba(255,255,255,0.1);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: white;
    font-weight: 600;
    backdrop-filter: blur(10px);
    border: 2px solid rgba(255,255,255,0.2);
}

.author-info h4 {
    font-size: 1rem;
    font-weight: 600;
    color: white;
    margin-bottom: 4px;
}

.author-info p {
    font-size: 0.8125rem;
    color: rgba(255,255,255,0.7);
}

/* Stats Cards */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 24px;
    margin-top: 60px;
    position: relative;
    z-index: 10;
}

.stat-card {
    background: rgba(255,255,255,0.05);
    backdrop-filter: blur(10px);
    border-radius: var(--radius-md);
    padding: 20px;
    border: 1px solid rgba(255,255,255,0.1);
    transition: var(--transition);
}

.stat-card:hover {
    transform: translateY(-4px);
    background: rgba(255,255,255,0.08);
}

.stat-number {
    font-size: 1.75rem;
    font-weight: 800;
    color: white;
    line-height: 1;
    margin-bottom: 4px;
    letter-spacing: -0.5px;
}

.stat-label {
    font-size: 0.75rem;
    color: rgba(255,255,255,0.7);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* ============================================
   RIGHT SIDE - SIGNUP FORM
   ============================================ */
.auth-form-side {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 48px;
    background: white;
    overflow-y: auto;
}

.form-container {
    width: 100%;
    max-width: 440px;
    animation: slideInRight 0.6s ease-out;
}

@keyframes slideInRight {
    from {
        opacity: 0;
        transform: translateX(30px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

.form-header {
    margin-bottom: 32px;
    text-align: center;
}

.form-header h1 {
    font-size: 2rem;
    font-weight: 700;
    color: var(--text);
    margin-bottom: 8px;
    letter-spacing: -0.5px;
}

.form-header p {
    font-size: 0.9375rem;
    color: var(--text-secondary);
}

/* Progress Steps */
.signup-progress {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin-bottom: 32px;
}

.progress-step {
    display: flex;
    align-items: center;
    gap: 8px;
}

.step-indicator {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--bg-secondary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--text-secondary);
    transition: var(--transition);
}

.step-indicator.active {
    background: var(--primary);
    color: white;
}

.step-indicator.completed {
    background: var(--success);
    color: white;
}

.step-line {
    width: 40px;
    height: 2px;
    background: var(--border);
}

/* User Type Cards */
.user-type-selector {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 24px;
}

.user-type-card {
    position: relative;
    padding: 16px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    cursor: pointer;
    transition: var(--transition);
    background: white;
}

.user-type-card:hover {
    border-color: var(--primary);
    box-shadow: var(--shadow-sm);
}

.user-type-card.active {
    border-color: var(--primary);
    background: var(--primary-light);
}

.user-type-card input {
    position: absolute;
    opacity: 0;
}

.user-type-icon {
    width: 40px;
    height: 40px;
    background: var(--bg-secondary);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 8px;
    font-size: 1.125rem;
    color: var(--text-secondary);
    transition: var(--transition);
}

.user-type-card.active .user-type-icon {
    background: var(--primary);
    color: white;
}

.user-type-title {
    font-size: 0.875rem;
    font-weight: 600;
    margin-bottom: 2px;
}

.user-type-desc {
    font-size: 0.6875rem;
    color: var(--text-tertiary);
}

/* Form Groups */
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 16px;
}

.form-group {
    margin-bottom: 16px;
}

.form-label {
    display: block;
    font-size: 0.75rem;
    font-weight: 600;
    margin-bottom: 6px;
    color: var(--text);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.input-wrapper {
    position: relative;
}

.form-input {
    width: 100%;
    padding: 12px 16px 12px 42px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    font-size: 0.9375rem;
    transition: var(--transition);
    background: white;
}

.form-input:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(0,102,255,0.1);
}

.form-input.error {
    border-color: var(--error);
}

.input-icon {
    position: absolute;
    left: 16px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-tertiary);
    font-size: 1rem;
    transition: var(--transition);
}

.form-input:focus + .input-icon {
    color: var(--primary);
}

/* Password Strength */
.password-strength {
    margin-top: 8px;
    height: 4px;
    background: var(--border);
    border-radius: 2px;
    overflow: hidden;
}

.strength-bar {
    height: 100%;
    width: 0%;
    transition: width 0.3s, background 0.3s;
}

.strength-bar.weak {
    width: 33.33%;
    background: var(--error);
}

.strength-bar.medium {
    width: 66.66%;
    background: var(--accent);
}

.strength-bar.strong {
    width: 100%;
    background: var(--success);
}

.strength-text {
    font-size: 0.6875rem;
    color: var(--text-tertiary);
    margin-top: 4px;
    text-align: right;
}

/* Terms Checkbox */
.terms-group {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin: 24px 0;
    padding: 16px;
    background: var(--bg-secondary);
    border-radius: var(--radius-md);
}

.terms-group input[type="checkbox"] {
    width: 18px;
    height: 18px;
    border: 2px solid var(--border);
    border-radius: 4px;
    appearance: none;
    cursor: pointer;
    transition: var(--transition);
    flex-shrink: 0;
    margin-top: 2px;
}

.terms-group input[type="checkbox"]:checked {
    background: var(--primary);
    border-color: var(--primary);
    position: relative;
}

.terms-group input[type="checkbox"]:checked::after {
    content: '✓';
    color: white;
    font-size: 12px;
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
}

.terms-group label {
    font-size: 0.8125rem;
    color: var(--text-secondary);
    line-height: 1.5;
    cursor: pointer;
}

.terms-group a {
    color: var(--primary);
    text-decoration: none;
    font-weight: 600;
}

.terms-group a:hover {
    text-decoration: underline;
}

/* Submit Button */
.btn-submit {
    width: 100%;
    padding: 14px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    border: none;
    border-radius: var(--radius-md);
    font-size: 0.9375rem;
    font-weight: 600;
    cursor: pointer;
    transition: var(--transition);
    position: relative;
    overflow: hidden;
}

.btn-submit::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
    transition: left 0.5s;
}

.btn-submit:hover::before {
    left: 100%;
}

.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.btn-submit:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}

/* Social Login */
.social-divider {
    position: relative;
    text-align: center;
    margin: 24px 0;
}

.social-divider::before,
.social-divider::after {
    content: '';
    position: absolute;
    top: 50%;
    width: calc(50% - 70px);
    height: 1px;
    background: var(--border);
}

.social-divider::before {
    left: 0;
}

.social-divider::after {
    right: 0;
}

.social-divider span {
    background: white;
    padding: 0 16px;
    font-size: 0.75rem;
    color: var(--text-tertiary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.social-buttons {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 24px;
}

.btn-social {
    padding: 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: white;
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--text);
    cursor: pointer;
    transition: var(--transition);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.btn-social:hover {
    border-color: var(--primary);
    background: var(--primary-light);
    color: var(--primary);
}

.btn-social i {
    font-size: 1.125rem;
}

.btn-google i { color: #ea4335; }
.btn-facebook i { color: #1877f2; }

/* Form Footer */
.form-footer {
    text-align: center;
    margin-top: 24px;
    padding-top: 24px;
    border-top: 1px solid var(--border);
    font-size: 0.875rem;
    color: var(--text-secondary);
}

.form-footer a {
    color: var(--primary);
    font-weight: 600;
    text-decoration: none;
}

.form-footer a:hover {
    text-decoration: underline;
}

/* Alert Messages */
.alert {
    padding: 16px;
    border-radius: var(--radius-md);
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 0.875rem;
    animation: slideDown 0.3s ease;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.alert-error {
    background: #fee2e2;
    color: var(--error);
    border: 1px solid #fecaca;
}

.alert-success {
    background: #dcfce7;
    color: var(--success);
    border: 1px solid #bbf7d0;
}

.alert i {
    font-size: 1.25rem;
}

/* Success State */
.success-container {
    text-align: center;
    padding: 20px 0;
}

.success-icon {
    width: 80px;
    height: 80px;
    background: var(--success);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 24px;
    color: white;
    font-size: 2.5rem;
    animation: scaleIn 0.5s ease;
}

@keyframes scaleIn {
    from {
        transform: scale(0);
    }
    to {
        transform: scale(1);
    }
}

.success-title {
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 8px;
}

.success-text {
    color: var(--text-secondary);
    margin-bottom: 24px;
}

.btn-success {
    display: inline-block;
    padding: 12px 32px;
    background: var(--primary);
    color: white;
    text-decoration: none;
    border-radius: var(--radius-md);
    font-weight: 600;
    transition: var(--transition);
}

.btn-success:hover {
    background: var(--primary-dark);
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

/* Responsive */
@media (max-width: 1024px) {
    .auth-split {
        grid-template-columns: 1fr;
    }
    
    .auth-showcase {
        display: none;
    }
    
    .auth-form-side {
        padding: 32px 20px;
    }
}

@media (max-width: 480px) {
    .form-row {
        grid-template-columns: 1fr;
    }
    
    .user-type-selector {
        grid-template-columns: 1fr;
    }
    
    .social-buttons {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="auth-split">
    <!-- Left Side - Brand Showcase -->
    <div class="auth-showcase">
        <!-- Logo -->
        <div class="showcase-logo">
            <a href="/gorwanda-plus/" class="logo-link">
                <div class="logo-icon">
                    <span>+</span>
                </div>
                <div class="logo-text">GoRwanda<span>+</span></div>
            </a>
        </div>
        
        <!-- Background Pattern & Shapes -->
        <div class="showcase-pattern"></div>
        <div class="showcase-shape shape-1"></div>
        <div class="showcase-shape shape-2"></div>
        <div class="showcase-shape shape-3"></div>
        
        <!-- Testimonials Carousel -->
        <div class="testimonials-container">
            <div class="testimonial-slide active">
                <div class="testimonial-rating">
                    <i class="bi bi-star-fill"></i>
                    <i class="bi bi-star-fill"></i>
                    <i class="bi bi-star-fill"></i>
                    <i class="bi bi-star-fill"></i>
                    <i class="bi bi-star-fill"></i>
                </div>
                <div class="testimonial-quote">
                    "GoRwanda+ made planning our trip so easy. Found the perfect lodge near Volcanoes National Park."
                </div>
                <div class="testimonial-author">
                    <div class="author-avatar">S</div>
                    <div class="author-info">
                        <h4>Sarah Johnson</h4>
                        <p>Traveled to Rwanda, 2024</p>
                    </div>
                </div>
            </div>
            
            <div class="testimonial-slide">
                <div class="testimonial-rating">
                    <i class="bi bi-star-fill"></i>
                    <i class="bi bi-star-fill"></i>
                    <i class="bi bi-star-fill"></i>
                    <i class="bi bi-star-fill"></i>
                    <i class="bi bi-star-fill"></i>
                </div>
                <div class="testimonial-quote">
                    "Listed our car rental business and got our first booking within 24 hours. Amazing platform!"
                </div>
                <div class="testimonial-author">
                    <div class="author-avatar">J</div>
                    <div class="author-info">
                        <h4>Jean Mugabo</h4>
                        <p>Business Owner, Kigali</p>
                    </div>
                </div>
            </div>
            
            <div class="testimonial-slide">
                <div class="testimonial-rating">
                    <i class="bi bi-star-fill"></i>
                    <i class="bi bi-star-fill"></i>
                    <i class="bi bi-star-fill"></i>
                    <i class="bi bi-star-fill"></i>
                    <i class="bi bi-star-fill"></i>
                </div>
                <div class="testimonial-quote">
                    "The gorilla trekking experience was unforgettable. Everything was perfectly organized."
                </div>
                <div class="testimonial-author">
                    <div class="author-avatar">M</div>
                    <div class="author-info">
                        <h4>Michael Chen</h4>
                        <p>Adventure Traveler</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($totalUsers); ?>+</div>
                <div class="stat-label">Happy Travelers</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($totalListings); ?>+</div>
                <div class="stat-label">Listings</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($totalReviews); ?>+</div>
                <div class="stat-label">Reviews</div>
            </div>
        </div>
    </div>
    
    <!-- Right Side - Signup Form -->
    <div class="auth-form-side">
        <div class="form-container">
            <?php if ($success): ?>
            <!-- Success State -->
            <div class="success-container">
                <div class="success-icon">
                    <i class="bi bi-check-lg"></i>
                </div>
                <h2 class="success-title">Welcome to GoRwanda+!</h2>
                <p class="success-text"><?php echo $success; ?></p>
                <a href="login.php" class="btn-success">Sign In to Your Account</a>
            </div>
            
            <?php else: ?>
            
            <!-- Progress Steps -->
            <div class="signup-progress">
                <div class="progress-step">
                    <span class="step-indicator active">1</span>
                    <span style="font-size: 0.75rem; color: var(--text-secondary);">Account</span>
                </div>
                <span class="step-line"></span>
                <div class="progress-step">
                    <span class="step-indicator">2</span>
                    <span style="font-size: 0.75rem; color: var(--text-secondary);">Profile</span>
                </div>
                <span class="step-line"></span>
                <div class="progress-step">
                    <span class="step-indicator">3</span>
                    <span style="font-size: 0.75rem; color: var(--text-secondary);">Complete</span>
                </div>
            </div>
            
            <!-- Header -->
            <div class="form-header">
                <h1>Create your account</h1>
                <p>Join <?php echo number_format($totalUsers); ?>+ travelers on GoRwanda+</p>
            </div>
            
            <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?php echo $error; ?>
            </div>
            <?php endif; ?>
            
            <form method="POST" action="" id="signupForm">
                <!-- User Type Selection -->
                <div class="user-type-selector">
                    <label class="user-type-card <?php echo ($formData['user_type'] ?? 'tourist') === 'tourist' ? 'active' : ''; ?>">
                        <input type="radio" name="user_type" value="tourist" 
                               <?php echo ($formData['user_type'] ?? 'tourist') === 'tourist' ? 'checked' : ''; ?>>
                        <div class="user-type-icon"><i class="bi bi-person"></i></div>
                        <div class="user-type-title">Traveler</div>
                        <div class="user-type-desc">Book stays & experiences</div>
                    </label>
                    
                    <label class="user-type-card <?php echo ($formData['user_type'] ?? '') === 'business_owner' ? 'active' : ''; ?>">
                        <input type="radio" name="user_type" value="business_owner"
                               <?php echo ($formData['user_type'] ?? '') === 'business_owner' ? 'checked' : ''; ?>>
                        <div class="user-type-icon"><i class="bi bi-building"></i></div>
                        <div class="user-type-title">Business</div>
                        <div class="user-type-desc">List your property</div>
                    </label>
                </div>
                
                <!-- Name Fields -->
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">First Name</label>
                        <div class="input-wrapper">
                            <i class="bi bi-person input-icon"></i>
                            <input type="text" name="first_name" class="form-input" 
                                   placeholder="John" value="<?php echo sanitize($formData['first_name'] ?? ''); ?>" 
                                   required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Last Name</label>
                        <div class="input-wrapper">
                            <i class="bi bi-person input-icon"></i>
                            <input type="text" name="last_name" class="form-input" 
                                   placeholder="Doe" value="<?php echo sanitize($formData['last_name'] ?? ''); ?>" 
                                   required>
                        </div>
                    </div>
                </div>
                
                <!-- Email -->
                <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <div class="input-wrapper">
                        <i class="bi bi-envelope input-icon"></i>
                        <input type="email" name="email" class="form-input" 
                               placeholder="you@example.com" value="<?php echo sanitize($formData['email'] ?? ''); ?>" 
                               required>
                    </div>
                </div>
                
                <!-- Phone -->
                <div class="form-group">
                    <label class="form-label">Phone Number</label>
                    <div class="input-wrapper">
                        <i class="bi bi-telephone input-icon"></i>
                        <input type="tel" name="phone" class="form-input" 
                               placeholder="+250 788 123 456" value="<?php echo sanitize($formData['phone'] ?? ''); ?>">
                    </div>
                </div>
                
                <!-- Password Fields -->
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Password</label>
                        <div class="input-wrapper">
                            <i class="bi bi-lock input-icon"></i>
                            <input type="password" name="password" id="password" class="form-input" 
                                   placeholder="••••••••" required minlength="6">
                        </div>
                        <div class="password-strength">
                            <div class="strength-bar" id="strengthBar"></div>
                        </div>
                        <div class="strength-text" id="strengthText"></div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Confirm</label>
                        <div class="input-wrapper">
                            <i class="bi bi-lock-fill input-icon"></i>
                            <input type="password" name="confirm_password" id="confirmPassword" class="form-input" 
                                   placeholder="••••••••" required>
                        </div>
                    </div>
                </div>
                
                <!-- Terms -->
                <div class="terms-group">
                    <input type="checkbox" id="terms" required>
                    <label for="terms">
                        I agree to the <a href="#">Terms of Service</a> and <a href="#">Privacy Policy</a>. 
                        I consent to receiving marketing emails.
                    </label>
                </div>
                
                <!-- Submit Button -->
                <button type="submit" class="btn-submit" id="submitBtn">
                    Create Account
                </button>
                
                <!-- Social Login -->
                <div class="social-divider">
                    <span>or continue with</span>
                </div>
                
                <div class="social-buttons">
                    <button type="button" class="btn-social btn-google" onclick="alert('Google Sign In coming soon!')">
                        <i class="bi bi-google"></i>
                        Google
                    </button>
                    <button type="button" class="btn-social btn-facebook" onclick="alert('Facebook Sign In coming soon!')">
                        <i class="bi bi-facebook"></i>
                        Facebook
                    </button>
                </div>
                
                <!-- Footer -->
                <div class="form-footer">
                    Already have an account? <a href="login.php">Sign in</a>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Testimonials Carousel
let currentTestimonial = 0;
const testimonials = document.querySelectorAll('.testimonial-slide');
const testimonialCount = testimonials.length;

function showTestimonial(index) {
    testimonials.forEach(t => t.classList.remove('active'));
    testimonials[index].classList.add('active');
}

function nextTestimonial() {
    currentTestimonial = (currentTestimonial + 1) % testimonialCount;
    showTestimonial(currentTestimonial);
}

// Auto-advance testimonials every 6 seconds
setInterval(nextTestimonial, 6000);

// User type card selection
document.querySelectorAll('.user-type-card').forEach(card => {
    card.addEventListener('click', function() {
        document.querySelectorAll('.user-type-card').forEach(c => c.classList.remove('active'));
        this.classList.add('active');
        this.querySelector('input[type="radio"]').checked = true;
    });
});

// Password strength meter
const passwordInput = document.getElementById('password');
const strengthBar = document.getElementById('strengthBar');
const strengthText = document.getElementById('strengthText');

passwordInput.addEventListener('input', function() {
    const password = this.value;
    let strength = 0;
    
    // Check length
    if (password.length >= 6) strength += 1;
    if (password.length >= 8) strength += 1;
    
    // Check for numbers
    if (/\d/.test(password)) strength += 1;
    
    // Check for special characters
    if (/[!@#$%^&*(),.?":{}|<>]/.test(password)) strength += 1;
    
    // Check for uppercase
    if (/[A-Z]/.test(password)) strength += 1;
    
    // Update strength bar
    let percentage = (strength / 5) * 100;
    strengthBar.style.width = percentage + '%';
    
    if (percentage < 40) {
        strengthBar.className = 'strength-bar weak';
        strengthText.textContent = 'Weak password';
        strengthText.style.color = 'var(--error)';
    } else if (percentage < 70) {
        strengthBar.className = 'strength-bar medium';
        strengthText.textContent = 'Medium password';
        strengthText.style.color = 'var(--accent)';
    } else {
        strengthBar.className = 'strength-bar strong';
        strengthText.textContent = 'Strong password';
        strengthText.style.color = 'var(--success)';
    }
});

// Password match validation
const confirmInput = document.getElementById('confirmPassword');
confirmInput.addEventListener('input', function() {
    const password = passwordInput.value;
    const confirm = this.value;
    
    if (confirm.length > 0) {
        if (password === confirm) {
            this.style.borderColor = 'var(--success)';
        } else {
            this.style.borderColor = 'var(--error)';
        }
    } else {
        this.style.borderColor = 'var(--border)';
    }
});

// Form submission with loading state
document.getElementById('signupForm')?.addEventListener('submit', function(e) {
    const password = passwordInput.value;
    const confirm = confirmInput.value;
    
    if (password !== confirm) {
        e.preventDefault();
        alert('Passwords do not match!');
        return;
    }
    
    const submitBtn = document.getElementById('submitBtn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i> Creating Account...';
});

// Phone number formatting
const phoneInput = document.querySelector('input[name="phone"]');
phoneInput.addEventListener('input', function(e) {
    let value = e.target.value.replace(/\D/g, '');
    
    if (value.length > 0 && !value.startsWith('250')) {
        if (value.startsWith('0')) {
            value = '250' + value.substring(1);
        } else {
            value = '250' + value;
        }
    }
    
    if (value.length > 0) {
        value = '+' + value;
    }
    
    e.target.value = value;
});
</script>

<?php require_once 'includes/footer.php'; ?>