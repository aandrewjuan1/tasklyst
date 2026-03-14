/**
 * Alpine.js component for the kanban board.
 * Handles drag-and-drop and status changes via dropdown (card teleports to target column).
 *
 * @param {Object} config - { selectedDate, columns, statusMeta }
 * @returns {Object} Alpine component object.
 */
export function kanbanBoard(config) {
    return {
        draggedTaskId: null,
        sourceColumn: null,
        cardElement: null,
        pendingIds: new Set(),
        dragOverColumn: null,
        selectedDate: config.selectedDate ?? null,
        columns: config.columns ?? {},
        statusMeta: config.statusMeta ?? {},
        moveErrorToast: config.moveErrorToast ?? 'Failed to move task. Please try again.',

        onDragStart(event) {
            const card = event.target.closest('[data-kanban-card]');
            if (!card) return;
            const taskId = card.getAttribute('data-task-id');
            if (!taskId || this.pendingIds.has(Number(taskId))) return;
            this.draggedTaskId = Number(taskId);
            this.sourceColumn = card.closest('[data-kanban-column]');
            this.cardElement = card;
            event.dataTransfer.setData('text/plain', taskId);
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('application/json', JSON.stringify({ taskId }));
            if (event.dataTransfer.setDragImage) {
                event.dataTransfer.setDragImage(card, 0, 0);
            }
            card.setAttribute('aria-grabbed', 'true');
        },

        onDragEnd(event) {
            const target = event?.target || this.cardElement;
            if (target) {
                const card = target.closest('[data-kanban-card]');
                if (card) card.setAttribute('aria-grabbed', 'false');
            }
            this.draggedTaskId = null;
            this.sourceColumn = null;
            this.cardElement = null;
            this.dragOverColumn = null;
        },

        async onDrop(targetStatus, event) {
            event.preventDefault();
            this.dragOverColumn = null;
            const taskIdStr = event.dataTransfer.getData('text/plain');
            if (!taskIdStr) return;
            const taskId = Number(taskIdStr);
            const targetColumn = event.currentTarget.closest('[data-kanban-column]');
            if (!targetColumn || !this.sourceColumn || this.sourceColumn === targetColumn) return;
            if (this.pendingIds.has(taskId)) return;
            this.pendingIds.add(taskId);
            const sourceStatus = this.sourceColumn.getAttribute('data-status');
            const targetStatusValue = targetColumn.getAttribute('data-status') || targetStatus;
            const previousCounts = {
                source: sourceStatus && this.columns[sourceStatus] ? this.columns[sourceStatus].count : null,
                target: targetStatusValue && this.columns[targetStatusValue] ? this.columns[targetStatusValue].count : null,
            };
            const sourceCards = this.sourceColumn.querySelector('[data-kanban-column-cards]');
            const targetCards = targetColumn.querySelector('[data-kanban-column-cards]');
            const snapshot = { sourceColumn: this.sourceColumn, sourceCards, cardElement: this.cardElement };
            try {
                targetCards.appendChild(this.cardElement);
                if (sourceStatus) {
                    if (!this.columns[sourceStatus]) this.columns[sourceStatus] = { count: 0 };
                    this.columns[sourceStatus].count = Math.max(0, (previousCounts.source ?? 0) - 1);
                }
                if (targetStatusValue) {
                    if (!this.columns[targetStatusValue]) this.columns[targetStatusValue] = { count: 0 };
                    this.columns[targetStatusValue].count = (previousCounts.target ?? 0) + 1;
                }
                const meta = this.statusMeta[targetStatusValue] || {};
                window.dispatchEvent(
                    new CustomEvent('task-status-updated', {
                        detail: {
                            itemId: taskId,
                            status: targetStatusValue,
                            statusLabel: meta.label ?? '',
                            statusClass: meta.class ?? '',
                        },
                        bubbles: true,
                    })
                );
                const occurrenceDate = this.selectedDate || null;
                const promise = this.$wire.$parent.$call(
                    'updateTaskProperty',
                    taskId,
                    'status',
                    targetStatus,
                    false,
                    occurrenceDate
                );
                await promise;
            } catch (error) {
                if (snapshot.sourceCards && snapshot.cardElement) {
                    snapshot.sourceCards.appendChild(snapshot.cardElement);
                }
                if (sourceStatus && previousCounts.source !== null && this.columns[sourceStatus]) {
                    this.columns[sourceStatus].count = previousCounts.source;
                }
                if (targetStatusValue && previousCounts.target !== null && this.columns[targetStatusValue]) {
                    this.columns[targetStatusValue].count = previousCounts.target;
                }
                this.$wire.dispatch('toast', { type: 'error', message: this.moveErrorToast });
            } finally {
                this.pendingIds.delete(taskId);
                this.draggedTaskId = null;
                this.sourceColumn = null;
                this.cardElement = null;
            }
        },

        onDragOver(event) {
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            this.dragOverColumn = event.currentTarget.closest('[data-kanban-column]');
        },

        onDragLeave(event) {
            const column = event.currentTarget.closest('[data-kanban-column]');
            if (column && !column.contains(event.relatedTarget)) {
                this.dragOverColumn = null;
            }
        },

        onTaskStatusUpdated(detail) {
            if (!detail || detail.itemId == null || !detail.status) return;
            const taskId = Number(detail.itemId);
            const newStatus = String(detail.status);
            if (!Number.isFinite(taskId)) return;

            const cardSelector = '[data-kanban-card][data-task-id="' + taskId + '"]';
            const card = this.$el.querySelector(cardSelector);
            if (!card) return;

            const sourceColumn = card.closest('[data-kanban-column]');
            if (!sourceColumn) return;

            const sourceStatus = sourceColumn.getAttribute('data-status');
            if (!sourceStatus || sourceStatus === newStatus) return;

            const targetColumnSelector = '[data-kanban-column][data-status="' + newStatus + '"]';
            const targetColumn = this.$el.querySelector(targetColumnSelector);
            if (!targetColumn) return;

            const sourceCards = sourceColumn.querySelector('[data-kanban-column-cards]');
            const targetCards = targetColumn.querySelector('[data-kanban-column-cards]');
            if (!sourceCards || !targetCards) return;

            const prevSource = this.columns[sourceStatus]?.count ?? sourceCards.children.length;
            const prevTarget = this.columns[newStatus]?.count ?? targetCards.children.length;

            // Disappear: fade out in place
            card.style.transition = 'opacity 0.12s ease';
            card.style.opacity = '0';

            const moveCard = () => {
                targetCards.prepend(card);
                if (!this.columns[sourceStatus]) this.columns[sourceStatus] = { count: 0 };
                if (!this.columns[newStatus]) this.columns[newStatus] = { count: 0 };
                this.columns[sourceStatus].count = Math.max(0, prevSource - 1);
                this.columns[newStatus].count = prevTarget + 1;
                card.style.opacity = '1';
            };

            setTimeout(moveCard, 140);
        },
    };
}
