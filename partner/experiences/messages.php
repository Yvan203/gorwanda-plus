<?php
$pageTitle = 'Messages';
require_once 'includes/experiences_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// ============================================
// GET FILTERS
// ============================================
$conversationId = isset($_GET['conversation']) ? intval($_GET['conversation']) : 0;
$status = isset($_GET['status']) ? sanitize($_GET['status']) : 'all';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// ============================================
// HANDLE MESSAGE ACTIONS
// ============================================

// Send new message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $receiverId = intval($_POST['receiver_id']);
    $bookingId = !empty($_POST['booking_id']) ? intval($_POST['booking_id']) : null;
    $subject = sanitize($_POST['subject']);
    $message = sanitize($_POST['message']);
    $parentId = !empty($_POST['parent_id']) ? intval($_POST['parent_id']) : null;
    
    // Verify receiver exists
    $stmt = $db->prepare("SELECT user_id FROM users WHERE user_id = ? AND is_active = 1");
    $stmt->execute([$receiverId]);
    if ($stmt->fetch()) {
        $stmt = $db->prepare("
            INSERT INTO messages (sender_id, receiver_id, booking_id, subject, message, parent_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$userId, $receiverId, $bookingId, $subject, $message, $parentId]);
        
        $success = "Message sent successfully";
        
        // If this is a reply, redirect to that conversation
        if ($parentId) {
            $stmt = $db->prepare("SELECT conversation_id FROM messages WHERE message_id = ?");
            $stmt->execute([$parentId]);
            $convId = $stmt->fetchColumn();
            header("Location: messages.php?conversation=" . $convId);
            exit;
        }
    } else {
        $error = "Recipient not found";
    }
}

// Mark message as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
    $messageId = intval($_POST['message_id']);
    
    $stmt = $db->prepare("
        UPDATE messages SET is_read = 1 
        WHERE message_id = ? AND receiver_id = ?
    ");
    $stmt->execute([$messageId, $userId]);
    
    echo json_encode(['success' => true]);
    exit;
}

// Mark all as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    $stmt = $db->prepare("
        UPDATE messages SET is_read = 1 
        WHERE receiver_id = ? AND is_read = 0
    ");
    $stmt->execute([$userId]);
    
    $success = "All messages marked as read";
}

// Archive conversation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_conversation'])) {
    $conversationId = intval($_POST['conversation_id']);
    
    // You might want to add an 'archived' column to messages table
    // For now, we'll just mark as read
    $stmt = $db->prepare("
        UPDATE messages SET is_read = 1 
        WHERE (sender_id = ? OR receiver_id = ?) AND conversation_id = ?
    ");
    $stmt->execute([$userId, $userId, $conversationId]);
    
    $success = "Conversation archived";
}

// ============================================
// GET CONVERSATIONS
// ============================================

// Get unread count
$stmt = $db->prepare("
    SELECT COUNT(*) as unread_count
    FROM messages
    WHERE receiver_id = ? AND is_read = 0
");
$stmt->execute([$userId]);
$unreadCount = $stmt->fetchColumn();

// Get all conversations for this user
$sql = "
    SELECT 
        m.message_id,
        m.conversation_id,
        m.subject,
        m.message,
        m.created_at,
        m.is_read,
        m.booking_id,
        u.user_id as other_user_id,
        u.first_name as other_first_name,
        u.last_name as other_last_name,
        u.email as other_email,
        u.profile_image as other_avatar,
        a.attraction_name,
        a.attraction_id,
        (SELECT COUNT(*) FROM messages WHERE conversation_id = m.conversation_id) as total_messages,
        (SELECT COUNT(*) FROM messages WHERE conversation_id = m.conversation_id AND receiver_id = ? AND is_read = 0) as conversation_unread
    FROM messages m
    JOIN users u ON (
        CASE 
            WHEN m.sender_id = ? THEN m.receiver_id = u.user_id
            ELSE m.sender_id = u.user_id
        END
    )
    LEFT JOIN bookings b ON m.booking_id = b.booking_id
    LEFT JOIN attraction_tiers at ON b.attraction_tier_id = at.tier_id
    LEFT JOIN attractions a ON at.attraction_id = a.attraction_id
    WHERE m.sender_id = ? OR m.receiver_id = ?
    GROUP BY m.conversation_id
    ORDER BY MAX(m.created_at) DESC
    LIMIT $perPage OFFSET $offset
";

$stmt = $db->prepare($sql);
$stmt->execute([$userId, $userId, $userId, $userId]);
$conversations = $stmt->fetchAll();

// Get total conversations count
$countSql = "
    SELECT COUNT(DISTINCT conversation_id) as total
    FROM messages
    WHERE sender_id = ? OR receiver_id = ?
";
$stmt = $db->prepare($countSql);
$stmt->execute([$userId, $userId]);
$totalConversations = $stmt->fetchColumn();
$totalPages = ceil($totalConversations / $perPage);

// ============================================
// GET SINGLE CONVERSATION
// ============================================
$messages = [];
$currentConversation = null;
$otherUser = null;
$bookingInfo = null;

if ($conversationId > 0) {
    // Get conversation details
    $stmt = $db->prepare("
        SELECT * FROM messages 
        WHERE conversation_id = ? AND (sender_id = ? OR receiver_id = ?)
        ORDER BY created_at ASC
    ");
    $stmt->execute([$conversationId, $userId, $userId]);
    $messages = $stmt->fetchAll();
    
    if (!empty($messages)) {
        $currentConversation = $messages[0]['conversation_id'];
        
        // Get other user info
        foreach ($messages as $msg) {
            if ($msg['sender_id'] != $userId) {
                $otherUserId = $msg['sender_id'];
                break;
            }
            if ($msg['receiver_id'] != $userId) {
                $otherUserId = $msg['receiver_id'];
                break;
            }
        }
        
        if (isset($otherUserId)) {
            $stmt = $db->prepare("SELECT user_id, first_name, last_name, email, profile_image FROM users WHERE user_id = ?");
            $stmt->execute([$otherUserId]);
            $otherUser = $stmt->fetch();
        }
        
        // Get booking info if any
        $bookingId = null;
        foreach ($messages as $msg) {
            if ($msg['booking_id']) {
                $bookingId = $msg['booking_id'];
                break;
            }
        }
        
        if ($bookingId) {
            $stmt = $db->prepare("
                SELECT b.*, a.attraction_name, at.tier_name
                FROM bookings b
                JOIN attraction_tiers at ON b.attraction_tier_id = at.tier_id
                JOIN attractions a ON at.attraction_id = a.attraction_id
                WHERE b.booking_id = ?
            ");
            $stmt->execute([$bookingId]);
            $bookingInfo = $stmt->fetch();
        }
        
        // Mark messages as read
        $stmt = $db->prepare("
            UPDATE messages SET is_read = 1 
            WHERE conversation_id = ? AND receiver_id = ? AND is_read = 0
        ");
        $stmt->execute([$conversationId, $userId]);
    }
}

// ============================================
// GET RECENT GUESTS FOR NEW MESSAGE
// ============================================
$stmt = $db->prepare("
    SELECT DISTINCT u.user_id, u.first_name, u.last_name, u.email
    FROM bookings b
    JOIN attraction_tiers at ON b.attraction_tier_id = at.tier_id
    JOIN attractions a ON at.attraction_id = a.attraction_id
    JOIN users u ON b.user_id = u.user_id
    WHERE a.owner_id = ?
    ORDER BY b.created_at DESC
    LIMIT 10
");
$stmt->execute([$userId]);
$recentGuests = $stmt->fetchAll();

// Get recent bookings for context
$stmt = $db->prepare("
    SELECT b.booking_id, b.booking_reference, a.attraction_name, b.experience_date
    FROM bookings b
    JOIN attraction_tiers at ON b.attraction_tier_id = at.tier_id
    JOIN attractions a ON at.attraction_id = a.attraction_id
    WHERE a.owner_id = ? AND b.status IN ('confirmed', 'pending')
    ORDER BY b.created_at DESC
    LIMIT 20
");
$stmt->execute([$userId]);
$recentBookings = $stmt->fetchAll();
?>

<style>
/* Messages Specific Styles */
.messages-container {
    display: grid;
    grid-template-columns: 350px 1fr;
    gap: 20px;
    background: var(--exp-gray);
    min-height: calc(100vh - 64px);
}

/* Conversations List */
.conversations-sidebar {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--exp-border);
    overflow: hidden;
    height: fit-content;
}

.sidebar-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--exp-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: var(--exp-gray);
}

.sidebar-header h3 {
    font-size: 1rem;
    font-weight: 700;
    color: var(--exp-text);
    margin: 0;
}

.unread-badge {
    background: var(--exp-purple);
    color: white;
    padding: 2px 8px;
    border-radius: 100px;
    font-size: 0.6875rem;
    font-weight: 600;
}

.new-message-btn {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: var(--exp-purple);
    color: white;
    border: none;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
}

.new-message-btn:hover {
    background: var(--exp-dark-purple);
    transform: scale(1.05);
}

.search-box {
    padding: 12px 16px;
    border-bottom: 1px solid var(--exp-border);
    position: relative;
}

.search-box i {
    position: absolute;
    left: 28px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--exp-text-light);
    font-size: 0.875rem;
}

.search-box input {
    width: 100%;
    padding: 8px 12px 8px 36px;
    border: 1px solid var(--exp-border);
    border-radius: var(--radius-sm);
    font-size: 0.8125rem;
}

.conversations-list {
    max-height: 600px;
    overflow-y: auto;
}

.conversation-item {
    display: flex;
    gap: 12px;
    padding: 16px;
    border-bottom: 1px solid var(--exp-border);
    cursor: pointer;
    transition: all 0.2s;
    position: relative;
}

.conversation-item:hover {
    background: var(--exp-light-purple);
}

.conversation-item.active {
    background: var(--exp-light-purple);
    border-left: 3px solid var(--exp-purple);
}

.conversation-item.unread {
    background: #f0f9ff;
}

.conversation-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: var(--exp-purple);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1.125rem;
    flex-shrink: 0;
}

.conversation-avatar img {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    object-fit: cover;
}

.conversation-content {
    flex: 1;
    min-width: 0;
}

.conversation-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 4px;
}

.conversation-name {
    font-weight: 700;
    font-size: 0.875rem;
    color: var(--exp-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.conversation-time {
    font-size: 0.625rem;
    color: var(--exp-text-light);
    white-space: nowrap;
}

.conversation-subject {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--exp-text);
    margin-bottom: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.conversation-preview {
    font-size: 0.6875rem;
    color: var(--exp-text-light);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.conversation-meta {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 4px;
}

.message-count {
    font-size: 0.625rem;
    color: var(--exp-text-light);
    display: flex;
    align-items: center;
    gap: 2px;
}

.unread-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--exp-purple);
    margin-left: auto;
}

/* Conversation View */
.conversation-view {
    background: white;
    border-radius: var(--radius-md);
    border: 1px solid var(--exp-border);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    height: calc(100vh - 120px);
}

.conversation-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--exp-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: var(--exp-gray);
}

.conversation-header-left {
    display: flex;
    align-items: center;
    gap: 12px;
}

.back-btn {
    display: none;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: 1px solid var(--exp-border);
    background: white;
    color: var(--exp-text);
    align-items: center;
    justify-content: center;
    cursor: pointer;
}

.back-btn:hover {
    background: var(--exp-light-purple);
    color: var(--exp-purple);
}

.header-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: var(--exp-purple);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1.125rem;
}

.header-avatar img {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    object-fit: cover;
}

.header-info h2 {
    font-size: 1.125rem;
    font-weight: 700;
    margin: 0 0 4px 0;
}

.header-info p {
    font-size: 0.75rem;
    color: var(--exp-text-light);
    margin: 0;
}

.header-actions {
    display: flex;
    gap: 8px;
}

.header-action-btn {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: 1px solid var(--exp-border);
    background: white;
    color: var(--exp-text);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
}

.header-action-btn:hover {
    background: var(--exp-light-purple);
    color: var(--exp-purple);
}

/* Booking Context */
.booking-context {
    padding: 12px 20px;
    background: var(--exp-light-purple);
    border-bottom: 1px solid var(--exp-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
}

.booking-info {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}

.booking-badge {
    background: var(--exp-purple);
    color: white;
    padding: 4px 10px;
    border-radius: 100px;
    font-size: 0.6875rem;
    font-weight: 600;
}

.booking-details {
    font-size: 0.75rem;
    color: var(--exp-text);
}

.booking-details strong {
    color: var(--exp-purple);
}

.booking-link {
    color: var(--exp-purple);
    font-size: 0.75rem;
    font-weight: 600;
    text-decoration: none;
}

.booking-link:hover {
    text-decoration: underline;
}

/* Messages Area */
.messages-area {
    flex: 1;
    overflow-y: auto;
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 16px;
    background: #f8fafc;
}

.message-date-divider {
    text-align: center;
    margin: 10px 0;
    position: relative;
}

.message-date-divider span {
    background: white;
    padding: 4px 12px;
    border-radius: 100px;
    font-size: 0.6875rem;
    color: var(--exp-text-light);
    border: 1px solid var(--exp-border);
}

.message-row {
    display: flex;
    gap: 12px;
    max-width: 70%;
}

.message-row.sent {
    margin-left: auto;
    flex-direction: row-reverse;
}

.message-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--exp-purple);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 0.75rem;
    flex-shrink: 0;
}

.message-avatar img {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    object-fit: cover;
}

.message-bubble {
    background: white;
    border-radius: var(--radius-md);
    padding: 12px 16px;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--exp-border);
    position: relative;
}

.message-row.sent .message-bubble {
    background: var(--exp-purple);
    color: white;
    border-color: var(--exp-purple);
}

.message-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 6px;
    font-size: 0.6875rem;
}

.message-sender {
    font-weight: 700;
    color: var(--exp-purple);
}

.message-row.sent .message-sender {
    color: rgba(255,255,255,0.9);
}

.message-time {
    color: var(--exp-text-light);
}

.message-row.sent .message-time {
    color: rgba(255,255,255,0.7);
}

.message-content {
    font-size: 0.8125rem;
    line-height: 1.5;
    word-wrap: break-word;
}

.message-row.sent .message-content {
    color: white;
}

.message-status {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 4px;
    margin-top: 4px;
    font-size: 0.5625rem;
    color: var(--exp-text-light);
}

.message-row.sent .message-status {
    color: rgba(255,255,255,0.7);
}

/* Reply Area */
.reply-area {
    padding: 20px;
    border-top: 1px solid var(--exp-border);
    background: white;
}

.reply-form {
    display: flex;
    gap: 12px;
    align-items: flex-end;
}

.reply-input {
    flex: 1;
}

.reply-input textarea {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid var(--exp-border);
    border-radius: var(--radius-md);
    font-size: 0.8125rem;
    resize: none;
    min-height: 80px;
}

.reply-input textarea:focus {
    outline: none;
    border-color: var(--exp-purple);
    box-shadow: 0 0 0 3px rgba(147,51,234,0.1);
}

.reply-actions {
    display: flex;
    gap: 8px;
}

.reply-btn {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: var(--exp-purple);
    color: white;
    border: none;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
}

.reply-btn:hover {
    background: var(--exp-dark-purple);
    transform: scale(1.05);
}

/* New Message Modal */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 20px;
    overflow-y: auto;
}

.modal.active {
    display: flex;
}

.modal-content {
    background: white;
    border-radius: var(--radius-md);
    width: 100%;
    max-width: 500px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: var(--shadow-lg);
}

.modal-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--exp-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: sticky;
    top: 0;
    background: white;
    z-index: 10;
}

.modal-header h3 {
    font-size: 1.125rem;
    font-weight: 700;
    margin: 0;
}

.modal-close {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: none;
    background: var(--exp-gray);
    color: var(--exp-text);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
}

.modal-close:hover {
    background: var(--exp-danger);
    color: white;
}

.modal-body {
    padding: 20px;
}

.modal-footer {
    padding: 16px 20px;
    border-top: 1px solid var(--exp-border);
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    background: var(--exp-gray);
}

/* Form Styles */
.form-group {
    margin-bottom: 16px;
}

.form-label {
    display: block;
    font-size: 0.75rem;
    font-weight: 600;
    margin-bottom: 4px;
    color: var(--exp-text);
    text-transform: uppercase;
}

.form-control {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--exp-border);
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
}

.form-control:focus {
    outline: none;
    border-color: var(--exp-purple);
    box-shadow: 0 0 0 3px rgba(147,51,234,0.1);
}

select.form-control {
    cursor: pointer;
}

textarea.form-control {
    resize: vertical;
    min-height: 100px;
}

/* Empty States */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--exp-text-light);
}

.empty-state i {
    font-size: 3rem;
    margin-bottom: 16px;
    color: var(--exp-text-light);
}

.empty-state h3 {
    font-size: 1rem;
    font-weight: 600;
    margin-bottom: 8px;
    color: var(--exp-text);
}

.empty-state p {
    font-size: 0.8125rem;
}

/* Alerts */
.alert {
    padding: 12px 16px;
    border-radius: var(--radius-sm);
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.alert-success {
    background: #e6f4ea;
    color: #10b981;
    border: 1px solid #a7f3d0;
}

.alert-danger {
    background: #fce8e8;
    color: #ef4444;
    border: 1px solid #fecaca;
}

/* Responsive */
@media (max-width: 992px) {
    .messages-container {
        grid-template-columns: 1fr;
    }
    
    .conversations-sidebar {
        display: <?php echo $conversationId ? 'none' : 'block'; ?>;
    }
    
    .conversation-view {
        display: <?php echo $conversationId ? 'flex' : 'none'; ?>;
        height: calc(100vh - 180px);
    }
    
    .back-btn {
        display: flex;
    }
}

@media (max-width: 768px) {
    .message-row {
        max-width: 85%;
    }
    
    .booking-info {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }
    
    .reply-form {
        flex-direction: column;
        align-items: stretch;
    }
    
    .reply-actions {
        justify-content: flex-end;
    }
}
</style>

<div class="messages-container">
    <!-- Conversations List -->
    <div class="conversations-sidebar">
        <div class="sidebar-header">
            <h3>Messages</h3>
            <div style="display: flex; align-items: center; gap: 10px;">
                <?php if ($unreadCount > 0): ?>
                <span class="unread-badge"><?php echo $unreadCount; ?> unread</span>
                <?php endif; ?>
                <button class="new-message-btn" onclick="openNewMessageModal()" title="New Message">
                    <i class="bi bi-pencil-fill"></i>
                </button>
            </div>
        </div>
        
        <div class="search-box">
            <i class="bi bi-search"></i>
            <input type="text" placeholder="Search conversations..." id="searchConversations" onkeyup="filterConversations()">
        </div>
        
        <div class="conversations-list" id="conversationsList">
            <?php if (empty($conversations)): ?>
            <div style="text-align: center; padding: 40px 20px;">
                <i class="bi bi-chat-dots" style="font-size: 2rem; color: var(--exp-text-light); display: block; margin-bottom: 12px;"></i>
                <p style="color: var(--exp-text-light); font-size: 0.8125rem;">No conversations yet</p>
                <button class="btn-outline btn-sm" onclick="openNewMessageModal()" style="margin-top: 8px;">
                    <i class="bi bi-plus"></i> Start a conversation
                </button>
            </div>
            <?php else: ?>
                <?php foreach ($conversations as $conv): 
                    $isUnread = $conv['conversation_unread'] > 0;
                    $otherName = $conv['other_first_name'] . ' ' . substr($conv['other_last_name'] ?? '', 0, 1) . '.';
                    $avatar = $conv['other_avatar'] ?? null;
                    $timeAgo = timeAgo($conv['created_at']);
                    $preview = strlen($conv['message']) > 60 ? substr($conv['message'], 0, 60) . '...' : $conv['message'];
                ?>
                <div class="conversation-item <?php echo $conv['conversation_id'] == $conversationId ? 'active' : ''; ?> <?php echo $isUnread ? 'unread' : ''; ?>" 
                     onclick="window.location.href='messages.php?conversation=<?php echo $conv['conversation_id']; ?>'">
                    
                    <div class="conversation-avatar">
                        <?php if ($avatar): ?>
                        <img src="<?php echo getImageUrl($avatar, 'profile'); ?>" alt="">
                        <?php else: ?>
                        <?php echo strtoupper(substr($conv['other_first_name'] ?? 'G', 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    
                    <div class="conversation-content">
                        <div class="conversation-header">
                            <span class="conversation-name"><?php echo sanitize($otherName); ?></span>
                            <span class="conversation-time"><?php echo $timeAgo; ?></span>
                        </div>
                        
                        <div class="conversation-subject">
                            <?php echo sanitize($conv['subject'] ?: 'No subject'); ?>
                        </div>
                        
                        <div class="conversation-preview">
                            <?php echo sanitize($preview); ?>
                        </div>
                        
                        <div class="conversation-meta">
                            <span class="message-count">
                                <i class="bi bi-chat"></i> <?php echo $conv['total_messages']; ?>
                            </span>
                            <?php if ($conv['attraction_name']): ?>
                            <span class="message-count">
                                <i class="bi bi-ticket-perforated"></i> <?php echo sanitize($conv['attraction_name']); ?>
                            </span>
                            <?php endif; ?>
                            <?php if ($isUnread): ?>
                            <span class="unread-dot"></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1 && empty($conversationId)): ?>
        <div style="padding: 16px; border-top: 1px solid var(--exp-border); display: flex; justify-content: center; gap: 8px;">
            <?php if ($page > 1): ?>
            <a href="?page=<?php echo $page - 1; ?>" class="btn-outline btn-sm">
                <i class="bi bi-chevron-left"></i>
            </a>
            <?php endif; ?>
            
            <span style="font-size: 0.75rem; padding: 6px 12px;">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
            
            <?php if ($page < $totalPages): ?>
            <a href="?page=<?php echo $page + 1; ?>" class="btn-outline btn-sm">
                <i class="bi bi-chevron-right"></i>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Conversation View -->
    <div class="conversation-view">
        <?php if ($conversationId && !empty($messages)): ?>
            <!-- Header -->
            <div class="conversation-header">
                <div class="conversation-header-left">
                    <button class="back-btn" onclick="window.location.href='messages.php'">
                        <i class="bi bi-arrow-left"></i>
                    </button>
                    
                    <div class="header-avatar">
                        <?php if ($otherUser && $otherUser['profile_image']): ?>
                        <img src="<?php echo getImageUrl($otherUser['profile_image'], 'profile'); ?>" alt="">
                        <?php else: ?>
                        <?php echo $otherUser ? strtoupper(substr($otherUser['first_name'], 0, 1)) : 'G'; ?>
                        <?php endif; ?>
                    </div>
                    
                    <div class="header-info">
                        <h2><?php echo $otherUser ? sanitize($otherUser['first_name'] . ' ' . $otherUser['last_name']) : 'Guest'; ?></h2>
                        <p><?php echo $otherUser ? sanitize($otherUser['email']) : ''; ?></p>
                    </div>
                </div>
                
                <div class="header-actions">
                    <button class="header-action-btn" onclick="markAllRead(<?php echo $conversationId; ?>)" title="Mark all as read">
                        <i class="bi bi-check2-all"></i>
                    </button>
                    <button class="header-action-btn" onclick="archiveConversation(<?php echo $conversationId; ?>)" title="Archive">
                        <i class="bi bi-archive"></i>
                    </button>
                </div>
            </div>
            
            <!-- Booking Context -->
            <?php if ($bookingInfo): ?>
            <div class="booking-context">
                <div class="booking-info">
                    <span class="booking-badge">Booking #<?php echo $bookingInfo['booking_reference']; ?></span>
                    <span class="booking-details">
                        <strong><?php echo sanitize($bookingInfo['attraction_name']); ?></strong> - 
                        <?php echo sanitize($bookingInfo['tier_name']); ?> • 
                        <?php echo date('M d, Y', strtotime($bookingInfo['experience_date'])); ?> • 
                        <?php echo $bookingInfo['num_participants']; ?> participants
                    </span>
                </div>
                <a href="bookings.php?booking=<?php echo $bookingInfo['booking_id']; ?>" class="booking-link">
                    View Booking <i class="bi bi-arrow-right"></i>
                </a>
            </div>
            <?php endif; ?>
            
            <!-- Messages Area -->
            <div class="messages-area" id="messagesArea">
                <?php 
                $currentDate = '';
                foreach ($messages as $msg):
                    $msgDate = date('Y-m-d', strtotime($msg['created_at']));
                    if ($msgDate != $currentDate):
                        $currentDate = $msgDate;
                ?>
                <div class="message-date-divider">
                    <span><?php echo date('F j, Y', strtotime($msg['created_at'])); ?></span>
                </div>
                <?php endif; ?>
                
                <div class="message-row <?php echo $msg['sender_id'] == $userId ? 'sent' : 'received'; ?>">
                    <div class="message-avatar">
                        <?php if ($msg['sender_id'] == $userId): ?>
                            <?php echo strtoupper(substr($user['first_name'], 0, 1)); ?>
                        <?php else: ?>
                            <?php echo $otherUser ? strtoupper(substr($otherUser['first_name'], 0, 1)) : 'G'; ?>
                        <?php endif; ?>
                    </div>
                    
                    <div class="message-bubble">
                        <div class="message-header">
                            <span class="message-sender">
                                <?php echo $msg['sender_id'] == $userId ? 'You' : sanitize($otherUser['first_name'] ?? 'Guest'); ?>
                            </span>
                            <span class="message-time">
                                <?php echo date('h:i A', strtotime($msg['created_at'])); ?>
                            </span>
                        </div>
                        
                        <div class="message-content">
                            <?php echo nl2br(sanitize($msg['message'])); ?>
                        </div>
                        
                        <?php if ($msg['sender_id'] == $userId): ?>
                        <div class="message-status">
                            <?php if ($msg['is_read']): ?>
                            <span><i class="bi bi-check2-all"></i> Read</span>
                            <?php else: ?>
                            <span><i class="bi bi-check2"></i> Sent</span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Reply Area -->
            <div class="reply-area">
                <form method="POST" class="reply-form" onsubmit="return sendReply(event)">
                    <input type="hidden" name="receiver_id" value="<?php echo $otherUser['user_id']; ?>">
                    <input type="hidden" name="booking_id" value="<?php echo $bookingInfo['booking_id'] ?? ''; ?>">
                    <input type="hidden" name="parent_id" value="<?php echo end($messages)['message_id']; ?>">
                    <input type="hidden" name="subject" value="Re: <?php echo $messages[0]['subject']; ?>">
                    
                    <div class="reply-input">
                        <textarea name="message" placeholder="Type your message..." required></textarea>
                    </div>
                    
                    <div class="reply-actions">
                        <button type="submit" name="send_message" class="reply-btn" title="Send message">
                            <i class="bi bi-send-fill"></i>
                        </button>
                    </div>
                </form>
            </div>
            
        <?php else: ?>
            <!-- Empty State -->
            <div class="empty-state">
                <i class="bi bi-chat-dots"></i>
                <h3>No conversation selected</h3>
                <p>Choose a conversation from the list or start a new one</p>
                <button class="btn-primary" onclick="openNewMessageModal()" style="margin-top: 16px;">
                    <i class="bi bi-plus"></i> New Message
                </button>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- New Message Modal -->
<div class="modal" id="newMessageModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>New Message</h3>
            <button class="modal-close" onclick="closeModal('newMessageModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">To</label>
                    <select name="receiver_id" class="form-control" required>
                        <option value="">Select recipient</option>
                        <?php foreach ($recentGuests as $guest): ?>
                        <option value="<?php echo $guest['user_id']; ?>">
                            <?php echo sanitize($guest['first_name'] . ' ' . $guest['last_name'] . ' (' . $guest['email'] . ')'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Related Booking (Optional)</label>
                    <select name="booking_id" class="form-control">
                        <option value="">No related booking</option>
                        <?php foreach ($recentBookings as $booking): ?>
                        <option value="<?php echo $booking['booking_id']; ?>">
                            #<?php echo $booking['booking_reference']; ?> - <?php echo sanitize($booking['attraction_name']); ?> (<?php echo date('M d', strtotime($booking['experience_date'])); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Subject</label>
                    <input type="text" name="subject" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Message</label>
                    <textarea name="message" class="form-control" rows="5" required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('newMessageModal')">Cancel</button>
                <button type="submit" name="send_message" class="btn-primary">Send Message</button>
            </div>
        </form>
    </div>
</div>

<script>
// ============================================
// SCROLL TO BOTTOM OF MESSAGES
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    const messagesArea = document.getElementById('messagesArea');
    if (messagesArea) {
        messagesArea.scrollTop = messagesArea.scrollHeight;
    }
});

// ============================================
// SEND REPLY (AJAX)
// ============================================
function sendReply(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    
    fetch('messages.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        // Reload the page to show new message
        window.location.reload();
    });
    
    return false;
}

// ============================================
// MARK AS READ (AJAX)
// ============================================
function markAsRead(messageId) {
    fetch('messages.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'mark_read=1&message_id=' + messageId
    });
}

function markAllRead(conversationId) {
    if (confirm('Mark all messages as read?')) {
        fetch('messages.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'mark_all_read=1'
        }).then(() => {
            window.location.reload();
        });
    }
}

function archiveConversation(conversationId) {
    if (confirm('Archive this conversation?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `<input type="hidden" name="conversation_id" value="${conversationId}"><input type="hidden" name="archive_conversation" value="1">`;
        document.body.appendChild(form);
        form.submit();
    }
}

// ============================================
// MODAL FUNCTIONS
// ============================================
function openNewMessageModal() {
    document.getElementById('newMessageModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
    document.body.style.overflow = 'auto';
}

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('active');
        document.body.style.overflow = 'auto';
    }
}

// ============================================
// FILTER CONVERSATIONS
// ============================================
function filterConversations() {
    const search = document.getElementById('searchConversations').value.toLowerCase();
    const conversations = document.querySelectorAll('.conversation-item');
    
    conversations.forEach(conv => {
        const name = conv.querySelector('.conversation-name')?.textContent.toLowerCase() || '';
        const subject = conv.querySelector('.conversation-subject')?.textContent.toLowerCase() || '';
        const preview = conv.querySelector('.conversation-preview')?.textContent.toLowerCase() || '';
        
        if (name.includes(search) || subject.includes(search) || preview.includes(search)) {
            conv.style.display = 'flex';
        } else {
            conv.style.display = 'none';
        }
    });
}

// Auto-mark messages as read when viewed
document.querySelectorAll('.message-row.received').forEach(msg => {
    // Could implement Intersection Observer here
});
</script>

<?php require_once 'includes/experiences_footer.php'; ?>