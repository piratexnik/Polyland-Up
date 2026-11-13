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
        // 5. Логика вкладки «📦 Каталог SCaT (БД)»
        // ============================================================
        const $scatBox = $('#plu-scat-catalog-box');
        if ($scatBox.length) {
            const $scatSourceSelect = $('#plu_scat_source_lang');
            const $scatRefreshBtn = $('#plu-scat-refresh-stats');
            const $scatStatsSpinner = $('#plu-scat-stats-spinner');
            const $scatTbody = $('#plu-scat-stats-tbody');
            const $scatTfoot = $('#plu-scat-stats-tfoot');

            const $scatStartBtn = $('#plu-scat-start');
            const $scatStopBtn = $('#plu-scat-stop');
            const $scatSpinner = $('#plu-scat-spinner');
            const $scatProgressWrap = $('#plu-scat-progress-wrap');
            const $scatProgressLabel = $('#plu-scat-progress-label');
            const $scatProgressPercent = $('#plu-scat-progress-percent');
            const $scatProgressBar = $('#plu-scat-progress-bar');
            const $scatProgressCounters = $('#plu-scat-progress-counters');
            const $scatLog = $('#plu-scat-log');

            const fmtNum = function(num) {
                const n = parseInt(num, 10) || 0;
                return n.toLocaleString('ru-RU');
            };

            const fmtCountCell = function(count, total) {
                const c = parseInt(count, 10) || 0;
                const t = parseInt(total, 10) || 0;
                const pct = t > 0 ? Math.round((c / t) * 100) : 0;
                return `${fmtNum(c)} (${pct}%)`;
            };

            const fmtDescrCell = function(count, unique, total) {
                const c = parseInt(count, 10) || 0;
                const u = parseInt(unique, 10) || 0;
                const t = parseInt(total, 10) || 0;
                const pct = t > 0 ? Math.round((c / t) * 100) : 0;
                return `${fmtNum(c)} / ${pct}% (уник.: ${fmtNum(u)})`;
            };

            // Авто-отключение чекбокса языка, выбранного как исходный
            const syncScatTargetLangs = function() {
                const srcLang = String($scatSourceSelect.val() || 'ru');
                $scatBox.find('.plu-scat-target-lang-cb').each(function() {
                    const $cb = $(this);
                    const $label = $cb.closest('.plu-menu-lang-item');
                    if (String($cb.val()) === srcLang) {
                        $cb.prop('checked', false).prop('disabled', true);
                        $label.addClass('is-disabled');
                    } else {
                        $cb.prop('disabled', false);
                        $label.removeClass('is-disabled');
                    }
                });
            };

            $scatSourceSelect.on('change', syncScatTargetLangs);
            syncScatTargetLangs();

            // Обновление живой таблицы статистики
            const refreshScatStats = function() {
                $scatRefreshBtn.prop('disabled', true);
                $scatStatsSpinner.addClass('is-active');

                $.ajax({
                    url: pluAdminData.ajax_url,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'plu_scat_stats',
                        nonce: pluAdminData.nonce
                    },
                    success: function(response) {
                        if (response.success && response.data && Array.isArray(response.data.tables)) {
                            const rowsHtml = response.data.tables.map(function(row) {
                                const total = parseInt(row.total, 10) || 0;
                                if (!row.exists) {
                                    return `
                                        <tr data-scat-table-row="${esc(row.table)}">
                                            <td>
                                                <strong><code>${esc(row.table)}</code></strong>
                                                <div style="font-size:12px; color:#50575e;">${esc(row.label)}</div>
                                            </td>
                                            <td colspan="7" style="color:#8c8f94; font-style:italic;">Таблица не найдена в базе данных</td>
                                        </tr>
                                    `;
                                }
                                return `
                                    <tr data-scat-table-row="${esc(row.table)}">
                                        <td>
                                            <strong><code>${esc(row.table)}</code></strong>
                                            <div style="font-size:12px; color:#50575e;">${esc(row.label)}</div>
                                        </td>
                                        <td class="num"><strong>${esc(fmtNum(total))}</strong></td>
                                        <td class="num">${esc(fmtCountCell(row.names.ru, total))}</td>
                                        <td class="num">${esc(fmtCountCell(row.names.uk, total))}</td>
                                        <td class="num">${esc(fmtCountCell(row.names.en, total))}</td>
                                        <td class="num">${esc(fmtDescrCell(row.descrs.ru, row.descrs_unique.ru, total))}</td>
                                        <td class="num">${esc(fmtDescrCell(row.descrs.uk, row.descrs_unique.uk, total))}</td>
                                        <td class="num">${esc(fmtDescrCell(row.descrs.en, row.descrs_unique.en, total))}</td>
                                    </tr>
                                `;
                            }).join('');

                            $scatTbody.html(rowsHtml);

                            const totals = response.data.totals || {};
                            const grandTotal = parseInt(totals.total, 10) || 0;
                            const names = totals.names || {};
                            const descrs = totals.descrs || {};
                            const uniq = totals.descrs_unique || {};

                            $scatTfoot.html(`
                                <tr class="plu-scat-total-row">
                                    <th><strong>ИТОГО</strong></th>
                                    <th class="num"><strong>${esc(fmtNum(grandTotal))}</strong></th>
                                    <th class="num"><strong>${esc(fmtCountCell(names.ru, grandTotal))}</strong></th>
                                    <th class="num"><strong>${esc(fmtCountCell(names.uk, grandTotal))}</strong></th>
                                    <th class="num"><strong>${esc(fmtCountCell(names.en, grandTotal))}</strong></th>
                                    <th class="num"><strong>${esc(fmtDescrCell(descrs.ru, uniq.ru, grandTotal))}</strong></th>
                                    <th class="num"><strong>${esc(fmtDescrCell(descrs.uk, uniq.uk, grandTotal))}</strong></th>
                                    <th class="num"><strong>${esc(fmtDescrCell(descrs.en, uniq.en, grandTotal))}</strong></th>
                                </tr>
                            `);
                        }
                    },
                    complete: function() {
                        $scatRefreshBtn.prop('disabled', false);
                        $scatStatsSpinner.removeClass('is-active');
                    }
                });
            };

            $scatRefreshBtn.on('click', function(e) {
                e.preventDefault();
                refreshScatStats();
            });

            // Пакетный конвейер перевода каталога SCaT
            let isRunning = false;
            let stopRequested = false;

            const setScatRunning = function(running) {
                isRunning = running;
                $scatStartBtn.prop('disabled', running);
                $scatStopBtn.prop('disabled', !running);
                $scatBox.find('.plu-scat-table-cb, #plu_scat_source_lang, .plu-scat-target-lang-cb, #plu_scat_fields_mode, #plu_scat_overwrite_mode, #plu_scat_engine, #plu_scat_batch_size')
                    .prop('disabled', running);
                if (!running) {
                    // Восстанавливаем отключенное состояние отсутствующих таблиц и исходного языка
                    $scatBox.find('.plu-scat-check-card.is-disabled .plu-scat-table-cb').prop('disabled', true);
                    syncScatTargetLangs();
                    $scatSpinner.removeClass('is-active');
                } else {
                    $scatSpinner.addClass('is-active');
                }
            };

            const appendScatLog = function(html, levelClass) {
                const cls = levelClass ? ` ${levelClass}` : '';
                $scatLog.show().append(`<div class="plu-log-item${cls}">${html}</div>`);
                $scatLog.scrollTop($scatLog[0].scrollHeight);
            };

            const updateScatProgress = function(doneRows, totalRows, labelText, totalUniqueDone, totalUpdatedRows, totalSavedRequests) {
                let pct = 0;
                if (totalRows > 0) {
                    pct = Math.min(100, Math.max(0, Math.round((doneRows / totalRows) * 100)));
                }
                $scatProgressWrap.show();
                $scatProgressLabel.text(labelText);
                $scatProgressPercent.text(`${pct}%`);
                $scatProgressBar.css('width', `${pct}%`);
                $scatProgressCounters.html(
                    `Позиций в БД: <strong>${esc(fmtNum(doneRows))}</strong> / ${esc(fmtNum(totalRows))} &bull; ` +
                    `Переведено уник. текстов: <strong>${esc(fmtNum(totalUniqueDone))}</strong> &bull; ` +
                    `Обновлено строк: <strong>${esc(fmtNum(totalUpdatedRows))}</strong> &bull; ` +
                    `Сэкономлено запросов (дедупликация): <strong>${esc(fmtNum(totalSavedRequests))}</strong>`
                );
            };

            $scatStopBtn.on('click', function(e) {
                e.preventDefault();
                if (!isRunning) {
                    return;
                }
                stopRequested = true;
                $scatStopBtn.prop('disabled', true);
                $scatProgressLabel.text('⏸ Остановка после завершения текущего шага...');
            });

            $scatStartBtn.on('click', function(e) {
                e.preventDefault();
                if (isRunning) {
                    return;
                }

                const tables = [];
                $scatBox.find('.plu-scat-table-cb:checked:not(:disabled)').each(function() {
                    tables.push(String($(this).val()));
                });

                const sourceLang = String($scatSourceSelect.val() || 'ru');
                const targetLangs = [];
                $scatBox.find('.plu-scat-target-lang-cb:checked:not(:disabled)').each(function() {
                    targetLangs.push(String($(this).val()));
                });

                const fieldsMode = String($('#plu_scat_fields_mode').val() || 'descr_only');
                const overwriteMode = String($('#plu_scat_overwrite_mode').val() || 'empty_only');
                const engine = String($('#plu_scat_engine').val() || 'gtx');
                const batchSize = parseInt($('#plu_scat_batch_size').val(), 10) || 35;

                $scatLog.empty().show();

                if (tables.length === 0) {
                    appendScatLog('&#9888; Выберите хотя бы одну таблицу каталога для обработки.', 'is-warning');
                    return;
                }

                if (targetLangs.length === 0) {
                    appendScatLog('&#9888; Выберите хотя бы один целевой язык.', 'is-warning');
                    return;
                }

                stopRequested = false;
                setScatRunning(true);
                updateScatProgress(0, 0, 'Анализ таблиц и подсчет уникальных текстов…', 0, 0, 0);

                // Шаг 0: планирование подзадач
                $.ajax({
                    url: pluAdminData.ajax_url,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'plu_scat_translate_batch',
                        nonce: pluAdminData.nonce,
                        phase: 'plan',
                        tables: tables,
                        source_lang: sourceLang,
                        target_langs: targetLangs,
                        fields_mode: fieldsMode,
                        overwrite_mode: overwriteMode
                    },
                    success: function(planResp) {
                        if (!planResp.success || !planResp.data) {
                            const msg = (planResp.data && planResp.data.message) ? planResp.data.message : 'Ошибка планирования задач.';
                            appendScatLog(`&#10060; ${esc(msg)}`, 'is-error');
                            setScatRunning(false);
                            return;
                        }

                        const plan = planResp.data;
                        const tasks = Array.isArray(plan.tasks) ? plan.tasks : [];
                        const grandTotalRows = parseInt(plan.grand_total_rows, 10) || 0;
                        const grandTotalUnique = parseInt(plan.grand_total_unique, 10) || 0;

                        if (grandTotalRows === 0 || tasks.length === 0) {
                            updateScatProgress(1, 1, 'Все выбранные поля уже переведены!', 0, 0, 0);
                            appendScatLog('&#9989; Все выбранные поля в отмеченных таблицах уже заполнены. Очередь пуста.', 'is-success');
                            setScatRunning(false);
                            return;
                        }

                        const estSavedPct = grandTotalRows > 0
                            ? Math.max(0, Math.round(((grandTotalRows - grandTotalUnique) / grandTotalRows) * 100))
                            : 0;

                        appendScatLog(
                            `&#128203; <strong>План сформирован:</strong> задач: ${esc(tasks.length)} &bull; ` +
                            `записей к обработке: <strong>${esc(fmtNum(grandTotalRows))}</strong> &bull; ` +
                            `уникальных текстов: <strong>${esc(fmtNum(grandTotalUnique))}</strong> (экономия дедупликации ~${esc(estSavedPct)}%).`,
                            ''
                        );

                        let taskIdx = 0;
                        let tableIndex = 0;
                        let lastId = 0;
                        let processedHashes = new Set();
                        let completedPrevRows = 0;
                        let currentTaskDone = 0;
                        let totalUniqueDone = 0;
                        let totalUpdatedRows = 0;
                        let totalSavedRequests = 0;
                        let stepNo = 0;

                        const runNextBatch = function() {
                            if (stopRequested) {
                                appendScatLog('&#9209; Перевод остановлен пользователем. Уже переведенные батчи сохранены в БД.', 'is-warning');
                                $scatProgressLabel.text('⏹ Остановлено пользователем');
                                setScatRunning(false);
                                refreshScatStats();
                                return;
                            }

                            while (taskIdx < tasks.length && (parseInt(tasks[taskIdx].total_rows, 10) || 0) === 0) {
                                const skippedTask = tasks[taskIdx];
                                appendScatLog(
                                    `&#9197; Пропуск <strong>${esc(skippedTask.lang_short)} (${esc(skippedTask.src_col)} &rarr; ${esc(skippedTask.dst_col)})</strong>: нет записей для обработки.`,
                                    ''
                                );
                                taskIdx++;
                                tableIndex = 0;
                                lastId = 0;
                                processedHashes = new Set();
                                currentTaskDone = 0;
                            }

                            if (taskIdx >= tasks.length) {
                                updateScatProgress(grandTotalRows, grandTotalRows, '🎉 Перевод каталога завершен!', totalUniqueDone, totalUpdatedRows, totalSavedRequests);
                                appendScatLog(
                                    `&#127881; <strong>Готово!</strong> Переведено уникальных текстов: <strong>${esc(fmtNum(totalUniqueDone))}</strong>, ` +
                                    `обновлено записей в БД: <strong>${esc(fmtNum(totalUpdatedRows))}</strong>, ` +
                                    `сэкономлено запросов: <strong>${esc(fmtNum(totalSavedRequests))}</strong>.`,
                                    'is-success'
                                );
                                setScatRunning(false);
                                refreshScatStats();
                                return;
                            }

                            const task = tasks[taskIdx];
                            stepNo++;

                            const taskLabel = `${task.lang_short}: ${task.src_col} → ${task.dst_col} (шаг #${stepNo})`;
                            updateScatProgress(
                                completedPrevRows + currentTaskDone,
                                grandTotalRows,
                                `⏳ Обработка: ${taskLabel}…`,
                                totalUniqueDone,
                                totalUpdatedRows,
                                totalSavedRequests
                            );

                            $.ajax({
                                url: pluAdminData.ajax_url,
                                type: 'POST',
                                dataType: 'json',
                                data: {
                                    action: 'plu_scat_translate_batch',
                                    nonce: pluAdminData.nonce,
                                    phase: 'run',
                                    tables: tables,
                                    source_lang: sourceLang,
                                    target_lang: task.target_lang,
                                    field_type: task.field_type,
                                    overwrite_mode: overwriteMode,
                                    engine: engine,
                                    batch_size: batchSize,
                                    table_index: tableIndex,
                                    last_id: lastId,
                                    task_total_rows: task.total_rows,
                                    table_counts: task.table_counts,
                                    processed_hashes: Array.from(processedHashes).join(',')
                                },
                                success: function(batchResp) {
                                    if (!batchResp.success || !batchResp.data) {
                                        const errMsg = (batchResp.data && batchResp.data.message) ? batchResp.data.message : 'Ошибка пакетного перевода.';
                                        appendScatLog(`&#10060; <strong>Шаг #${esc(stepNo)} (${esc(task.lang_short)}):</strong> ${esc(errMsg)}`, 'is-error');
                                        setScatRunning(false);
                                        refreshScatStats();
                                        return;
                                    }

                                    const d = batchResp.data;
                                    if (Array.isArray(d.new_hashes)) {
                                        d.new_hashes.forEach(function(h) {
                                            processedHashes.add(String(h));
                                        });
                                    }

                                    const uCount = parseInt(d.translated_unique, 10) || 0;
                                    const rCount = parseInt(d.updated_rows, 10) || 0;
                                    const sCount = parseInt(d.saved_requests, 10) || 0;
                                    const emptyCount = parseInt(d.skipped_empty, 10) || 0;

                                    totalUniqueDone += uCount;
                                    totalUpdatedRows += rCount;
                                    totalSavedRequests += sCount;
                                    currentTaskDone = parseInt(d.subjob_done_rows, 10) || currentTaskDone;

                                    if (uCount > 0 || emptyCount > 0) {
                                        let samplesHtml = '';
                                        if (Array.isArray(d.samples) && d.samples.length > 0) {
                                            const items = d.samples.map(function(s) {
                                                const skuTag = s.sku ? `[${esc(s.sku)}] ` : '';
                                                return `<div><code>${skuTag}${esc(s.src)}</code> &rarr; <em>${esc(s.dst)}</em> <span style="color:#646970;">(&times;${esc(s.matches)})</span></div>`;
                                            }).join('');
                                            samplesHtml = `<div class="plu-log-note">${items}</div>`;
                                        }

                                        const emptyNote = emptyCount > 0 ? ` | пропущено пустых: ${esc(emptyCount)}` : '';
                                        appendScatLog(
                                            `<div><strong>&#9989; Шаг #${esc(stepNo)} [${esc(task.lang_short)} &bull; ${esc(task.src_col)}&rarr;${esc(task.dst_col)}]:</strong> ` +
                                            `переведено <strong>${esc(uCount)}</strong> уник. &rarr; обновлено <strong>${esc(rCount)}</strong> строк ` +
                                            `<span style="color:#1a7a28;">(экономия: ${esc(sCount)} дублей)</span> ` +
                                            `<span style="color:#646970;">(${esc(d.provider_label)}, ${esc(d.time_sec)} с${emptyNote})</span></div>${samplesHtml}`,
                                            'is-success'
                                        );
                                    }

                                    if (d.finished) {
                                        completedPrevRows += (parseInt(task.total_rows, 10) || 0);
                                        currentTaskDone = 0;
                                        taskIdx++;
                                        tableIndex = 0;
                                        lastId = 0;
                                        processedHashes = new Set();
                                    } else {
                                        tableIndex = parseInt(d.next_table_index, 10) || 0;
                                        lastId = parseInt(d.next_last_id, 10) || 0;
                                    }

                                    updateScatProgress(
                                        completedPrevRows + currentTaskDone,
                                        grandTotalRows,
                                        `⏳ Обработка: ${taskLabel}…`,
                                        totalUniqueDone,
                                        totalUpdatedRows,
                                        totalSavedRequests
                                    );

                                    runNextBatch();
                                },
                                error: function(xhr, status, error) {
                                    const serverMsg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
                                        ? xhr.responseJSON.data.message
                                        : '';
                                    const displayErr = serverMsg
                                        ? `${esc(serverMsg)} (HTTP ${esc(xhr.status || 500)})`
                                        : `Сетевая ошибка (${esc(error || status)}, HTTP ${esc(xhr.status || 500)})`;
                                    appendScatLog(`&#10060; <strong>Шаг #${esc(stepNo)}:</strong> ${displayErr}`, 'is-error');
                                    setScatRunning(false);
                                    refreshScatStats();
                                }
                            });
                        };

                        runNextBatch();
                    },
                    error: function(xhr, status, error) {
                        const displayErr = `Сетевая ошибка планирования (${esc(error || status)}, HTTP ${esc(xhr.status || 500)})`;
                        appendScatLog(`&#10060; ${displayErr}`, 'is-error');
                        setScatRunning(false);
                    }
                });
            });
        }
    });
})(jQuery);

