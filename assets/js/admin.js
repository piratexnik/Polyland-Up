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

        // Загрузка доступных моделей Gemini по API-ключу
        const $btnFetchModels = $('#plu-fetch-models-btn');
        if ($btnFetchModels.length) {
            const $modelsSpinner = $('#plu-fetch-models-spinner');
            const $modelsResult = $('#plu-models-list-result');
            const $modelInput = $('#plu_gemini_model, #gemini_model');
            const $datalist = $('#plu-gemini-models');

            $btnFetchModels.on('click', function(e) {
                e.preventDefault();
                const apiKey = $('#plu_gemini_api_key').val() || '';

                $btnFetchModels.prop('disabled', true);
                $modelsSpinner.addClass('is-active');
                $modelsResult.empty();

                $.ajax({
                    url: pluAdminData.ajax_url,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'plu_fetch_gemini_models',
                        nonce: pluAdminData.nonce,
                        api_key: apiKey
                    },
                    success: function(response) {
                        if (response.success && response.data && Array.isArray(response.data.models)) {
                            const models = response.data.models;
                            const currentVal = String($modelInput.val() || '').trim();

                            // Обновляем datalist
                            if ($datalist.length) {
                                $datalist.empty();
                                models.forEach(function(m) {
                                    $datalist.append(`<option value="${esc(m.id)}"></option>`);
                                });
                            }

                            // Отрисовываем кликабельные чипсы
                            const chipsHtml = models.map(function(m) {
                                const isActive = (m.id === currentVal) ? ' is-active' : '';
                                const limitStr = m.inputTokenLimit ? ` (${Math.round(m.inputTokenLimit / 1000)}K tokens)` : '';
                                const titleAttr = `${m.displayName || m.id}${limitStr}`;
                                return `<button type="button" class="plu-model-chip${isActive}" data-model="${esc(m.id)}" title="${esc(titleAttr)}">${esc(m.id)}</button>`;
                            }).join('');

                            $modelsResult.html(`
                                <div class="plu-models-chips-header">Найдено моделей: <strong>${esc(models.length)}</strong> (кликните для выбора):</div>
                                <div class="plu-models-chips">${chipsHtml}</div>
                            `);
                        } else {
                            const msg = (response.data && response.data.message) ? response.data.message : 'Не удалось загрузить модели.';
                            $modelsResult.html(`
                                <div class="plu-api-test-box plu-api-test-error">
                                    <strong>&#9888; Ошибка:</strong> ${esc(msg)}
                                </div>
                            `);
                        }
                    },
                    error: function(xhr, status, error) {
                        $modelsResult.html(`
                            <div class="plu-api-test-box plu-api-test-error">
                                <strong>&#9888; Сетевая ошибка:</strong> ${esc(error || status)} (код ${esc(xhr.status || 500)})
                            </div>
                        `);
                    },
                    complete: function() {
                        $btnFetchModels.prop('disabled', false);
                        $modelsSpinner.removeClass('is-active');
                    }
                });
            });

            $modelsResult.on('click', '.plu-model-chip', function(e) {
                e.preventDefault();
                const selectedModel = String($(this).attr('data-model') || '');
                if (selectedModel) {
                    $modelInput.val(selectedModel).trigger('change');
                    $modelsResult.find('.plu-model-chip').removeClass('is-active');
                    $(this).addClass('is-active');
                }
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

                const ajaxData = {
                    action: 'plu_duplicate_post',
                    nonce: pluAdminData.clone_nonce,
                    post_id: params.postId,
                    target_lang: langItem.slug,
                    mode: params.mode,
                    keep_same_slug: params.keepSameSlug ? 1 : 0,
                    rewrite_links: params.rewriteLinks ? 1 : 0,
                    target_status: params.targetStatus,
                    overwrite_existing: params.overwriteExisting ? 1 : 0
                };

                $.ajax({
                    url: pluAdminData.ajax_url,
                    type: 'POST',
                    dataType: 'json',
                    data: ajaxData,
                    success: function(response) {
                        const $stepRow = $('#' + rowId);

                        if (response.success && response.data) {
                            const d = response.data;
                            const actionLabel = d.updated ? (i18n.done_updated || 'обновлён!') : (i18n.done_created || 'готов!');
                            const summaryHtml = d.translation_summary
                                ? ` <span class="plu-log-summary">— ${esc(d.translation_summary)}.</span>`
                                : '';
                            let notesHtml = '';

                            if (Array.isArray(d.notes) && d.notes.length > 0) {
                                notesHtml = d.notes.map(n => `<div class="plu-log-note">${esc(n)}</div>`).join('');
                            }

                            const resultHtml = `
                                <div>
                                    <strong>&#9989; Язык ${esc(d.lang_upper)} ${esc(actionLabel)}</strong>
                                    <span style="color:#646970;">(#${esc(d.post_id)}, slug: <code>${esc(d.slug)}</code>)</span>${summaryHtml}
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
                        const serverMsg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
                            ? xhr.responseJSON.data.message
                            : '';
                        const displayErr = serverMsg
                            ? `${esc(serverMsg)} (HTTP ${esc(xhr.status || 500)})`
                            : `Сетевая ошибка (${esc(error || status)}, HTTP ${esc(xhr.status || 500)})`;
                        $stepRow.addClass('is-error').html(
                            `<strong>&#10060; ${langCodeUpper}:</strong> ${displayErr}`
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
                    const slug = String($cb.val() || '').toLowerCase();
                    const isExisting = String($cb.attr('data-exists')) === '1';
                    if (isExisting) {
                        hasExistingSelected = true;
                    }
                    queue.push({
                        slug: slug,
                        name: String($cb.attr('data-lang-name') || slug),
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

        // ============================================================
        // 3. Кнопка сброса системного промпта к эталонному SEO-стандарту
        // ============================================================
        const $btnResetPrompt = $('#plu-reset-prompt-btn');
        if ($btnResetPrompt.length) {
            $btnResetPrompt.on('click', function(e) {
                e.preventDefault();
                if (typeof pluAdminData !== 'undefined' && pluAdminData.default_system_prompt) {
                    $('#plu_system_prompt').val(pluAdminData.default_system_prompt).trigger('change');
                    $('#plu-reset-prompt-status').fadeIn(150);
                }
            });
        }

        // ============================================================
        // 4. Логика вкладки «🍔 Дубликатор Меню»
        // ============================================================
        const $menuBox = $('#plu-menu-duplicator-box');
        const $menuRunBtn = $('#plu-run-menu-duplicator');

        if ($menuBox.length && $menuRunBtn.length) {
            const $menuSpinner = $('#plu-menu-duplicator-spinner');
            const $menuLog = $('#plu-menu-duplicator-log');
            const $sourceLangSelect = $('#plu_menu_source_lang');

            // Автоматически снимаем галочку с исходного языка при его переключении
            $sourceLangSelect.on('change', function() {
                const src = String($(this).val() || '');
                $menuBox.find('.plu-menu-target-lang-cb').each(function() {
                    if (String($(this).val()) === src) {
                        $(this).prop('checked', false);
                    }
                });
            });

            const processNextMenuLang = function(queue, index, params) {
                if (index >= queue.length) {
                    $menuRunBtn.prop('disabled', false);
                    $menuSpinner.removeClass('is-active');
                    $menuLog.append(
                        '<div class="plu-log-item is-success"><strong>&#127881; Клонирование меню для выбранных языков завершено.</strong></div>'
                    );
                    return;
                }

                const langItem = queue[index];
                const stepNum = index + 1;
                const totalNum = queue.length;
                const langUpper = esc(langItem.slug.toUpperCase());
                const rowId = 'plu-menu-log-step-' + langItem.slug;

                $menuLog.append(
                    `<div class="plu-log-item" id="${esc(rowId)}">&#9203; Обработка меню для языка ${langUpper} (${stepNum} из ${totalNum})...</div>`
                );

                $.ajax({
                    url: pluAdminData.ajax_url,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'plu_duplicate_menu',
                        nonce: pluAdminData.nonce,
                        source_menu_id: params.sourceMenuId,
                        source_lang: params.sourceLang,
                        target_lang: langItem.slug,
                        translate_mode: params.translateMode,
                        translate_custom_labels: params.translateCustomLabels ? 1 : 0,
                        assign_locations: params.assignLocations ? 1 : 0,
                        overwrite_menu: params.overwriteMenu ? 1 : 0
                    },
                    success: function(response) {
                        const $stepRow = $('#' + rowId);

                        if (response.success && response.data) {
                            const d = response.data;
                            const actionText = d.overwritten ? 'обновлено' : 'создано';
                            const locText = (Array.isArray(d.assigned_locations) && d.assigned_locations.length > 0)
                                ? `Локации темы: <code>${esc(d.assigned_locations.join(', '))}</code>`
                                : 'Локации темы: не привязаны (у исходного меню не было локаций)';

                            $stepRow.addClass('is-success').html(`
                                <div>
                                    <strong>&#9989; Меню «${esc(d.menu_name)}» (${esc(d.lang_upper)}) ${esc(actionText)}!</strong>
                                    <span style="color:#646970;">(пунктов: ${esc(d.items_count)}, переведено строк: ${esc(d.translated_count)}, провайдер: ${esc(d.provider_label)})</span>
                                </div>
                                <div class="plu-log-note">${locText}</div>
                                <div class="plu-log-links">
                                    ${d.edit_url ? `<a href="${esc(d.edit_url)}" class="button button-small" target="_blank" rel="noopener noreferrer">&#9998;&#65039; Редактировать меню в WP</a>` : ''}
                                </div>
                            `);
                        } else {
                            const msg = (response.data && response.data.message) ? response.data.message : 'Ошибка клонирования меню.';
                            $stepRow.addClass('is-error').html(`<strong>&#10060; ${langUpper}:</strong> ${esc(msg)}`);
                        }
                    },
                    error: function(xhr, status, error) {
                        const $stepRow = $('#' + rowId);
                        const serverMsg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
                            ? xhr.responseJSON.data.message
                            : '';
                        const displayErr = serverMsg
                            ? `${esc(serverMsg)} (HTTP ${esc(xhr.status || 500)})`
                            : `Сетевая ошибка (${esc(error || status)}, HTTP ${esc(xhr.status || 500)})`;
                        $stepRow.addClass('is-error').html(`<strong>&#10060; ${langUpper}:</strong> ${displayErr}`);
                    },
                    complete: function() {
                        processNextMenuLang(queue, index + 1, params);
                    }
                });
            };

            $menuRunBtn.on('click', function(e) {
                e.preventDefault();

                const queue = [];
                $menuBox.find('.plu-menu-target-lang-cb:checked').each(function() {
                    queue.push({
                        slug: String($(this).val()),
                        name: String($(this).attr('data-lang-name') || $(this).val())
                    });
                });

                $menuLog.empty().show();

                if (queue.length === 0) {
                    $menuLog.html('<div class="plu-log-item is-warning">&#9888; Выберите хотя бы один целевой язык.</div>');
                    return;
                }

                const params = {
                    sourceMenuId: parseInt($('#plu_menu_source_id').val(), 10) || 0,
                    sourceLang: String($sourceLangSelect.val() || ''),
                    translateMode: String($('#plu_menu_translate_mode').val() || 'gtx'),
                    translateCustomLabels: $('#plu_menu_translate_custom_labels').is(':checked'),
                    assignLocations: $('#plu_menu_assign_locations').is(':checked'),
                    overwriteMenu: $('#plu_menu_overwrite').is(':checked')
                };

                $menuRunBtn.prop('disabled', true);
                $menuSpinner.addClass('is-active');

                processNextMenuLang(queue, 0, params);
            });
        }

        // ============================================================
        // 5. Логика вкладки «🔤 Строки шаблонов (UI)»
        // ============================================================
        const $stringsBox = $('#plu-strings-manager-box');
        if ($stringsBox.length) {
            const $table = $('#plu-strings-table');
            const $tbody = $('#plu-strings-tbody');
            const $spinner = $('#plu-strings-spinner');
            const $status = $('#plu-strings-status');

            let langCols = [];
            try {
                langCols = JSON.parse($table.attr('data-langs') || '[]');
            } catch (e) {
                langCols = [];
            }

            const sanitizeId = function(val) {
                return String(val || '')
                    .toLowerCase()
                    .replace(/-/g, '_')
                    .replace(/[^a-z0-9_]/g, '')
                    .replace(/_+/g, '_')
                    .replace(/^_+|_+$/g, '');
            };

            const showStringsStatus = function(type, message) {
                $status
                    .removeClass('is-success is-error is-warning')
                    .addClass('is-' + type)
                    .html(esc(message))
                    .fadeIn(150);
            };

            const collectRows = function() {
                const rows = [];
                $tbody.find('.plu-string-row').each(function() {
                    const $row = $(this);
                    const id = sanitizeId($row.find('.plu-str-id-input').val());
                    const langs = {};
                    $row.find('.plu-str-lang-input').each(function() {
                        const slug = String($(this).attr('data-lang') || '');
                        if (slug) {
                            langs[slug] = String($(this).val() || '');
                        }
                    });
                    rows.push({ id: id, langs: langs });
                });
                return rows;
            };

            // Обновление кнопок быстрого копирования при редактировании ID ключа
            $tbody.on('input', '.plu-str-id-input', function() {
                const $row = $(this).closest('.plu-string-row');
                const cleanId = sanitizeId($(this).val());
                const shortcodeVal = cleanId ? `[plu_str id="${cleanId}"]` : '[plu_str id="..."]';
                const metaVal = cleanId ? `plu_str_${cleanId}` : 'plu_str_...';

                const $scBtn = $row.find('.plu-copy-badge[data-copy-type="shortcode"]');
                $scBtn.attr('data-copy-value', shortcodeVal).find('code').text(shortcodeVal);

                const $metaBtn = $row.find('.plu-copy-badge[data-copy-type="meta"]');
                $metaBtn.attr('data-copy-value', metaVal).find('code').text(metaVal);
            });

            // Копирование в буфер обмена по клику на бейдж
            $tbody.on('click', '.plu-copy-badge', function(e) {
                e.preventDefault();
                const $btn = $(this);
                const textToCopy = String($btn.attr('data-copy-value') || '');
                if (!textToCopy || textToCopy.indexOf('...') !== -1) {
                    return;
                }

                const markCopied = function() {
                    const origHtml = $btn.data('orig-html') || $btn.html();
                    $btn.data('orig-html', origHtml);
                    $btn.addClass('is-copied').text('✓ Скопировано!');
                    setTimeout(function() {
                        $btn.removeClass('is-copied').html(origHtml);
                    }, 1400);
                };

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(textToCopy).then(markCopied).catch(function() {});
                } else {
                    const $tmp = $('<textarea>').val(textToCopy).appendTo('body').select();
                    try {
                        document.execCommand('copy');
                        markCopied();
                    } catch (err) {}
                    $tmp.remove();
                }
            });

            // Добавление новой строки
            $('#plu-add-string-row').on('click', function(e) {
                e.preventDefault();
                let langCellsHtml = '';
                langCols.forEach(function(col) {
                    const slug = esc(col.slug || '');
                    langCellsHtml += `
                        <td>
                            <input
                                type="text"
                                class="widefat plu-str-lang-input"
                                data-lang="${slug}"
                                value=""
                                placeholder="${slug.toUpperCase()}"
                            />
                        </td>
                    `;
                });

                const rowHtml = `
                    <tr class="plu-string-row">
                        <td>
                            <input type="text" class="regular-text plu-str-id-input" value="" placeholder="new_key" />
                        </td>
                        <td>
                            <div class="plu-copy-badges">
                                <button type="button" class="button button-small plu-copy-badge" data-copy-type="shortcode" data-copy-value="">
                                    &#128203; <code>[plu_str id="..."]</code>
                                </button>
                                <button type="button" class="button button-small plu-copy-badge" data-copy-type="meta" data-copy-value="">
                                    &#128203; <code>plu_str_...</code>
                                </button>
                            </div>
                        </td>
                        ${langCellsHtml}
                        <td class="plu-col-actions">
                            <button type="button" class="button button-small plu-remove-string-row" title="Удалить строку">&#10005;</button>
                        </td>
                    </tr>
                `;

                const $newRow = $(rowHtml);
                $tbody.append($newRow);
                $newRow.find('.plu-str-id-input').focus();
            });

            // Удаление строки
            $tbody.on('click', '.plu-remove-string-row', function(e) {
                e.preventDefault();
                $(this).closest('.plu-string-row').remove();
            });

            // Авто-перевод пустых ячеек через AI / GTX
            $('#plu-translate-empty-strings').on('click', function(e) {
                e.preventDefault();
                const $btn = $(this);
                const rows = collectRows();

                $btn.prop('disabled', true);
                $('#plu-save-ui-strings').prop('disabled', true);
                $spinner.addClass('is-active');
                $status.hide();

                $.ajax({
                    url: pluAdminData.ajax_url,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'plu_translate_ui_strings',
                        nonce: pluAdminData.nonce,
                        rows: JSON.stringify(rows)
                    },
                    success: function(response) {
                        if (response.success && response.data) {
                            const translations = response.data.translations || {};
                            const $domRows = $tbody.find('.plu-string-row');

                            Object.keys(translations).forEach(function(rowIdxStr) {
                                const idx = parseInt(rowIdxStr, 10);
                                const $row = $domRows.eq(idx);
                                if (!$row.length) {
                                    return;
                                }
                                const langMap = translations[rowIdxStr] || {};
                                Object.keys(langMap).forEach(function(langSlug) {
                                    const $input = $row.find(`.plu-str-lang-input[data-lang="${langSlug}"]`);
                                    if ($input.length && !String($input.val() || '').trim()) {
                                        $input.val(langMap[langSlug]).addClass('is-ai-filled');
                                    }
                                });
                            });

                            showStringsStatus('success', response.data.message || 'Перевод выполнен.');
                        } else {
                            const msg = (response.data && response.data.message) ? response.data.message : 'Ошибка авто-перевода.';
                            showStringsStatus('error', msg);
                        }
                    },
                    error: function(xhr, status, error) {
                        showStringsStatus('error', `Сетевая ошибка (${error || status}, HTTP ${xhr.status || 500})`);
                    },
                    complete: function() {
                        $btn.prop('disabled', false);
                        $('#plu-save-ui-strings').prop('disabled', false);
                        $spinner.removeClass('is-active');
                    }
                });
            });

            // Сохранение всех строк
            $('#plu-save-ui-strings').on('click', function(e) {
                e.preventDefault();
                const $btn = $(this);
                const rows = collectRows();

                // Нормализуем ID в DOM
                $tbody.find('.plu-str-id-input').each(function() {
                    $(this).val(sanitizeId($(this).val())).trigger('input');
                });

                $btn.prop('disabled', true);
                $('#plu-translate-empty-strings').prop('disabled', true);
                $spinner.addClass('is-active');
                $status.hide();

                $.ajax({
                    url: pluAdminData.ajax_url,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'plu_save_ui_strings',
                        nonce: pluAdminData.nonce,
                        rows: JSON.stringify(rows)
                    },
                    success: function(response) {
                        if (response.success && response.data) {
                            showStringsStatus('success', response.data.message || 'Строки успешно сохранены.');
                        } else {
                            const msg = (response.data && response.data.message) ? response.data.message : 'Ошибка сохранения.';
                            showStringsStatus('error', msg);
                        }
                    },
                    error: function(xhr, status, error) {
                        showStringsStatus('error', `Сетевая ошибка (${error || status}, HTTP ${xhr.status || 500})`);
                    },
                    complete: function() {
                        $btn.prop('disabled', false);
                        $('#plu-translate-empty-strings').prop('disabled', false);
                        $spinner.removeClass('is-active');
                    }
                });
            });
        }

        // ============================================================
        // 6. Логика вкладки «🔢 Пагинация и Loop» (Live Preview + Copy)
        // ============================================================
        const $pgBox = $('#plu-pagination-admin-box');
        if ($pgBox.length) {
            const $preview = $('#plu-pg-live-preview');
            const $previewWrap = $('#plu-preview-pg-wrap');
            const $previewStatus = $('#plu-preview-pg-status');
            const $previewRmBtn = $('#plu-preview-rm-btn');

            // Копирование шорткодов из шпаргалки в 1 клик (работает во всех вкладках)
            $(document).on('click', '.plu-pg-copy-btn', function(e) {
                e.preventDefault();
                const $btn = $(this);
                const textToCopy = String($btn.attr('data-copy-value') || '');
                if (!textToCopy) {
                    return;
                }

                const markCopied = function() {
                    const origHtml = $btn.data('orig-html') || $btn.html();
                    $btn.data('orig-html', origHtml);
                    $btn.addClass('is-copied').text('✓ Скопировано!');
                    setTimeout(function() {
                        $btn.removeClass('is-copied').html(origHtml);
                    }, 1400);
                };

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(textToCopy).then(markCopied).catch(function() {});
                } else {
                    const $tmp = $('<textarea>').val(textToCopy).appendTo('body').select();
                    try {
                        document.execCommand('copy');
                        markCopied();
                    } catch (err) {}
                    $tmp.remove();
                }
            });

            // Обновление CSS-переменных Live Preview в реальном времени
            $pgBox.on('input change', '.plu-pg-live-input', function() {
                const cssVar = String($(this).attr('data-css-var') || '');
                const unit = String($(this).attr('data-css-unit') || '');
                const val = String($(this).val() || '');
                if (cssVar && $preview.length) {
                    $preview.get(0).style.setProperty(cssVar, val + unit);
                }
            });

            // Выравнивание
            $('#plu_pg_align').on('change', function() {
                const align = String($(this).val() || 'center');
                $previewWrap.removeClass('plu-align-left plu-align-center plu-align-right').addClass('plu-align-' + align);
            });

            // Стрелки
            $('#plu_pg_show_arrows').on('change', function() {
                $pgBox.find('.plu-preview-arrow').toggle($(this).is(':checked'));
            });

            // Статус
            $('#plu_pg_show_status').on('change', function() {
                $previewStatus.toggle($(this).is(':checked'));
            });

            // Стиль кнопки [plu_read_more]
            $('#plu_rm_style').on('change', function() {
                const style = String($(this).val() || 'outline');
                $previewRmBtn.removeClass('plu-rm-style-solid plu-rm-style-outline plu-rm-style-link').addClass('plu-rm-style-' + style);
            });
        }

        // ============================================================
        // 7. Логика вкладки «🗂 Пакетный перевод и Ревизия архива»
        // ============================================================
        const $bulkBox = $('#plu-bulk-archive-box');
        if ($bulkBox.length) {
            const $scanBtn = $('#plu-bulk-scan-btn');
            const $scanSpinner = $('#plu-bulk-scan-spinner');
            const $catStatusBar = $('#plu-bulk-category-status-bar');
            const $tbody = $('#plu-bulk-tbody');
            const $selectAllCb = $('#plu-bulk-select-all');
            const $startBtn = $('#plu-bulk-start-btn');
            const $stopBtn = $('#plu-bulk-stop-btn');
            const $summaryCounter = $('#plu-bulk-summary-counter');
            const $scanStats = $('#plu-bulk-scan-stats');
            const $progressWrap = $('#plu-bulk-progress-wrap');
            const $progressLabel = $('#plu-bulk-progress-label');
            const $progressPercent = $('#plu-bulk-progress-percent');
            const $progressBar = $('#plu-bulk-progress-bar');
            const $processLog = $('#plu-bulk-process-log');
            const $logToolbar = $('#plu-bulk-log-toolbar');
            const $clearLogBtn = $('#plu-bulk-clear-log-btn');
            const $autoHideCb = $('#plu-bulk-auto-hide-done');
            const $hideDoneBtn = $('#plu-bulk-hide-done-btn');
            let lastScanCounts = null;

            let activeLangs = [];
            try {
                activeLangs = JSON.parse($bulkBox.attr('data-langs') || '["uk","en","ru"]');
            } catch (e) {
                activeLangs = ['uk', 'en', 'ru'];
            }

            let isBulkRunning = false;
            let stopRequested = false;
            let pauseTimer = null;

            const renderCategoryStatusBar = function(catInfo) {
                if (!catInfo || !catInfo.cat_id) {
                    $catStatusBar.hide().empty();
                    return;
                }

                const parts = [];
                const matrix = catInfo.matrix || {};

                activeLangs.forEach(function(langSlug) {
                    const lUpper = esc(langSlug.toUpperCase());
                    const item = matrix[langSlug];
                    if (item && item.exists) {
                        parts.push(`<span class="plu-cat-lang-pill is-ok"><strong>${lUpper}</strong> ${esc(item.name)} (ID: ${esc(item.term_id)})</span>`);
                    } else {
                        parts.push(`<span class="plu-cat-lang-pill is-missing"><strong>${lUpper}</strong> &#10060; Нет связанной рубрики</span>`);
                    }
                });

                let fixBtnHtml = '';
                if (catInfo.has_missing) {
                    fixBtnHtml = `
                        <button type="button" class="button button-secondary plu-bulk-fix-cat-btn" data-cat-id="${esc(catInfo.cat_id)}">
                            &#10133; Создать/связать недостающие языковые рубрики в 1 клик
                        </button>
                    `;
                }

                $catStatusBar.html(`
                    <div class="plu-cat-bar-inner">
                        <div class="plu-cat-bar-pills">
                            <strong>Связи рубрики в Polylang:</strong> ${parts.join(' <span class="plu-cat-sep">|</span> ')}
                        </div>
                        <div class="plu-cat-bar-actions">${fixBtnHtml}</div>
                    </div>
                `).fadeIn(150);
            };

            const formatDetectedLangLabel = function(rLang) {
                if (rLang === 'universal') {
                    return 'Универсальный';
                }
                if (rLang === 'cyrillic_neutral') {
                    return 'Кириллица';
                }
                return String(rLang || '').toUpperCase();
            };

            const renderLangCellHtml = function(langSlug, cellData) {
                if (!cellData || cellData.state === 'missing' || !cellData.post_id) {
                    return `<div class="plu-matrix-cell is-missing">&#10060; Нет поста</div>`;
                }

                const pid = esc(cellData.post_id);
                const rLangLabel = esc(formatDetectedLangLabel(cellData.real_lang));
                const editLink = cellData.edit_url
                    ? `<a href="${esc(cellData.edit_url)}" target="_blank" rel="noopener noreferrer" title="Открыть в редакторе">#${pid}</a>`
                    : `#${pid}`;
                const unlinkedBadge = cellData.is_unlinked
                    ? `<div class="plu-unlinked-hint">&#128279; Не связан (найден по слагу)</div>`
                    : '';

                if (cellData.state === 'empty') {
                    return `<div class="plu-matrix-cell is-empty">&#11093; ${editLink} (Пустой)${unlinkedBadge}</div>`;
                }
                if (cellData.state === 'mismatch') {
                    return `<div class="plu-matrix-cell is-mismatch">&#9888;&#65039; ${editLink} (Текст: <strong>${rLangLabel}!</strong>)${unlinkedBadge}</div>`;
                }
                return `<div class="plu-matrix-cell is-ok">&#9989; ${editLink} (${rLangLabel})${unlinkedBadge}</div>`;
            };

            const renderAuditRowHtml = function(row, markDoneSummary) {
                const postId = esc(row.post_id);
                const rowClass = (row.is_complete || markDoneSummary) ? 'plu-bulk-row is-complete' : (row.has_mismatch ? 'plu-bulk-row is-mismatch' : 'plu-bulk-row');
                const checkedAttr = (!row.is_complete && !markDoneSummary) ? 'checked="checked"' : '';

                // Колонка слага
                let slugHtml = `<div><code>${esc(row.current_slug)}</code></div>`;
                if (row.slug_needs_clean) {
                    slugHtml += `<div class="plu-slug-clean-badge">&#129529; Будет очищен &rarr; <code>${esc(row.clean_slug)}</code></div>`;
                }

                // Колонка Паспорт Polylang vs Реальный язык текста
                const assignedUpper = esc(String(row.assigned_lang || '?').toUpperCase());
                const realLabel = esc(formatDetectedLangLabel(row.real_lang || 'empty'));
                let langDetectHtml = '';
                if (row.real_lang === 'empty') {
                    langDetectHtml = `<span class="plu-detect-badge is-empty">Паспорт: <strong>${assignedUpper}</strong> &rarr; &#11093; Пустой</span>`;
                } else if (row.real_lang === 'universal') {
                    langDetectHtml = `<span class="plu-detect-badge is-ok">Паспорт: <strong>${assignedUpper}</strong> &rarr; &#9989; <strong>Универсальный</strong></span>`;
                } else if (row.real_lang === 'cyrillic_neutral') {
                    const isCyrOk = ['uk', 'ru', 'be', 'bg', 'sr', 'mk'].includes(String(row.assigned_lang || '').toLowerCase().slice(0, 2));
                    langDetectHtml = isCyrOk
                        ? `<span class="plu-detect-badge is-ok">Паспорт: <strong>${assignedUpper}</strong> &rarr; &#9989; <strong>Кириллица</strong></span>`
                        : `<span class="plu-detect-badge is-mismatch">Паспорт: <strong>${assignedUpper}</strong> &rarr; &#9888;&#65039; <strong>Кириллица!</strong></span>`;
                } else if (row.assigned_lang !== row.real_lang) {
                    langDetectHtml = `<span class="plu-detect-badge is-mismatch">Паспорт: <strong>${assignedUpper}</strong> &rarr; &#9888;&#65039; Реальный текст: <strong>${realLabel}!</strong></span>`;
                } else {
                    langDetectHtml = `<span class="plu-detect-badge is-ok">Паспорт: <strong>${assignedUpper}</strong> &rarr; &#9989; Текст: <strong>${realLabel}</strong></span>`;
                }

                if (row.has_unlinked) {
                    langDetectHtml += `<div class="plu-unlinked-alert">&#128279; Найдена несвязанная пара по слагу!</div>`;
                }

                let matrixCells = '';
                activeLangs.forEach(function(lSlug) {
                    const cell = (row.matrix && row.matrix[lSlug]) ? row.matrix[lSlug] : null;
                    matrixCells += `<td>${renderLangCellHtml(lSlug, cell)}</td>`;
                });

                const doneBanner = markDoneSummary
                    ? `<div class="plu-row-done-banner">&#9989; Готово: ${esc(markDoneSummary)}</div>`
                    : '';

                return `
                    <tr class="${rowClass}" id="plu-bulk-row-${postId}" data-post-id="${postId}">
                        <th scope="row" class="check-column">
                            <input type="checkbox" class="plu-bulk-row-cb" value="${postId}" ${checkedAttr} />
                        </th>
                        <td>
                            <div class="plu-bulk-post-meta">
                                <span class="plu-bulk-id">#${postId}</span>
                                <span class="plu-bulk-date">&#128197; ${esc(row.date)}</span>
                            </div>
                            <div class="plu-bulk-post-title">
                                <a href="${esc(row.edit_url)}" target="_blank" rel="noopener noreferrer"><strong>${esc(row.title)}</strong></a>
                            </div>
                            ${doneBanner}
                        </td>
                        <td>${slugHtml}</td>
                        <td>${langDetectHtml}</td>
                        ${matrixCells}
                        <td style="text-align:center;">
                            <button type="button" class="button button-small button-primary plu-bulk-single-run-btn" data-post-id="${postId}">
                                &#9889; Обработать
                            </button>
                        </td>
                    </tr>
                `;
            };

            const renderScanStats = function() {
                if (lastScanCounts) {
                    $scanStats.html(
                        `Всего групп в выборке: <strong>${esc(lastScanCounts.total_scanned)}</strong> | ` +
                        `Требуют действий: <strong>${esc(lastScanCounts.needs_action)}</strong> | ` +
                        `Пересортица: <strong>${esc(lastScanCounts.mismatch_only)}</strong> | ` +
                        `Полностью готовы: <strong>${esc(lastScanCounts.complete)}</strong>`
                    );
                } else {
                    $scanStats.empty();
                }

                const hasDoneRows = $tbody.find('.plu-bulk-row.is-complete').length > 0;
                if (hasDoneRows) {
                    $hideDoneBtn.show();
                } else {
                    $hideDoneBtn.hide();
                }
            };

            const renderEmptyDoneRow = function(msg) {
                const colSpan = 5 + activeLangs.length;
                $tbody.html(`
                    <tr>
                        <td colspan="${colSpan}" style="text-align:center; padding:24px; color:#1a7a28;">
                            <strong>${msg}</strong>
                        </td>
                    </tr>
                `);
                $selectAllCb.prop('checked', false);
            };

            const updateSelectedCounter = function() {
                const totalRows = $tbody.find('.plu-bulk-row-cb').length;
                const checkedCount = $tbody.find('.plu-bulk-row-cb:checked').length;
                const sessionLimit = parseInt($('#plu-bulk-session-limit').val(), 10) || 0;
                const effectiveRun = (sessionLimit > 0 && checkedCount > sessionLimit) ? sessionLimit : checkedCount;

                if (totalRows === 0) {
                    $summaryCounter.text('');
                    $startBtn.prop('disabled', true);
                    return;
                }

                $summaryCounter.html(
                    `Отмечено статей: <strong>${checkedCount}</strong> из ${totalRows}` +
                    (sessionLimit > 0 && checkedCount > sessionLimit ? ` (за этот запуск будет обработано: <strong>${effectiveRun}</strong> по лимиту сессии)` : '')
                );
                $startBtn.prop('disabled', checkedCount === 0 || isBulkRunning);
            };

            // Сканирование статей
            const runScan = function() {
                const sourceFilter = String($('#plu-bulk-source-filter').val() || 'all_posts');
                const yearFrom = parseInt($('#plu-bulk-year-from').val(), 10) || 0;
                const yearTo = parseInt($('#plu-bulk-year-to').val(), 10) || 0;
                const problemFilter = String($('#plu-bulk-problem-filter').val() || 'needs_action');

                $scanBtn.prop('disabled', true);
                $scanSpinner.addClass('is-active');

                $.ajax({
                    url: pluAdminData.ajax_url,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'plu_bulk_scan_category',
                        nonce: pluAdminData.nonce,
                        source_filter: sourceFilter,
                        year_from: yearFrom,
                        year_to: yearTo,
                        problem_filter: problemFilter
                    },
                    success: function(response) {
                        if (response.success && response.data) {
                            const d = response.data;
                            renderCategoryStatusBar(d.category_info || null);

                            lastScanCounts = d.counts ? Object.assign({}, d.counts) : null;

                            const rows = Array.isArray(d.rows) ? d.rows : [];
                            if (rows.length === 0) {
                                renderEmptyDoneRow('&#9989; По выбранным фильтрам статей, требующих внимания, не найдено!');
                            } else {
                                const htmlRows = rows.map(r => renderAuditRowHtml(r, '')).join('');
                                $tbody.html(htmlRows);
                                const allChecked = $tbody.find('.plu-bulk-row-cb:not(:checked)').length === 0;
                                $selectAllCb.prop('checked', allChecked);
                            }
                            renderScanStats();
                            updateSelectedCounter();
                        } else {
                            const msg = (response.data && response.data.message) ? response.data.message : 'Ошибка сканирования.';
                            alert(msg);
                        }
                    },
                    error: function(xhr, status, error) {
                        alert(`Сетевая ошибка сканирования: ${error || status} (HTTP ${xhr.status || 500})`);
                    },
                    complete: function() {
                        $scanBtn.prop('disabled', false);
                        $scanSpinner.removeClass('is-active');
                    }
                });
            };

            $scanBtn.on('click', function(e) {
                e.preventDefault();
                runScan();
            });

            // Создание и связывание недостающих рубрик в 1 клик
            $catStatusBar.on('click', '.plu-bulk-fix-cat-btn', function(e) {
                e.preventDefault();
                const $btn = $(this);
                const catId = parseInt($btn.attr('data-cat-id'), 10) || 0;
                if (!catId) {
                    return;
                }

                $btn.prop('disabled', true).text('⏳ Создание и перевод рубрик...');

                $.ajax({
                    url: pluAdminData.ajax_url,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'plu_bulk_fix_category_terms',
                        nonce: pluAdminData.nonce,
                        cat_id: catId
                    },
                    success: function(response) {
                        if (response.success && response.data) {
                            renderCategoryStatusBar(response.data.category_info || null);
                        } else {
                            const msg = (response.data && response.data.message) ? response.data.message : 'Ошибка создания рубрик.';
                            alert(msg);
                            $btn.prop('disabled', false).text('➕ Создать/связать недостающие языковые рубрики в 1 клик');
                        }
                    },
                    error: function(xhr, status, error) {
                        alert(`Сетевая ошибка: ${error || status}`);
                        $btn.prop('disabled', false);
                    }
                });
            });

            // Чекбокс «Выбрать все»
            $selectAllCb.on('change', function() {
                const isChecked = $(this).is(':checked');
                $tbody.find('.plu-bulk-row-cb').prop('checked', isChecked);
                updateSelectedCounter();
            });

            $tbody.on('change', '.plu-bulk-row-cb', updateSelectedCounter);
            $('#plu-bulk-session-limit').on('change', updateSelectedCounter);

            // Сбор текущих настроек пакетной обработки
            const collectBulkSettings = function() {
                const targetLangs = [];
                $bulkBox.find('.plu-bulk-target-lang-cb:checked').each(function() {
                    targetLangs.push(String($(this).val()));
                });

                return {
                    sessionLimit: parseInt($('#plu-bulk-session-limit').val(), 10) || 0,
                    delaySec: parseInt($('#plu-bulk-delay-sec').val(), 10) || 0,
                    engine: String($('#plu-bulk-engine').val() || 'gtx'),
                    overwritePolicy: String($('#plu-bulk-overwrite-policy').val() || 'smart'),
                    datePolicy: String($('#plu-bulk-date-policy').val() || 'preserve'),
                    targetLangs: targetLangs,
                    fixMismatch: $('#plu-bulk-fix-mismatch').is(':checked') ? 1 : 0,
                    cleanSlugs: $('#plu-bulk-clean-slugs').is(':checked') ? 1 : 0
                };
            };

            // Последовательная очередь обработки постов
            const runBulkQueue = function(postIds, index, cfg) {
                if (stopRequested || index >= postIds.length) {
                    isBulkRunning = false;
                    $stopBtn.hide();
                    $startBtn.prop('disabled', false);
                    $tbody.find('.plu-bulk-single-run-btn').prop('disabled', false);
                    updateSelectedCounter();

                    const finishMsg = stopRequested
                        ? `&#9208; Пакетная обработка остановлена пользователем (обработано ${index} из ${postIds.length}).`
                        : `&#127881; Пакетная сессия успешно завершена! Обработано статей: ${postIds.length}.`;
                    $progressLabel.html(finishMsg);
                    $processLog.prepend(`<div class="plu-log-item is-success"><strong>${finishMsg}</strong></div>`);
                    return;
                }

                const postId = postIds[index];
                const stepNum = index + 1;
                const totalNum = postIds.length;
                const pct = Math.round(((stepNum - 1) / totalNum) * 100);

                $progressPercent.text(pct + '%');
                $progressBar.css('width', pct + '%');
                $progressLabel.html(`&#9203; Обработка статьи #${esc(postId)} (${stepNum} из ${totalNum})...`);

                const $row = $(`#plu-bulk-row-${postId}`);
                $row.addClass('is-processing');
                $row.find('.plu-bulk-single-run-btn').prop('disabled', true).text('⏳ В работе...');

                $.ajax({
                    url: pluAdminData.ajax_url,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'plu_bulk_process_post',
                        nonce: pluAdminData.nonce,
                        post_id: postId,
                        engine: cfg.engine,
                        overwrite_policy: cfg.overwritePolicy,
                        date_policy: cfg.datePolicy,
                        target_langs: cfg.targetLangs,
                        fix_language_mismatch: cfg.fixMismatch,
                        clean_same_slugs: cfg.cleanSlugs
                    },
                    success: function(response) {
                        if (response.success && response.data) {
                            const d = response.data;
                            const summaryText = d.summary || 'Готово';
                            const isComplete = !!(d.row && d.row.is_complete);
                            const wasIncomplete = !$row.hasClass('is-complete');

                            if (d.row && d.row.post_id) {
                                const newRowHtml = renderAuditRowHtml(d.row, summaryText);
                                $row.replaceWith(newRowHtml);
                            } else {
                                $row.removeClass('is-processing is-mismatch').addClass('is-complete');
                                $row.find('.plu-bulk-row-cb').prop('checked', false);
                                $row.find('.plu-bulk-single-run-btn').prop('disabled', false).text('✓ Готово');
                            }

                            if (isComplete && wasIncomplete && lastScanCounts) {
                                lastScanCounts.complete = (parseInt(lastScanCounts.complete, 10) || 0) + 1;
                                lastScanCounts.needs_action = Math.max(0, (parseInt(lastScanCounts.needs_action, 10) || 0) - 1);
                            }

                            $logToolbar.show();
                            $processLog.prepend(
                                `<div class="plu-log-item is-success">` +
                                `<strong>&#9989; Статья #${esc(postId)} (${esc(d.date)}, slug: <code>${esc(d.clean_slug)}</code>):</strong> ${esc(summaryText)}` +
                                `</div>`
                            );

                            if (isComplete && $autoHideCb.is(':checked')) {
                                setTimeout(function() {
                                    const $targetRow = $(`#plu-bulk-row-${postId}`);
                                    if ($targetRow.length) {
                                        $targetRow.fadeOut(300, function() {
                                            $(this).remove();
                                            if ($tbody.find('.plu-bulk-row').length === 0) {
                                                renderEmptyDoneRow('&#9989; Все отмеченные статьи в текущем списке успешно обработаны!');
                                            }
                                            renderScanStats();
                                            updateSelectedCounter();
                                        });
                                    }
                                }, 1200);
                            } else {
                                renderScanStats();
                                updateSelectedCounter();
                            }
                        } else {
                            const errMsg = (response.data && response.data.message) ? response.data.message : 'Ошибка обработки статьи.';
                            $row.removeClass('is-processing').addClass('is-error');
                            $row.find('.plu-bulk-single-run-btn').prop('disabled', false).text('⚡ Повторить');
                            $logToolbar.show();
                            $processLog.prepend(
                                `<div class="plu-log-item is-error"><strong>&#10060; Статья #${esc(postId)}:</strong> ${esc(errMsg)}</div>`
                            );
                        }
                    },
                    error: function(xhr, status, error) {
                        $row.removeClass('is-processing').addClass('is-error');
                        $row.find('.plu-bulk-single-run-btn').prop('disabled', false).text('⚡ Повторить');
                        $logToolbar.show();
                        $processLog.prepend(
                            `<div class="plu-log-item is-error"><strong>&#10060; Статья #${esc(postId)}:</strong> Сетевая ошибка (${esc(error || status)}, HTTP ${esc(xhr.status || 500)})</div>`
                        );
                    },
                    complete: function() {
                        const donePct = Math.round((stepNum / totalNum) * 100);
                        $progressPercent.text(donePct + '%');
                        $progressBar.css('width', donePct + '%');

                        if (stopRequested || stepNum >= totalNum) {
                            runBulkQueue(postIds, index + 1, cfg);
                            return;
                        }

                        if (cfg.delaySec > 0) {
                            $progressLabel.html(`&#9201; Пауза ${esc(cfg.delaySec)} сек перед следующей статьей (${stepNum} из ${totalNum} готово)...`);
                            pauseTimer = setTimeout(function() {
                                runBulkQueue(postIds, index + 1, cfg);
                            }, cfg.delaySec * 1000);
                        } else {
                            runBulkQueue(postIds, index + 1, cfg);
                        }
                    }
                });
            };

            // Запуск пакетной обработки отмеченных строк
            $startBtn.on('click', function(e) {
                e.preventDefault();
                if (isBulkRunning) {
                    return;
                }

                const cfg = collectBulkSettings();
                if (cfg.targetLangs.length === 0) {
                    alert('Выберите хотя бы один целевой язык.');
                    return;
                }

                let selectedIds = [];
                $tbody.find('.plu-bulk-row-cb:checked').each(function() {
                    const pid = parseInt($(this).val(), 10);
                    if (pid > 0) {
                        selectedIds.push(pid);
                    }
                });

                if (selectedIds.length === 0) {
                    alert('Отметьте хотя бы одну статью в таблице.');
                    return;
                }

                if (cfg.sessionLimit > 0 && selectedIds.length > cfg.sessionLimit) {
                    selectedIds = selectedIds.slice(0, cfg.sessionLimit);
                }

                isBulkRunning = true;
                stopRequested = false;
                $startBtn.prop('disabled', true);
                $stopBtn.show();
                $progressWrap.show();
                $logToolbar.show();
                $processLog.empty().show();

                runBulkQueue(selectedIds, 0, cfg);
            });

            // Остановка пакетной очереди
            $stopBtn.on('click', function(e) {
                e.preventDefault();
                stopRequested = true;
                if (pauseTimer) {
                    clearTimeout(pauseTimer);
                    pauseTimer = null;
                }
                $progressLabel.html('&#9208; Остановка после завершения текущего запроса...');
            });

            // Быстрая кнопка обработки одной строки прямо из таблицы
            $tbody.on('click', '.plu-bulk-single-run-btn', function(e) {
                e.preventDefault();
                if (isBulkRunning) {
                    return;
                }
                const postId = parseInt($(this).attr('data-post-id'), 10) || 0;
                if (!postId) {
                    return;
                }

                const cfg = collectBulkSettings();
                if (cfg.targetLangs.length === 0) {
                    alert('Выберите хотя бы один целевой язык.');
                    return;
                }

                isBulkRunning = true;
                stopRequested = false;
                $startBtn.prop('disabled', true);
                $stopBtn.show();
                $progressWrap.show();
                $logToolbar.show();
                $processLog.show();

                runBulkQueue([postId], 0, cfg);
            });

            // Ручное скрытие выполненных (✅) строк из таблицы
            $hideDoneBtn.on('click', function(e) {
                e.preventDefault();
                const $doneRows = $tbody.find('.plu-bulk-row.is-complete');
                if ($doneRows.length === 0) {
                    $hideDoneBtn.hide();
                    return;
                }
                $doneRows.fadeOut(300, function() {
                    $(this).remove();
                    if ($tbody.find('.plu-bulk-row').length === 0) {
                        renderEmptyDoneRow('&#9989; Все выполненные статьи скрыты из таблицы!');
                    }
                    renderScanStats();
                    updateSelectedCounter();
                });
            });

            // Очистка лога выполнения
            $clearLogBtn.on('click', function(e) {
                e.preventDefault();
                $processLog.empty().hide();
                $logToolbar.hide();
            });
        }

        // ============================================================
        // 8. Живое превью (Live Preview) переключателя языков [plu_lang_switcher]
        // ============================================================
        const $lsBox = $('#plu-lang-switcher-box');
        if ($lsBox.length) {
            const $lsPreview = $('#plu-ls-live-preview');
            const $lsDesktopNav = $('#plu-ls-preview-desktop');
            const $lsMobileNav = $('#plu-ls-preview-mobile');

            // Универсальный клик-копирование, если открыта вкладка menus (где нет $pgBox)
            if (!$('#plu-pagination-admin-box').length) {
                $lsBox.on('click', '.plu-pg-copy-btn', function(e) {
                    e.preventDefault();
                    const $btn = $(this);
                    const textToCopy = String($btn.attr('data-copy-value') || '');
                    if (!textToCopy) {
                        return;
                    }
                    const markCopied = function() {
                        const origHtml = $btn.data('orig-html') || $btn.html();
                        $btn.data('orig-html', origHtml);
                        $btn.addClass('is-copied').text('✓ Скопировано!');
                        setTimeout(function() {
                            $btn.removeClass('is-copied').html(origHtml);
                        }, 1400);
                    };
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(textToCopy).then(markCopied).catch(function() {});
                    } else {
                        const $tmp = $('<textarea>').val(textToCopy).appendTo('body').select();
                        try {
                            document.execCommand('copy');
                            markCopied();
                        } catch (err) {}
                        $tmp.remove();
                    }
                });
            }

            const renderLangSwitcherPreview = function() {
                const style = String($('#plu_ls_style').val() || 'classic');
                const useMobileShort = $('#plu_ls_use_mobile_short').is(':checked');
                const hideCurrent = $('#plu_ls_hide_current').is(':checked');
                const separator = String($('#plu_ls_separator').val() || '');
                const activeColor = String($('#plu_ls_active_color').val() || '#006d77');
                const textColor = String($('#plu_ls_text_color').val() || '#334155');

                if ($lsPreview.length) {
                    $lsPreview.get(0).style.setProperty('--plu-ls-active', activeColor);
                    $lsPreview.get(0).style.setProperty('--plu-ls-text', textColor);
                }

                const items = [];
                $lsBox.find('.plu-ls-lang-row').each(function(idx) {
                    const $row = $(this);
                    const slug = String($row.attr('data-lang-slug') || '');
                    const isCurrent = (idx === 0);
                    if (hideCurrent && isCurrent) {
                        return;
                    }

                    const showFlag = $row.find('.plu-ls-live-flag').is(':checked');
                    const flagHtmlRaw = String($row.find('.plu-ls-admin-flag').attr('data-flag-html') || '');
                    const desktopLabel = String($row.find('.plu-ls-live-desktop-label').val() || slug.toUpperCase());
                    const mobileLabel = String($row.find('.plu-ls-live-mobile-label').val() || slug.toUpperCase());

                    let flagPart = '';
                    if (showFlag) {
                        if (flagHtmlRaw) {
                            flagPart = flagHtmlRaw;
                        } else {
                            flagPart = `<span class="plu-ls-flag plu-ls-flag-fallback" aria-hidden="true">&#127987;</span>`;
                        }
                    }

                    const activeClass = isCurrent ? ' is-active' : '';
                    const mobileSpan = useMobileShort ? `<span class="plu-ls-label-mobile">${esc(mobileLabel)}</span>` : '';

                    items.push(
                        `<a href="#preview-${esc(slug)}" onclick="return false;" class="plu-ls-link${activeClass}">` +
                        `${flagPart}<span class="plu-ls-label-desktop">${esc(desktopLabel)}</span>${mobileSpan}` +
                        `</a>`
                    );
                });

                const sepHtml = (style === 'classic' && separator.trim() !== '')
                    ? `<span class="plu-ls-sep" aria-hidden="true">${esc(separator)}</span>`
                    : '';
                const innerHtml = items.join(sepHtml);

                $lsDesktopNav
                    .removeClass('plu-ls-style-classic plu-ls-style-pills plu-ls-style-minimal')
                    .addClass('plu-ls-style-' + style)
                    .html(innerHtml);

                $lsMobileNav
                    .removeClass('plu-ls-style-classic plu-ls-style-pills plu-ls-style-minimal plu-ls-has-mobile-short')
                    .addClass('plu-ls-style-' + style + (useMobileShort ? ' plu-ls-has-mobile-short' : ''))
                    .html(innerHtml);
            };

            $lsBox.on('input change', '.plu-ls-live-flag, .plu-ls-live-desktop-label, .plu-ls-live-mobile-label, .plu-ls-live-ctrl', renderLangSwitcherPreview);
            renderLangSwitcherPreview();
        }

    });
})(jQuery);
