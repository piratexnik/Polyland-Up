<?php
declare(strict_types=1);

namespace PolylangUp\Modules;

use PolylangUp\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Шлюз пакетного перевода текста через Google Translate (каскад GTX) и Google Gemini API.
 */
final class TranslatorAPI {

    /**
     * Статистика последнего выполненного перевода.
     *
     * @var array<string, mixed>
     */
    private static array $last_stats = [];

    /**
     * Возвращает статистику последнего запроса на перевод.
     *
     * @return array<string, mixed>
     */
    public static function get_last_stats(): array {
        return self::$last_stats;
    }

    /**
     * Единая точка входа для перевода словаря строк.
     *
     * @param array<string, string> $dictionary           Ассоциативный массив вида ['k_0' => 'Текст...'].
     * @param string                $source_lang          Код исходного языка Polylang.
     * @param string                $target_lang          Код целевого языка Polylang.
     * @param string                $engine               Движок перевода ('gtx', 'gemini' или '' для настройки по умолчанию).
     * @param string                $custom_system_prompt Дополнительная системная инструкция / глоссарий для Gemini AI.
     * @return array<string, string>|\WP_Error Переведенный словарь с теми же ключами или WP_Error.
     */
    public static function translate(
        array $dictionary,
        string $source_lang,
        string $target_lang,
        string $engine = '',
        string $custom_system_prompt = ''
    ): array|\WP_Error {
        $engine = strtolower( trim( $engine ) );
        if ( '' === $engine ) {
            $engine = strtolower( trim( (string) plu_get_option( 'default_translator', 'gtx' ) ) );
        }
        if ( ! in_array( $engine, [ 'gtx', 'gemini' ], true ) ) {
            $engine = 'gtx';
        }

        self::$last_stats = [
            'engine'           => $engine,
            'provider_label'   => 'gemini' === $engine ? 'Google Gemini AI' : 'Google GTX',
            'translated_count' => 0,
            'total_count'      => count( $dictionary ),
            'time_ms'          => 0,
            'time_sec'         => 0.0,
            'error'            => '',
        ];

        if ( empty( $dictionary ) ) {
            return [];
        }

        $sl = self::normalize_lang_code( $source_lang );
        $tl = self::normalize_lang_code( $target_lang );

        $start = microtime( true );

        if ( 'gemini' === $engine ) {
            $result = self::translate_via_gemini( $dictionary, $sl, $tl, $custom_system_prompt );
        } else {
            $result = self::translate_via_gtx( $dictionary, $sl, $tl );
        }

        $elapsed_ms  = (int) round( ( microtime( true ) - $start ) * 1000 );
        $elapsed_sec = round( $elapsed_ms / 1000, 1 );

        self::$last_stats['time_ms']  = $elapsed_ms;
        self::$last_stats['time_sec'] = $elapsed_sec;

        if ( is_wp_error( $result ) ) {
            self::$last_stats['error'] = $result->get_error_message();
            return $result;
        }

        self::$last_stats['translated_count'] = count( $result );

        return $result;
    }

    /**
     * Нормализация слага языка Polylang в стандартный ISO-код для API переводчиков.
     * Пример: 'ua' -> 'uk', 'jp' -> 'ja'.
     *
     * @param string $slug Слаг языка в Polylang.
     * @return string ISO-код языка.
     */
    public static function normalize_lang_code( string $slug ): string {
        $clean = strtolower( trim( $slug ) );

        $direct_map = [
            'ua'    => 'uk',
            'jp'    => 'ja',
            'cz'    => 'cs',
            'dk'    => 'da',
            'gr'    => 'el',
            'kr'    => 'ko',
            'se'    => 'sv',
            'no'    => 'nb',
            'rs'    => 'sr',
            'cn'    => 'zh-CN',
            'zh-cn' => 'zh-CN',
            'zh-tw' => 'zh-TW',
            'tw'    => 'zh-TW',
            'br'    => 'pt-BR',
            'pt-br' => 'pt-BR',
        ];

        if ( isset( $direct_map[ $clean ] ) ) {
            return $direct_map[ $clean ];
        }

        // Попытка уточнить код из локали языка в Polylang (например uk_UA -> uk)
        if ( function_exists( 'PLL' ) && isset( PLL()->model ) && is_object( PLL()->model ) && method_exists( PLL()->model, 'get_language' ) ) {
            $lang_obj = PLL()->model->get_language( $clean );
            if ( is_object( $lang_obj ) && ! empty( $lang_obj->locale ) ) {
                $locale = (string) $lang_obj->locale;
                if ( in_array( $locale, [ 'zh_CN', 'zh_TW', 'pt_BR' ], true ) ) {
                    return str_replace( '_', '-', $locale );
                }
                $parts = explode( '_', $locale );
                if ( ! empty( $parts[0] ) && strlen( $parts[0] ) === 2 ) {
                    return strtolower( $parts[0] );
                }
            }
        }

        return $clean;
    }

    /**
     * Провайдер 1: Пакетный бесплатный перевод через батчи и 3-ступенчатый каскад GTX.
     *
     * @param array<string, string> $dictionary Словарь исходных строк.
     * @param string                $sl         Нормализованный код исходного языка.
     * @param string                $tl         Нормализованный код целевого языка.
     * @return array<string, string>|\WP_Error
     */
    public static function translate_via_gtx( array $dictionary, string $sl, string $tl ): array|\WP_Error {
        $result        = [];
        $used_channels = [];
        $separator     = "\n[[[PLU_SEP_99]]]\n";
        $max_batch_len = 2200;
        $max_batch_cnt = 25;

        // Предварительно защищаем переменные Rank Math и HTML-теги в каждой строке
        $protected_dict = [];
        $tokens_maps    = [];

        foreach ( $dictionary as $key => $text ) {
            $map = [];
            $protected_dict[ $key ] = self::protect_special_tokens( (string) $text, $map );
            $tokens_maps[ $key ]    = $map;
        }

        // Формируем батчи до ~2200 символов (и не более 25 строк на запрос)
        $batches       = [];
        $current_batch = [];
        $current_len   = 0;

        foreach ( $protected_dict as $key => $p_text ) {
            $item_len = mb_strlen( $p_text, 'UTF-8' );
            $sep_len  = empty( $current_batch ) ? 0 : mb_strlen( $separator, 'UTF-8' );

            if ( ! empty( $current_batch ) && ( ( $current_len + $sep_len + $item_len ) > $max_batch_len || count( $current_batch ) >= $max_batch_cnt ) ) {
                $batches[]     = $current_batch;
                $current_batch = [];
                $current_len   = 0;
            }

            $current_batch[ $key ] = $p_text;
            $current_len          += $sep_len + $item_len;
        }

        if ( ! empty( $current_batch ) ) {
            $batches[] = $current_batch;
        }

        // Переводим каждый батч
        foreach ( $batches as $batch ) {
            $keys = array_keys( $batch );

            if ( 1 === count( $batch ) ) {
                $single_key = $keys[0];
                $cascade    = Settings::translate_free_cascade( $batch[ $single_key ], $sl, $tl );
                if ( $cascade['success'] ) {
                    $used_channels[ $cascade['channel_label'] ] = true;
                    $restored              = self::restore_special_tokens( $cascade['translated'], $tokens_maps[ $single_key ] );
                    $result[ $single_key ] = '' !== $restored ? $restored : $dictionary[ $single_key ];
                } else {
                    $result[ $single_key ] = $dictionary[ $single_key ];
                }
                continue;
            }

            $joined_text   = implode( $separator, array_values( $batch ) );
            $batch_success = false;

            // Для батча > 1 элемента сначала пробуем Google-каналы (clients5 -> gtx), т.к. у MyMemory лимит 500 байт
            $batch_response = self::request_google_batch( $joined_text, $sl, $tl );
            if ( $batch_response['success'] ) {
                $segments = preg_split( '/\s*\[\s*\[\s*\[\s*PLU[_\s]*SEP[_\s]*99\s*\]\s*\]\s*\]\s*/iu', $batch_response['translated'] );

                if ( is_array( $segments ) && count( $segments ) === count( $keys ) ) {
                    $batch_success = true;
                    $used_channels[ $batch_response['channel_label'] ] = true;

                    foreach ( $keys as $idx => $key ) {
                        $translated_segment = trim( (string) $segments[ $idx ] );
                        $restored           = self::restore_special_tokens( $translated_segment, $tokens_maps[ $key ] );
                        $result[ $key ]     = '' !== $restored ? $restored : $dictionary[ $key ];
                    }
                }
            }

            // Фоллбэк: если батч не совпал по количеству сегментов или вернул 429 — поштучно через 3-ступенчатый каскад
            if ( ! $batch_success ) {
                foreach ( $keys as $key ) {
                    $cascade = Settings::translate_free_cascade( $batch[ $key ], $sl, $tl );
                    if ( $cascade['success'] ) {
                        $used_channels[ $cascade['channel_label'] ] = true;
                        $restored       = self::restore_special_tokens( $cascade['translated'], $tokens_maps[ $key ] );
                        $result[ $key ] = '' !== $restored ? $restored : $dictionary[ $key ];
                    } else {
                        $result[ $key ] = $dictionary[ $key ];
                    }
                }
            }
        }

        if ( ! empty( $used_channels ) ) {
            self::$last_stats['provider_label'] = implode( ' + ', array_keys( $used_channels ) );
        } else {
            self::$last_stats['provider_label'] = 'Google GTX (Fallback)';
        }

        return $result;
    }

    /**
     * Запрос пакетного перевода через Google-каналы (clients5 -> gtx), поддерживающие длинные батчи.
     *
     * @param string $joined_text Текст батча с разделителями.
     * @param string $sl          Исходный язык.
     * @param string $tl          Целевой язык.
     * @return array{success: bool, channel_label: string, translated: string}
     */
    private static function request_google_batch( string $joined_text, string $sl, string $tl ): array {
        $browser_ua   = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
        $request_args = [
            'timeout'    => 15,
            'user-agent' => $browser_ua,
            'headers'    => [
                'Accept'          => 'application/json, text/plain, */*',
                'Accept-Language' => 'en-US,en;q=0.9,ru;q=0.8',
            ],
        ];

        $encoded = urlencode( $joined_text );

        $channels = [
            'clients5' => [
                'label' => 'Google GTX / clients5',
                'url'   => "https://clients5.google.com/translate_a/t?client=dict-chrome-ex&sl={$sl}&tl={$tl}&q={$encoded}",
            ],
            'gtx' => [
                'label' => 'Google GTX / gtx',
                'url'   => "https://translate.googleapis.com/translate_a/single?client=gtx&sl={$sl}&tl={$tl}&dt=t&q={$encoded}",
            ],
        ];

        foreach ( $channels as $key => $cfg ) {
            $response = wp_remote_get( $cfg['url'], $request_args );
            if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
                continue;
            }

            $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
            if ( ! is_array( $data ) ) {
                continue;
            }

            $translated = '';
            if ( 'clients5' === $key ) {
                if ( isset( $data[0] ) && is_array( $data[0] ) && isset( $data[0][0] ) && is_string( $data[0][0] ) ) {
                    $translated = $data[0][0];
                } elseif ( isset( $data[0] ) && is_string( $data[0] ) ) {
                    $translated = $data[0];
                } elseif ( isset( $data['sentences'] ) && is_array( $data['sentences'] ) ) {
                    $parts = [];
                    foreach ( $data['sentences'] as $s ) {
                        if ( is_array( $s ) && isset( $s['trans'] ) && is_string( $s['trans'] ) ) {
                            $parts[] = $s['trans'];
                        }
                    }
                    $translated = implode( '', $parts );
                }
            } elseif ( 'gtx' === $key && isset( $data[0] ) && is_array( $data[0] ) ) {
                $parts = [];
                foreach ( $data[0] as $seg ) {
                    if ( is_array( $seg ) && isset( $seg[0] ) && is_string( $seg[0] ) ) {
                        $parts[] = $seg[0];
                    }
                }
                $translated = implode( '', $parts );
            }

            if ( '' !== trim( $translated ) ) {
                return [
                    'success'       => true,
                    'channel_label' => $cfg['label'],
                    'translated'    => $translated,
                ];
            }
        }

        return [
            'success'       => false,
            'channel_label' => '',
            'translated'    => '',
        ];
    }

    /**
     * Замена переменных Rank Math (%sep%, %sitename%, %title% и др.) и HTML-тегов
     * на числовые токены [[9000]], [[9001]]... перед отправкой в GTX.
     *
     * @param string             $text       Исходный текст.
     * @param array<int, string> $tokens_map Карта восстановленных значений по индексу.
     * @return string Защищенный текст.
     */
    public static function protect_special_tokens( string $text, array &$tokens_map ): string {
        $counter    = 9000;
        $tokens_map = [];

        // 1. Защищаем HTML-теги целиком (сохраняя атрибуты, классы и ссылки от искажений GTX)
        $protected = (string) preg_replace_callback(
            '/<[^>]+>/u',
            static function ( array $matches ) use ( &$counter, &$tokens_map ): string {
                $idx                = $counter++;
                $tokens_map[ $idx ] = $matches[0];
                return '[[' . $idx . ']]';
            },
            $text
        );

        // 2. Защищаем переменные Rank Math (%sep%, %sitename%, %title%, %page%, %sitedesc% и др.)
        $protected = (string) preg_replace_callback(
            '/%[a-zA-Z0-9_:-]+%/u',
            static function ( array $matches ) use ( &$counter, &$tokens_map ): string {
                $idx                = $counter++;
                $tokens_map[ $idx ] = $matches[0];
                return '[[' . $idx . ']]';
            },
            $protected
        );

        return $protected;
    }

    /**
     * Восстановление защищенных токенов [[9000]] обратно в Rank Math переменные и HTML-теги.
     *
     * @param string             $text       Переведенный текст.
     * @param array<int, string> $tokens_map Карта токенов.
     * @return string Восстановленный текст.
     */
    public static function restore_special_tokens( string $text, array $tokens_map ): string {
        if ( empty( $tokens_map ) ) {
            return $text;
        }

        return (string) preg_replace_callback(
            '/\[\s*\[\s*(\d+)\s*\]\s*\]/u',
            static function ( array $matches ) use ( $tokens_map ): string {
                $idx = (int) $matches[1];
                return $tokens_map[ $idx ] ?? $matches[0];
            },
            $text
        );
    }

    /**
     * Провайдер 2: Нейросетевой пакетный перевод через Google Gemini API (чанки по 90 элементов).
     *
     * @param array<string, string> $dictionary           Словарь исходных строк.
     * @param string                $sl                   Нормализованный код исходного языка.
     * @param string                $tl                   Нормализованный код целевого языка.
     * @param string                $custom_system_prompt Дополнительная системная инструкция / глоссарий для Gemini AI.
     * @return array<string, string>|\WP_Error
     */
    public static function translate_via_gemini(
        array $dictionary,
        string $sl,
        string $tl,
        string $custom_system_prompt = ''
    ): array|\WP_Error {
        $api_key = trim( (string) plu_get_option( 'gemini_api_key', '' ) );
        if ( '' === $api_key ) {
            return new \WP_Error(
                'plu_missing_gemini_key',
                __( 'Не указан API-ключ Gemini. Заполните его на странице настроек «Polylang AI».', 'polyland-up' )
            );
        }

        $model = trim( (string) plu_get_option( 'gemini_model', 'gemini-2.5-flash' ) );
        $model = (string) preg_replace( '#^models/#i', '', $model );
        if ( '' === $model ) {
            $model = 'gemini-2.5-flash';
        }

        self::$last_stats['provider_label'] = 'Gemini ' . $model;

        $user_prompt   = trim( (string) plu_get_option( 'system_prompt', '' ) );
        $custom_prompt = trim( $custom_system_prompt );
        $tech_prompt   = sprintf(
            'Translate all values of the provided JSON object from language "%1$s" to language "%2$s". Return strictly a valid JSON object with the EXACT same keys ("k_0", "k_1", etc.). Keep all HTML tags, attributes (except translatable alt/title), URLs, and Rank Math variables (%%sep%%, %%sitename%%, %%title%%) untouched.',
            $sl,
            $tl
        );

        $prompt_parts = array_filter(
            [ $user_prompt, $custom_prompt, $tech_prompt ],
            static fn( string $part ): bool => '' !== $part
        );
        $full_system_instruction = implode( "\n\n", $prompt_parts );

        $endpoint            = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent';
        $chunks              = array_chunk( $dictionary, 90, true );
        $result              = [];
        $use_thinking_config = ( stripos( $model, 'flash' ) !== false || stripos( $model, 'hydrogen' ) !== false );

        foreach ( $chunks as $chunk ) {
            $generation_config = [
                'responseMimeType' => 'application/json',
                'temperature'      => 0.1,
            ];

            if ( $use_thinking_config ) {
                $generation_config['thinkingConfig'] = [
                    'thinkingBudget' => 0,
                ];
            }

            $payload = [
                'systemInstruction' => [
                    'parts' => [
                        [ 'text' => $full_system_instruction ],
                    ],
                ],
                'contents' => [
                    [
                        'role'  => 'user',
                        'parts' => [
                            [ 'text' => (string) wp_json_encode( (object) $chunk, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ],
                        ],
                    ],
                ],
                'generationConfig' => $generation_config,
            ];

            $request_args = [
                'timeout' => 90,
                'headers' => [
                    'Content-Type'   => 'application/json',
                    'x-goog-api-key' => $api_key,
                ],
                'body'    => (string) wp_json_encode( $payload ),
            ];

            $response = wp_remote_post( $endpoint, $request_args );

            // Страховка: если модель не поддерживает thinkingConfig (HTTP 400), повторяем без него
            if (
                $use_thinking_config &&
                ! is_wp_error( $response ) &&
                400 === (int) wp_remote_retrieve_response_code( $response )
            ) {
                $err_body = (string) wp_remote_retrieve_body( $response );
                if ( stripos( $err_body, 'thinkingConfig' ) !== false || stripos( $err_body, 'thinking' ) !== false ) {
                    $use_thinking_config = false;
                    unset( $payload['generationConfig']['thinkingConfig'] );
                    $request_args['body'] = (string) wp_json_encode( $payload );
                    $response             = wp_remote_post( $endpoint, $request_args );
                }
            }

            // Повторная попытка при временной ошибке 429 / 503
            if ( ! is_wp_error( $response ) && in_array( (int) wp_remote_retrieve_response_code( $response ), [ 429, 503 ], true ) ) {
                sleep( 2 );
                $response = wp_remote_post( $endpoint, $request_args );
            }

            if ( is_wp_error( $response ) ) {
                return new \WP_Error(
                    'plu_gemini_network_error',
                    sprintf( __( 'Сетевая ошибка Gemini API: %s', 'polyland-up' ), $response->get_error_message() )
                );
            }

            $code     = (int) wp_remote_retrieve_response_code( $response );
            $raw_body = (string) wp_remote_retrieve_body( $response );
            $data     = json_decode( $raw_body, true );

            if ( 200 !== $code ) {
                $err_msg = is_array( $data ) ? (string) ( $data['error']['message'] ?? '' ) : '';
                return new \WP_Error(
                    'plu_gemini_http_error',
                    sprintf(
                        __( 'Ошибка Gemini API (HTTP %1$d): %2$s', 'polyland-up' ),
                        $code,
                        '' !== $err_msg ? $err_msg : __( 'Неизвестный ответ сервера.', 'polyland-up' )
                    )
                );
            }

            $candidate_text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $decoded_chunk  = self::decode_gemini_json( (string) $candidate_text );

            if ( ! is_array( $decoded_chunk ) ) {
                // Фоллбэк: если модель вернула невалидный JSON, оставляем оригиналы чанка без поломки верстки
                foreach ( $chunk as $k => $orig ) {
                    $result[ $k ] = $orig;
                }
                continue;
            }

            foreach ( $chunk as $k => $orig ) {
                if ( isset( $decoded_chunk[ $k ] ) && is_string( $decoded_chunk[ $k ] ) && '' !== trim( $decoded_chunk[ $k ] ) ) {
                    $result[ $k ] = $decoded_chunk[ $k ];
                } else {
                    $result[ $k ] = $orig;
                }
            }
        }

        return $result;
    }

    /**
     * Безопасный парсинг JSON-ответа Gemini с очисткой возможных markdown-оберток ```json.
     *
     * @param string $raw_text Сырой текст из ответа модели.
     * @return array<string, mixed>|null
     */
    public static function decode_gemini_json( string $raw_text ): ?array {
        $trimmed = trim( $raw_text );
        if ( '' === $trimmed ) {
            return null;
        }

        // Снимаем возможные блоки ```json ... ```
        $cleaned = (string) preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', $trimmed );
        $decoded = json_decode( trim( $cleaned ), true );

        if ( is_array( $decoded ) ) {
            return $decoded;
        }

        // Попытка вырезать первый JSON-объект { ... }
        if ( preg_match( '/\{.*\}/s', $cleaned, $matches ) ) {
            $fallback = json_decode( $matches[0], true );
            if ( is_array( $fallback ) ) {
                return $fallback;
            }
        }

        return null;
    }
}
