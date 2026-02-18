import { listItemCard } from './alpine/list-item-card.js';
import { focusBar } from './alpine/focus-bar.js';

// listItemCard registers itself in Alpine.store('listItemCards')[itemId] for focusBar sub-component access.
// Store is a single mutable object; cards add/remove themselves on init/destroy to avoid full copy on every mount.
document.addEventListener('livewire:init', () => {
    window.Alpine.data('listItemCard', listItemCard);
    window.Alpine.data('focusBar', focusBar);
});
