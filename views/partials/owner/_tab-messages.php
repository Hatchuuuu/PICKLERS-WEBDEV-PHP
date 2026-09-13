      <div class="owner-topbar">
        <div class="owner-topbar-title-wrap">
          <h1>Direct Messages Support Center</h1>
          <p>Direct messaging with players and support.</p>
        </div>
      </div>

      <?php if (empty($conversations)): ?>
        <div style="text-align:center; padding:60px 20px; background:rgba(255,255,255,0.03); border:1px dashed rgba(255,255,255,0.1); border-radius:16px;">
          <div style="font-size:36px; margin-bottom:10px;">💬</div>
          <p style="font-size:15px; font-weight:700; color:var(--pk-text-primary); margin:0 0 4px;">No conversations yet</p>
          <p style="font-size:12.5px; color:var(--pk-text-muted); margin:0;">When a player messages your facility, it will show up here.</p>
        </div>
      <?php else: ?>
      <div class="messages-layout">
        <!-- Inbox Sidebar -->
        <div class="inbox-list-col">
          <div style="padding:16px 20px; border-bottom:1px solid rgba(255,255,255,0.06); font-size:13px; font-weight:800; color:#FFFFFF;">
            Player Conversations (<?php echo count($conversations); ?>)
          </div>
          <?php foreach ($conversations as $idx => $conv): ?>
            <div class="inbox-item <?php echo $idx === 0 ? 'active' : ''; ?>" id="inbox_item_<?php echo htmlspecialchars($conv['user_id']); ?>" onclick="selectConversation('<?php echo htmlspecialchars(addslashes($conv['user_id'])); ?>', '<?php echo htmlspecialchars(addslashes($conv['user_name'])); ?>', '<?php echo htmlspecialchars(addslashes($conv['user_avatar'])); ?>', this)">
              <img src="<?php echo htmlspecialchars($conv['user_avatar']); ?>" alt="Avatar" style="width:38px; height:38px; border-radius:50%; object-fit:cover;">
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
            <img id="chatActiveAvatar" src="<?php echo htmlspecialchars($activeConv['user_avatar']); ?>" alt="Avatar" style="width:36px; height:36px; border-radius:50%; object-fit:cover;">
            <div>
              <div style="font-size:14px; font-weight:800; color:#FFFFFF;" id="chatActiveUser"><?php echo htmlspecialchars($activeConv['user_name']); ?></div>
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
      <script>
        // Real conversations only exist when $conversations isn't empty (see
        // the guard above) — this tells owner.js's selectConversation()/
        // sendChatMessage() which real player to load/reply to on first
        // paint, matching the thread the server already rendered above.
        window.__initialChatPartnerId = <?php echo json_encode($activeConv['user_id']); ?>;
      </script>
      <?php endif; ?>
