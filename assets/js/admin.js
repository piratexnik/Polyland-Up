/**
 * Polylang Up - Admin Script
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        const $btnTest = $('#plu-btn-test-api');
        const $spinner = $('#plu-api-test-spinner');
        const $resultBox = $('#plu-api-test-result');

        if (!$btnTest.length) {
            return;
        }

        // Переключение контекстных подсказок в зависимости от движка
        const $translatorSelect = $('#plu_default_translator');
        function updateTranslatorVisibility() {
            const selected = $translatorSelect.val();
            const $geminiRows = $('.plu-gemini-field');
            if (selected === 'gtx') {
                $geminiRows.addClass('plu-row-dimmed');
            } else {
                $geminiRows.removeClass('plu-row-dimmed');
            }
        }
        $translatorSelect.on('change', updateTranslatorVisibility);
        updateTranslatorVisibility();

        // Обработка клика на кнопку «Проверить соединение с API»
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
                        const timeMs = data.time_ms ? ' (' + data.time_ms + ' мс)' : '';
                        const html = `
                            <div class="plu-api-test-box plu-api-test-success">
                                <strong>&#10003; ${data.engine}:</strong> ${data.message || 'Соединение успешно.'}
                                ${data.translated ? `<div class="plu-test-meta">Тестовый перевод: "Connection successful" &rarr; <em>"${data.translated}"</em>${timeMs}</div>` : ''}
                            </div>
                        `;
                        $resultBox.html(html);
                    } else {
                        const msg = (response.data && response.data.message) ? response.data.message : 'Неизвестная ошибка проверки API.';
                        const html = `
                            <div class="plu-api-test-box plu-api-test-error">
                                <strong>&#9888; Ошибка проверки:</strong> ${msg}
                            </div>
                        `;
                        $resultBox.html(html);
                    }
                },
                error: function(xhr, status, error) {
                    const html = `
                        <div class="plu-api-test-box plu-api-test-error">
                            <strong>&#9888; Сетевая ошибка сервера:</strong> ${error || status} (код ${xhr.status || 500})
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
    });
})(jQuery);
