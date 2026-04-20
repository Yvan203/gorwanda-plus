<?php
$pageTitle = 'Messages Management';
require_once 'includes/admin_header.php';

$db = getDB();

// Handle message actions
$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
$messageId = isset($_POST['message_id']) ? intval($_POST['message_id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);
$conversationId = isset($_POST['conversation_id']) ? sanitize($_POST['conversation_id']) : (isset($_GET['conversation_id']) ? sanitize($_GET['conversation_id']) : '');

// Send new message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'send_message') {
    $conversationId = sanitize($_POST['conversation_id'] ?? '');
    $receiverId = intval($_POST['receiver_id'] ?? 0);
    $subject = sanitize($_POST['subject'] ?? '');
    $message = sanitize($_POST['message'] ?? '');
    $userId = $_SESSION['user_id'];
    
    if (empty($conversationId)) {
        // Create new conversation
        $conversationId = 'conv_' . min($userId, $receiverId) . '_' . max($userId, $receiverId) . '_' . time();
        
        // Check if conversation already exists
        $stmt = $db->prepare("SELECT conversation_id FROM conversation_participants WHERE conversation_id = ?");
        $stmt->execute([$conversationId]);
        if (!$stmt->fetch()) {
            $stmt = $db->prepare("INSERT INTO conversation_participants (conversation_id, user_id, last_read_at) VALUES (?, ?, NOW())");
            $stmt->execute([$conversationId, $userId]);
            $stmt->execute([$conversationId, $receiverId]);
        }
    }
    
    if (!empty($message)) {
        $stmt = $db->prepare("
            INSERT INTO messages (conversation_id, sender_id, receiver_id, subject, message, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$conversationId, $userId, $receiverId, $subject, $message]);
        $_SESSION['success'] = "Message sent successfully";
    }
    
    header("Location: messages.php?conversation_id=" . urlencode($conversationId));
    exit;
}

// Mark conversation as read
if ($action === 'mark_read' && !empty($conversationId)) {
    $userId = $_SESSION['user_id'];
    $stmt = $db->prepare("
        UPDATE conversation_participants 
        SET last_read_at = NOW() 
        WHERE conversation_id = ? AND user_id = ?
    ");
    $stmt->execute([$conversationId, $userId]);
    header("Location: messages.php?conversation_id=" . urlencode($conversationId));
    exit;
}

// Mute/Unmute conversation
if ($action === 'mute' && !empty($conversationId)) {
    $userId = $_SESSION['user_id'];
    $stmt = $db->prepare("
        UPDATE conversation_participants 
        SET is_muted = 1 
        WHERE conversation_id = ? AND user_id = ?
    ");
    $stmt->execute([$conversationId, $userId]);
    $_SESSION['success'] = "Conversation muted";
    header("Location: messages.php?conversation_id=" . urlencode($conversationId));
    exit;
}

if ($action === 'unmute' && !empty($conversationId)) {
    $userId = $_SESSION['user_id'];
    $stmt = $db->prepare("
        UPDATE conversation_participants 
        SET is_muted = 0 
        WHERE conversation_id = ? AND user_id = ?
    ");
    $stmt->execute([$conversationId, $userId]);
    $_SESSION['success'] = "Conversation unmuted";
    header("Location: messages.php?conversation_id=" . urlencode($conversationId));
    exit;
}

// Leave conversation
if ($action === 'leave' && !empty($conversationId)) {
    $userId = $_SESSION['user_id'];
    $stmt = $db->prepare("
        UPDATE conversation_participants 
        SET left_at = NOW() 
        WHERE conversation_id = ? AND user_id = ?
    ");
    $stmt->execute([$conversationId, $userId]);
    $_SESSION['success'] = "Left conversation";
    header('Location: messages.php');
    exit;
}

// Delete message
if ($action === 'delete_message' && $messageId > 0) {
    $stmt = $db->prepare("DELETE FROM messages WHERE message_id = ?");
    $stmt->execute([$messageId]);
    $_SESSION['success'] = "Message deleted";
    header("Location: messages.php" . (!empty($conversationId) ? "?conversation_id=" . urlencode($conversationId) : ""));
    exit;
}

// Get filter parameters
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$filter = isset($_GET['filter']) ? sanitize($_GET['filter']) : 'all'; // all, unread, muted
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$perPage = 15;
$offset = ($page - 1) * $perPage;

$userId = $_SESSION['user_id'];

// Get conversations for the admin
$sql = "
    SELECT DISTINCT
        cp.conversation_id,
        cp.last_read_at,
        cp.is_muted,
        cp.left_at,
        u.user_id as other_user_id,
        u.first_name,
        u.last_name,
        u.email,
        u.profile_image,
        u.user_type,
        (SELECT COUNT(*) FROM messages m 
         WHERE m.conversation_id = cp.conversation_id 
         AND m.receiver_id = ? AND m.is_read = 0) as unread_count,
        (SELECT MAX(created_at) FROM messages m 
         WHERE m.conversation_id = cp.conversation_id) as last_message_time,
        (SELECT message FROM messages m 
         WHERE m.conversation_id = cp.conversation_id 
         ORDER BY created_at DESC LIMIT 1) as last_message,
        (SELECT subject FROM messages m 
         WHERE m.conversation_id = cp.conversation_id 
         ORDER BY created_at DESC LIMIT 1) as last_subject,
        (SELECT sender_id FROM messages m 
         WHERE m.conversation_id = cp.conversation_id 
         ORDER BY created_at DESC LIMIT 1) as last_sender_id
    FROM conversation_participants cp
    INNER JOIN conversation_participants cp2 ON cp.conversation_id = cp2.conversation_id AND cp2.user_id != cp.user_id
    INNER JOIN users u ON cp2.user_id = u.user_id
    WHERE cp.user_id = ? AND cp.left_at IS NULL
";

$params = [$userId, $userId];

if ($filter === 'unread') {
    $sql .= " AND (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = cp.conversation_id AND m.receiver_id = ? AND m.is_read = 0) > 0";
    $params[] = $userId;
} elseif ($filter === 'muted') {
    $sql .= " AND cp.is_muted = 1";
}

if ($search) {
    $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$sql .= " ORDER BY last_message_time DESC LIMIT $perPage OFFSET $offset";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$conversations = $stmt->fetchAll();

// Get total count for pagination
$countSql = "
    SELECT COUNT(DISTINCT cp.conversation_id)
    FROM conversation_participants cp
    INNER JOIN conversation_participants cp2 ON cp.conversation_id = cp2.conversation_id AND cp2.user_id != cp.user_id
    INNER JOIN users u ON cp2.user_id = u.user_id
    WHERE cp.user_id = ? AND cp.left_at IS NULL
";
$countParams = [$userId];

if ($filter === 'unread') {
    $countSql .= " AND (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = cp.conversation_id AND m.receiver_id = ? AND m.is_read = 0) > 0";
    $countParams[] = $userId;
} elseif ($filter === 'muted') {
    $countSql .= " AND cp.is_muted = 1";
}

if ($search) {
    $countSql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
}

$stmt = $db->prepare($countSql);
$stmt->execute($countParams);
$totalConversations = $stmt->fetchColumn() ?: 0;
$totalPages = $totalConversations > 0 ? ceil($totalConversations / $perPage) : 1;

// Get messages for selected conversation
$currentConversation = null;
$messages = [];
$otherUser = null;

if (!empty($conversationId)) {
    // Get conversation participants
    $stmt = $db->prepare("
        SELECT u.user_id, u.first_name, u.last_name, u.email, u.profile_image, u.user_type
        FROM conversation_participants cp
        INNER JOIN users u ON cp.user_id = u.user_id
        WHERE cp.conversation_id = ? AND cp.user_id != ? AND cp.left_at IS NULL
    ");
    $stmt->execute([$conversationId, $userId]);
    $otherUser = $stmt->fetch();
    
    if ($otherUser) {
        // Get messages
        $stmt = $db->prepare("
            SELECT m.*, u.first_name, u.last_name, u.profile_image
            FROM messages m
            LEFT JOIN users u ON m.sender_id = u.user_id
            WHERE m.conversation_id = ?
            ORDER BY m.created_at ASC
        ");
        $stmt->execute([$conversationId]);
        $messages = $stmt->fetchAll();
        
        // Mark messages as read
        $stmt = $db->prepare("
            UPDATE messages SET is_read = 1 
            WHERE conversation_id = ? AND receiver_id = ? AND is_read = 0
        ");
        $stmt->execute([$conversationId, $userId]);
        
        // Update last read
        $stmt = $db->prepare("
            UPDATE conversation_participants 
            SET last_read_at = NOW() 
            WHERE conversation_id = ? AND user_id = ?
        ");
        $stmt->execute([$conversationId, $userId]);
    }
}

// Get statistics
$stats = $db->prepare("
    SELECT 
        COUNT(DISTINCT cp.conversation_id) as total_conversations,
        SUM(CASE WHEN cp.is_muted = 0 THEN 1 ELSE 0 END) as active_conversations,
        SUM(CASE WHEN cp.is_muted = 1 THEN 1 ELSE 0 END) as muted_conversations,
        (SELECT COUNT(*) FROM messages m WHERE m.receiver_id = ? AND m.is_read = 0) as unread_messages,
        (SELECT COUNT(*) FROM messages m WHERE DATE(m.created_at) = CURDATE()) as today_messages,
        (SELECT COUNT(*) FROM messages m WHERE m.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as weekly_messages
    FROM conversation_participants cp
    WHERE cp.user_id = ? AND cp.left_at IS NULL
");
$stats->execute([$userId, $userId]);
$stats = $stats->fetch();

// Get user type badge class
function getUserTypeBadge($type) {
    switch($type) {
        case 'admin': return ['class' => 'badge-admin', 'icon' => 'shield', 'label' => 'Admin'];
        case 'business_owner': return ['class' => 'badge-business', 'icon' => 'building', 'label' => 'Partner'];
        default: return ['class' => 'badge-tourist', 'icon' => 'person', 'label' => 'Guest'];
    }
}
?>

<style>
/* Messages Management Styles */
.messages-container {
    display: flex;
    gap: 24px;
    min-height: calc(100vh - 200px);
}

/* Conversations Sidebar */
.conversations-sidebar {
    width: 350px;
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.sidebar-header {
    padding: 16px;
    border-bottom: 1px solid var(--booking-border);
    background: var(--booking-gray-light);
}

.sidebar-header h3 {
    font-size: 0.875rem;
    font-weight: 700;
    margin: 0 0 12px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.filter-tabs {
    display: flex;
    gap: 8px;
}

.filter-tab {
    flex: 1;
    padding: 6px 12px;
    background: var(--booking-white);
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.6875rem;
    font-weight: 600;
    text-align: center;
    cursor: pointer;
    transition: all var(--transition-fast);
    text-decoration: none;
    color: var(--booking-text);
}

.filter-tab:hover {
    border-color: var(--booking-blue);
    color: var(--booking-blue);
}

.filter-tab.active {
    background: var(--booking-blue);
    border-color: var(--booking-blue);
    color: var(--booking-white);
}

.search-box {
    padding: 12px;
    border-bottom: 1px solid var(--booking-border);
}

.search-box input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
}

.conversations-list {
    flex: 1;
    overflow-y: auto;
}

.conversation-item {
    padding: 12px 16px;
    border-bottom: 1px solid var(--booking-border);
    cursor: pointer;
    transition: background var(--transition-fast);
    text-decoration: none;
    display: block;
    color: var(--booking-text);
}

.conversation-item:hover {
    background: var(--booking-gray-light);
}

.conversation-item.active {
    background: rgba(0,102,255,0.05);
    border-left: 3px solid var(--booking-blue);
}

.conversation-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 6px;
}

.conversation-name {
    font-weight: 600;
    font-size: 0.8125rem;
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}

.unread-badge {
    background: var(--booking-blue);
    color: white;
    font-size: 0.5625rem;
    font-weight: 600;
    padding: 2px 6px;
    border-radius: 10px;
    min-width: 18px;
    text-align: center;
}

.conversation-time {
    font-size: 0.5625rem;
    color: var(--booking-text-light);
}

.conversation-subject {
    font-size: 0.6875rem;
    font-weight: 500;
    margin-bottom: 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.conversation-preview {
    font-size: 0.625rem;
    color: var(--booking-text-light);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.conversation-muted {
    opacity: 0.6;
}

/* Chat Area */
.chat-area {
    flex: 1;
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.chat-header {
    padding: 16px;
    border-bottom: 1px solid var(--booking-border);
    background: var(--booking-gray-light);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}

.chat-user-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.chat-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--booking-blue), var(--booking-blue-dark));
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    color: white;
}

.chat-avatar img {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    object-fit: cover;
}

.chat-user-details h4 {
    font-size: 0.875rem;
    font-weight: 700;
    margin: 0 0 2px 0;
}

.chat-user-details p {
    font-size: 0.625rem;
    color: var(--booking-text-light);
    margin: 0;
}

.chat-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.chat-action-btn {
    padding: 6px 12px;
    border-radius: var(--radius-sm);
    font-size: 0.625rem;
    font-weight: 600;
    cursor: pointer;
    background: var(--booking-white);
    border: 1px solid var(--booking-border);
    text-decoration: none;
    color: var(--booking-text);
}

.chat-action-btn:hover {
    background: var(--booking-gray-light);
}

/* Messages Area */
.messages-area {
    flex: 1;
    overflow-y: auto;
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 16px;
    background: var(--booking-gray-light);
}

.message-item {
    display: flex;
    gap: 12px;
    max-width: 70%;
}

.message-item.sent {
    align-self: flex-end;
    flex-direction: row-reverse;
}

.message-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--booking-gray);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    font-weight: 600;
    flex-shrink: 0;
}

.message-avatar img {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    object-fit: cover;
}

.message-bubble {
    background: var(--booking-white);
    border-radius: 12px;
    padding: 10px 14px;
    box-shadow: var(--shadow-sm);
}

.message-item.sent .message-bubble {
    background: var(--booking-blue);
    color: white;
}

.message-subject {
    font-weight: 600;
    font-size: 0.75rem;
    margin-bottom: 6px;
    padding-bottom: 4px;
    border-bottom: 1px solid rgba(0,0,0,0.1);
}

.message-item.sent .message-subject {
    border-bottom-color: rgba(255,255,255,0.2);
}

.message-text {
    font-size: 0.75rem;
    line-height: 1.4;
    word-wrap: break-word;
}

.message-time {
    font-size: 0.5625rem;
    color: var(--booking-text-light);
    margin-top: 6px;
    text-align: right;
}

.message-item.sent .message-time {
    color: rgba(255,255,255,0.7);
}

.message-status {
    font-size: 0.5rem;
    margin-left: 4px;
}

/* Message Input */
.message-input-area {
    padding: 16px;
    border-top: 1px solid var(--booking-border);
    background: var(--booking-white);
}

.message-form {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.message-subject-input {
    padding: 10px 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
}

.message-text-input {
    padding: 10px 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    resize: vertical;
    min-height: 80px;
    font-family: inherit;
}

.message-actions {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}

.send-btn {
    padding: 8px 24px;
    background: var(--booking-blue);
    color: var(--booking-white);
    border: none;
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
}

.send-btn:hover {
    background: var(--booking-blue-dark);
}

/* Empty States */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--booking-text-light);
}

.empty-state i {
    font-size: 3rem;
    color: var(--booking-text-lighter);
    margin-bottom: 16px;
}

.empty-state p {
    font-size: 0.75rem;
}

/* New Conversation Modal */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 10000;
    justify-content: center;
    align-items: center;
}

.modal-container {
    background: var(--booking-white);
    border-radius: var(--radius-lg);
    width: 90%;
    max-width: 500px;
}

.modal-header {
    padding: 20px;
    border-bottom: 1px solid var(--booking-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    font-size: 1rem;
    font-weight: 700;
    margin: 0;
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.25rem;
    cursor: pointer;
}

.modal-body {
    padding: 20px;
}

.modal-footer {
    padding: 16px 20px;
    border-top: 1px solid var(--booking-border);
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}

.form-group {
    margin-bottom: 16px;
}

.form-group label {
    display: block;
    font-size: 0.6875rem;
    font-weight: 600;
    color: var(--booking-text-light);
    margin-bottom: 4px;
}

.form-control {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
}

/* Stats Cards */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 12px;
    margin-bottom: 24px;
}

.stat-card {
    background: var(--booking-white);
    border-radius: var(--radius-md);
    border: 1px solid var(--booking-border);
    padding: 12px;
    text-align: center;
}

.stat-value {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--booking-text);
}

.stat-label {
    font-size: 0.5625rem;
    color: var(--booking-text-light);
    text-transform: uppercase;
    margin-top: 2px;
}

/* Badges */
.user-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    border-radius: 100px;
    font-size: 0.5625rem;
    font-weight: 600;
}

.badge-admin {
    background: rgba(255,140,0,0.1);
    color: var(--booking-warning);
}

.badge-business {
    background: rgba(147,51,234,0.1);
    color: #9333ea;
}

.badge-tourist {
    background: rgba(0,102,255,0.1);
    color: var(--booking-blue);
}

/* Alert */
.alert {
    padding: 12px 16px;
    border-radius: var(--radius-md);
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.alert-success {
    background: #e6f4ea;
    color: var(--booking-success);
}

/* Responsive */
@media (max-width: 1024px) {
    .stats-grid {
        grid-template-columns: repeat(3, 1fr);
    }
    .conversations-sidebar {
        width: 280px;
    }
}

@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .messages-container {
        flex-direction: column;
    }
    .conversations-sidebar {
        width: 100%;
        max-height: 400px;
    }
    .message-item {
        max-width: 85%;
    }
    .chat-header {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>

<div class="messages-header">
    <div class="page-title">
        <h1></h1>
    </div>
</div>

<?php if (isset($_SESSION['success'])): ?>
<div class="alert alert-success">
    <div>
        <i class="bi bi-check-circle-fill"></i>
        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
    </div>
    <button onclick="this.parentElement.remove()" style="background: none; border: none; cursor: pointer;">&times;</button>
</div>
<?php endif; ?>

<!-- Statistics Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($stats['total_conversations'] ?? 0); ?></div>
        <div class="stat-label">Total Conversations</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($stats['active_conversations'] ?? 0); ?></div>
        <div class="stat-label">Active</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($stats['unread_messages'] ?? 0); ?></div>
        <div class="stat-label">Unread</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($stats['today_messages'] ?? 0); ?></div>
        <div class="stat-label">Today</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($stats['weekly_messages'] ?? 0); ?></div>
        <div class="stat-label">This Week</div>
    </div>
</div>

<div class="messages-container">
    <!-- Conversations Sidebar -->
    <div class="conversations-sidebar">
        <div class="sidebar-header">
            <h3><i class="bi bi-chat-dots"></i> Conversations</h3>
            <div class="filter-tabs">
                <a href="?filter=all" class="filter-tab <?php echo $filter == 'all' ? 'active' : ''; ?>">All</a>
                <a href="?filter=unread" class="filter-tab <?php echo $filter == 'unread' ? 'active' : ''; ?>">Unread</a>
                <a href="?filter=muted" class="filter-tab <?php echo $filter == 'muted' ? 'active' : ''; ?>">Muted</a>
            </div>
        </div>
        <div class="search-box">
            <form method="GET" action="messages.php">
                <input type="hidden" name="filter" value="<?php echo $filter; ?>">
                <input type="text" name="search" placeholder="Search conversations..." value="<?php echo htmlspecialchars($search); ?>">
            </form>
        </div>
        <div class="conversations-list">
            <?php if (empty($conversations)): ?>
            <div class="empty-state" style="padding: 40px 20px;">
                <i class="bi bi-chat"></i>
                <p>No conversations found</p>
                <button class="send-btn" style="margin-top: 12px;" onclick="openNewConversationModal()">Start New Conversation</button>
            </div>
            <?php else: ?>
            <?php foreach ($conversations as $conv): 
                $badge = getUserTypeBadge($conv['user_type']);
                $isUnread = $conv['unread_count'] > 0;
                $isMuted = $conv['is_muted'] == 1;
                $lastMessage = $conv['last_message'] ? substr($conv['last_message'], 0, 50) : 'No messages';
                $lastSender = $conv['last_sender_id'] == $userId ? 'You: ' : '';
            ?>
            <a href="?conversation_id=<?php echo urlencode($conv['conversation_id']); ?>&filter=<?php echo $filter; ?>" 
               class="conversation-item <?php echo $conversationId == $conv['conversation_id'] ? 'active' : ''; ?> <?php echo $isMuted ? 'conversation-muted' : ''; ?>">
                <div class="conversation-header">
                    <div class="conversation-name">
                        <?php echo sanitize($conv['first_name'] . ' ' . $conv['last_name']); ?>
                        <span class="user-badge <?php echo $badge['class']; ?>">
                            <i class="bi bi-<?php echo $badge['icon']; ?>"></i>
                            <?php echo $badge['label']; ?>
                        </span>
                        <?php if ($isMuted): ?>
                        <span class="user-badge" style="background: rgba(0,0,0,0.05);">
                            <i class="bi bi-bell-slash"></i> Muted
                        </span>
                        <?php endif; ?>
                    </div>
                    <?php if ($isUnread): ?>
                    <span class="unread-badge"><?php echo $conv['unread_count']; ?></span>
                    <?php endif; ?>
                </div>
                <div class="conversation-time"><?php echo timeAgo($conv['last_message_time']); ?></div>
                <div class="conversation-subject">
                    <?php echo $lastSender; ?><?php echo sanitize($conv['last_subject'] ?: 'Message'); ?>
                </div>
                <div class="conversation-preview"><?php echo sanitize($lastMessage); ?></div>
            </a>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Chat Area -->
    <div class="chat-area">
        <?php if ($otherUser): 
            $badge = getUserTypeBadge($otherUser['user_type']);
            $isMuted = false;
            // Check if conversation is muted
            $stmt = $db->prepare("SELECT is_muted FROM conversation_participants WHERE conversation_id = ? AND user_id = ?");
            $stmt->execute([$conversationId, $userId]);
            $mutedStatus = $stmt->fetch();
            $isMuted = $mutedStatus && $mutedStatus['is_muted'] == 1;
        ?>
        <div class="chat-header">
            <div class="chat-user-info">
                <div class="chat-avatar">
                    <?php if ($otherUser['profile_image']): ?>
                    <img src="<?php echo getImageUrl($otherUser['profile_image'], 'profile'); ?>" alt="">
                    <?php else: ?>
                    <?php echo strtoupper(substr($otherUser['first_name'], 0, 1) . substr($otherUser['last_name'], 0, 1)); ?>
                    <?php endif; ?>
                </div>
                <div class="chat-user-details">
                    <h4><?php echo sanitize($otherUser['first_name'] . ' ' . $otherUser['last_name']); ?></h4>
                    <p>
                        <span class="user-badge <?php echo $badge['class']; ?>">
                            <i class="bi bi-<?php echo $badge['icon']; ?>"></i>
                            <?php echo $badge['label']; ?>
                        </span>
                        • <?php echo sanitize($otherUser['email']); ?>
                    </p>
                </div>
            </div>
            <div class="chat-actions">
                <button class="chat-action-btn" onclick="openNewConversationModal(<?php echo $otherUser['user_id']; ?>, '<?php echo addslashes($otherUser['first_name'] . ' ' . $otherUser['last_name']); ?>')">
                    <i class="bi bi-plus-lg"></i> New
                </button>
                <?php if ($isMuted): ?>
                <a href="?action=unmute&conversation_id=<?php echo urlencode($conversationId); ?>&filter=<?php echo $filter; ?>" class="chat-action-btn">
                    <i class="bi bi-bell"></i> Unmute
                </a>
                <?php else: ?>
                <a href="?action=mute&conversation_id=<?php echo urlencode($conversationId); ?>&filter=<?php echo $filter; ?>" class="chat-action-btn" onclick="return confirm('Mute this conversation? You will not receive notifications.')">
                    <i class="bi bi-bell-slash"></i> Mute
                </a>
                <?php endif; ?>
                <a href="?action=leave&conversation_id=<?php echo urlencode($conversationId); ?>&filter=<?php echo $filter; ?>" class="chat-action-btn" onclick="return confirm('Leave this conversation? You will no longer see it.')">
                    <i class="bi bi-box-arrow-left"></i> Leave
                </a>
                <a href="user-detail.php?id=<?php echo $otherUser['user_id']; ?>" class="chat-action-btn" target="_blank">
                    <i class="bi bi-box-arrow-up-right"></i> Profile
                </a>
            </div>
        </div>

        <div class="messages-area" id="messagesArea">
            <?php if (empty($messages)): ?>
            <div class="empty-state">
                <i class="bi bi-chat-dots"></i>
                <p>No messages yet. Start the conversation!</p>
            </div>
            <?php else: ?>
            <?php foreach ($messages as $msg): 
                $isSent = $msg['sender_id'] == $userId;
                $senderInitials = strtoupper(substr($msg['first_name'], 0, 1) . substr($msg['last_name'], 0, 1));
            ?>
            <div class="message-item <?php echo $isSent ? 'sent' : 'received'; ?>">
                <?php if (!$isSent): ?>
                <div class="message-avatar">
                    <?php if ($msg['profile_image']): ?>
                    <img src="<?php echo getImageUrl($msg['profile_image'], 'profile'); ?>" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                    <?php else: ?>
                    <?php echo $senderInitials; ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="message-bubble">
                    <?php if ($msg['subject']): ?>
                    <div class="message-subject"><?php echo sanitize($msg['subject']); ?></div>
                    <?php endif; ?>
                    <div class="message-text"><?php echo nl2br(sanitize($msg['message'])); ?></div>
                    <div class="message-time">
                        <?php echo date('M d, H:i', strtotime($msg['created_at'])); ?>
                        <?php if ($isSent): ?>
                        <span class="message-status">
                            <?php if ($msg['is_read']): ?>
                            <i class="bi bi-check2-all"></i> Read
                            <?php else: ?>
                            <i class="bi bi-check2"></i> Sent
                            <?php endif; ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="message-input-area">
            <form method="POST" action="messages.php" class="message-form" id="messageForm">
                <input type="hidden" name="action" value="send_message">
                <input type="hidden" name="conversation_id" value="<?php echo htmlspecialchars($conversationId); ?>">
                <input type="hidden" name="receiver_id" value="<?php echo $otherUser['user_id']; ?>">
                <input type="text" name="subject" class="message-subject-input" placeholder="Subject" value="RE: <?php echo htmlspecialchars($messages[0]['subject'] ?? ''); ?>">
                <textarea name="message" class="message-text-input" placeholder="Type your message here..." required></textarea>
                <div class="message-actions">
                    <button type="submit" class="send-btn">
                        <i class="bi bi-send"></i> Send Message
                    </button>
                </div>
            </form>
        </div>

        <?php else: ?>
        <div class="empty-state" style="flex: 1; display: flex; flex-direction: column; justify-content: center;">
            <i class="bi bi-chat-dots"></i>
            <h3 style="font-size: 1rem; margin-top: 16px;">No conversation selected</h3>
            <p style="font-size: 0.75rem;">Select a conversation from the sidebar or start a new one</p>
            <button class="send-btn" style="margin-top: 16px; align-self: center;" onclick="openNewConversationModal()">
                <i class="bi bi-plus-lg"></i> New Conversation
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- New Conversation Modal -->
<div id="newConversationModal" class="modal-overlay">
    <div class="modal-container">
        <form method="POST" action="messages.php">
            <input type="hidden" name="action" value="send_message">
            <div class="modal-header">
                <h3>New Conversation</h3>
                <button type="button" class="modal-close" onclick="closeNewConversationModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Select User</label>
                    <select name="receiver_id" id="receiverSelect" class="form-control" required>
                        <option value="">Select a user...</option>
                        <?php
                        $users = $db->query("
                            SELECT user_id, first_name, last_name, email, user_type 
                            FROM users 
                            WHERE user_id != $userId 
                            ORDER BY first_name
                        ")->fetchAll();
                        foreach ($users as $user):
                            $badge = getUserTypeBadge($user['user_type']);
                        ?>
                        <option value="<?php echo $user['user_id']; ?>">
                            <?php echo sanitize($user['first_name'] . ' ' . $user['last_name']); ?> 
                            (<?php echo $badge['label']; ?>) - <?php echo sanitize($user['email']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Subject</label>
                    <input type="text" name="subject" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Message</label>
                    <textarea name="message" class="form-control" rows="5" required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="chat-action-btn" onclick="closeNewConversationModal()">Cancel</button>
                <button type="submit" class="send-btn">Send Message</button>
            </div>
        </form>
    </div>
</div>

<script>
// Auto-scroll to bottom of messages
function scrollToBottom() {
    const messagesArea = document.getElementById('messagesArea');
    if (messagesArea) {
        messagesArea.scrollTop = messagesArea.scrollHeight;
    }
}

// Scroll to bottom on page load
document.addEventListener('DOMContentLoaded', function() {
    scrollToBottom();
});

// New Conversation Modal
function openNewConversationModal(userId = null, userName = null) {
    const modal = document.getElementById('newConversationModal');
    modal.style.display = 'flex';
    
    if (userId) {
        const select = document.getElementById('receiverSelect');
        for (let i = 0; i < select.options.length; i++) {
            if (select.options[i].value == userId) {
                select.options[i].selected = true;
                break;
            }
        }
        
        // Pre-fill subject
        const subjectInput = document.querySelector('#newConversationModal input[name="subject"]');
        if (subjectInput && userName) {
            subjectInput.value = 'Message for ' + userName;
        }
    }
}

function closeNewConversationModal() {
    document.getElementById('newConversationModal').style.display = 'none';
}

// Close modal on escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeNewConversationModal();
    }
});

// Close modal when clicking outside
window.onclick = function(e) {
    const modal = document.getElementById('newConversationModal');
    if (e.target === modal) {
        closeNewConversationModal();
    }
}
</script>

<?php require_once 'includes/admin_footer.php'; ?>