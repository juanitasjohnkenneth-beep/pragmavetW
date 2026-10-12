<!-- INJECT THIS IN HEADER (Right side of page header area) -->
<style>
/* Notification Bell Styles */
.notif-bell { position: relative; cursor: pointer; color: #64748B; font-size: 22px; margin-right: 20px; transition: 0.2s; }
.notif-bell:hover { color: #2563eb; }
.notif-badge { position: absolute; top: -5px; right: -8px; background: #E53E3E; color: white; font-size: 10px; font-weight: bold; border-radius: 50%; padding: 2px 5px; box-shadow: 0 0 0 2px white; }
.notif-dropdown { position: absolute; right: 0; top: 40px; width: 320px; background: white; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); border: 1px solid #E2E8F0; z-index: 1000; display: none; opacity: 0; transform: translateY(-10px); transition: 0.2s; }
.notif-dropdown.show { display: block; opacity: 1; transform: translateY(0); }
.notif-header { padding: 15px; border-bottom: 1px solid #E2E8F0; font-weight: 700; color: #2563eb; display: flex; justify-content: space-between; align-items: center; }
.notif-body { max-height: 300px; overflow-y: auto; }
.notif-item { padding: 12px 15px; border-bottom: 1px solid #F7FAFC; font-size: 13px; color: #4A5568; transition: 0.2s; }
.notif-item:hover { background: #F7FAFC; }
.notif-item.unread { background: #EBF8FF; }

/* Floating Chat Styles */
.chat-fab { position: fixed; bottom: 30px; right: 30px; width: 60px; height: 60px; background: #3b82f6; border-radius: 50%; display: flex; justify-content: center; align-items: center; color: white; font-size: 24px; box-shadow: 0 5px 15px rgba(43,108,176,0.3); cursor: pointer; z-index: 9999; transition: 0.3s; }
.chat-fab:hover { transform: scale(1.05); }
.chat-badge { position: absolute; top: 0px; right: 0px; background: #E53E3E; font-size: 12px; padding: 3px 6px; border-radius: 20px; font-weight: bold; box-shadow: 0 0 0 2px white; }
.chat-window { position: fixed; bottom: 100px; right: 30px; width: 350px; height: 450px; background: white; border-radius: 15px; box-shadow: 0 10px 40px rgba(0,0,0,0.15); display: flex; flex-direction: column; overflow: hidden; z-index: 9998; transition: 0.3s; transform-origin: bottom right; transform: scale(0); opacity: 0; }
.chat-window.open { transform: scale(1); opacity: 1; }
.chat-header { background: #3b82f6; color: white; padding: 15px; font-weight: 600; display: flex; justify-content: space-between; align-items: center; }
.chat-close { cursor: pointer; font-size: 18px; }
.chat-body { flex: 1; background: #F7FAFC; padding: 15px; overflow-y: auto; display: flex; flex-direction: column; gap: 10px; scroll-behavior: smooth; }
.chat-bubble { max-width: 80%; padding: 10px 14px; border-radius: 18px; font-size: 14px; position: relative; line-height: 1.4; word-wrap: break-word; }
.chat-bubble.mine { background: #3b82f6; color: white; align-self: flex-end; border-bottom-right-radius: 4px; }
.chat-bubble.admin { background: #E6EEF8; color: #2D3748; align-self: flex-start; border-bottom-left-radius: 4px; }
.chat-time { font-size: 10px; margin-top: 4px; opacity: 0.7; display: block; text-align: right; }
.chat-footer { padding: 15px; background: white; border-top: 1px solid #E2E8F0; display: flex; gap: 10px; }
.chat-input { flex: 1; border: 1px solid #E2E8F0; border-radius: 20px; padding: 8px 15px; font-size: 14px; outline: none; }
.chat-input:focus { border-color: #3b82f6; }
.chat-send { background: #3b82f6; color: white; border: none; width: 36px; height: 36px; border-radius: 50%; display: flex; justify-content: center; align-items: center; cursor: pointer; transition: 0.2s; }
.chat-send:active { transform: scale(0.9); }
</style>

<!-- Floating Chat Button -->
<div class="chat-fab" id="chatFab" onclick="toggleChat()">
    <i class="fa fa-comment-dots"></i>
    <span class="chat-badge" id="chatBadge" style="display:none;">0</span>
</div>

<!-- Chat Window -->
<div class="chat-window" id="chatWindow">
    <div class="chat-header">
        <div><i class="fa fa-hospital-user me-2"></i> Vet Clinic Connect</div>
        <i class="fa fa-times chat-close" onclick="toggleChat()"></i>
    </div>
    <div class="chat-body" id="chatBody">
        <div class="text-center text-muted small my-3">Send a message directly to the admin staff for any inquiries.</div>
        <!-- Messages loaded here -->
    </div>
    <div class="chat-footer flex-column" style="padding-bottom:10px;">
        <div class="d-flex gap-2 w-100 align-items-center">
            <input type="file" id="chatAttachment" style="display:none;" onchange="updateChatAttachmentLabel()">
            <button class="chat-send" style="background:#E2E8F0; color:#2D3748; flex-shrink:0;" onclick="document.getElementById('chatAttachment').click()"><i class="fa fa-paperclip"></i></button>
            <input type="text" id="chatInput" class="chat-input" style="flex:1;" placeholder="Type a message..." onkeypress="handleEnter(event)">
            <button class="chat-send" style="flex-shrink:0;" onclick="sendMessage()"><i class="fa fa-paper-plane"></i></button>
        </div>
        <div id="chatAttachmentLabel" style="display:none; font-size:11px; text-align:right; color:#3b82f6; width:100%; margin-top:5px; padding-right:10px;"></div>
    </div>
</div>

<script>
// Chat Logic
let chatOpen = false;
let checkInterval = null;
let renderedMsgIds = new Set();
let isFirstLoad = true;

function playNotificationSound() {
    try {
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        if(!AudioContext) return;
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
    } catch(err) { console.warn("Audio play blocked: ", err); }
}

function showChatToast(msgObj) {
    let container = document.getElementById('chatToastContainer');
    if(!container) {
        container = document.createElement('div');
        container.id = 'chatToastContainer';
        container.style.cssText = 'position:fixed; bottom:90px; right:30px; z-index:99999; pointer-events:none; display:flex; flex-direction:column; gap:10px;';
        document.body.appendChild(container);
    }
    
    let toast = document.createElement('div');
    toast.className = 'shadow-lg rounded text-white bg-dark p-3';
    toast.style.cssText = 'min-width:250px; transform: translateX(120%); transition: all 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55); pointer-events:auto; cursor:pointer;';
    toast.innerHTML = `
        <div class="d-flex align-items-center">
            <i class="fa fa-envelope text-info me-2"></i>
            <strong class="me-auto">New Message from ${msgObj.sender_name}</strong>
        </div>
        <div class="mt-2 small text-white-50 text-truncate" style="max-width:260px;">${msgObj.message}</div>
    `;
    
    toast.onclick = () => { if(!chatOpen) toggleChat(); };
    container.appendChild(toast);
    
    // Animate in
    setTimeout(() => { toast.style.transform = 'translateX(0)'; }, 50);
    
    // Animate out
    setTimeout(() => {
        toast.style.transform = 'translateX(120%)';
        setTimeout(() => toast.remove(), 400);
    }, 4500);
}

function toggleChat() {
    const win = document.getElementById('chatWindow');
    chatOpen = !chatOpen;
    if(chatOpen) {
        win.classList.add('open');
        fetchMessages(true);
        startPolling();
        document.getElementById('chatBadge').style.display = 'none'; // hide pill
    } else {
        win.classList.remove('open');
        stopPolling();
    }
}

function fetchMessages(forceScroll = false) {
    fetch('api_chat.php?action=fetch')
        .then(res => res.json())
        .then(data => {
            const body = document.getElementById('chatBody');
            
            let isAtBottom = (body.scrollHeight - body.scrollTop <= body.clientHeight + 50);
            let hasNew = false;
            let optimistics = body.querySelectorAll('.optimistic-msg');
            
            if(data.length === 0 && renderedMsgIds.size === 0) {
                // Check if empty msg already exists
                if(!body.querySelector('#emptyMsgHint')) {
                    body.innerHTML = '<div class="text-center text-muted small my-3" id="emptyMsgHint">No messages yet. Say hi!</div>';
                }
            }
            data.forEach(msg => {
                if(!renderedMsgIds.has(msg.id)) {
                    renderedMsgIds.add(msg.id);
                    hasNew = true;
                    
                    let wrapper = document.createElement('div');
                    wrapper.className = 'd-flex w-100 mb-2 align-items-end ' + (msg.is_me ? 'justify-content-end' : '');
                    let avatarHTML = '<img src="https://ui-avatars.com/api/?name='+encodeURIComponent(msg.sender_name)+'&background='+(msg.is_me?'2B6CB0':'E2E8F0')+'&color='+(msg.is_me?'fff':'2D3748')+'&rounded=true&size=32" class="rounded-circle '+(msg.is_me?'ms-2':'me-2')+'" style="width:32px; height:32px; flex-shrink:0;">';
                    
                    let attachmentHTML = '';
                    if(msg.attachment_url) {
                        if(msg.attachment_type === 'image') {
                            attachmentHTML = `<img src="${msg.attachment_url}" style="max-width:100%; border-radius:8px; margin-bottom:5px; cursor:pointer; display:block;" onclick="window.open('${msg.attachment_url}')">`;
                        } else if(msg.attachment_type === 'video') {
                            attachmentHTML = `<video src="${msg.attachment_url}" controls style="max-width:100%; border-radius:8px; margin-bottom:5px; display:block;"></video>`;
                        } else {
                            attachmentHTML = `<a href="${msg.attachment_url}" download style="display:block; padding:8px; background:rgba(0,0,0,0.1); border-radius:6px; color:inherit; text-decoration:none; margin-bottom:5px;"><i class="fa fa-file"></i> ${msg.attachment_name}</a>`;
                        }
                    }
                    
                    let bubbleHTML = '';
                    if(msg.is_me) {
                        bubbleHTML = '<div class="chat-bubble flex-grow-0 mine m-0" style="max-width:85%;">' + attachmentHTML + msg.message + '<span class="chat-time d-flex align-items-center justify-content-end w-100">'+msg.time+' <span class="ms-1 text-white-50" style="font-size:9px;">| Sent <i class="fa fa-check-double text-white"></i></span></span></div>';
                    } else {
                        bubbleHTML = '<div class="chat-bubble flex-grow-0 admin m-0" style="max-width:85%;">' + attachmentHTML + msg.message + '<span class="chat-time">'+msg.time+'</span></div>';
                        
                        // Fire notification if it's not the initial load and it's from the admin
                        if(!isFirstLoad) {
                            if(!chatOpen) {
                                playNotificationSound();
                                showChatToast(msg);
                            } else {
                                // Just a very subtle pop if chat is already open but we aren't focused
                                playNotificationSound();
                            }
                        }
                    }
                    
                    if(msg.is_me) wrapper.innerHTML = bubbleHTML + avatarHTML;
                    else wrapper.innerHTML = avatarHTML + bubbleHTML;
                    
                    body.appendChild(wrapper);
                }
            });
            
            isFirstLoad = false;
            
            if(hasNew) {
                optimistics.forEach(el => el.remove());
                const emptyHint = body.querySelector('#emptyMsgHint');
                if(emptyHint) emptyHint.remove();
                
                if(isAtBottom || forceScroll) body.scrollTop = body.scrollHeight;
            }
        });
}

function updateChatAttachmentLabel() {
    const input = document.getElementById('chatAttachment');
    const label = document.getElementById('chatAttachmentLabel');
    if (input.files && input.files[0]) {
        label.style.display = 'block';
        label.innerText = '📎 ' + input.files[0].name;
    } else {
        label.style.display = 'none';
        label.innerText = '';
    }
}

function sendMessage() {
    const inputField = document.getElementById('chatInput');
    const fileInput = document.getElementById('chatAttachment');
    const val = inputField.value.trim();
    const hasFile = fileInput.files && fileInput.files.length > 0;
    
    if(val === '' && !hasFile) return;
    
    // Optimistic UI Append
    const body = document.getElementById('chatBody');
    let wrapper = document.createElement('div');
    wrapper.className = 'd-flex w-100 mb-2 align-items-end justify-content-end optimistic-msg';
    let avatarHTML = '<img src="https://ui-avatars.com/api/?name=<?php echo urlencode($_SESSION["user"] ?? "Me"); ?>&background=2B6CB0&color=fff&rounded=true&size=32" class="rounded-circle ms-2" style="width:32px; height:32px; flex-shrink:0;">';
    
    let optAttachmentHTML = '';
    if(hasFile) {
        optAttachmentHTML = '<div style="font-size:11px; opacity:0.8; margin-bottom:5px;">📎 Uploading attachment...</div>';
    }
    
    let bubbleHTML = '<div class="chat-bubble flex-grow-0 mine m-0" style="max-width:85%;">' + optAttachmentHTML + val + '<span class="chat-time">Just now</span></div>';
    wrapper.innerHTML = bubbleHTML + avatarHTML;
    body.appendChild(wrapper);
    body.scrollTop = body.scrollHeight;
    
    inputField.value = '';
    
    let formData = new FormData();
    formData.append('action', 'send');
    formData.append('message', val);
    if(hasFile) formData.append('attachment', fileInput.files[0]);
    
    fileInput.value = '';
    updateChatAttachmentLabel();

    fetch('api_chat.php', {
        method: 'POST',
        body: formData
    }).then(function (r) { return r.json().catch(function () { return {}; }); })
      .then(function (d) { if (d && d.status === 'error' && d.error) alert(d.error); fetchMessages(); });
}

function handleEnter(e) {
    if(e.key === 'Enter') sendMessage();
}

// Notifications Logic
let notifOpen = false;
function toggleNotifications() {
    const drp = document.getElementById('notifDropdown');
    notifOpen = !notifOpen;
    if(notifOpen) {
        drp.classList.add('show');
        loadNotifs();
    } else {
        drp.classList.remove('show');
    }
}

function loadNotifs() {
    fetch('api_notifications.php?action=fetch')
    .then(r=>r.json())
    .then(d=>{
        const list = document.getElementById('notifList');
        list.innerHTML = '';
        if(d.notifications.length === 0) list.innerHTML = '<div class="text-center text-muted small p-4">No recent notifications.</div>';
        
        d.notifications.forEach(n => {
            let cl = n.is_read == 0 ? 'unread' : '';
            const link = notifLink(n);
            list.innerHTML += `<div class="notif-item ${cl}" data-id="${parseInt(n.id)}" data-link="${link}" onclick="openNotif(this)" style="${link ? 'cursor:pointer;' : ''}">${n.message} <br><small class="text-muted">${n.created_at}</small></div>`;
        });
    });
}

// Where a notification should take the client (stored link first, otherwise inferred from its text)
function notifLink(n) {
    if (n.link) return n.link;
    const m = String(n.message || '').toLowerCase();
    if (m.includes('boarding')) return 'boarding.php';
    if (m.includes('reschedul') || m.includes('proposed a new schedule')) return 'appointments.php?open_resched=1';
    if (m.includes('vaccin') || m.includes('booster') || m.includes('prescription')) return 'pets.php';
    if (m.includes('reservation fee') || m.includes('invoice') || m.includes('payment') || m.includes('billing')) return 'billing.php';
    if (m.includes('appointment') || m.includes('follow-up') || m.includes('visit')) return 'appointments.php';
    return '';
}

function openNotif(el) {
    const id = el.getAttribute('data-id');
    const link = el.getAttribute('data-link');
    fetch('api_notifications.php?action=mark_one&id=' + encodeURIComponent(id))
        .catch(() => {})
        .finally(() => { if (link) window.location.href = link; else { loadNotifs(); checkUnreadGlobally(); } });
}

function markAllRead(e) {
    if(e) e.stopPropagation();
    fetch('api_notifications.php?action=mark_read').then(()=>{
        loadNotifs();
        checkUnreadGlobally();
    });
}

// Global Unread Badges Check
function checkUnreadGlobally() {
    // 1. Notifs
    fetch('api_notifications.php?action=fetch')
    .then(r => r.json())
    .then(d => {
        const bd = document.getElementById('notifBadge');
        if(bd) {
            if(d.unread_count > 0) {
                bd.style.display = 'inline-block';
                bd.innerText = d.unread_count;
            } else {
                bd.style.display = 'none';
            }
        }
    });

    // 2. Chat
    fetch('api_chat.php?action=unread_count')
    .then(r=>r.json())
    .then(d=>{
        const cb = document.getElementById('chatBadge');
        if(!chatOpen && d.unread > 0) {
            cb.style.display = 'inline-block';
            cb.innerText = d.unread;
        } else if (d.unread === 0 && cb) {
            cb.style.display = 'none';
        }
    });
}

function startPolling() { checkInterval = setInterval(fetchMessages, 3000); }
function stopPolling() { clearInterval(checkInterval); }

// Generic start routine
setInterval(checkUnreadGlobally, 3000);
document.addEventListener('DOMContentLoaded', checkUnreadGlobally);
</script>
