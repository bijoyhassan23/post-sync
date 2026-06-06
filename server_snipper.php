<?php
/*
|--------------------------------------------------------------------------
| NL Site — Vacatures Post sender Server
|--------------------------------------------------------------------------
*/

define('BE_SITE_URL',    'https://podoloog.togetherpreview.nl');
define('BE_SYNC_SECRET', 'c7A$kL9vP2!rX5@Zq8TnYwM4e3s1u0');

/**
 * -------------------------------------------------------
 * Push on create / update
 * -------------------------------------------------------
 */
add_action('acf/save_post', function ($post_id) {

    if (!is_numeric($post_id)) return;

    $post = get_post($post_id);
    if (!$post) return;

    if ($post->post_type !== 'vacatures') return;

    if (!in_array($post->post_status, ['publish', 'future'])) return;

    /**
     * Loop prevention — 5 second transient guard
     */
    $transient_key = 'nl_be_syncing_' . $post_id;

    if (get_transient($transient_key)) {
        error_log('[NL→BE] Skipped loop guard: ' . $post_id);
        return;
    }

    set_transient($transient_key, 1, 5);

    /**
     * ACF fields
     */
    $acf = get_fields($post_id);

    $be_title   = $acf['vacature_title_be']   ?? '';
    $be_slug    = $acf['vacature_url_be']     ?? '';
    $be_content = $acf['vacature_content_be'] ?? '';

    if (empty(trim($be_title))) {
        error_log('[NL→BE] Skipped: empty BE title for post ' . $post_id);
        return;
    }

    $image_url = has_post_thumbnail($post_id)
        ? get_the_post_thumbnail_url($post_id, 'full')
        : '';

    $payload = [
        'source_post_id' => $post_id,
        'be_title'       => $be_title,
        'be_content'     => $be_content,
        'be_slug'        => $be_slug,
        'post_date'      => $post->post_date,
        'image_url'      => $image_url,
    ];

    error_log('[NL→BE] Pushing post: ' . $post_id . ' | title: ' . $be_title);

    nl_be_push_to_be_site('/wp-json/nl-be-sync/v1/post', $payload);

}, 999);

/**
 * -------------------------------------------------------
 * Push on delete
 * -------------------------------------------------------
 */
add_action('before_delete_post', function ($post_id) {

    $post = get_post($post_id);

    if (!$post || $post->post_type !== 'vacatures') return;

    error_log('[NL→BE] Deleting post: ' . $post_id);

    nl_be_push_to_be_site('/wp-json/nl-be-sync/v1/post/delete', [
        'source_post_id' => $post_id,
    ]);

});

/**
 * -------------------------------------------------------
 * HTTP Push helper
 * -------------------------------------------------------
 */
function nl_be_push_to_be_site($endpoint, $payload) {

    $url = BE_SITE_URL . $endpoint;

    $response = wp_remote_post($url, [
        'timeout' => 30,
        'headers' => [
            'Content-Type'  => 'application/json',
            'X-Sync-Secret' => BE_SYNC_SECRET,
        ],
        'body' => wp_json_encode($payload),
    ]);

    if (is_wp_error($response)) {
        error_log('[NL→BE] WP_Error: ' . $response->get_error_message());
    } else {
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        error_log('[NL→BE] Response code: ' . $code);
        error_log('[NL→BE] Response body: ' . $body);
    }

    return $response;
}