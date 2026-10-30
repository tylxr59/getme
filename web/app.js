const itemsList = document.getElementById('itemsList');
        const addItemButton = document.getElementById('addItem');
        const menuToggle = document.getElementById('menuToggle');
        const menuPopover = document.getElementById('menuPopover');
        const toast = document.getElementById('toast');
        const summary = document.getElementById('summary');
        const progressBar = document.getElementById('progressBar');

        const icons = {
            delete: '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg>'
        };

        let toastTimeout;
        let dragState = null;
        let pendingDrag = null;
        let swipeState = null;
        const listState = new ListState(maybeRefresh);
        let refreshRequested = false;
        let refreshInFlight = false;

        function showToast(message) {
            toast.textContent = message;
            toast.classList.add('visible');
            clearTimeout(toastTimeout);
            toastTimeout = setTimeout(() => toast.classList.remove('visible'), 2200);
        }

        function setMenuOpen(open) {
            menuPopover.classList.toggle('visible', open);
            menuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        const syncClient = new GetmeClient('', window.getmeInitialSnapshot);
        async function api(action, payload = {}) {
            try {
                return await syncClient.action(action, payload);
            } catch (error) {
                if (error.code === 'conflict') refreshRequested = true;
                throw error;
            }
        }

        function queueMutation(work) { return listState.enqueue(work); }

        function newRequestId() {
            if (window.crypto && crypto.randomUUID) {
                return crypto.randomUUID();
            }
            return `${Date.now()}-${Math.random().toString(36).slice(2)}`;
        }

        function escapeHtml(value) {
            const element = document.createElement('div');
            element.textContent = value;
            return element.innerHTML;
        }

        function itemTemplate(item) {
            const checked = Number(item.checked) === 1;
            const disabled = item.id ? '' : ' disabled';
            return `
                <input type="checkbox" class="item-checkbox" aria-label="Toggle item" ${checked ? 'checked' : ''}${disabled}>
                <span class="item-text" contenteditable="false">${escapeHtml(item.name)}</span>
                <button class="item-action delete" type="button" aria-label="Delete item" title="Delete item">${icons.delete}</button>
            `;
        }

        function createItemElement(item) {
            const element = document.createElement('li');
            element.className = 'item' + (Number(item.checked) === 1 ? ' checked' : '');
            if (item.id) {
                element.dataset.id = item.id;
            }
            element.innerHTML = itemTemplate(item);
            setupDragAndDrop(element);
            return element;
        }

        function updateCount() {
            const items = [...itemsList.querySelectorAll('.item[data-id]:not(.swiped-away)')];
            const total = items.length;
            const done = items.filter(item => item.classList.contains('checked')).length;
            const left = total - done;
            document.querySelector('.empty-state').classList.toggle('visible',
                !itemsList.querySelector('.item:not(.swiped-away)'));
            summary.textContent = total === 0 ? 'Your list is empty'
                : left === 0 ? 'All done'
                : `${left} to get` + (done ? ` · ${done} done` : '');
            progressBar.style.width = `${total ? Math.round(done / total * 100) : 0}%`;
        }

        function currentOrder() {
            return [...itemsList.querySelectorAll('.item[data-id]')].map(item => item.dataset.id);
        }

        function appendToUncheckedItems(item) {
            const firstCheckedItem = itemsList.querySelector('.item.checked');
            firstCheckedItem ? firstCheckedItem.before(item) : itemsList.appendChild(item);
        }

        async function maybeRefresh() {
            if (!refreshRequested || refreshInFlight || listState.pendingWrites || dragState || swipeState ||
                itemsList.querySelector('.item.editing, .item.new-item, .item.saving, .item.save-failed')) {
                return;
            }

            refreshRequested = false;
            refreshInFlight = true;
            const revision = listState.localRevision;
            try {
                const data = await api('fetch');
                if (revision !== listState.localRevision || listState.pendingWrites || dragState || swipeState ||
                    itemsList.querySelector('.item.editing, .item.new-item, .item.saving, .item.save-failed')) {
                    refreshRequested = true;
                } else {
                    itemsList.replaceChildren(...data.items.map(createItemElement));
                    updateCount();
                }
            } catch (error) {
                showToast(error.message);
            } finally {
                refreshInFlight = false;
                if (refreshRequested) {
                    maybeRefresh();
                }
            }
        }

        menuToggle.addEventListener('click', event => {
            event.stopPropagation();
            setMenuOpen(!menuPopover.classList.contains('visible'));
        });

        menuPopover.addEventListener('click', event => {
            event.stopPropagation();
        });

        document.addEventListener('click', event => {
            if (!event.target.closest('.header-actions')) {
                setMenuOpen(false);
            }
        });

        document.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                setMenuOpen(false);
            }
        });

        function openNewItem() {
            setMenuOpen(false);
            const pendingItem = itemsList.querySelector('.item.new-item.editing') ||
                itemsList.querySelector('.item.new-item:not(.saving)');
            if (pendingItem) {
                startEditing(pendingItem);
                return;
            }

            const item = createItemElement({ id: null, name: '', checked: 0 });
            item.classList.add('new-item');
            item.dataset.requestId = newRequestId();
            appendToUncheckedItems(item);
            updateCount();
            startEditing(item);
        }

        addItemButton.addEventListener('click', openNewItem);
        document.getElementById('addRow').addEventListener('click', openNewItem);

        itemsList.addEventListener('change', async event => {
            if (!event.target.classList.contains('item-checkbox')) {
                return;
            }

            const item = event.target.closest('.item');
            const checked = event.target.checked;
            item.classList.toggle('checked', checked);
            checked ? itemsList.appendChild(item) : itemsList.prepend(item);
            updateCount();

            try {
                const order = currentOrder();
                await queueMutation(async () => {
                    await api('toggle', { id: item.dataset.id, checked: checked ? 1 : 0 });
                    await api('reorder', { items: order });
                });
            } catch (error) {
                showToast(error.message);
                queueRefresh();
            }
        });

        async function deleteItem(item) {
            if (item.classList.contains('deleting')) {
                return false;
            }
            if (!item.dataset.id) {
                item.remove();
                updateCount();
                maybeRefresh();
                return true;
            }

            item.classList.add('deleting');
            try {
                await queueMutation(() => api('delete', { id: item.dataset.id }));
                item.remove();
                updateCount();
                return true;
            } catch (error) {
                item.classList.remove('deleting');
                showToast(error.message);
                return false;
            }
        }

        itemsList.addEventListener('click', event => {
            const deleteButton = event.target.closest('.delete');
            if (deleteButton) {
                deleteItem(deleteButton.closest('.item'));
            }
        });

        itemsList.addEventListener('pointerdown', event => {
            if (event.target.closest('.delete')) {
                event.preventDefault();
            }
        });

        itemsList.addEventListener('dblclick', event => {
            const item = event.target.closest('.item');
            if (item && !event.target.closest('button, input')) {
                startEditing(item);
            }
        });

        function startEditing(item) {
            if (item.classList.contains('saving')) {
                return;
            }
            const text = item.querySelector('.item-text');
            if (text.classList.contains('editing')) {
                text.focus();
                return;
            }

            const isNew = !item.dataset.id;
            const original = text.textContent;
            const savedName = item.dataset.savedName || original;
            listState.localRevision++;
            text.contentEditable = 'true';
            text.classList.add('editing');
            item.classList.add('editing');
            text.focus();

            const range = document.createRange();
            range.selectNodeContents(text);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);

            const finish = async (save, createNext = false) => {
                text.removeEventListener('keydown', onKeydown);
                text.removeEventListener('blur', onBlur);
                text.contentEditable = 'false';
                text.classList.remove('editing');
                item.classList.remove('editing');

                const next = text.textContent.trim();
                if (!save || next === '') {
                    if (isNew) {
                        item.remove();
                        updateCount();
                    } else {
                        text.textContent = savedName;
                        item.classList.remove('save-failed');
                        delete item.dataset.savedName;
                    }
                    maybeRefresh();
                    return;
                }
                if (!isNew && next === original && !item.classList.contains('save-failed')) {
                    if (createNext) {
                        openNewItem();
                    }
                    maybeRefresh();
                    return;
                }

                item.classList.add('saving');
                if (createNext) {
                    openNewItem();
                }
                try {
                    if (isNew) {
                        const data = await queueMutation(() => api('add', {
                            name: next,
                            request_id: item.dataset.requestId
                        }));
                        item.dataset.id = data.item.id;
                        item.classList.remove('new-item');
                        item.querySelector('.item-checkbox').disabled = false;
                        text.textContent = data.item.name;
                        updateCount();
                    } else {
                        await queueMutation(() => api('edit', { id: item.dataset.id, name: next }));
                        item.classList.remove('save-failed');
                        delete item.dataset.savedName;
                    }
                } catch (error) {
                    if (isNew) {
                        text.textContent = next;
                    } else {
                        item.dataset.savedName = savedName;
                        item.classList.add('save-failed');
                        text.textContent = next;
                    }
                    showToast(error.message);
                } finally {
                    item.classList.remove('saving');
                    if (isNew && !item.dataset.id &&
                        !itemsList.querySelector('.item.editing')) {
                        startEditing(item);
                    }
                    maybeRefresh();
                }
            };

            const onBlur = () => finish(true);
            const onKeydown = event => {
                if (event.key === 'Enter' && !event.isComposing) {
                    event.preventDefault();
                    finish(true, true);
                }
                if (event.key === 'Escape') {
                    event.preventDefault();
                    finish(false);
                }
            };

            text.addEventListener('blur', onBlur);
            text.addEventListener('keydown', onKeydown);
        }

        function setupDragAndDrop(element) {
            element.draggable = false;
            element.addEventListener('pointerdown', startPointerDrag);
        }

        // Once a touch drag is live, hold the page still under the finger.
        document.addEventListener('touchmove', event => {
            if (dragState || swipeState) {
                event.preventDefault();
            }
        }, { passive: false });

        itemsList.addEventListener('contextmenu', event => {
            if (dragState || pendingDrag) {
                event.preventDefault();
            }
        });

        function dragAfterElement(y, selector) {
            return [...itemsList.querySelectorAll(selector)].reduce((closest, child) => {
                const box = child.getBoundingClientRect();
                const offset = y - box.top - box.height / 2;
                return offset < 0 && offset > closest.offset ? { offset, element: child } : closest;
            }, { offset: Number.NEGATIVE_INFINITY }).element;
        }

        function captureItemPositions() {
            const positions = new Map();
            itemsList.querySelectorAll('.item:not(.touch-dragging)').forEach(item => {
                positions.set(item, item.getBoundingClientRect());
            });
            return positions;
        }

        function animateMovedItems(previousPositions) {
            itemsList.querySelectorAll('.item:not(.touch-dragging)').forEach(item => {
                const previous = previousPositions.get(item);
                if (!previous) {
                    return;
                }

                const next = item.getBoundingClientRect();
                const deltaY = previous.top - next.top;
                if (Math.abs(deltaY) < 1) {
                    return;
                }

                item.animate([
                    { transform: `translateY(${deltaY}px)` },
                    { transform: 'translateY(0)' }
                ], {
                    duration: 150,
                    easing: 'ease-out'
                });
            });
        }

        function movePlaceholder(y) {
            const previousPositions = captureItemPositions();
            const after = dragAfterElement(y, '.item:not(.touch-dragging):not(.reorder-placeholder)');
            after ? after.before(dragState.placeholder) : itemsList.appendChild(dragState.placeholder);
            animateMovedItems(previousPositions);
        }

        function startPointerDrag(event) {
            if (dragState || pendingDrag || event.button !== 0) {
                return;
            }

            const item = event.target.closest('.item');
            if (!item || item.classList.contains('editing') ||
                item.classList.contains('saving') || !item.dataset.id ||
                event.target.closest('button, input')) {
                return;
            }

            pendingDrag = {
                item,
                pointerId: event.pointerId,
                touch: event.pointerType !== 'mouse',
                startX: event.clientX,
                startY: event.clientY,
                clientX: event.clientX,
                clientY: event.clientY
            };

            item.addEventListener('pointermove', movePointerDrag);
            item.addEventListener('pointerup', endPointerDrag, { once: true });
            item.addEventListener('pointercancel', cancelPointerDrag, { once: true });

            if (!pendingDrag.touch) {
                item.setPointerCapture(event.pointerId);
                return;
            }

            // Touch drags start with a long press so a swipe still scrolls the page.
            item.classList.add('press-pending');
            pendingDrag.timer = setTimeout(() => {
                item.classList.remove('press-pending');
                if (!pendingDrag || pendingDrag.item !== item) {
                    return;
                }
                item.setPointerCapture(pendingDrag.pointerId);
                if (navigator.vibrate) {
                    navigator.vibrate(10);
                }
                beginPointerDrag(pendingDrag);
            }, 350);
        }

        function abandonPendingDrag() {
            const { item, timer } = pendingDrag;
            clearTimeout(timer);
            item.classList.remove('press-pending');
            item.removeEventListener('pointermove', movePointerDrag);
            item.removeEventListener('pointerup', endPointerDrag);
            item.removeEventListener('pointercancel', cancelPointerDrag);
            pendingDrag = null;
        }

        function beginPointerDrag(event) {
            const item = pendingDrag.item;

            const rect = item.getBoundingClientRect();
            const originalNextSibling = item.nextElementSibling;
            const placeholder = item.cloneNode(false);
            placeholder.className = item.className.replace(/\btouch-dragging\b/g, '').trim() + ' reorder-placeholder';
            placeholder.style.height = `${rect.height}px`;
            item.after(placeholder);

            dragState = {
                item,
                placeholder,
                originalNextSibling,
                pointerId: event.pointerId,
                offsetX: event.clientX - rect.left,
                offsetY: event.clientY - rect.top
            };

            Object.assign(item.style, {
                position: 'fixed',
                width: `${rect.width}px`,
                left: `${rect.left}px`,
                top: `${rect.top}px`,
                zIndex: '1000',
                pointerEvents: 'none'
            });
            item.classList.add('touch-dragging');
            pendingDrag = null;
        }

        function movePointerDrag(event) {
            if (pendingDrag && event.pointerId === pendingDrag.pointerId) {
                pendingDrag.clientX = event.clientX;
                pendingDrag.clientY = event.clientY;
                const deltaX = event.clientX - pendingDrag.startX;
                const deltaY = event.clientY - pendingDrag.startY;
                if (Math.hypot(deltaX, deltaY) < (pendingDrag.touch ? 10 : 6)) {
                    return;
                }
                if (pendingDrag.touch) {
                    const { item, pointerId, startX } = pendingDrag;
                    const leftward = deltaX < 0 && Math.abs(deltaX) > Math.abs(deltaY) * 1.5;
                    abandonPendingDrag();
                    if (leftward) {
                        beginSwipe(item, pointerId, startX);
                    }
                    return;
                }
                event.preventDefault();
                beginPointerDrag(event);
            }

            if (!dragState || event.pointerId !== dragState.pointerId) {
                return;
            }

            event.preventDefault();
            dragState.item.style.left = `${event.clientX - dragState.offsetX}px`;
            dragState.item.style.top = `${event.clientY - dragState.offsetY}px`;
            movePlaceholder(event.clientY);
        }

        function finishPointerDrag(save) {
            if (pendingDrag) {
                abandonPendingDrag();
            }

            if (!dragState) {
                return;
            }

            const { item, placeholder, originalNextSibling } = dragState;
            Object.assign(item.style, {
                position: '',
                width: '',
                left: '',
                top: '',
                zIndex: '',
                pointerEvents: ''
            });
            item.classList.remove('touch-dragging');
            if (save) {
                placeholder.replaceWith(item);
            } else {
                placeholder.remove();
                if (originalNextSibling && originalNextSibling.parentNode === itemsList) {
                    itemsList.insertBefore(item, originalNextSibling);
                } else {
                    itemsList.appendChild(item);
                }
            }
            dragState = null;
            updateCount();

            if (save) {
                saveOrder();
            } else {
                maybeRefresh();
            }
        }

        function endPointerDrag(event) {
            event.currentTarget.removeEventListener('pointermove', movePointerDrag);
            finishPointerDrag(true);
        }

        function cancelPointerDrag(event) {
            event.currentTarget.removeEventListener('pointermove', movePointerDrag);
            finishPointerDrag(false);
        }

        // Swiping a row left on touch screens deletes it once past the threshold.
        function beginSwipe(item, pointerId, startX) {
            swipeState = { item, pointerId, startX, armed: false };
            item.setPointerCapture(pointerId);
            item.classList.add('swiping');
            item.addEventListener('pointermove', moveSwipe);
            item.addEventListener('pointerup', endSwipe);
            item.addEventListener('pointercancel', endSwipe);
        }

        function moveSwipe(event) {
            if (!swipeState || event.pointerId !== swipeState.pointerId) {
                return;
            }

            const { item } = swipeState;
            const offset = Math.min(0, event.clientX - swipeState.startX);
            const armed = -offset > Math.min(120, item.offsetWidth * 0.35);
            if (armed && !swipeState.armed && navigator.vibrate) {
                navigator.vibrate(10);
            }
            swipeState.armed = armed;
            item.classList.toggle('swipe-armed', armed);
            item.style.setProperty('--swipe', `${offset}px`);
        }

        function settleSwipe(item, offset) {
            item.classList.add('swipe-settling');
            item.style.setProperty('--swipe', `${offset}px`);
            return new Promise(resolve => setTimeout(resolve, 220));
        }

        function resetSwipe(item) {
            item.classList.remove('swiping', 'swipe-armed', 'swipe-settling');
            item.style.removeProperty('--swipe');
        }

        async function endSwipe(event) {
            if (!swipeState || event.pointerId !== swipeState.pointerId) {
                return;
            }

            const { item, armed } = swipeState;
            swipeState = null;
            item.removeEventListener('pointermove', moveSwipe);
            item.removeEventListener('pointerup', endSwipe);
            item.removeEventListener('pointercancel', endSwipe);

            if (!armed || event.type !== 'pointerup') {
                await settleSwipe(item, 0);
                resetSwipe(item);
                maybeRefresh();
                return;
            }

            await settleSwipe(item, -item.offsetWidth);
            await item.animate([
                { height: `${item.offsetHeight}px` },
                { height: '0px', minHeight: '0px', paddingTop: '0px', paddingBottom: '0px' }
            ], { duration: 180, easing: 'ease-in' }).finished;
            item.classList.add('swiped-away');
            updateCount();

            if (!await deleteItem(item)) {
                item.classList.remove('swiped-away');
                resetSwipe(item);
                updateCount();
            }
        }

        async function saveOrder() {
            try {
                const order = currentOrder();
                await queueMutation(() => api('reorder', { items: order }));
            } catch (error) {
                showToast(error.message);
            }
        }

        document.getElementById('clearChecked').addEventListener('click', async () => {
            setMenuOpen(false);
            if (!confirm('Clear checked items?')) {
                return;
            }

            try {
                await queueMutation(() => api('clear_checked'));
                itemsList.querySelectorAll('.item.checked').forEach(item => item.remove());
                updateCount();
            } catch (error) {
                showToast(error.message);
            }
        });

        document.getElementById('clearAll').addEventListener('click', async () => {
            setMenuOpen(false);
            if (!confirm('Clear all items?')) {
                return;
            }

            try {
                await queueMutation(() => api('clear_all'));
                itemsList.replaceChildren();
                updateCount();
            } catch (error) {
                showToast(error.message);
            }
        });

        document.getElementById('exportMarkdown').addEventListener('click', async () => {
            setMenuOpen(false);
            const lines = [...itemsList.querySelectorAll('.item')].map(item => {
                const checked = item.querySelector('.item-checkbox').checked ? 'x' : ' ';
                const text = item.querySelector('.item-text').textContent.trim();
                return `- [${checked}] ${text}`;
            });

            try {
                await navigator.clipboard.writeText(lines.join('\n') + (lines.length ? '\n' : ''));
                showToast('Markdown copied');
            } catch (error) {
                showToast('Clipboard is not available');
            }
        });

        let refreshTimeout;
        function queueRefresh() {
            refreshRequested = true;
            clearTimeout(refreshTimeout);
            refreshTimeout = setTimeout(maybeRefresh, 250);
        }

        window.addEventListener('focus', queueRefresh);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                queueRefresh();
            }
        });

        document.querySelectorAll('.item').forEach(setupDragAndDrop);
        updateCount();
