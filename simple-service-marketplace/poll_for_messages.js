let lastMessageId =
    window.lastMessageId || 0;

let pollTimeout;

async function pollChat() {
    try {
        const response = await fetch(
            `/wp-json/simple-service-marketplace/v1/chat-updates?order_id=${ORDER_ID}&after=${lastMessageId}`,
            {
                credentials: 'same-origin'
            }
        );

        const messages = await response.json();

        if (messages.length) {
            messages.forEach(message => {

                // TODO: implement appendMessage
                appendMessage(message);

                lastMessageId = Math.max(
                    lastMessageId,
                    message.id
                );
            });
        }

    } catch (error) {
        console.error(
            'Chat polling failed:',
            error
        );
    }

    const delay = document.hidden
        ? 10000
        : 2000;

    pollTimeout = setTimeout(
        pollChat,
        delay
    );
}

document.addEventListener(
    'visibilitychange',
    () => {
        clearTimeout(pollTimeout);
        pollChat();
    }
);

// start polling
pollChat();