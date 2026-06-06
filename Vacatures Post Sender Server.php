<?php


/*
|--------------------------------------------------------------------------
| NL Site — Push Changes to BE Site (Posts + Vacatures)
|--------------------------------------------------------------------------
*/

define('BE_SITE_URL',    'https://podoloog.togetherpreview.nl');
define('BE_SYNC_SECRET', 'c7A$kL9vP2!rX5@Zq8TnYwM4e3s1u0');

/**
 * -------------------------------------------------------
 * Push VACATURES on create / update
 * -------------------------------------------------------
 */
add_action('acf/save_post', function ($post_id) {

    if (!is_numeric($post_id)) return;

    $post = get_post($post_id);
    if (!$post) return;

    if ($post->post_type !== 'vacatures') return;
    if (!in_array($post->post_status, ['publish', 'future'])) return;

    $transient_key = 'nl_be_syncing_' . $post_id;
    if (get_transient($transient_key)) return;
    set_transient($transient_key, 1, 5);

    $acf = get_fields($post_id);

    $be_title = $acf['vacature_title_be'] ?? '';
    if (empty(trim($be_title))) {
        error_log('[NL→BE] Skipped vacature: empty BE title for post ' . $post_id);
        return;
    }

    $image_url = has_post_thumbnail($post_id)
        ? get_the_post_thumbnail_url($post_id, 'full')
        : '';

    $payload = [
        'source_post_id' => $post_id,
        'post_type'      => 'vacatures',
        'post_date'      => $post->post_date,
        'image_url'      => $image_url,

        // Core fields
        'be_title'   => $be_title,
        'be_content' => $acf['vacature_content_be'] ?? '',
        'be_slug'    => $acf['vacature_url_be']     ?? '',

        // Location / job info
        'land'              => $acf['land_be']              ?? $acf['land']              ?? '',
        'locatie'           => $acf['locatie_be']           ?? $acf['locatie']           ?? '',
        'uren_per_week'     => $acf['uren_per_week_be']     ?? $acf['uren_per_week']     ?? '',
        'opleidingsniveau'  => $acf['opleidingsniveau_be']  ?? $acf['opleidingsniveau']  ?? '',

        // Content image blocks
        'content_image_block_content_1'        => $acf['content_image_block_content_1_be']        ?? '',
        'content_image_block_image_1'          => nl_be_get_image_url($acf['content_image_block_image_1_be'] ?? $acf['content_image_block_image_1'] ?? ''),
        'content_image_block_bottom_content_1' => $acf['content_image_block_bottom_content_1_be'] ?? '',

        'content_image_block_content_2'        => $acf['content_image_block_content_2_be']        ?? '',
        'content_image_block_image_2'          => nl_be_get_image_url($acf['content_image_block_image_2_be'] ?? $acf['content_image_block_image_2'] ?? ''),
        'content_image_block_bottom_content_2' => $acf['content_image_block_bottom_content_2_be'] ?? '',

        'content_image_block_content_3'        => $acf['content_image_block_content_3_be']        ?? '',
        'content_image_block_image_3'          => nl_be_get_image_url($acf['content_image_block_image_3_be'] ?? $acf['content_image_block_image_3'] ?? ''),
        'content_image_block_bottom_content_3' => $acf['content_image_block_bottom_content_3_be'] ?? '',

        'content_image_block_content_4'        => $acf['content_image_block_content_4_be']        ?? '',
        'content_image_block_image_4'          => nl_be_get_image_url($acf['content_image_block_image_4_be'] ?? $acf['content_image_block_image_4'] ?? ''),
        'content_image_block_bottom_content_4' => $acf['content_image_block_bottom_content_4_be'] ?? '',

        'content_image_block_content_5'        => $acf['content_image_block_content_5_be']        ?? '',
        'content_image_block_image_5'          => nl_be_get_image_url($acf['content_image_block_image_5_be'] ?? $acf['content_image_block_image_5'] ?? ''),
        'content_image_block_bottom_content_5' => $acf['content_image_block_bottom_content_5_be'] ?? '',

        // CTA
        'cta_content' => $acf['cta_content_be'] ?? '',

        // Related vacatures (array of post IDs)
        'related_vacature' => $acf['related_vacature_be'] ?? $acf['related_vacature'] ?? [],

        // Taxonomies (fetched separately on receiver side via source_post_id)
        'taxonomies' => nl_be_get_vacature_taxonomies($post_id),
    ];

    error_log('[NL→BE] Pushing vacature: ' . $post_id . ' | title: ' . $be_title);

    nl_be_push_to_be_site('/wp-json/nl-be-sync/v1/post', $payload);

}, 999);

/**
 * -------------------------------------------------------
 * Push REGULAR POSTS on create / update
 * -------------------------------------------------------
 */
add_action('acf/save_post', function ($post_id) {

    if (!is_numeric($post_id)) return;

    $post = get_post($post_id);
    if (!$post) return;

    if ($post->post_type !== 'post') return;
    if (!in_array($post->post_status, ['publish', 'future'])) return;

    $transient_key = 'nl_be_syncing_post_' . $post_id;
    if (get_transient($transient_key)) return;
    set_transient($transient_key, 1, 5);

    $acf = get_fields($post_id);

    $be_title = $acf['post_title_be'] ?? '';
    if (empty(trim($be_title))) {
        error_log('[NL→BE] Skipped post: empty BE title for post ' . $post_id);
        return;
    }

    $image_url = has_post_thumbnail($post_id)
        ? get_the_post_thumbnail_url($post_id, 'full')
        : '';

    $payload = [
        'source_post_id' => $post_id,
        'post_type'      => 'post',
        'post_date'      => $post->post_date,
        'image_url'      => $image_url,

        'be_title'   => $be_title,
        'be_slug'    => $acf['post_url_be']     ?? '',
        'be_excerpt' => $acf['post_excerpt_be'] ?? '',
        'be_content' => $acf['post_content_be'] ?? '',
    ];

    error_log('[NL→BE] Pushing post: ' . $post_id . ' | title: ' . $be_title);

    nl_be_push_to_be_site('/wp-json/nl-be-sync/v1/post', $payload);

}, 999);

/**
 * -------------------------------------------------------
 * Push on delete (both post types)
 * -------------------------------------------------------
 */
add_action('before_delete_post', function ($post_id) {

    $post = get_post($post_id);

    if (!$post) return;
    if (!in_array($post->post_type, ['post', 'vacatures'])) return;

    error_log('[NL→BE] Deleting: ' . $post->post_type . ' ' . $post_id);

    nl_be_push_to_be_site('/wp-json/nl-be-sync/v1/post/delete', [
        'source_post_id' => $post_id,
        'post_type'      => $post->post_type,
    ]);

});

/**
 * -------------------------------------------------------
 * Helper — get image URL from ACF image field
 * (ACF returns array, ID, or URL depending on field settings)
 * -------------------------------------------------------
 */
function nl_be_get_image_url($value) {

    if (empty($value)) return '';

    // ACF image array
    if (is_array($value) && isset($value['url'])) {
        return $value['url'];
    }

    // Attachment ID
    if (is_numeric($value)) {
        return wp_get_attachment_url($value) ?: '';
    }

    // Already a URL
    if (is_string($value) && filter_var($value, FILTER_VALIDATE_URL)) {
        return $value;
    }

    return '';
}

/**
 * -------------------------------------------------------
 * Helper — get vacature taxonomy terms for payload
 * -------------------------------------------------------
 */
function nl_be_get_vacature_taxonomies($post_id) {

    $result = [];

    $taxonomies = ['functie', 'vacature_type'];

    foreach ($taxonomies as $taxonomy) {

        $terms = wp_get_post_terms($post_id, $taxonomy);

        if (is_wp_error($terms) || empty($terms)) {
            $result[$taxonomy] = [];
            continue;
        }

        $result[$taxonomy] = array_map(function($term) {
            return [
                'name' => $term->name,
                'slug' => $term->slug,
            ];
        }, $terms);
    }

    return $result;
}

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