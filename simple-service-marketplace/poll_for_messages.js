
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
                    <div>
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

                container.prepend(el);

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

pollChat();