const newMessageClass = "sso-new-message";

const audio = new Audio(assetUrls.notificationSound);

async function pollChat() {

    try {

        const response =
            await fetch(
                `/wp-admin/admin-ajax.php?action=sso_chat_updates&order_id=${window.ssoOrderId}&after=${window.ssoLastMessageId}`
            );

        const messages =
            await response.json();

        if (messages.length) {

            const container =
                document.getElementById(
                    'sso-chat-messages'
                );

            messages.forEach(msg => {

                const el =
                    document.createElement(
                        'div'
                    );

                el.className =
                    'sso-message';

                el.dataset.id =
                    msg.id;

                el.innerHTML = `
                    <div class"${newMessageClass}">
                        <strong>
                            ${escapeHtml(msg.sender)}
                        </strong>
                    </div>

                    <div class="sso-date">
                        ${escapeHtml(msg.date)}
                    </div>

                    <div class="sso-message-body">
                        ${escapeHtml(msg.message)}
                    </div>
                `;

               el.className = newMessageClass;

                setTimeout(() => {
                    el.classList.remove(newMessageClass);
                }, 2500);

                container.prepend(el);

                console.log([msg.sender, message.senderName]);

                if(msg.sender != message.senderName) {
                    audio.play();
                }

                window.ssoLastMessageId =
                    Math.max(
                        window.ssoLastMessageId,
                        msg.id
                    );
            });

            container.scrollTop =
                container.scrollHeight;
        }

    } catch (e) {
        console.error(
            'Chat update failed',
            e
        );
    }

    setTimeout(
        pollChat,
        document.hidden
            ? 10000
            : 2000
    );
}

function escapeHtml(text) {
    const div =
        document.createElement(
            'div'
        );

    div.textContent = text;

    return div.innerHTML;
}

function addMessage(text) {
  const msg = document.createElement("div");
  msg.className = "message is-new";
  msg.textContent = text;

  document.querySelector("#chat").appendChild(msg);

  // Remove highlight state after animation
  setTimeout(() => {
    msg.classList.remove(newMessageClass);
  }, 2000);
}

pollChat();