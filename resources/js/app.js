import { listItemCard } from './alpine/list-item-card.js';

// listItemCard registers itself in Alpine.store('listItemCards')[itemId] for focus-session escape handler etc.
document.addEventListener('livewire:init', () => {
    window.Alpine.data('listItemCard', listItemCard);
});

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allow your team to quickly build robust real-time web applications.
 */

import './echo';
