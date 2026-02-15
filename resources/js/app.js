import { listItemCard } from './alpine/list-item-card.js';

document.addEventListener('livewire:init', () => {
    window.Alpine.data('listItemCard', listItemCard);
});
