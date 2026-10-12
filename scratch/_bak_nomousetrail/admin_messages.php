<?php
session_start();
include("dbconnection.php");
include_once("includes/rbac_guard.php");

// RBAC Access Guard
$user_role = rbac_get_role();
if (!isset($_SESSION['user_id']) || !in_array($user_role, ['admin', 'staff', 'clinic_owner', 'owner'])) {
    header("Location: " . rbac_home_url($user_role));
    exit();
}

// RBAC: vet staff need the "Chat with clients" task
include_once("includes/permissions.php");
if ($user_role === 'staff' && !pv_can($conn, 'staff.assist_client_communication')) {
    $_SESSION['flash_msg'] = pv_denied_message('staff.assist_client_communication');
    $_SESSION['flash_type'] = 'danger';
    header("Location: staff_dashboard.php");
    exit();
}
$first_admin_query = $conn->query("SELECT id FROM users WHERE role='admin' ORDER BY id ASC LIMIT 1");
$admin_id = 1;
if ($first_admin_query->num_rows > 0) {
    $admin_id = $first_admin_query->fetch_assoc()['id'];
}
$active_client = $_GET['client_id'] ?? 0;

// Fetch all clients that have messaged the clinic inbox
$clients_q = $conn->query("
    SELECT u.id, u.fullname, 
    (SELECT COUNT(*) FROM chat_messages cm WHERE cm.sender_id=u.id AND cm.receiver_id=$admin_id AND cm.is_read=0) as unread_count,
    MAX(m.id) as last_msg_id
    FROM users u 
    JOIN chat_messages m ON ((m.sender_id=u.id AND m.receiver_id=$admin_id) OR (m.sender_id=$admin_id AND m.receiver_id=u.id))
    WHERE IFNULL(u.role, '') NOT IN ('admin', 'doctor')
    GROUP BY u.id, u.fullname
    ORDER BY last_msg_id DESC
");

$clients = [];
if ($clients_q) {
    while ($r = $clients_q->fetch_assoc())
        $clients[] = $r;
}

// Mark active as read on load (API handles further marking)
if ($active_client > 0) {
    $conn->query("UPDATE chat_messages SET is_read=1 WHERE receiver_id=$admin_id AND sender_id=$active_client");
}

// â”€â”€ AJAX User Search Handler â”€â”€
if (isset($_GET['search_query'])) {
    header('Content-Type: application/json');
    $query = "%" . $_GET['search_query'] . "%";
    $stmt = $conn->prepare("SELECT id, fullname, email FROM users WHERE fullname LIKE ? AND role NOT IN ('admin','doctor') LIMIT 10");
    $stmt->bind_param("s", $query);
    $stmt->execute();
    $res = $stmt->get_result();
    $found = [];
    while ($r = $res->fetch_assoc())
        $found[] = $r;
    echo json_encode($found);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <script src="theme.js?v=<?= time() ?>"></script>
    <link rel="stylesheet" href="theme.css?v=<?= time() ?>">

    <title>Admin Live Chat | VetAdmin</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --secondary: #3b82f6;
            --dark: #1e40af;
            --light-bg: #E6EEF8;
            --sidebar-width: 260px;
        }

        body {
            background: var(--light-bg);
            font-family: 'Inter', sans-serif;
            color: #333;
            overflow-x: hidden;
        }

        .sidebar {
            background: #0f172a;
            width: var(--sidebar-width);
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            padding: 24px 16px;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            box-shadow: 5px 0 20px rgba(0, 0, 0, .2);
            overflow-y: auto;
        }

        .sidebar h4 {
            color: #ffffff;
            font-weight: 800;
            font-size: 22px;
            margin-bottom: 30px;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sidebar a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            border-radius: 8px;
            color: #94a3b8;
            text-decoration: none;
            margin-bottom: 4px;
            font-weight: 600;
            font-size: 15px;
            transition: .2s;
        }

        .sidebar a i {
            width: 25px;
            font-size: 16px;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background: #2563eb;
            color: #ffffff;
        }

        .sidebar .logout-link {
            position: absolute;
            bottom: 30px;
            width: calc(100% - 40px);
            color: #ff6b6b;
        }

        .main-content {
            margin-left: 260px !important;
            width: calc(100% - 260px) !important;
            max-width: calc(100% - 260px) !important;
            padding: 24px 28px !important;
            height: 100vh;
            display: flex;
            flex-direction: column;
            box-sizing: border-box !important;
        }

        @media (max-width: 1200px) {
            .main-content {
                padding: 20px 20px !important;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
                padding: 16px !important;
            }
            .sidebar {
                position: relative !important;
                width: 100% !important;
                height: auto !important;
            }
        }

        /* Chat UI Container */
        .chat-container {
            display: flex;
            flex: 1;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            margin-top: 20px;
        }

        .chat-list {
            width: 300px;
            border-right: 1px solid #E2E8F0;
            background: #F7FAFC;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
        }

        .chat-box {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: white;
            position: relative;
        }

        .client-item {
            padding: 20px;
            border-bottom: 1px solid #E2E8F0;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: 0.2s;
            text-decoration: none;
            color: inherit;
        }

        .client-item:hover,
        .client-item.active {
            background: #EBF8FF;
        }

        .client-item.active {
            border-left: 4px solid var(--secondary);
        }

        .client-name {
            font-weight: 600;
            color: var(--primary);
        }

        .chat-header {
            padding: 20px;
            border-bottom: 1px solid #E2E8F0;
            background: white;
            font-weight: bold;
            font-size: 18px;
            color: var(--primary);
        }

        .chat-history {
            flex: 1;
            padding: 25px;
            overflow-y: auto;
            background: #fafcff;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .chat-input-area {
            padding: 20px;
            border-top: 1px solid #E2E8F0;
            background: white;
            display: flex;
            gap: 15px;
        }

        .chat-bubble {
            max-width: 60%;
            padding: 12px 18px;
            border-radius: 20px;
            font-size: 15px;
            position: relative;
            line-height: 1.5;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .chat-bubble.admin {
            background: var(--secondary);
            color: white;
            align-self: flex-end;
            border-bottom-right-radius: 4px;
        }

        .chat-bubble.client {
            background: #E2E8F0;
            color: #2D3748;
            align-self: flex-start;
            border-bottom-left-radius: 4px;
        }

        .chat-time {
            display: block;
            font-size: 11px;
            margin-top: 5px;
            text-align: right;
            opacity: 0.8;
        }

        .empty-chat {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #A0AEC0;
        }

        .sidebar::-webkit-scrollbar {
            width: 5px;
        }

        .sidebar::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 10px;
        }

        .sidebar::-webkit-scrollbar-track {
            background: transparent;
        }
    
        /* ====== WHITE SIDEBAR THEME ====== */
        .sidebar {
            background: #ffffff !important;
            width: var(--sidebar-width, 260px) !important;
            height: 100vh !important;
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            padding: 28px 20px 24px !important;
            z-index: 1000 !important;
            display: flex !important;
            flex-direction: column !important;
            border-right: 1px solid #eef2f6 !important;
            box-shadow: none !important;
            overflow-y: auto !important;
        }

        .sidebar-brand {
            display: flex !important;
            align-items: center !important;
            gap: 12px !important;
            margin-bottom: 24px !important;
            padding: 0 4px !important;
            text-decoration: none !important;
        }

        .sidebar-brand-icon {
            width: 44px !important;
            height: 44px !important;
            min-width: 44px !important;
            border-radius: 13px !important;
            background: #2563eb !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            color: #ffffff !important;
            font-size: 20px !important;
            flex-shrink: 0 !important;
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.28) !important;
        }

        .sidebar-brand span {
            font-weight: 800 !important;
            color: #182032 !important;
            font-size: 22px !important;
            letter-spacing: -0.4px !important;
        }

        .sidebar-user {
            margin-bottom: 24px !important;
            padding: 15px 18px !important;
            border-radius: 14px !important;
            background: #f8fafc !important;
            border: 1px solid #eef2f6 !important;
        }

        .sidebar-user .welcome {
            font-size: 13px !important;
            color: #64748b !important;
            font-weight: 500 !important;
            line-height: 1.3 !important;
        }

        .sidebar-user .uname {
            font-weight: 700 !important;
            color: #0f172a !important;
            font-size: 15.5px !important;
            margin-top: 3px !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
        }

        .sidebar a {
            display: flex !important;
            align-items: center !important;
            gap: 14px !important;
            padding: 12px 16px !important;
            border-radius: 12px !important;
            color: #334155 !important;
            text-decoration: none !important;
            margin-bottom: 4px !important;
            font-weight: 600 !important;
            font-size: 14.5px !important;
            transition: all 0.2s ease !important;
            letter-spacing: -0.1px !important;
        }

        .sidebar a i {
            width: 22px !important;
            text-align: center !important;
            font-size: 16px !important;
            color: #64748b !important;
            transition: color 0.2s !important;
            flex-shrink: 0 !important;
        }

        .sidebar a:hover {
            background: #f8fafc !important;
            color: #0f172a !important;
        }

        .sidebar a:hover i {
            color: #2563eb !important;
        }

        .sidebar a.active {
            background: #2563eb !important;
            color: #ffffff !important;
            font-weight: 700 !important;
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.28) !important;
        }

        .sidebar a.active i {
            color: #ffffff !important;
        }

        .sidebar .sidebar-logout {
            margin-top: auto !important;
            padding-top: 20px !important;
            border-top: 1px solid #f1f5f9 !important;
        }

        .sidebar .sidebar-logout a {
            color: #dc2626 !important;
            font-weight: 600 !important;
            margin-bottom: 0 !important;
        }

        .sidebar .sidebar-logout a i {
            color: #dc2626 !important;
        }

        .sidebar .sidebar-logout a:hover {
            background: #fef2f2 !important;
            color: #b91c1c !important;
        }

        .sidebar::-webkit-scrollbar { width: 5px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.06); border-radius: 10px; }
        .sidebar::-webkit-scrollbar-track { background: transparent; }
</style>
    <link rel="stylesheet" href="assets/pragmavet.css">
    <link href="assets/global-style.css" rel="stylesheet">
</head>

<body>

    <?php include("includes/admin_sidebar.php"); ?>

    <div class="main-content">
        <div>
            <h2 class="fw-bold m-0 text-dark">Live Client Communications</h2>
            <p class="text-muted">Real-time direct messaging hub</p>
        </div>

        <div class="chat-container">
            <!-- Sidebar array of clients -->
            <div class="chat-list">
                <div class="p-3 border-bottom bg-white">
                    <h6 class="fw-bold mb-3 text-dark">Conversations</h6>
                    <div class="position-relative">
                        <input type="text" id="userSearchInput"
                            class="form-control form-control-sm rounded-pill ps-4 border-0 bg-light"
                            placeholder="Search " onkeyup="performUserSearch()">
                        <i class="fa fa-search position-absolute text-muted"
                            style="left:12px; top:50%; transform:translateY(-50%); font-size:11px;"></i>
                    </div>
                </div>

                <!-- Global Search Results Placeholder -->
                <div id="userSearchResults" class="border-bottom"
                    style="display:none; background:#f8fafc; max-height:300px; overflow-y:auto;"></div>
                <?php if (empty($clients)): ?>
                    <div class="p-4 text-muted text-center small"><i class="fa fa-inbox fa-3x mb-3 opacity-25"></i><br>No
                        active conversations yet.</div>
                <?php else: ?>
                    <?php foreach ($clients as $c): ?>
                        <a href="admin_messages.php?client_id=<?php echo $c['id']; ?>"
                            class="client-item <?php echo $active_client == $c['id'] ? 'active' : ''; ?>">
                            <div class="client-name"><i class="fa fa-user-circle me-2 text-secondary"></i>
                                <?php echo htmlspecialchars($c['fullname']); ?></div>
                            <?php if ($c['unread_count'] > 0): ?>
                                <span class="badge bg-danger rounded-pill"><?php echo $c['unread_count']; ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Chat Box Content -->
            <?php if ($active_client > 0):
                $c_name = $conn->query("SELECT fullname FROM users WHERE id=$active_client")->fetch_assoc()['fullname'];
                ?>
                <div class="chat-box">
                    <div class="chat-header">
                        Chatting with: <span class="text-dark"><?php echo htmlspecialchars($c_name); ?></span>
                    </div>
                    <div class="chat-history" id="chatHistory">
                        <!-- Loaded via JS -->
                    </div>
                    <div class="chat-input-area flex-column pb-3">
                        <div class="d-flex gap-2 w-100 align-items-center">
                            <input type="file" id="adminAttachment" style="display:none;"
                                onchange="updateAdminAttachmentLabel()">
                            <button class="btn btn-light rounded-pill px-3 border shadow-sm"
                                onclick="document.getElementById('adminAttachment').click()"><i
                                    class="fa fa-paperclip text-secondary"></i></button>
                            <input type="text" id="adminInput" class="form-control rounded-pill px-4"
                                placeholder="Type a message..." onkeypress="if(event.key==='Enter') sendAdminMessage()">
                            <button class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm"
                                onclick="sendAdminMessage()"><i class="fa fa-paper-plane me-2"></i>Send</button>
                        </div>
                        <div id="adminAttachmentLabel"
                            style="display:none; font-size:12px; color:var(--secondary); text-align:right; margin-top:5px; padding-right:120px;">
                        </div>
                    </div>
                </div>

                <!-- Live JS Logic for Admin -->
                <script>
                    const activeClient = <?php echo $active_client; ?>;

                    let renderedAdminMsgIds = new Set();
                    let isFirstLoad = true;

                    function playNotificationSound() {
                        try {
                            const AudioContext = window.AudioContext || window.webkitAudioContext;
                            if (!AudioContext) return;
                            const ctx = new AudioContext();
                            const osc = ctx.createOscillator();
                            const gainNode = ctx.createGain();

                            osc.connect(gainNode);
                            gainNode.connect(ctx.destination);

                            osc.type = 'sine';
                            osc.frequency.setValueAtTime(600, ctx.currentTime);
                            osc.frequency.exponentialRampToValueAtTime(1200, ctx.currentTime + 0.1);

                            gainNode.gain.setValueAtTime(0.1, ctx.currentTime);
                            gainNode.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.2);

                            osc.start(ctx.currentTime);
                            osc.stop(ctx.currentTime + 0.2);
                        } catch (err) { console.warn("Audio play blocked: ", err); }
                    }

                    function showChatToast(msgObj) {
                        let container = document.getElementById('chatToastContainer');
                        if (!container) {
                            container = document.createElement('div');
                            container.id = 'chatToastContainer';
                            container.style.cssText = 'position:fixed; bottom:30px; right:30px; z-index:99999; pointer-events:none; display:flex; flex-direction:column; gap:10px;';
                            document.body.appendChild(container);
                        }

                        let toast = document.createElement('div');
                        toast.className = 'shadow-lg rounded text-white bg-dark p-3';
                        toast.style.cssText = 'min-width:250px; transform: translateX(120%); transition: all 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55); pointer-events:auto;';
                        toast.innerHTML = `
                <div class="d-flex align-items-center">
                    <i class="fa fa-envelope text-info me-2"></i>
                    <strong class="me-auto">New Client Message</strong>
                </div>
                <div class="mt-2 small text-white-50 text-truncate" style="max-width:260px;">${msgObj.message}</div>
            `;
                        container.appendChild(toast);
                        setTimeout(() => { toast.style.transform = 'translateX(0)'; }, 50);
                        setTimeout(() => {
                            toast.style.transform = 'translateX(120%)';
                            setTimeout(() => toast.remove(), 400);
                        }, 4500);
                    }

                    function loadHistory() {
                        fetch('api_chat.php?action=fetch&client_id=' + activeClient)
                            .then(r => r.json())
                            .then(data => {
                                const hist = document.getElementById('chatHistory');

                                let isAtBottom = (hist.scrollHeight - hist.scrollTop <= hist.clientHeight + 50);
                                let hasNew = false;
                                let optimistics = hist.querySelectorAll('.optimistic-msg');

                                if (data.length === 0 && renderedAdminMsgIds.size === 0) {
                                    if (!hist.querySelector('#emptyAdminMsg')) {
                                        hist.innerHTML = '<div class="text-center text-muted my-5" id="emptyAdminMsg">No messages. Begin the conversation!</div>';
                                    }
                                }

                                data.forEach(msg => {
                                    if (!renderedAdminMsgIds.has(msg.id)) {
                                        renderedAdminMsgIds.add(msg.id);
                                        hasNew = true;

                                        let is_me = msg.is_me;
                                        let wrapper = document.createElement('div');
                                        wrapper.className = 'd-flex w-100 mb-3 align-items-end ' + (is_me ? 'justify-content-end' : '');
                                        let avatar = '<img src="https://ui-avatars.com/api/?name=' + encodeURIComponent(msg.sender_name) + '&background=' + (is_me ? '2B6CB0' : 'E2E8F0') + '&color=' + (is_me ? 'fff' : '2D3748') + '&rounded=true&size=36" class="rounded-circle ' + (is_me ? 'ms-2' : 'me-2') + '" style="width:36px; height:36px; flex-shrink:0;">';

                                        let attachmentHTML = '';
                                        if (msg.attachment_url) {
                                            if (msg.attachment_type === 'image') {
                                                attachmentHTML = `<img src="${msg.attachment_url}" style="max-width:100%; border-radius:8px; margin-bottom:5px; cursor:pointer; display:block;" onclick="window.open('${msg.attachment_url}')">`;
                                            } else if (msg.attachment_type === 'video') {
                                                attachmentHTML = `<video src="${msg.attachment_url}" controls style="max-width:100%; border-radius:8px; margin-bottom:5px; display:block;"></video>`;
                                            } else {
                                                attachmentHTML = `<a href="${msg.attachment_url}" download style="display:block; padding:8px; background:rgba(0,0,0,0.1); border-radius:6px; color:inherit; text-decoration:none; margin-bottom:5px;"><i class="fa fa-file"></i> ${msg.attachment_name}</a>`;
                                            }
                                        }

                                        let bubble = '';
                                        if (is_me) {
                                            bubble = '<div class="chat-bubble flex-grow-0 admin m-0">' + attachmentHTML + msg.message + '<span class="chat-time d-flex align-items-center justify-content-end w-100">' + msg.time + ' <span class="ms-1 text-white-50" style="font-size:9px;">| Sent <i class="fa fa-check-double text-white"></i></span></span></div>';
                                        } else {
                                            bubble = '<div class="chat-bubble flex-grow-0 client m-0">' + attachmentHTML + msg.message + '<span class="chat-time">' + msg.time + '</span></div>';
                                            if (!isFirstLoad) {
                                                playNotificationSound();
                                                showChatToast(msg);
                                            }
                                        }

                                        if (is_me) wrapper.innerHTML = bubble + avatar;
                                        else wrapper.innerHTML = avatar + bubble;
                                        hist.appendChild(wrapper);
                                    }
                                });

                                isFirstLoad = false;

                                if (hasNew) {
                                    optimistics.forEach(e => e.remove());
                                    let emptyMsg = hist.querySelector('#emptyAdminMsg');
                                    if (emptyMsg) emptyMsg.remove();

                                    if (isAtBottom) hist.scrollTop = hist.scrollHeight;
                                }
                            });
                    }

                    function updateAdminAttachmentLabel() {
                        const input = document.getElementById('adminAttachment');
                        const label = document.getElementById('adminAttachmentLabel');
                        if (input.files && input.files[0]) {
                            label.style.display = 'block';
                            label.innerText = '📎 Attached: ' + input.files[0].name;
                        } else {
                            label.style.display = 'none';
                            label.innerText = '';
                        }
                    }

                    function sendAdminMessage() {
                        const inputField = document.getElementById('adminInput');
                        const fileInput = document.getElementById('adminAttachment');
                        const val = inputField.value.trim();
                        const hasFile = fileInput.files && fileInput.files.length > 0;

                        if (val === '' && !hasFile) return;

                        const hist = document.getElementById('chatHistory');
                        let wrapper = document.createElement('div');
                        wrapper.className = 'd-flex w-100 mb-3 align-items-end justify-content-end optimistic-msg';
                        let avatar = '<img src="https://ui-avatars.com/api/?name=Clinic+Admin&background=2B6CB0&color=fff&rounded=true&size=36" class="rounded-circle ms-2" style="width:36px; height:36px; flex-shrink:0;">';
                        let optHTML = hasFile ? '<div style="font-size:11px; opacity:0.8; margin-bottom:5px;">📎 Uploading...</div>' : '';
                        let bubble = '<div class="chat-bubble flex-grow-0 admin m-0">' + optHTML + val + '<span class="chat-time">Just now</span></div>';
                        wrapper.innerHTML = bubble + avatar;
                        hist.appendChild(wrapper);
                        hist.scrollTop = hist.scrollHeight;

                        let formData = new FormData();
                        formData.append('action', 'send');
                        formData.append('client_id', activeClient);
                        formData.append('message', val);
                        if (hasFile) formData.append('attachment', fileInput.files[0]);

                        inputField.value = '';
                        fileInput.value = '';
                        updateAdminAttachmentLabel();

                        fetch('api_chat.php', {
                            method: 'POST',
                            body: formData
                        }).then(() => {
                            loadHistory();
                        });
                    }

                    setInterval(loadHistory, 3000);
                    loadHistory();
                </script>
            <?php else: ?>
                <div class="empty-chat">
                    <i class="fa fa-comments fa-5x mb-4 text-secondary opacity-25"></i>
                    <h4 class="text-secondary fw-bold">Select a Conversation</h4>
                    <p class="text-muted">Choose a client from the left pane to view history and reply.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>


    <script>
        let searchTimeout = null;
        function performUserSearch() {
            const q = document.getElementById('userSearchInput').value.trim().toLowerCase();
            const results = document.getElementById('userSearchResults');
            const existingItems = document.querySelectorAll('.chat-list .client-item:not(#userSearchResults .client-item)');
            
            // 1. Instant Local Filter of existing sidebar conversations
            existingItems.forEach(item => {
                const name = item.querySelector('.client-name').innerText.toLowerCase();
                item.style.display = name.includes(q) ? 'flex' : 'none';
            });

            // 2. Global Server Search for new clients
            if(q.length === 0) {
                results.innerHTML = '';
                results.style.display = 'none';
                return;
            }
            
            results.style.display = 'block';
            if(q.length < 2) {
                results.innerHTML = '<div class="p-3 text-center text-muted small">Type 2+ for global search...</div>';
                return;
            }
            
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                results.innerHTML = '<div class="p-3 text-center"><i class="fa fa-spinner fa-spin text-primary"></i></div>';
                
                fetch('admin_messages.php?search_query=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(data => {
                    // Filter out clients already in the sidebar to avoid duplicates
                    const existingIds = new Set();
                    existingItems.forEach(item => {
                        const href = item.getAttribute('href');
                        const idMatch = href.match(/client_id=(\d+)/);
                        if(idMatch) existingIds.add(idMatch[1]);
                    });

                    const filteredData = data.filter(u => !existingIds.has(String(u.id)));

                    if(filteredData.length === 0) {
                        results.innerHTML = '<div class="p-3 text-center text-muted small">No new users found globally.</div>';
                        return;
                    }
                    
                    results.innerHTML = '<div class="p-2 bg-light text-uppercase fw-bold text-muted" style="font-size:10px; border-top:1px solid #eee;">Found in Global Directory</div>';
                    filteredData.forEach(u => {
                        const item = document.createElement('a');
                        item.href = 'admin_messages.php?client_id=' + u.id;
                        item.className = 'client-item';
                        item.style.padding = '12px 20px';
                        item.innerHTML = `
                            <div class="client-name" style="font-size:13px;"><i class="fa fa-user-circle me-2 opacity-50"></i>${u.fullname}</div>
                        `;
                        results.appendChild(item);
                    });
                });
            }, 400);
        }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="mousetrail.js"></script>
</body>

</html>