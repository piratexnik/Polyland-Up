/**
 * Polylang Up - Admin Script
 */
(function($) {
    'use strict';

    const esc = function(str) {
        return $('<div>').text(String(str || '')).html();
    };

    $(document).ready(function() {
        // ============================================================
        // 1. Логика страницы настроек (тестирование API перевода)
        // ============================================================
        const $btnTest = $('#plu-btn-test-api');
        if ($btnTest.length) {
            const $spinner = $('#plu-api-test-spinner');
            const $resultBox = $('#plu-api-test-result');
            const $translatorSelect = $('#plu_default_translator');

            const updateTranslatorVisibility = function() {
                const selected = $translatorSelect.val();
                const $geminiRows = $('.plu-gemini-field');
                if (selected === 'gtx') {
                    $geminiRows.addClass('plu-row-dimmed');
                } else {
                    $geminiRows.removeClass('plu-row-dimmed');
                }
            };

            $translatorSelect.on('change', updateTranslatorVisibility);
            updateTranslatorVisibility();

            $btnTest.on('click', function(e) {
                e.preventDefault();

                const translator = $('#plu_default_translator').val() || 'gtx';
                const apiKey = $('#plu_gemini_api_key').val() || '';
                const model = $('#plu_gemini_model').val() || 'gemini-2.5-flash';

                $btnTest.prop('disabled', true);
                $spinner.addClass('is-active');
                $resultBox.empty();

                $.ajax({
                    url: pluAdminData.ajax_url,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'plu_test_api',
                        nonce: pluAdminData.nonce,
                        translator: translator,
                        api_key: apiKey,
                        model: model
                    },
                    success: function(response) {
                        if (response.success && response.data) {
                            const data = response.data;
                            let html = '';

                            if (data.channel) {
                                html = `
                                    <div class="plu-api-test-box plu-api-test-success">
                                        <strong>&#9989; ${esc(data.message)}</strong>
                                    </div>
                                `;
                            } else {
                                const timeMs = data.time_ms ? ' (' + esc(data.time_ms) + ' мс)' : '';
                                html = `
                                    <div class="plu-api-test-box plu-api-test-success">
                                        <strong>&#9989; ${esc(data.engine)}:</strong> ${esc(data.message || 'Соединение успешно.')}
                                        ${data.translated ? `<div class="plu-test-meta">Тестовый перевод: "Connection successful" &rarr; <em>"${esc(data.translated)}"</em>${timeMs}</div>` : ''}
                                    </div>
                                `;
                            }
                            $resultBox.html(html);
                        } else {
                            const msg = (response.data && response.data.message) ? response.data.message : 'Неизвестная ошибка проверки API.';
                            const html = `
                                <div class="plu-api-test-box plu-api-test-error">
                                    <strong>&#9888; Ошибка проверки:</strong> ${esc(msg)}
                                </div>
                            `;
                            $resultBox.html(html);
                        }
                    },
                    error: function(xhr, status, error) {
                        const html = `
                            <div class="plu-api-test-box plu-api-test-error">
                                <strong>&#9888; Сетевая ошибка сервера:</strong> ${esc(error || status)} (код ${esc(xhr.status || 500)})
                            </div>
                        `;
                        $resultBox.html(html);
                    },
                    complete: function() {
                        $btnTest.prop('disabled', false);
                        $spinner.removeClass('is-active');
                    }
                });
            });
        }

        // ============================================================
        // 2. Логика метабокса в редакторе записей (AI Дубликатор)
        // ============================================================
        const $box = $('#plu-duplicator-box');
        const $runBtn = $('#plu-run-duplicator');

        if ($box.length && $runBtn.length) {
            const i18n = (typeof pluAdminData !== 'undefined' && pluAdminData.i18n) ? pluAdminData.i18n : {};
            const $overwriteWrap = $('#plu-overwrite-wrapper');
            const $overwriteCb = $('#plu_overwrite_existing');
            const $spinner = $('#plu-duplicator-spinner');
            const $logBox = $('#plu-duplicator-log');

            // Проверка необходимости показа галочки подтверждения перезаписи
            const toggleOverwriteVisibility = function() {
                let hasExistingSelected = false;
                $box.find('.plu-target-lang-cb:checked').each(function() {
                    if (String($(this).attr('data-exists')) === '1') {
                        hasExistingSelected = true;
                        return false;
                    }
                });

                if (hasExistingSelected) {
                    $overwriteWrap.slideDown(150);
                } else {
                    $overwriteWrap.slideUp(150);
                    $overwriteCb.prop('checked', false);
                }
            };

            $box.on('change', '.plu-target-lang-cb', toggleOverwriteVisibility);
            toggleOverwriteVisibility();

            // Последовательная обработка выбранных языков
            const processNextLanguage = function(queue, index, params) {
                if (index >= queue.length) {
                    $runBtn.prop('disabled', false);
                    $spinner.hide().removeClass('is-active');
                    toggleOverwriteVisibility();
                    $logBox.append(
                        `<div class="plu-log-item is-success"><strong>&#127881; ${esc(i18n.finished_all || 'Обработка завершена.')}</strong></div>`
                    );
                    return;
                }

                const langItem = queue[index];
                const stepNum = index + 1;
                const totalNum = queue.length;
                const langCodeUpper = esc(langItem.slug.toUpperCase());
                const rowId = 'plu-log-step-' + langItem.slug;

                const processingText = `${esc(i18n.processing_lang || 'Обработка языка')} ${langCodeUpper} (${stepNum} ${esc(i18n.of || 'из')} ${totalNum})...`;
                $logBox.append(
                    `<div class="plu-log-item" id="${esc(rowId)}">&#9203; ${processingText}</div>`
                );

                $.ajax({
                    url: pluAdminData.ajax_url,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'plu_duplicate_post',
                        nonce: pluAdminData.clone_nonce,
                        post_id: params.postId,
                        target_lang: langItem.slug,
                        mode: params.mode,
                        keep_same_slug: params.keepSameSlug ? 1 : 0,
                        rewrite_links: params.rewriteLinks ? 1 : 0,
                        target_status: params.targetStatus,
                        overwrite_existing: params.overwriteExisting ? 1 : 0
                    },
                    success: function(response) {
                        const $stepRow = $('#' + rowId);

                        if (response.success && response.data) {
                            const d = response.data;
                            const actionLabel = d.updated ? (i18n.done_updated || 'обновлён!') : (i18n.done_created || 'готов!');
                            let notesHtml = '';

                            if (Array.isArray(d.notes) && d.notes.length > 0) {
                                notesHtml = d.notes.map(n => `<div class="plu-log-note">${esc(n)}</div>`).join('');
                            }

                            const resultHtml = `
                                <div>
                                    <strong>&#9989; Язык ${esc(d.lang_upper)} ${esc(actionLabel)}</strong>
                                    <span style="color:#646970;">(#${esc(d.post_id)}, slug: <code>${esc(d.slug)}</code>)</span>
                                </div>
                                ${notesHtml}
                                <div class="plu-log-links">
                                    ${d.edit_url ? `<a href="${esc(d.edit_url)}" class="button button-small" target="_blank" rel="noopener noreferrer">${esc(i18n.btn_wp_edit || '✏️ Редактор WP')}</a>` : ''}
                                    ${d.divi_url ? `<a href="${esc(d.divi_url)}" class="button button-small" target="_blank" rel="noopener noreferrer">${esc(i18n.btn_divi_builder || '🎨 Divi Builder')}</a>` : ''}
                                    ${d.view_url ? `<a href="${esc(d.view_url)}" class="button button-small" target="_blank" rel="noopener noreferrer">${esc(i18n.btn_view_page || '👁 Открыть страницу')}</a>` : ''}
                                </div>
                            `;
                            $stepRow.addClass('is-success').html(resultHtml);

                            // Обновляем статус-бейдж и быстрые ссылки в списке языков без перезагрузки страницы
                            const $langRow = $box.find(`.plu-lang-row[data-lang-row="${langItem.slug}"]`);
                            if ($langRow.length) {
                                $langRow.removeClass('is-missing').addClass('is-existing');
                                $langRow.find('.plu-target-lang-cb')
                                    .attr('data-exists', '1')
                                    .prop('checked', false);

                                const statusText = d.status === 'publish' ? 'Опубликовано' : 'Черновик';
                                $langRow.find('.plu-lang-meta-row').html(`
                                    <span class="plu-badge plu-badge-neutral">Уже есть: #${esc(d.post_id)} (${esc(statusText)})</span>
                                    <span class="plu-lang-quick-links">
                                        <a href="${esc(d.edit_url)}" title="Редактировать перевод" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-edit"></span></a>
                                        <a href="${esc(d.view_url)}" title="Открыть перевод" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-visibility"></span></a>
                                    </span>
                                `);
                            }
                        } else {
                            const errMsg = (response.data && response.data.message) ? response.data.message : 'Ошибка при клонировании.';
                            $stepRow.addClass('is-error').html(`<strong>&#10060; ${langCodeUpper}:</strong> ${esc(errMsg)}`);
                        }
                    },
                    error: function(xhr, status, error) {
                        const $stepRow = $('#' + rowId);
                        $stepRow.addClass('is-error').html(
                            `<strong>&#10060; ${langCodeUpper}:</strong> Сетевая ошибка (${esc(error || status)}, HTTP ${esc(xhr.status || 500)})`
                        );
                    },
                    complete: function() {
                        processNextLanguage(queue, index + 1, params);
                    }
                });
            };

            $runBtn.on('click', function(e) {
                e.preventDefault();

                const queue = [];
                let hasExistingSelected = false;

                $box.find('.plu-target-lang-cb:checked').each(function() {
                    const $cb = $(this);
                    const isExisting = String($cb.attr('data-exists')) === '1';
                    if (isExisting) {
                        hasExistingSelected = true;
                    }
                    queue.push({
                        slug: String($cb.val()),
                        name: String($cb.attr('data-lang-name') || $cb.val()),
                        exists: isExisting
                    });
                });

                $logBox.empty().show();

                if (queue.length === 0) {
                    $logBox.html(`<div class="plu-log-item is-warning">&#9888; ${esc(i18n.no_langs_selected || 'Выберите хотя бы один целевой язык.')}</div>`);
                    return;
                }

                const overwriteExisting = $overwriteCb.is(':checked');
                if (hasExistingSelected && !overwriteExisting) {
                    $logBox.html(`<div class="plu-log-item is-warning">&#9888; ${esc(i18n.overwrite_required || 'Подтвердите перезапись существующего перевода.')}</div>`);
                    return;
                }

                const params = {
                    postId: parseInt($box.attr('data-post-id'), 10) || 0,
                    mode: String($('#plu_mode').val() || 'clone_only'),
                    keepSameSlug: $('#plu_keep_same_slug').is(':checked'),
                    rewriteLinks: $('#plu_rewrite_links').is(':checked'),
                    targetStatus: String($('#plu_target_status').val() || 'draft'),
                    overwriteExisting: overwriteExisting
                };

                $runBtn.prop('disabled', true);
                $spinner.css('display', 'block').addClass('is-active');

                processNextLanguage(queue, 0, params);
            });
        }
    });
})(jQuery);
