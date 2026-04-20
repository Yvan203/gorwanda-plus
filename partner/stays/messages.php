<?php
$pageTitle = 'Messages';
require_once 'includes/stays_header.php';

$db = getDB();
$userId = $_SESSION['user_id'];
$userType = $_SESSION['user_type'];

// ============================================
// GET FILTERS
// ============================================
$conversationId = isset($_GET['conversation']) ? sanitize($_GET['conversation']) : '';
$folder = isset($_GET['folder']) ? sanitize($_GET['folder']) : 'inbox';
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';

// ============================================
// HANDLE MESSAGE ACTIONS
// ============================================

// Send new message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $receiverId = intval($_POST['receiver_id']);
    $bookingId = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : null;
    $subject = sanitize($_POST['subject']);
    $message = sanitize($_POST['message']);
    
    // Generate conversation ID (sort IDs to ensure consistency)
    $participants = [$userId, $receiverId];
    sort($participants);
    $conversationId = 'conv_' . implode('_', $participants) . '_' . time();
    
    // Insert message
    $stmt = $db->prepare("
        INSERT INTO messages (conversation_id, sender_id, receiver_id, booking_id, subject, message, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$conversationId, $userId, $receiverId, $bookingId, $subject, $message]);
    
    // Add to conversation participants
    $stmt = $db->prepare("
        INSERT IGNORE INTO conversation_participants (conversation_id, user_id, last_read_at)
        VALUES (?, ?, NOW()), (?, ?, NOW())
    ");
    $stmt->execute([$conversationId, $userId, $conversationId, $receiverId]);
    
    $success = "Message sent successfully!";
}

// Reply to conversation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply_message'])) {
    $conversationId = sanitize($_POST['conversation_id']);
    $receiverId = intval($_POST['receiver_id']);
    $message = sanitize($_POST['message']);
    
    // Insert reply
    $stmt = $db->prepare("
        INSERT INTO messages (conversation_id, sender_id, receiver_id, message, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$conversationId, $userId, $receiverId, $message]);
    
    // Update last read
    $stmt = $db->prepare("
        UPDATE conversation_participants 
        SET last_read_at = NOW() 
        WHERE conversation_id = ? AND user_id = ?
    ");
    $stmt->execute([$conversationId, $userId]);
    
    $success = "Reply sent!";
}

// Mark as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
    $conversationId = sanitize($_POST['conversation_id']);
    
    $stmt = $db->prepare("
        UPDATE conversation_participants 
        SET last_read_at = NOW() 
        WHERE conversation_id = ? AND user_id = ?
    ");
    $stmt->execute([$conversationId, $userId]);
    
    // Also mark individual messages as read
    $stmt = $db->prepare("
        UPDATE messages 
        SET is_read = 1 
        WHERE conversation_id = ? AND receiver_id = ? AND is_read = 0
    ");
    $stmt->execute([$conversationId, $userId]);
}

// Archive conversation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_conversation'])) {
    $conversationId = sanitize($_POST['conversation_id']);
    
    $stmt = $db->prepare("
        UPDATE messages 
        SET is_archived = 1 
        WHERE conversation_id = ? AND receiver_id = ?
    ");
    $stmt->execute([$conversationId, $userId]);
    
    $success = "Conversation archived";
}

// Delete message (soft delete or actual delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_message'])) {
    $messageId = intval($_POST['message_id']);
    
    // Verify ownership
    $stmt = $db->prepare("DELETE FROM messages WHERE message_id = ? AND sender_id = ?");
    $stmt->execute([$messageId, $userId]);
    
    $success = "Message deleted";
}

// Use template
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['use_template'])) {
    $templateId = intval($_POST['template_id']);
    $guestName = sanitize($_POST['guest_name']);
    $propertyName = sanitize($_POST['property_name']);
    $checkinDate = $_POST['checkin_date'] ?? '';
    $checkinTime = $_POST['checkin_time'] ?? '';
    $propertyAddress = $_POST['property_address'] ?? '';
    
    $stmt = $db->prepare("SELECT * FROM message_templates WHERE template_id = ? AND (user_id = ? OR is_default = 1)");
    $stmt->execute([$templateId, $userId]);
    $template = $stmt->fetch();
    
    if ($template) {
        // Replace placeholders
        $subject = str_replace(['[Guest Name]', '[Property Name]'], [$guestName, $propertyName], $template['subject']);
        $message = str_replace(
            ['[Guest Name]', '[Property Name]', '[Check-in Date]', '[Check-in Time]', '[Check-out Time]', '[Property Address]', '[Your Name]'],
            [$guestName, $propertyName, $checkinDate, $checkinTime, '11:00', $propertyAddress, $_SESSION['user_name']],
            $template['message']
        );
        
        // Store in session for the compose form
        $_SESSION['template_subject'] = $subject;
        $_SESSION['template_message'] = $message;
    }
}

// ============================================
// GET CONVERSATIONS
// ============================================

// Build query based on folder
$conversationWhere = "";
if ($folder === 'inbox') {
    $conversationWhere = "AND m.receiver_id = ? AND m.is_archived = 0";
    $conversationParams = [$userId];
} elseif ($folder === 'sent') {
    $conversationWhere = "AND m.sender_id = ? AND m.is_archived = 0";
    $conversationParams = [$userId];
} elseif ($folder === 'archived') {
    $conversationWhere = "AND m.receiver_id = ? AND m.is_archived = 1";
    $conversationParams = [$userId];
} elseif ($folder === 'unread') {
    $conversationWhere = "AND m.receiver_id = ? AND m.is_read = 0 AND m.is_archived = 0";
    $conversationParams = [$userId];
}

// Get all conversations
$stmt = $db->prepare("
    SELECT 
        m.conversation_id,
        MAX(m.created_at) as last_message_time,
        COUNT(CASE WHEN m.receiver_id = ? AND m.is_read = 0 THEN 1 END) as unread_count,
        (SELECT message FROM messages WHERE conversation_id = m.conversation_id ORDER BY created_at DESC LIMIT 1) as last_message,
        (SELECT subject FROM messages WHERE conversation_id = m.conversation_id ORDER BY created_at DESC LIMIT 1) as last_subject,
        (SELECT sender_id FROM messages WHERE conversation_id = m.conversation_id ORDER BY created_at DESC LIMIT 1) as last_sender_id,
        CASE 
            WHEN m.sender_id = ? THEN m.receiver_id
            ELSE m.sender_id
        END as other_user_id
    FROM messages m
    WHERE 1=1 $conversationWhere
    GROUP BY m.conversation_id
    ORDER BY last_message_time DESC
");
$params = array_merge([$userId, $userId], $conversationParams);
$stmt->execute($params);
$conversations = $stmt->fetchAll();

// Get details for each conversation
foreach ($conversations as &$conv) {
    // Get other user details
    $stmt = $db->prepare("
        SELECT user_id, first_name, last_name, email, profile_image, user_type
        FROM users WHERE user_id = ?
    ");
    $stmt->execute([$conv['other_user_id']]);
    $conv['other_user'] = $stmt->fetch();
    
    // Get last message sender
    $stmt = $db->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
    $stmt->execute([$conv['last_sender_id']]);
    $sender = $stmt->fetch();
    $conv['last_sender_name'] = $sender ? $sender['first_name'] . ' ' . substr($sender['last_name'], 0, 1) . '.' : 'Unknown';
}

// Get messages for selected conversation
$messages = [];
$currentConversation = null;
$otherUser = null;

if ($conversationId) {
    // Get conversation messages
    $stmt = $db->prepare("
        SELECT m.*, u.first_name, u.last_name, u.profile_image, u.user_type
        FROM messages m
        JOIN users u ON m.sender_id = u.user_id
        WHERE m.conversation_id = ?
        ORDER BY m.created_at ASC
    ");
    $stmt->execute([$conversationId]);
    $messages = $stmt->fetchAll();
    
    // Get other participant
    foreach ($conversations as $c) {
        if ($c['conversation_id'] === $conversationId) {
            $currentConversation = $c;
            $otherUser = $c['other_user'];
            break;
        }
    }
    
    // Mark messages as read
    if (!empty($messages)) {
        $stmt = $db->prepare("
            UPDATE messages 
            SET is_read = 1 
            WHERE conversation_id = ? AND receiver_id = ? AND is_read = 0
        ");
        $stmt->execute([$conversationId, $userId]);
    }
}

// Get unread count for badge
$stmt = $db->prepare("
    SELECT COUNT(*) as unread 
    FROM messages 
    WHERE receiver_id = ? AND is_read = 0 AND is_archived = 0
");
$stmt->execute([$userId]);
$unreadCount = $stmt->fetchColumn();

// Get message templates
$stmt = $db->prepare("
    SELECT * FROM message_templates 
    WHERE user_id = ? OR is_default = 1 
    ORDER BY is_default DESC, template_name
");
$stmt->execute([$userId]);
$templates = $stmt->fetchAll();

// Get recent contacts (users who have messaged or been messaged)
$stmt = $db->prepare("
    SELECT DISTINCT 
        CASE 
            WHEN m.sender_id = ? THEN m.receiver_id
            ELSE m.sender_id
        END as contact_id
    FROM messages m
    WHERE m.sender_id = ? OR m.receiver_id = ?
    ORDER BY m.created_at DESC
    LIMIT 10
");
$stmt->execute([$userId, $userId, $userId]);
$contactIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

$recentContacts = [];
if (!empty($contactIds)) {
    $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
    $stmt = $db->prepare("
        SELECT user_id, first_name, last_name, email, profile_image, user_type
        FROM users WHERE user_id IN ($placeholders)
    ");
    $stmt->execute($contactIds);
    $recentContacts = $stmt->fetchAll();
}

// Get user's properties for context
$stmt = $db->prepare("
    SELECT stay_id, stay_name, city FROM stays WHERE owner_id = ? AND is_active = 1
");
$stmt->execute([$userId]);
$properties = $stmt->fetchAll();
?>

<style>
/* Messages Specific Styles */
.messages-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 15px;
}

.messages-title h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--booking-text);
    margin: 0 0 4px 0;
}

.messages-title p {
    font-size: 0.8125rem;
    color: var(--booking-text-light);
    margin: 0;
}

/* Messages Container */
.messages-container {
    display: grid;
    grid-template-columns: 350px 1fr;
    gap: 20px;
    background: white;
    border-radius: var(--radius-lg);
    border: 1px solid var(--booking-border);
    overflow: hidden;
    min-height: 600px;
}

/* Sidebar */
.messages-sidebar {
    border-right: 1px solid var(--booking-border);
    background: var(--booking-gray);
    display: flex;
    flex-direction: column;
}

.sidebar-header {
    padding: 20px;
    border-bottom: 1px solid var(--booking-border);
}

.compose-btn {
    width: 100%;
    padding: 12px;
    background: var(--booking-blue);
    color: white;
    border: none;
    border-radius: var(--radius-md);
    font-weight: 600;
    font-size: 0.9375rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: all 0.2s;
}

.compose-btn:hover {
    background: var(--booking-dark-blue);
}

.folder-nav {
    padding: 16px;
}

.folder-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 12px;
    border-radius: var(--radius-md);
    color: var(--booking-text);
    text-decoration: none;
    transition: all 0.2s;
    margin-bottom: 2px;
}

.folder-item:hover {
    background: var(--booking-light-blue);
    color: var(--booking-blue);
}

.folder-item.active {
    background: var(--booking-light-blue);
    color: var(--booking-blue);
    font-weight: 600;
}

.folder-item i {
    font-size: 1.125rem;
    margin-right: 12px;
}

.folder-item .badge {
    background: var(--booking-danger);
    color: white;
    padding: 2px 6px;
    border-radius: 100px;
    font-size: 0.6875rem;
    font-weight: 600;
}

/* Conversation List */
.conversation-list {
    flex: 1;
    overflow-y: auto;
    max-height: 500px;
}

.conversation-item {
    display: flex;
    padding: 16px;
    border-bottom: 1px solid var(--booking-border);
    cursor: pointer;
    transition: all 0.2s;
    position: relative;
}

.conversation-item:hover {
    background: var(--booking-light-blue);
}

.conversation-item.active {
    background: var(--booking-light-blue);
    border-left: 3px solid var(--booking-blue);
}

.conversation-item.unread {
    background: #fff4e6;
}

.conversation-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: var(--booking-blue);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1.125rem;
    margin-right: 12px;
    flex-shrink: 0;
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
    font-size: 0.9375rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.conversation-time {
    font-size: 0.6875rem;
    color: var(--booking-text-light);
}

.conversation-subject {
    font-size: 0.8125rem;
    font-weight: 600;
    margin-bottom: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.conversation-preview {
    font-size: 0.75rem;
    color: var(--booking-text-light);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.unread-indicator {
    position: absolute;
    top: 16px;
    right: 16px;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--booking-danger);
}

/* Message Area */
.messages-main {
    display: flex;
    flex-direction: column;
    height: 100%;
}

.message-header {
    padding: 20px;
    border-bottom: 1px solid var(--booking-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.message-contact-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.message-contact-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: var(--booking-blue);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1.125rem;
}

.message-contact-details h3 {
    font-size: 1.125rem;
    font-weight: 700;
    margin-bottom: 2px;
}

.message-contact-details p {
    font-size: 0.75rem;
    color: var(--booking-text-light);
}

.message-actions {
    display: flex;
    gap: 8px;
}

.message-action-btn {
    padding: 8px 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-sm);
    background: white;
    color: var(--booking-text);
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.message-action-btn:hover {
    background: var(--booking-light-blue);
    border-color: var(--booking-blue);
    color: var(--booking-blue);
}

.messages-thread {
    flex: 1;
    overflow-y: auto;
    padding: 20px;
    max-height: 400px;
    background: var(--booking-gray);
}

.message-bubble {
    display: flex;
    margin-bottom: 20px;
    max-width: 70%;
}

.message-bubble.sent {
    margin-left: auto;
    flex-direction: row-reverse;
}

.message-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: var(--booking-blue);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.875rem;
    font-weight: 600;
    margin: 0 10px;
    flex-shrink: 0;
}

.message-content {
    background: white;
    border-radius: var(--radius-lg);
    padding: 12px 16px;
    box-shadow: var(--shadow-sm);
}

.message-bubble.sent .message-content {
    background: var(--booking-light-blue);
}

.message-sender {
    font-size: 0.75rem;
    font-weight: 600;
    margin-bottom: 4px;
    color: var(--booking-blue);
}

.message-text {
    font-size: 0.875rem;
    line-height: 1.5;
    margin-bottom: 4px;
}

.message-time {
    font-size: 0.625rem;
    color: var(--booking-text-light);
    text-align: right;
}

.message-reply-form {
    padding: 20px;
    border-top: 1px solid var(--booking-border);
}

.reply-input {
    display: flex;
    gap: 12px;
}

.reply-input textarea {
    flex: 1;
    padding: 12px;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-md);
    resize: none;
    font-size: 0.875rem;
}

.reply-input button {
    padding: 0 24px;
    background: var(--booking-blue);
    color: white;
    border: none;
    border-radius: var(--radius-md);
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.reply-input button:hover {
    background: var(--booking-dark-blue);
}

/* Compose Modal */
.compose-modal .modal-content {
    max-width: 600px;
}

.contact-search {
    position: relative;
}

.contact-search-results {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-md);
    max-height: 200px;
    overflow-y: auto;
    z-index: 10;
    display: none;
}

.contact-search-results.show {
    display: block;
}

.contact-result-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 16px;
    cursor: pointer;
    transition: all 0.2s;
}

.contact-result-item:hover {
    background: var(--booking-light-blue);
}

.contact-result-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--booking-blue);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.875rem;
    font-weight: 600;
}

/* Templates */
.templates-dropdown {
    position: relative;
    display: inline-block;
}

.templates-menu {
    position: absolute;
    top: 100%;
    right: 0;
    background: white;
    border: 1px solid var(--booking-border);
    border-radius: var(--radius-md);
    width: 300px;
    max-height: 400px;
    overflow-y: auto;
    z-index: 10;
    display: none;
    box-shadow: var(--shadow-lg);
}

.templates-menu.show {
    display: block;
}

.template-item {
    padding: 12px 16px;
    border-bottom: 1px solid var(--booking-border);
    cursor: pointer;
    transition: all 0.2s;
}

.template-item:hover {
    background: var(--booking-light-blue);
}

.template-item:last-child {
    border-bottom: none;
}

.template-name {
    font-weight: 700;
    font-size: 0.875rem;
    margin-bottom: 2px;
}

.template-preview {
    font-size: 0.75rem;
    color: var(--booking-text-light);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Responsive */
@media (max-width: 992px) {
    .messages-container {
        grid-template-columns: 1fr;
    }
    
    .messages-sidebar {
        display: none;
    }
    
    .messages-sidebar.show {
        display: block;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        z-index: 1000;
    }
}
</style>

<div class="messages-header">
    <div class="messages-title">
        <h1>Messages</h1>
        <p>Communicate with guests and manage all conversations</p>
    </div>
    <div>
        <button class="btn-primary" onclick="openComposeModal()">
            <i class="bi bi-pencil-square"></i> Compose
        </button>
    </div>
</div>

<!-- Messages Container -->
<div class="messages-container">
    <!-- Sidebar -->
    <div class="messages-sidebar">
        <div class="sidebar-header">
            <button class="compose-btn" onclick="openComposeModal()">
                <i class="bi bi-pencil-square"></i> New Message
            </button>
        </div>
        
        <!-- Folders -->
        <div class="folder-nav">
            <a href="?folder=inbox" class="folder-item <?php echo $folder == 'inbox' ? 'active' : ''; ?>">
                <div>
                    <i class="bi bi-inbox"></i>
                    <span>Inbox</span>
                </div>
                <?php if ($unreadCount > 0): ?>
                <span class="badge"><?php echo $unreadCount; ?></span>
                <?php endif; ?>
            </a>
            <a href="?folder=unread" class="folder-item <?php echo $folder == 'unread' ? 'active' : ''; ?>">
                <div>
                    <i class="bi bi-envelope"></i>
                    <span>Unread</span>
                </div>
            </a>
            <a href="?folder=sent" class="folder-item <?php echo $folder == 'sent' ? 'active' : ''; ?>">
                <div>
                    <i class="bi bi-send"></i>
                    <span>Sent</span>
                </div>
            </a>
            <a href="?folder=archived" class="folder-item <?php echo $folder == 'archived' ? 'active' : ''; ?>">
                <div>
                    <i class="bi bi-archive"></i>
                    <span>Archived</span>
                </div>
            </a>
        </div>
        
        <!-- Recent Contacts -->
        <?php if (!empty($recentContacts)): ?>
        <div style="padding: 0 16px 16px;">
            <div style="font-size: 0.75rem; font-weight: 600; color: var(--booking-text-light); text-transform: uppercase; margin-bottom: 8px;">
                Recent Contacts
            </div>
            <?php foreach ($recentContacts as $contact): ?>
            <div class="conversation-item" onclick="startNewConversation(<?php echo $contact['user_id']; ?>)">
                <div class="conversation-avatar">
                    <?php echo strtoupper(substr($contact['first_name'], 0, 1)); ?>
                </div>
                <div class="conversation-content">
                    <div class="conversation-header">
                        <span class="conversation-name">
                            <?php echo sanitize($contact['first_name'] . ' ' . substr($contact['last_name'], 0, 1) . '.'); ?>
                        </span>
                    </div>
                    <div class="conversation-preview">
                        <?php echo ucfirst(str_replace('_', ' ', $contact['user_type'])); ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <!-- Conversation List -->
        <div class="conversation-list">
            <?php if (empty($conversations)): ?>
            <div style="text-align: center; padding: 40px 20px; color: var(--booking-text-light);">
                <i class="bi bi-chat-dots" style="font-size: 2rem; display: block; margin-bottom: 12px;"></i>
                <p>No conversations yet</p>
            </div>
            <?php else: ?>
                <?php foreach ($conversations as $conv): 
                    $isUnread = $conv['unread_count'] > 0 && $folder != 'sent';
                    $isActive = $conv['conversation_id'] == $conversationId;
                ?>
                <a href="?conversation=<?php echo $conv['conversation_id']; ?>&folder=<?php echo $folder; ?>" 
                   class="conversation-item <?php echo $isActive ? 'active' : ''; ?> <?php echo $isUnread ? 'unread' : ''; ?>">
                    <div class="conversation-avatar">
                        <?php echo strtoupper(substr($conv['other_user']['first_name'], 0, 1)); ?>
                    </div>
                    <div class="conversation-content">
                        <div class="conversation-header">
                            <span class="conversation-name">
                                <?php echo sanitize($conv['other_user']['first_name'] . ' ' . substr($conv['other_user']['last_name'], 0, 1) . '.'); ?>
                            </span>
                            <span class="conversation-time">
                                <?php echo timeAgo($conv['last_message_time']); ?>
                            </span>
                        </div>
                        <div class="conversation-subject">
                            <?php echo $conv['last_subject'] ?: '(No Subject)'; ?>
                        </div>
                        <div class="conversation-preview">
                            <?php if ($conv['last_sender_id'] == $userId): ?>
                            <span style="color: var(--booking-blue);">You: </span>
                            <?php endif; ?>
                            <?php echo substr(strip_tags($conv['last_message']), 0, 60); ?>...
                        </div>
                    </div>
                    <?php if ($isUnread): ?>
                    <span class="unread-indicator"></span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Main Message Area -->
    <div class="messages-main">
        <?php if ($conversationId && $currentConversation && $otherUser): ?>
            <!-- Message Header -->
            <div class="message-header">
                <div class="message-contact-info">
                    <div class="message-contact-avatar">
                        <?php echo strtoupper(substr($otherUser['first_name'], 0, 1)); ?>
                    </div>
                    <div class="message-contact-details">
                        <h3><?php echo sanitize($otherUser['first_name'] . ' ' . $otherUser['last_name']); ?></h3>
                        <p>
                            <?php echo ucfirst(str_replace('_', ' ', $otherUser['user_type'])); ?> • 
                            <?php echo sanitize($otherUser['email']); ?>
                        </p>
                    </div>
                </div>
                
                <div class="message-actions">
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="conversation_id" value="<?php echo $conversationId; ?>">
                        <button type="submit" name="archive_conversation" class="message-action-btn" 
                                onclick="return confirm('Archive this conversation?')">
                            <i class="bi bi-archive"></i> Archive
                        </button>
                    </form>
                    <button class="message-action-btn" onclick="showTemplates()">
                        <i class="bi bi-file-text"></i> Templates
                    </button>
                </div>
            </div>
            
            <!-- Message Thread -->
            <div class="messages-thread" id="messageThread">
                <?php foreach ($messages as $msg): 
                    $isSent = $msg['sender_id'] == $userId;
                ?>
                <div class="message-bubble <?php echo $isSent ? 'sent' : ''; ?>">
                    <div class="message-avatar">
                        <?php echo strtoupper(substr($msg['first_name'], 0, 1)); ?>
                    </div>
                    <div class="message-content">
                        <?php if (!$isSent): ?>
                        <div class="message-sender">
                            <?php echo sanitize($msg['first_name'] . ' ' . substr($msg['last_name'], 0, 1) . '.'); ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($msg['subject']): ?>
                        <div style="font-weight: 600; margin-bottom: 4px;"><?php echo sanitize($msg['subject']); ?></div>
                        <?php endif; ?>
                        <div class="message-text"><?php echo nl2br(sanitize($msg['message'])); ?></div>
                        <div class="message-time">
                            <?php echo date('M j, Y g:i A', strtotime($msg['created_at'])); ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Reply Form -->
            <div class="message-reply-form">
                <form method="POST">
                    <input type="hidden" name="conversation_id" value="<?php echo $conversationId; ?>">
                    <input type="hidden" name="receiver_id" value="<?php echo $otherUser['user_id']; ?>">
                    
                    <div class="reply-input">
                        <textarea name="message" rows="3" placeholder="Type your message..." required></textarea>
                        <button type="submit" name="reply_message">Send</button>
                    </div>
                    
                    <div style="margin-top: 8px; display: flex; gap: 8px; justify-content: flex-end;">
                        <button type="button" class="btn-outline btn-sm" onclick="showTemplates()">
                            <i class="bi bi-file-text"></i> Use Template
                        </button>
                    </div>
                </form>
            </div>
            
        <?php else: ?>
            <!-- Empty State -->
            <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; padding: 40px; text-align: center; color: var(--booking-text-light);">
                <i class="bi bi-chat-dots" style="font-size: 4rem; margin-bottom: 20px;"></i>
                <h3 style="font-size: 1.25rem; margin-bottom: 8px;">Select a conversation</h3>
                <p style="max-width: 300px;">Choose a conversation from the sidebar or start a new message</p>
                <button class="btn-primary" style="margin-top: 20px;" onclick="openComposeModal()">
                    <i class="bi bi-pencil-square"></i> Compose New Message
                </button>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ============================================ -->
<!-- MODALS -->
<!-- ============================================ -->

<!-- Compose Message Modal -->
<div class="modal compose-modal" id="composeModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>New Message</h3>
            <button class="modal-close" onclick="closeModal('composeModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST" id="composeForm">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">To <span class="required">*</span></label>
                    <div class="contact-search">
                        <input type="text" id="contactSearch" class="form-control" placeholder="Search by name or email..." autocomplete="off">
                        <input type="hidden" name="receiver_id" id="receiver_id" required>
                        <div class="contact-search-results" id="searchResults"></div>
                    </div>
                    <div id="selectedContact" style="display: none; margin-top: 8px; padding: 8px; background: var(--booking-light-blue); border-radius: var(--radius-sm);"></div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Subject</label>
                    <input type="text" name="subject" id="compose_subject" class="form-control" 
                           value="<?php echo $_SESSION['template_subject'] ?? ''; ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Message <span class="required">*</span></label>
                    <textarea name="message" id="compose_message" rows="6" class="form-control" required><?php echo $_SESSION['template_message'] ?? ''; ?></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Related Booking (Optional)</label>
                    <select name="booking_id" class="form-control">
                        <option value="">None</option>
                        <?php
                        // Get recent bookings for this user's properties
                        $stmt = $db->prepare("
                            SELECT b.booking_id, b.booking_reference, s.stay_name, b.check_in_date
                            FROM bookings b
                            JOIN stay_rooms sr ON b.stay_room_id = sr.room_id
                            JOIN stays s ON sr.stay_id = s.stay_id
                            WHERE s.owner_id = ?
                            ORDER BY b.created_at DESC
                            LIMIT 10
                        ");
                        $stmt->execute([$userId]);
                        $recentBookings = $stmt->fetchAll();
                        ?>
                        <?php foreach ($recentBookings as $booking): ?>
                        <option value="<?php echo $booking['booking_id']; ?>">
                            #<?php echo $booking['booking_reference']; ?> - <?php echo sanitize($booking['stay_name']); ?> (<?php echo $booking['check_in_date']; ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal('composeModal')">Cancel</button>
                <button type="submit" name="send_message" class="btn-primary">Send Message</button>
            </div>
        </form>
    </div>
</div>

<!-- Templates Modal -->
<div class="modal" id="templatesModal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3>Message Templates</h3>
            <button class="modal-close" onclick="closeModal('templatesModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-body">
            <p style="margin-bottom: 16px;">Select a template to quickly compose common messages.</p>
            
            <?php if (empty($templates)): ?>
            <p class="text-secondary">No templates available.</p>
            <?php else: ?>
                <?php foreach ($templates as $template): ?>
                <div class="template-item" onclick="useTemplate(<?php echo $template['template_id']; ?>)">
                    <div class="template-name"><?php echo sanitize($template['template_name']); ?></div>
                    <div class="template-preview"><?php echo substr(strip_tags($template['message']), 0, 100); ?>...</div>
                    <div style="font-size: 0.7rem; color: var(--booking-text-light); margin-top: 4px;">
                        <?php echo ucfirst($template['category']); ?>
                        <?php if ($template['is_default']): ?> • Default<?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-secondary" onclick="closeModal('templatesModal')">Close</button>
        </div>
    </div>
</div>

<script>
// ============================================
// MODAL FUNCTIONS
// ============================================
function openModal(modalId) {
    document.getElementById(modalId).classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
    document.body.style.overflow = 'auto';
    
    // Clear session template data
    <?php unset($_SESSION['template_subject']); ?>
    <?php unset($_SESSION['template_message']); ?>
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('active');
        document.body.style.overflow = 'auto';
    }
}

// ============================================
// COMPOSE MESSAGE
// ============================================
function openComposeModal() {
    document.getElementById('composeForm').reset();
    document.getElementById('selectedContact').style.display = 'none';
    document.getElementById('receiver_id').value = '';
    openModal('composeModal');
}

function startNewConversation(userId) {
    // You would fetch user details and populate the compose modal
    window.location.href = 'messages.php?compose=' + userId;
}

// Contact search
document.getElementById('contactSearch')?.addEventListener('input', function() {
    const searchTerm = this.value;
    if (searchTerm.length < 2) {
        document.getElementById('searchResults').classList.remove('show');
        return;
    }
    
    // In production, this would be an AJAX call
    // For now, we'll simulate with a static list
    fetch(`/gorwanda-plus/api/search-users.php?q=${searchTerm}`)
        .then(response => response.json())
        .then(data => {
            const resultsDiv = document.getElementById('searchResults');
            resultsDiv.innerHTML = '';
            
            data.forEach(user => {
                const div = document.createElement('div');
                div.className = 'contact-result-item';
                div.onclick = () => selectContact(user);
                div.innerHTML = `
                    <div class="contact-result-avatar">${user.first_name.charAt(0)}</div>
                    <div>
                        <div style="font-weight: 600;">${user.first_name} ${user.last_name}</div>
                        <div style="font-size: 0.75rem; color: var(--booking-text-light);">${user.email}</div>
                    </div>
                `;
                resultsDiv.appendChild(div);
            });
            
            resultsDiv.classList.add('show');
        });
});

function selectContact(user) {
    document.getElementById('receiver_id').value = user.user_id;
    document.getElementById('contactSearch').value = user.first_name + ' ' + user.last_name;
    document.getElementById('selectedContact').style.display = 'block';
    document.getElementById('selectedContact').innerHTML = `
        <i class="bi bi-person-check-fill text-success"></i>
        Sending to: <strong>${user.first_name} ${user.last_name}</strong> (${user.email})
    `;
    document.getElementById('searchResults').classList.remove('show');
}

// ============================================
// TEMPLATES
// ============================================
function showTemplates() {
    openModal('templatesModal');
}

function useTemplate(templateId) {
    // Get guest details from current conversation
    <?php if ($otherUser): ?>
    const guestName = "<?php echo $otherUser['first_name']; ?>";
    const propertyName = "<?php echo $properties[0]['stay_name'] ?? 'Your Property'; ?>";
    const checkinDate = "<?php echo date('Y-m-d'); ?>";
    const checkinTime = "14:00";
    const propertyAddress = "<?php echo $properties[0]['city'] ?? 'Kigali, Rwanda'; ?>";
    
    // Create form and submit to use template
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="template_id" value="${templateId}">
        <input type="hidden" name="guest_name" value="${guestName}">
        <input type="hidden" name="property_name" value="${propertyName}">
        <input type="hidden" name="checkin_date" value="${checkinDate}">
        <input type="hidden" name="checkin_time" value="${checkinTime}">
        <input type="hidden" name="property_address" value="${propertyAddress}">
        <input type="hidden" name="use_template" value="1">
    `;
    document.body.appendChild(form);
    form.submit();
    <?php endif; ?>
    
    closeModal('templatesModal');
}

// ============================================
// SCROLL TO BOTTOM OF MESSAGES
// ============================================
function scrollToBottom() {
    const thread = document.getElementById('messageThread');
    if (thread) {
        thread.scrollTop = thread.scrollHeight;
    }
}

// Scroll to bottom on page load
document.addEventListener('DOMContentLoaded', function() {
    scrollToBottom();
});

// ============================================
// AUTO-RESIZE TEXTAREA
// ============================================
document.querySelectorAll('textarea').forEach(textarea => {
    textarea.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
    });
});
</script>

<?php require_once 'includes/stays_footer.php'; ?>