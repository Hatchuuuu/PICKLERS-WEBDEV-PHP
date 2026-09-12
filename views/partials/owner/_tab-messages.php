      <div class="owner-topbar">
        <div class="owner-topbar-title-wrap">
          <h1>Direct Messages Support Center</h1>
          <p>Direct messaging with players and support.</p>
        </div>
      </div>

      <div class="messages-layout">
        <!-- Inbox Sidebar -->
        <div class="inbox-list-col">
          <div style="padding:16px 20px; border-bottom:1px solid rgba(255,255,255,0.06); font-size:13px; font-weight:800; color:#FFFFFF;">
            Player Conversations (<?php echo count($conversations); ?>)
          </div>
          <?php foreach ($conversations as $idx => $conv): ?>
            <div class="inbox-item <?php echo $idx === 0 ? 'active' : ''; ?>" onclick="selectConversation('<?php echo htmlspecialchars(addslashes($conv['user_name'])); ?>')">
              <img src="<?php echo $conv['user_avatar']; ?>" alt="Avatar" style="width:38px; height:38px; border-radius:50%; object-fit:cover;">
              <div style="flex:1; overflow:hidden;">
                <div style="font-size:14px; font-weight:700; color:#FFFFFF;"><?php echo htmlspecialchars($conv['user_name']); ?></div>
                <div style="font-size:12px; color:#94A3B8; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?php echo htmlspecialchars($conv['last_message']); ?></div>
              </div>
              <?php if (!empty($conv['unread'])): ?>
                <div class="inbox-unread-count"><?php echo $conv['unread']; ?></div>
              <?php else: ?>
                <span style="font-size:11px; color:var(--pk-text-muted, #94A3B8);"><?php echo $conv['time']; ?></span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>

        <!-- Chat Thread Column -->
        <div class="chat-thread-col">
          <?php $activeConv = $conversations[0]; ?>
          <div class="chat-header">
            <img src="<?php echo $activeConv['user_avatar']; ?>" alt="Avatar" style="width:36px; height:36px; border-radius:50%; object-fit:cover;">
            <div>
              <div style="font-size:14px; font-weight:800; color:#FFFFFF;" id="chatActiveUser"><?php echo htmlspecialchars($activeConv['user_name']); ?></div>
              <div style="font-size:11px; color:#00D98B;">â— Online &bull; Booking Court 3 (Today)</div>
            </div>
          </div>

          <div class="chat-messages-body" id="chatThreadContainer">
            <?php foreach ($activeConv['messages'] as $msg): ?>
              <div class="chat-bubble <?php echo $msg['sender'] === 'owner' ? 'from-owner' : 'from-user'; ?>">
                <?php echo htmlspecialchars($msg['text']); ?>
                <div style="font-size:10px; opacity:0.7; margin-top:4px; text-align:right;"><?php echo $msg['time']; ?></div>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="chat-input-bar">
            <input type="text" id="chatMessageInput" class="chat-input-field" placeholder="Reply to player..." onkeydown="if(event.key==='Enter') sendChatMessage()">
            <button type="button" class="btn-chat-send" onclick="sendChatMessage()">
              <span>Send</span>
              <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
            </button>
          </div>
        </div>
      </div>
