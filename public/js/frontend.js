/**
 * WooCommerce Card-to-Card Payment - frontend behaviour.
 *
 * Vanilla JavaScript only (no frameworks). All user-facing strings come from
 * the localized ctpSettings.i18n object (Persian/English via WordPress i18n).
 * Features:
 *  - Copy card number to clipboard (Clipboard API + execCommand fallback),
 *    works on desktop and mobile.
 *  - AJAX receipt upload with client-side pre-checks; the server remains
 *    the authority for validation.
 */
(function () {
        'use strict';

        var settings = window.ctpSettings || {};
        var i18n = settings.i18n || {};

        function t(key, fallback) {
                return i18n[key] || fallback;
        }

        function setFeedback(el, message, kind) {
                if (!el) {
                        return;
                }
                el.textContent = message || '';
                el.className = el.className.replace(/ ?ctp-upload-feedback--\w+/g, '');
                if (kind) {
                        el.className += ' ctp-upload-feedback--' + kind;
                }
        }

        /* ---------- Copy to clipboard ---------- */

        function legacyCopy(text) {
                var ok = false;
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.setAttribute('readonly', '');
                ta.style.position = 'fixed';
                ta.style.left = '-9999px';
                document.body.appendChild(ta);
                try {
                        ta.select();
                        ta.setSelectionRange(0, text.length);
                        ok = document.execCommand('copy');
                } catch (e) {
                        ok = false;
                }
                document.body.removeChild(ta);
                return ok;
        }

        function copyText(text) {
                if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
                        return navigator.clipboard.writeText(text).then(
                                function () { return true; },
                                function () { return legacyCopy(text); }
                        );
                }
                return Promise.resolve(legacyCopy(text));
        }

        function showCopyFeedback(container, message, ok) {
                var target = container.querySelector('.ctp-copy-feedback');
                if (!target) {
                        return;
                }
                target.textContent = message;
                target.style.color = ok ? '#1d6a38' : '#8a1f1f';
                if (message) {
                        window.setTimeout(function () {
                                if (target.textContent === message) {
                                        target.textContent = '';
                                }
                        }, 2500);
                }
        }

        document.addEventListener('click', function (event) {
                var button = event.target.closest('.ctp-copy-button');
                if (!button) {
                        return;
                }
                var number = button.getAttribute('data-card-number') || '';
                var container = button.closest('.ctp-card') || document;
                copyText(number).then(function (ok) {
                        showCopyFeedback(container, ok ? t('copied', 'Copied!') : t('copyFailed', 'Copying failed.'), ok);
                        if (ok) {
                                var original = button.textContent;
                                button.textContent = t('copied', 'Copied!');
                                window.setTimeout(function () {
                                        button.textContent = original;
                                }, 2000);
                        }
                });
        });

        /* ---------- File input preview + pre-checks ---------- */

        function isAllowedType(file) {
                var allowed = ['image/jpeg', 'image/png', 'image/webp'];
                var name = (file.name || '').toLowerCase();
                var extOk = /\.(jpe?g|png|webp)$/.test(name);
                var mimeOk = !file.type || allowed.indexOf(file.type) !== -1;
                return extOk && mimeOk;
        }

        document.addEventListener('change', function (event) {
                var input = event.target;
                if (!input || !input.classList || !input.classList.contains('ctp-file-input')) {
                        return;
                }
                var form = input.closest('.ctp-receipt-upload');
                var nameEl = form ? form.querySelector('[data-ctp-file-name]') : null;
                var feedback = form ? form.querySelector('[data-ctp-feedback]') : null;

                if (!input.files || !input.files.length) {
                        if (nameEl) { nameEl.textContent = ''; }
                        return;
                }

                var file = input.files[0];
                var maxMb = parseInt(settings.maxFileSizeMb, 10) || 5;

                if (nameEl) {
                        nameEl.textContent = t('fileSelected', 'Selected file:') + ' ' + file.name;
                }

                if (!isAllowedType(file)) {
                        setFeedback(feedback, t('invalidType', 'This file type is not allowed.'), 'error');
                } else if (file.size > maxMb * 1024 * 1024) {
                        setFeedback(feedback, t('fileTooLarge', 'The selected file is too large.'), 'error');
                } else {
                        setFeedback(feedback, '', null);
                }
        });

        /* ---------- AJAX upload ---------- */

        function updateStatus(container, label) {
                var statusEl = container.querySelector('[data-ctp-status]');
                if (statusEl && label) {
                        statusEl.textContent = label;
                }
                var statusBox = container.querySelector('.ctp-status');
                if (statusBox && label) {
                        statusBox.className = 'ctp-status ctp-status--receipt_submitted';
                }
        }

        function safeUrl(value) {
                var url = String(value || '');
                return /^https?:\/\//i.test(url) ? url : '';
        }

        function showNewReceipt(container, data) {
                var holder = container.querySelector('[data-ctp-receipt]');
                if (!holder || !data) {
                        return;
                }

                // Build the preview with DOM APIs only (never innerHTML): the
                // URLs come from our own endpoint but are validated anyway.
                var url = safeUrl(data.receipt_url);
                if (!url) {
                        return;
                }

                var figure = document.createElement('figure');
                figure.className = 'ctp-receipt__figure';

                var link = document.createElement('a');
                link.setAttribute('href', url);
                link.setAttribute('target', '_blank');
                link.setAttribute('rel', 'noopener');

                var img = document.createElement('img');
                img.className = 'ctp-receipt__thumb';
                img.width = 220;
                img.alt = t('receiptAlt', 'Uploaded payment receipt');
                var thumb = safeUrl(data.thumbnail_url);
                if (thumb) {
                        img.setAttribute('src', thumb);
                }
                link.appendChild(img);

                var caption = document.createElement('figcaption');
                caption.className = 'ctp-receipt__meta';

                var date = document.createElement('span');
                date.className = 'ctp-receipt__date';
                date.textContent = t('uploadedLabel', 'Uploaded:') + ' ' + String(data.uploaded_at || '');
                caption.appendChild(date);

                var full = document.createElement('a');
                full.className = 'ctp-receipt__full';
                full.setAttribute('href', url);
                full.setAttribute('target', '_blank');
                full.setAttribute('rel', 'noopener');
                full.textContent = t('viewFull', 'View full size');
                caption.appendChild(full);

                figure.appendChild(link);
                figure.appendChild(caption);

                while (holder.firstChild) {
                        holder.removeChild(holder.firstChild);
                }
                holder.appendChild(figure);
        }

        document.addEventListener('submit', function (event) {
                var form = event.target;
                if (!form || !form.classList || !form.classList.contains('ctp-receipt-upload')) {
                        return;
                }
                event.preventDefault();

                var input = form.querySelector('.ctp-file-input');
                var feedback = form.querySelector('[data-ctp-feedback]');
                var button = form.querySelector('.ctp-upload-button');
                var wrapper = form.closest('.ctp-payment-wrapper');

                if (!input || !input.files || !input.files.length) {
                        setFeedback(feedback, t('chooseFile', 'Please choose a file first.'), 'error');
                        return;
                }

                var file = input.files[0];
                var maxMb = parseInt(settings.maxFileSizeMb, 10) || 5;
                if (!isAllowedType(file)) {
                        setFeedback(feedback, t('invalidType', 'This file type is not allowed.'), 'error');
                        return;
                }
                if (file.size > maxMb * 1024 * 1024) {
                        setFeedback(feedback, t('fileTooLarge', 'The selected file is too large.'), 'error');
                        return;
                }

                var data = new FormData(form);
                data.append('action', 'ctp_upload_receipt');

                if (button) {
                        button.disabled = true;
                }
                setFeedback(feedback, t('uploading', 'Uploading…'), null);

                fetch(settings.ajaxUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: data
                })
                        .then(function (response) {
                                return response.json().catch(function () {
                                        throw new Error('invalid-response');
                                });
                        })
                        .then(function (result) {
                                if (button) {
                                        button.disabled = false;
                                }
                                if (result && result.success && result.data) {
                                        updateStatus(wrapper, result.data.status_label);
                                        showNewReceipt(wrapper, result.data);
                                        input.value = '';
                                        var nameEl = form.querySelector('[data-ctp-file-name]');
                                        if (nameEl) { nameEl.textContent = ''; }

                                        // On the dedicated payment page: reveal the
                                        // "View order" link and continue to the
                                        // order-received page after the upload.
                                        var received = wrapper ? wrapper.querySelector('[data-ctp-received]') : null;
                                        var redirectUrl = received ? (received.getAttribute('href') || '') : '';
                                        if (redirectUrl) {
                                                received.hidden = false;
                                                setFeedback(feedback, '✓ ' + result.data.message + ' — ' + t('redirecting', 'Redirecting…'), 'success');
                                                window.setTimeout(function () {
                                                        window.location.href = redirectUrl;
                                                }, 1800);
                                        } else {
                                                setFeedback(feedback, '✓ ' + result.data.message, 'success');
                                        }
                                } else {
                                        var message = result && result.data && result.data.message
                                                ? result.data.message
                                                : t('uploadFailed', 'Upload failed. Please try again.');
                                        setFeedback(feedback, message, 'error');
                                }
                        })
                        .catch(function () {
                                if (button) {
                                        button.disabled = false;
                                }
                                setFeedback(feedback, t('uploadFailed', 'Upload failed. Please try again.'), 'error');
                        });
        });
})();
