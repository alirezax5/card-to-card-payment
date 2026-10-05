/**
 * WooCommerce Card-to-Card Payment - admin behaviour.
 *
 * Vanilla JavaScript only. All user-facing strings come from the localized
 * ctpAdmin.i18n object (Persian/English via WordPress i18n).
 *
 * Features:
 *  - Dynamic bank-cards repeater: add / remove / enable / reorder
 *    (drag & drop + keyboard-accessible up/down buttons), card number
 *    auto-formatting, JSON serialization into the settings form.
 *  - Order metabox actions: verify payment, reject receipt (with reason),
 *    remove receipt - all via secure AJAX with per-order nonces.
 */
(function () {
        'use strict';

        var cfg = window.ctpAdmin || {};
        var i18n = cfg.i18n || {};

        function t(key, fallback) {
                return i18n[key] || fallback;
        }

        /* =====================================================
         * Cards repeater
         * ==================================================== */

        var repeater = document.getElementById('ctp-cards-repeater');

        function randomId() {
                return 'card_' + Math.random().toString(36).slice(2, 12);
        }

        function formatCardNumber(value) {
                var digits = (value || '').replace(/\D/g, '').slice(0, 19);
                return digits.replace(/(.{4})/g, '$1 ').trim();
        }

        /**
         * Sync the "no cards yet" placeholder and repeater visibility helpers.
         */
        function syncRepeaterState() {
                if (!repeater) {
                        return;
                }
                var emptyEl = repeater.querySelector('.ctp-cards-empty');
                if (emptyEl) {
                        emptyEl.hidden = rows().length > 0;
                }
        }

        /**
         * Build the live preview line.
         *
         * The card number is placed inside its own LTR-isolated element so an
         * RTL admin UI (e.g. Persian) can never visually reorder the digit
         * groups ("6037 9911 1234 5678" must never render reversed).
         */
        function updatePreview(row) {
                var preview = row.querySelector('.ctp-card-row__preview');
                var number = row.querySelector('.ctp-card-row__number');
                if (!preview || !number) {
                        return;
                }

                while (preview.firstChild) {
                        preview.removeChild(preview.firstChild);
                }

                var digits = (number.value || '').replace(/\D/g, '');
                if (!digits) {
                        return;
                }

                var numSpan = document.createElement('span');
                numSpan.className = 'ctp-preview-number';
                numSpan.dir = 'ltr';
                numSpan.textContent = formatCardNumber(digits);
                preview.appendChild(numSpan);

                var firstName = row.querySelector('.ctp-card-row__first-name');
                var lastName = row.querySelector('.ctp-card-row__last-name');
                if (firstName && lastName) {
                        var holder = (firstName.value + ' ' + lastName.value).trim();
                        if (holder) {
                                var holderSpan = document.createElement('span');
                                holderSpan.className = 'ctp-preview-holder';
                                holderSpan.textContent = '— ' + holder;
                                preview.appendChild(holderSpan);
                        }
                }

                if (digits.length !== 16) {
                        var warn = document.createElement('span');
                        warn.className = 'ctp-invalid-number';
                        warn.textContent = t('invalidNumber', 'Warning: card numbers usually have 16 digits.');
                        preview.appendChild(warn);
                }
        }

        /**
         * Keep the row's disabled appearance in sync with the switch.
         */
        function syncDisabledState(row) {
                var enabled = row.querySelector('.ctp-card-row__enabled');
                if (!enabled) {
                        return;
                }
                if (enabled.checked) {
                        row.classList.remove('is-disabled');
                } else {
                        row.classList.add('is-disabled');
                }
        }

        function bindRow(row) {
                var number = row.querySelector('.ctp-card-row__number');
                number.addEventListener('input', function () {
                        var pos = this.selectionStart;
                        var before = this.value.length;
                        this.value = formatCardNumber(this.value);
                        var after = this.value.length;
                        var diff = after - before;
                        this.setSelectionRange(Math.max(0, pos + diff), Math.max(0, pos + diff));
                        updatePreview(row);
                });
                row.querySelector('.ctp-card-row__first-name').addEventListener('input', function () { updatePreview(row); });
                row.querySelector('.ctp-card-row__last-name').addEventListener('input', function () { updatePreview(row); });

                var enabledInput = row.querySelector('.ctp-card-row__enabled');
                enabledInput.addEventListener('change', function () {
                        syncDisabledState(row);
                        serialize();
                });
                syncDisabledState(row);
                row.setAttribute('draggable', 'true');

                row.addEventListener('dragstart', function (event) {
                        repeaterDrag = row;
                        row.classList.add('is-dragging');
                        if (event.dataTransfer) {
                                event.dataTransfer.effectAllowed = 'move';
                                try { event.dataTransfer.setData('text/plain', row.getAttribute('data-card-id')); } catch (e) { /* noop */ }
                        }
                });
                row.addEventListener('dragend', function () {
                        row.classList.remove('is-dragging');
                        rows().forEach(function (r) { r.classList.remove('is-drag-over'); });
                });
                row.addEventListener('dragover', function (event) {
                        event.preventDefault();
                        if (repeaterDrag && repeaterDrag !== row) {
                                row.classList.add('is-drag-over');
                        }
                });
                row.addEventListener('dragleave', function () {
                        row.classList.remove('is-drag-over');
                });
                row.addEventListener('drop', function (event) {
                        event.preventDefault();
                        row.classList.remove('is-drag-over');
                        if (!repeaterDrag || repeaterDrag === row) {
                                return;
                        }
                        var list = row.parentNode;
                        var rect = row.getBoundingClientRect();
                        var after = (event.clientY - rect.top) > rect.height / 2;
                        list.insertBefore(repeaterDrag, after ? row.nextSibling : row);
                        repeaterDrag = null;
                        serialize();
                });
                updatePreview(row);
        }

        var repeaterDrag = null;

        function rows() {
                return Array.prototype.slice.call(repeater.querySelectorAll('.ctp-card-row'));
        }

        function makeRow(data) {
                var template = document.getElementById('ctp-card-template');
                var holder = document.createElement('div');
                holder.innerHTML = template.textContent;
                var row = holder.firstElementChild;
                row.classList.remove('is-template');
                row.setAttribute('data-card-id', data.id || randomId());
                row.querySelector('.ctp-card-row__number').value = formatCardNumber(data.number || '');
                row.querySelector('.ctp-card-row__first-name').value = data.first_name || '';
                row.querySelector('.ctp-card-row__last-name').value = data.last_name || '';
                row.querySelector('.ctp-card-row__enabled').checked = !!data.enabled;
                bindRow(row);
                return row;
        }

        function rowToData(row) {
                return {
                        id: row.getAttribute('data-card-id') || randomId(),
                        number: (row.querySelector('.ctp-card-row__number').value || '').replace(/\D/g, ''),
                        first_name: row.querySelector('.ctp-card-row__first-name').value.trim(),
                        last_name: row.querySelector('.ctp-card-row__last-name').value.trim(),
                        enabled: row.querySelector('.ctp-card-row__enabled').checked
                };
        }

        function serialize() {
                var field = document.getElementById('woocommerce_card_to_card_cards');
                if (!field || !repeater) {
                        return;
                }
                var data = rows().map(rowToData).filter(function (c) { return c.number !== ''; });
                field.value = JSON.stringify(data);
                syncRepeaterState();
        }

        function handleRepeaterClick(event) {
                var target = event.target;
                if (!target || !target.closest) {
                        return;
                }

                if (target.closest('.ctp-cards-add')) {
                        event.preventDefault();
                        var row = makeRow({ enabled: true });
                        repeater.querySelector('.ctp-cards-list').appendChild(row);
                        row.querySelector('.ctp-card-row__number').focus();
                        serialize();
                        return;
                }

                var removeBtn = target.closest('.ctp-card-row__remove');
                if (removeBtn) {
                        event.preventDefault();
                        if (window.confirm(t('confirmRemove', 'Remove this card?'))) {
                                var parent = removeBtn.closest('.ctp-card-row');
                                if (parent) {
                                        parent.remove();
                                        serialize();
                                }
                        }
                        return;
                }

                var upBtn = target.closest('.ctp-card-row__up');
                if (upBtn) {
                        event.preventDefault();
                        var rowUp = upBtn.closest('.ctp-card-row');
                        if (rowUp && rowUp.previousElementSibling) {
                                rowUp.parentNode.insertBefore(rowUp, rowUp.previousElementSibling);
                                serialize();
                        }
                        return;
                }

                var downBtn = target.closest('.ctp-card-row__down');
                if (downBtn) {
                        event.preventDefault();
                        var rowDown = downBtn.closest('.ctp-card-row');
                        if (rowDown && rowDown.nextElementSibling) {
                                rowDown.parentNode.insertBefore(rowDown.nextElementSibling, rowDown);
                                serialize();
                        }
                }
        }

        function handleRepeaterChange(event) {
                if (event.target.closest && event.target.closest('#ctp-cards-repeater')) {
                        serialize();
                }
        }

        if (repeater) {
                var jsonField = document.createElement('textarea');
                jsonField.id = 'woocommerce_card_to_card_cards';
                jsonField.name = 'woocommerce_card_to_card_cards';
                jsonField.hidden = true;
                jsonField.setAttribute('aria-hidden', 'true');
                jsonField.value = '[]';
                repeater.appendChild(jsonField);

                document.addEventListener('click', handleRepeaterClick);
                document.addEventListener('change', handleRepeaterChange);

                Array.prototype.forEach.call(repeater.querySelectorAll('.ctp-card-row'), function (row) {
                        bindRow(row);
                });

                syncRepeaterState();

                document.addEventListener('submit', function (event) {
                        if (event.target.querySelector && event.target.querySelector('#woocommerce_card_to_card_cards')) {
                                serialize();
                        }
                }, true);
        }

        /* =====================================================
         * Order metabox actions
         * ==================================================== */

        var box = document.getElementById('ctp-payment-box');

        function setAdminFeedback(message, kind) {
                var el = box ? box.querySelector('[data-ctp-admin-feedback]') : null;
                if (!el) {
                        return;
                }
                el.textContent = message || '';
                el.className = 'ctp-metabox__feedback' + (kind ? ' ctp-feedback-' + kind : '');
        }

        function adminAction(action, orderId, nonce, extra) {
                var body = new URLSearchParams();
                body.append('action', action);
                body.append('order_id', orderId);
                body.append('nonce', nonce);
                if (extra) {
                        Object.keys(extra).forEach(function (key) {
                                body.append(key, extra[key]);
                        });
                }
                return fetch(window.ajaxurl || '/wp-admin/admin-ajax.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                        body: body.toString()
                }).then(function (response) {
                        return response.json().catch(function () { throw new Error('invalid-response'); });
                });
        }

        function handleAdminClicks(event) {
                var target = event.target;
                if (!box || !target.closest) {
                        return;
                }

                var boxEl = target.closest('.ctp-metabox');
                if (!boxEl) {
                        return;
                }

                var orderId = boxEl.getAttribute('data-order-id');
                var nonce = boxEl.querySelector('.ctp-metabox__nonce').value;
                var dialog = boxEl.querySelector('.ctp-reject-dialog');

                if (target.closest('.ctp-action-verify')) {
                        event.preventDefault();
                        if (!window.confirm(t('confirmVerify', 'Verify this payment?'))) {
                                return;
                        }
                        setAdminFeedback('', null);
                        adminAction('ctp_admin_verify', orderId, nonce).then(function (result) {
                                if (result && result.success) {
                                        setAdminFeedback(result.data.message + ' ' + t('reloading', 'Reloading…'), 'success');
                                        window.location.reload();
                                } else {
                                        var message = result && result.data && result.data.message ? result.data.message : t('actionFailed', 'The action failed.');
                                        if (result && result.data && !result.data.message) {
                                                message = t('securityFailed', 'Security check failed.');
                                        }
                                        setAdminFeedback(message, 'error');
                                }
                        }).catch(function () {
                                setAdminFeedback(t('actionFailed', 'The action failed.'), 'error');
                        });
                        return;
                }

                if (target.closest('.ctp-action-reject')) {
                        event.preventDefault();
                        if (dialog) {
                                dialog.hidden = !dialog.hidden;
                                var reasonField = dialog.querySelector('.ctp-reject-dialog__reason');
                                if (reasonField && !dialog.hidden) {
                                        reasonField.focus();
                                }
                        }
                        return;
                }

                if (target.closest('.ctp-reject-cancel')) {
                        event.preventDefault();
                        if (dialog) {
                                dialog.hidden = true;
                        }
                        return;
                }

                if (target.closest('.ctp-reject-confirm')) {
                        event.preventDefault();
                        var reason = dialog.querySelector('.ctp-reject-dialog__reason').value.trim();
                        if (!reason) {
                                setAdminFeedback(t('reasonRequired', 'Please enter the rejection reason.'), 'error');
                                return;
                        }
                        adminAction('ctp_admin_reject', orderId, nonce, { reason: reason }).then(function (result) {
                                if (result && result.success) {
                                        setAdminFeedback(result.data.message + ' ' + t('reloading', 'Reloading…'), 'success');
                                        window.location.reload();
                                } else {
                                        setAdminFeedback(result && result.data && result.data.message ? result.data.message : t('actionFailed', 'The action failed.'), 'error');
                                }
                        }).catch(function () {
                                setAdminFeedback(t('actionFailed', 'The action failed.'), 'error');
                        });
                        return;
                }

                if (target.closest('.ctp-action-delete')) {
                        event.preventDefault();
                        if (!window.confirm(t('confirmDelete', 'Remove this receipt?'))) {
                                return;
                        }
                        adminAction('ctp_admin_delete_receipt', orderId, nonce).then(function (result) {
                                if (result && result.success) {
                                        setAdminFeedback(result.data.message + ' ' + t('reloading', 'Reloading…'), 'success');
                                        window.location.reload();
                                } else {
                                        setAdminFeedback(result && result.data && result.data.message ? result.data.message : t('actionFailed', 'The action failed.'), 'error');
                                }
                        }).catch(function () {
                                setAdminFeedback(t('actionFailed', 'The action failed.'), 'error');
                        });
                }
        }

        if (box) {
                document.addEventListener('click', handleAdminClicks);
        }
})();
