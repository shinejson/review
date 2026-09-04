<?php
/**
 * ============================================================
 *  Optibiz — social publishing
 * ============================================================
 *  Turns a customer review into a ready-to-post caption and
 *  publishes it to the connected network through its official
 *  HTTP API:
 *
 *    Facebook Page   POST  graph.facebook.com/v19.0/{page-id}/feed
 *    Instagram       POST  graph.facebook.com/v19.0/{ig-user-id}/media (+ publish)
 *    LinkedIn        POST  api.linkedin.com/v2/ugcPosts
 *    X (Twitter)     POST  api.twitter.com/2/tweets
 *
 *  Credentials live in `social_accounts` (one row per workspace
 *  and platform) and are supplied by the workspace owner from
 *  admin/social.php — the app never brokers an OAuth flow of its
 *  own, it uses the long-lived page/organisation token you paste
 *  in. Nothing is sent anywhere until a token exists.
 *
 *  Every function degrades gracefully: without cURL, without a
 *  token, or when the network answers with an error, the caller
 *  receives ['ok' => false, 'error' => '…'] and the post is
 *  stored as a draft/failed row instead of being lost.
 */

if (!function_exists('social_platforms')) {
    /** Catalogue of supported networks and what each one needs. */
    function social_platforms()
    {
        return [
            'facebook' => [
                'label'      => 'Facebook Page',
                'glyph'      => 'f',
                'limit'      => 63206,
                'ref_label'  => 'Page ID',
                'ref_hint'   => 'Numeric ID of the Facebook Page (Page → About → Page transparency).',
                'token_hint' => 'Long-lived Page access token with pages_manage_posts.',
                'docs'       => 'https://developers.facebook.com/docs/pages-api/posts',
            ],
            'instagram' => [
                'label'      => 'Instagram Business',
                'glyph'      => '◎',
                'limit'      => 2200,
                'ref_label'  => 'Instagram user ID',
                'ref_hint'   => 'IG Business account id linked to your Facebook Page.',
                'token_hint' => 'Page token with instagram_content_publish. Image-only API: captions post with your logo card.',
                'docs'       => 'https://developers.facebook.com/docs/instagram-api/guides/content-publishing',
            ],
            'linkedin' => [
                'label'      => 'LinkedIn Page',
                'glyph'      => 'in',
                'limit'      => 3000,
                'ref_label'  => 'Organisation URN or ID',
                'ref_hint'   => 'e.g. 12345678 or urn:li:organization:12345678.',
                'token_hint' => 'OAuth 2 access token with w_organization_social.',
                'docs'       => 'https://learn.microsoft.com/linkedin/marketing/integrations/community-management/shares/ugc-post-api',
            ],
            'twitter' => [
                'label'      => 'X (Twitter)',
                'glyph'      => '𝕏',
                'limit'      => 280,
                'ref_label'  => 'Handle (optional)',
                'ref_hint'   => 'Only used for the link back to the post.',
                'token_hint' => 'OAuth 2 user access token with tweet.write.',
                'docs'       => 'https://developer.x.com/en/docs/x-api/tweets/manage-tweets/introduction',
            ],
        ];
    }
}

if (!function_exists('social_platform')) {
    /** Metadata for one platform key (falls back to Facebook). */
    function social_platform($key)
    {
        $all = social_platforms();
        return isset($all[$key]) ? $all[$key] : $all['facebook'];
    }
}

if (!function_exists('social_platform_keys')) {
    function social_platform_keys()
    {
        return array_keys(social_platforms());
    }
}

if (!function_exists('social_hashtags')) {
    /** Hashtags built from the company and category names. */
    function social_hashtags($company_name, $category_name = '')
    {
        $tags = [];
        foreach ([$company_name, $category_name] as $source) {
            $slug = preg_replace('/[^A-Za-z0-9]/', '', (string) $source);
            if ($slug !== '') {
                $tags[] = '#' . $slug;
            }
        }
        $tags[] = '#CustomerReview';
        $tags[] = '#5StarService';
        return array_slice(array_unique($tags), 0, 4);
    }
}

if (!function_exists('social_caption')) {
    /**
     * Build a platform-tailored caption from a review row.
     *
     * @param array  $review   id, rating, comment, customer_name, company_name
     * @param string $platform platform key
     * @param string $category optional category name for hashtags
     */
    function social_caption($review, $platform = 'facebook', $category = '')
    {
        $meta    = social_platform($platform);
        $rating  = max(1, min(5, (int) ($review['rating'] ?? 5)));
        $stars   = str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);
        $comment = trim((string) ($review['comment'] ?? ''));
        $author  = trim((string) ($review['customer_name'] ?? ''));
        $company = trim((string) ($review['company_name'] ?? ''));

        // "Abena Owusu" -> "Abena O." — recognisable without publishing a full name.
        $short = 'A happy customer';
        if ($author !== '') {
            $parts = preg_split('/\s+/', $author);
            $short = $parts[0];
            if (count($parts) > 1) {
                $last = end($parts);
                $short .= ' ' . strtoupper(substr($last, 0, 1)) . '.';
            }
        }

        if ($comment === '') {
            $comment = $rating >= 4
                ? 'Rated us ' . $rating . ' out of 5.'
                : 'Left us ' . $rating . ' out of 5 — and we are on it.';
        }

        $tags = implode(' ', social_hashtags($company, $category));

        switch ($platform) {
            case 'twitter':
                $body = $stars . ' “' . $comment . '” — ' . $short;
                $body .= "\n\nThank you for choosing " . $company . '!';
                break;

            case 'linkedin':
                $body  = 'Customer feedback we are proud of ' . $stars . "\n\n";
                $body .= '“' . $comment . '”' . "\n— " . $short . "\n\n";
                $body .= 'At ' . $company . ' every rating shapes how we work. Thank you for taking the time to tell us.';
                break;

            case 'instagram':
                $body  = $stars . ' ' . strtoupper($company) . "\n\n";
                $body .= '“' . $comment . '”' . "\n— " . $short . "\n\n";
                $body .= 'Tap the link in bio to leave your own review 💬';
                break;

            case 'facebook':
            default:
                $body  = $stars . ' Another ' . $rating . '-star review for ' . $company . '!' . "\n\n";
                $body .= '“' . $comment . '”' . "\n— " . $short . "\n\n";
                $body .= 'Thank you for the trust. Got feedback of your own? Rate us — it takes 30 seconds.';
                break;
        }

        $caption = trim($body) . "\n\n" . $tags;

        // Never hand the API something it will reject on length.
        if (function_exists('mb_strlen') && mb_strlen($caption, 'UTF-8') > $meta['limit']) {
            $caption = rtrim(mb_substr($caption, 0, $meta['limit'] - 1, 'UTF-8')) . '…';
        } elseif (!function_exists('mb_strlen') && strlen($caption) > $meta['limit']) {
            $caption = rtrim(substr($caption, 0, $meta['limit'] - 1)) . '…';
        }

        return $caption;
    }
}

if (!function_exists('social_length')) {
    /** Character count that matches how the networks count. */
    function social_length($text)
    {
        return function_exists('mb_strlen') ? mb_strlen((string) $text, 'UTF-8') : strlen((string) $text);
    }
}

if (!function_exists('social_http')) {
    /**
     * Minimal JSON/form HTTP client used by the publishers.
     * Returns ['status' => int, 'body' => array|string, 'error' => string].
     */
    function social_http($method, $url, $payload = [], $headers = [], $asJson = true)
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'error' => 'PHP cURL extension is not enabled on this server.'];
        }

        $ch = curl_init();
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        ];

        if (strtoupper($method) !== 'GET') {
            if ($asJson) {
                $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
                $headers[] = 'Content-Type: application/json';
            } else {
                $opts[CURLOPT_POSTFIELDS] = http_build_query($payload);
            }
        }
        if ($headers) {
            $opts[CURLOPT_HTTPHEADER] = $headers;
        }
        curl_setopt_array($ch, $opts);

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['status' => $status, 'body' => '', 'error' => $err !== '' ? $err : 'Request failed.'];
        }

        $decoded = json_decode((string) $raw, true);
        return [
            'status' => $status,
            'body'   => is_array($decoded) ? $decoded : (string) $raw,
            'error'  => '',
        ];
    }
}

if (!function_exists('social_api_error')) {
    /** Pull a human message out of a network's error payload. */
    function social_api_error($response, $fallback = 'The network rejected the post.')
    {
        if (!empty($response['error'])) {
            return $response['error'];
        }
        $body = $response['body'];
        if (is_array($body)) {
            if (isset($body['error']['message'])) {
                return (string) $body['error']['message'];
            }
            if (isset($body['error_description'])) {
                return (string) $body['error_description'];
            }
            if (isset($body['message'])) {
                return (string) $body['message'];
            }
            if (isset($body['detail'])) {
                return (string) $body['detail'];
            }
            if (isset($body['title'])) {
                return (string) $body['title'];
            }
        } elseif (is_string($body) && trim($body) !== '') {
            return substr(trim(strip_tags($body)), 0, 240);
        }
        return $fallback . ' (HTTP ' . (int) $response['status'] . ')';
    }
}

if (!function_exists('social_publish')) {
    /**
     * Publish $message with the stored credentials of $account.
     *
     * @param string $platform platform key
     * @param array  $account  row from social_accounts
     * @param string $message  caption to publish
     * @return array ['ok' => bool, 'id' => string, 'url' => string, 'error' => string]
     */
    function social_publish($platform, $account, $message)
    {
        $fail = function ($error) {
            return ['ok' => false, 'id' => '', 'url' => '', 'error' => $error];
        };

        $token = trim((string) ($account['access_token'] ?? ''));
        $ref   = trim((string) ($account['account_ref'] ?? ''));
        $message = trim((string) $message);

        if ($message === '') {
            return $fail('Write something before publishing.');
        }
        if ($token === '') {
            return $fail('Connect ' . social_platform($platform)['label'] . ' first — no access token is stored.');
        }
        if (($account['status'] ?? 'connected') !== 'connected') {
            return $fail('This connection is disabled. Re-enable it to publish.');
        }

        switch ($platform) {
            case 'facebook':
                if ($ref === '') {
                    return $fail('Add the Facebook Page ID to the connection.');
                }
                $res = social_http(
                    'POST',
                    'https://graph.facebook.com/v19.0/' . rawurlencode($ref) . '/feed',
                    ['message' => $message, 'access_token' => $token],
                    [],
                    false
                );
                if ($res['status'] >= 200 && $res['status'] < 300 && is_array($res['body']) && !empty($res['body']['id'])) {
                    $id = (string) $res['body']['id'];
                    return ['ok' => true, 'id' => $id, 'url' => 'https://www.facebook.com/' . $id, 'error' => ''];
                }
                return $fail(social_api_error($res));

            case 'instagram':
                if ($ref === '') {
                    return $fail('Add the Instagram Business user ID to the connection.');
                }
                // Instagram only publishes media, so the caption rides on a container.
                $create = social_http(
                    'POST',
                    'https://graph.facebook.com/v19.0/' . rawurlencode($ref) . '/media',
                    ['caption' => $message, 'image_url' => (string) ($account['image_url'] ?? ''), 'access_token' => $token],
                    [],
                    false
                );
                if (!(is_array($create['body']) && !empty($create['body']['id']))) {
                    return $fail(social_api_error($create, 'Instagram could not build the post.'));
                }
                $publish = social_http(
                    'POST',
                    'https://graph.facebook.com/v19.0/' . rawurlencode($ref) . '/media_publish',
                    ['creation_id' => $create['body']['id'], 'access_token' => $token],
                    [],
                    false
                );
                if (is_array($publish['body']) && !empty($publish['body']['id'])) {
                    $id = (string) $publish['body']['id'];
                    return ['ok' => true, 'id' => $id, 'url' => 'https://www.instagram.com/', 'error' => ''];
                }
                return $fail(social_api_error($publish, 'Instagram rejected the post.'));

            case 'linkedin':
                if ($ref === '') {
                    return $fail('Add the LinkedIn organisation ID to the connection.');
                }
                $urn = strpos($ref, 'urn:li:') === 0 ? $ref : 'urn:li:organization:' . $ref;
                $res = social_http(
                    'POST',
                    'https://api.linkedin.com/v2/ugcPosts',
                    [
                        'author'          => $urn,
                        'lifecycleState'  => 'PUBLISHED',
                        'specificContent' => [
                            'com.linkedin.ugc.ShareContent' => [
                                'shareCommentary'    => ['text' => $message],
                                'shareMediaCategory' => 'NONE',
                            ],
                        ],
                        'visibility' => ['com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC'],
                    ],
                    [
                        'Authorization: Bearer ' . $token,
                        'X-Restli-Protocol-Version: 2.0.0',
                    ]
                );
                if ($res['status'] >= 200 && $res['status'] < 300) {
                    $id = is_array($res['body']) && !empty($res['body']['id']) ? (string) $res['body']['id'] : '';
                    return [
                        'ok'    => true,
                        'id'    => $id,
                        'url'   => $id !== '' ? 'https://www.linkedin.com/feed/update/' . $id : 'https://www.linkedin.com/',
                        'error' => '',
                    ];
                }
                return $fail(social_api_error($res));

            case 'twitter':
                $res = social_http(
                    'POST',
                    'https://api.twitter.com/2/tweets',
                    ['text' => $message],
                    ['Authorization: Bearer ' . $token]
                );
                if ($res['status'] >= 200 && $res['status'] < 300 && is_array($res['body']) && !empty($res['body']['data']['id'])) {
                    $id = (string) $res['body']['data']['id'];
                    $handle = $ref !== '' ? ltrim($ref, '@') : 'i';
                    return ['ok' => true, 'id' => $id, 'url' => 'https://x.com/' . $handle . '/status/' . $id, 'error' => ''];
                }
                return $fail(social_api_error($res));
        }

        return $fail('Unknown platform.');
    }
}

if (!function_exists('social_verify')) {
    /**
     * Read-only credential check used by the "Test connection"
     * button, so a broken token is spotted before a post fails.
     */
    function social_verify($platform, $account)
    {
        $token = trim((string) ($account['access_token'] ?? ''));
        $ref   = trim((string) ($account['account_ref'] ?? ''));
        if ($token === '') {
            return ['ok' => false, 'error' => 'No access token stored.'];
        }

        switch ($platform) {
            case 'facebook':
            case 'instagram':
                $res = social_http(
                    'GET',
                    'https://graph.facebook.com/v19.0/' . rawurlencode($ref !== '' ? $ref : 'me')
                        . '?fields=id,name&access_token=' . urlencode($token)
                );
                break;
            case 'linkedin':
                $res = social_http('GET', 'https://api.linkedin.com/v2/userinfo', [], [
                    'Authorization: Bearer ' . $token,
                ]);
                break;
            case 'twitter':
                $res = social_http('GET', 'https://api.twitter.com/2/users/me', [], [
                    'Authorization: Bearer ' . $token,
                ]);
                break;
            default:
                return ['ok' => false, 'error' => 'Unknown platform.'];
        }

        if ($res['status'] >= 200 && $res['status'] < 300) {
            $name = '';
            if (is_array($res['body'])) {
                foreach (['name', 'localizedName', 'username'] as $key) {
                    if (!empty($res['body'][$key])) {
                        $name = (string) $res['body'][$key];
                        break;
                    }
                    if (!empty($res['body']['data'][$key])) {
                        $name = (string) $res['body']['data'][$key];
                        break;
                    }
                }
            }
            return ['ok' => true, 'error' => '', 'name' => $name];
        }
        return ['ok' => false, 'error' => social_api_error($res, 'The network refused the credentials.')];
    }
}

if (!function_exists('social_mask_token')) {
    /** Never echo a full token back into the page. */
    function social_mask_token($token)
    {
        $token = (string) $token;
        $len = strlen($token);
        if ($len === 0) {
            return '';
        }
        if ($len <= 8) {
            return str_repeat('•', $len);
        }
        return substr($token, 0, 4) . str_repeat('•', 12) . substr($token, -4);
    }
}

if (!function_exists('social_share_url')) {
    /**
     * Web intent link — the manual fallback when a workspace has
     * not connected the API yet.
     */
    function social_share_url($platform, $message, $link = '')
    {
        $text = rawurlencode($message);
        switch ($platform) {
            case 'twitter':
                return 'https://x.com/intent/tweet?text=' . $text;
            case 'linkedin':
                return 'https://www.linkedin.com/feed/?shareActive=true&text=' . $text;
            case 'facebook':
                return 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode($link !== '' ? $link : 'https://optibiz.app')
                    . '&quote=' . $text;
            case 'instagram':
            default:
                return 'https://www.instagram.com/';
        }
    }
}
