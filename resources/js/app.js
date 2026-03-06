import { listItemCard } from './alpine/list-item-card.js';
import { assistantChatFlyout } from './alpine/assistant-chat-flyout.js';

// listItemCard registers itself in Alpine.store('listItemCards')[itemId] for focus-session escape handler etc.
document.addEventListener('livewire:init', () => {
    window.Alpine.data('listItemCard', listItemCard);
    window.Alpine.data('assistantChatFlyout', (wire, config) => assistantChatFlyout(wire, config));
});

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allow your team to quickly build robust real-time web applications.
 */

import './echo';
