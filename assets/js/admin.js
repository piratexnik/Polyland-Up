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

            // Копирование шорткодов из шпаргалки в 1 клик
            $pgBox.on('click', '.plu-pg-copy-btn', function(e) {
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

    });
})(jQuery);
