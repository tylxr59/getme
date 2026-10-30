const itemsList = document.getElementById('itemsList');
        const addItemButton = document.getElementById('addItem');
        const menuToggle = document.getElementById('menuToggle');
        const menuPopover = document.getElementById('menuPopover');
        const toast = document.getElementById('toast');

        const icons = {
            delete: '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M6 19c0 1.1.9 2 2 2h8a2 2 0 0 0 2-2V7H6v12ZM8 4l1-1h6l1 1h4v2H4V4h4Z"/></svg>'
        };

        let toastTimeout;
        let dragState = null;
        let pendingDrag = null;
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
            const items = [...itemsList.querySelectorAll('.item')];
            const total = items.length;
            document.querySelector('.empty-state').classList.toggle('visible', total === 0);
        }

        function currentOrder() {
            return [...itemsList.querySelectorAll('.item[data-id]')].map(item => item.dataset.id);
        }

        function appendToUncheckedItems(item) {
            const firstCheckedItem = itemsList.querySelector('.item.checked');
            firstCheckedItem ? firstCheckedItem.before(item) : itemsList.appendChild(item);
        }

        async function maybeRefresh() {
            if (!refreshRequested || refreshInFlight || listState.pendingWrites || dragState ||
                itemsList.querySelector('.item.editing, .item.new-item, .item.saving, .item.save-failed')) {
                return;
            }

            refreshRequested = false;
            refreshInFlight = true;
            const revision = listState.localRevision;
            try {
                const data = await api('fetch');
                if (revision !== listState.localRevision || listState.pendingWrites || dragState ||
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

        itemsList.addEventListener('click', async event => {
            const deleteButton = event.target.closest('.delete');

            if (deleteButton) {
                const item = deleteButton.closest('.item');
                if (item.classList.contains('deleting')) {
                    return;
                }
                if (!item.dataset.id) {
                    item.remove();
                    updateCount();
                    maybeRefresh();
                    return;
                }

                item.classList.add('deleting');
                try {
                    await queueMutation(() => api('delete', { id: item.dataset.id }));
                    item.remove();
                    updateCount();
                } catch (error) {
                    item.classList.remove('deleting');
                    showToast(error.message);
                }
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
                startX: event.clientX,
                startY: event.clientY
            };

            item.setPointerCapture(event.pointerId);
            item.addEventListener('pointermove', movePointerDrag);
            item.addEventListener('pointerup', endPointerDrag, { once: true });
            item.addEventListener('pointercancel', cancelPointerDrag, { once: true });
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
                const deltaX = event.clientX - pendingDrag.startX;
                const deltaY = event.clientY - pendingDrag.startY;
                if (Math.hypot(deltaX, deltaY) < 6) {
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
            pendingDrag = null;

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
