<?php

/*
|--------------------------------------------------------------------------
| BE Site — REST API Receiver (Posts + Vacatures)
|--------------------------------------------------------------------------
*/

add_action('rest_api_init', function () {

    register_rest_route('nl-be-sync/v1', '/post', [
        'methods'             => 'POST',
        'callback'            => 'nl_be_receive_post',
        'permission_callback' => 'nl_be_verify_secret',
    ]);

    register_rest_route('nl-be-sync/v1', '/post/delete', [
        'methods'             => 'POST',
        'callback'            => 'nl_be_receive_delete',
        'permission_callback' => 'nl_be_verify_secret',
    ]);
});

/**
 * Verify secret key
 */
function nl_be_verify_secret(WP_REST_Request $request) {

    $secret = $request->get_header('x-sync-secret');

    return $secret === 'c7A$kL9vP2!rX5@Zq8TnYwM4e3s1u0';
}

/**
 * -------------------------------------------------------
 * Create or update post
 * -------------------------------------------------------
 */
function nl_be_receive_post(WP_REST_Request $request) {

    $data = $request->get_json_params();

    $source_post_id = $data['source_post_id'] ?? 0;
    $post_type      = $data['post_type']      ?? 'post';
    $be_title       = $data['be_title']       ?? '';
    $be_content     = $data['be_content']     ?? '';
    $be_slug        = $data['be_slug']        ?? '';
    $post_date      = $data['post_date']      ?? current_time('mysql');
    $image_url      = $data['image_url']      ?? '';

    if (empty($source_post_id) || empty(trim($be_title))) {
        return new WP_REST_Response(['error' => 'Missing required fields'], 400);
    }

    $slug = !empty($be_slug)
        ? sanitize_title($be_slug)
        : sanitize_title($be_title);

    $content = !empty($be_content)
        ? wpautop($be_content)
        : '';

    /**
     * Find existing synced post
     */
    $existing_posts = get_posts([
        'post_type'   => $post_type,
        'numberposts' => 1,
        'meta_key'    => '_source_post_id',
        'meta_value'  => $source_post_id,
        'post_status' => 'any',
    ]);

    $post_data = [
        'post_type'     => $post_type,
        'post_status'   => 'publish',
        'post_title'    => $be_title,
        'post_content'  => $content,
        'post_name'     => $slug,
        'post_date'     => $post_date,
        'post_date_gmt' => get_gmt_from_date($post_date),
    ];

    // Regular post excerpt
    if ($post_type === 'post' && !empty($data['be_excerpt'])) {
        $post_data['post_excerpt'] = $data['be_excerpt'];
    }

    if (!empty($existing_posts)) {

        $post_data['ID'] = $existing_posts[0]->ID;
        $post_id = wp_update_post($post_data);

    } else {

        $post_id = wp_insert_post($post_data);

        if (!is_wp_error($post_id)) {
            update_post_meta($post_id, '_source_post_id', $source_post_id);
        }
    }

    if (is_wp_error($post_id)) {
        error_log('[BE Receiver] Failed to save post: ' . $post_id->get_error_message());
        return new WP_REST_Response(['error' => 'Failed to save post'], 500);
    }

    update_post_meta($post_id, '_source_modified', current_time('mysql'));

    /**
     * Vacatures — save all ACF fields
     */
    if ($post_type === 'vacatures') {
        nl_be_save_vacature_fields($post_id, $data);
    }

    /**
     * Featured image
     */
    if (!empty($image_url)) {
        nl_be_receive_featured_image($image_url, $post_id);
    }

    error_log('[BE Receiver] Saved ' . $post_type . ': ' . $post_id . ' from source: ' . $source_post_id);

    return new WP_REST_Response([
        'success' => true,
        'post_id' => $post_id,
    ], 200);
}

/**
 * -------------------------------------------------------
 * Save all vacature ACF fields on BE site
 * -------------------------------------------------------
 */
function nl_be_save_vacature_fields($post_id, $data) {

    // Simple text / WYSIWYG fields
    $text_fields = [
        'land',
        'locatie',
        'uren_per_week',
        'opleidingsniveau',
        'cta_content',
        'content_image_block_content_1',
        'content_image_block_bottom_content_1',
        'content_image_block_content_2',
        'content_image_block_bottom_content_2',
        'content_image_block_content_3',
        'content_image_block_bottom_content_3',
        'content_image_block_content_4',
        'content_image_block_bottom_content_4',
        'content_image_block_content_5',
        'content_image_block_bottom_content_5',
    ];

    foreach ($text_fields as $field) {

        $value = $data[$field] ?? '';

        // Apply wpautop for WYSIWYG fields
        if (
            str_contains($field, 'content') &&
            is_string($value) &&
            strip_tags($value) !== $value
        ) {
            $value = wpautop($value);
        }

        update_field($field, $value, $post_id);
    }

    // Image fields — import and save attachment ID
    $image_fields = [
        'content_image_block_image_1',
        'content_image_block_image_2',
        'content_image_block_image_3',
        'content_image_block_image_4',
        'content_image_block_image_5',
    ];

    foreach ($image_fields as $field) {

        $image_url = $data[$field] ?? '';

        if (empty($image_url)) {
            update_field($field, '', $post_id);
            continue;
        }

        $attachment_id = nl_be_import_external_image($image_url, $post_id);

        if ($attachment_id) {
            update_field($field, $attachment_id, $post_id);
        }
    }

    // Related vacatures — map source IDs to local IDs
    $related_source_ids = $data['related_vacature'] ?? [];

    if (!empty($related_source_ids) && is_array($related_source_ids)) {

        $mapped = [];

        foreach ($related_source_ids as $source_related_id) {

            // Handle both plain ID and post object
            $source_id = is_array($source_related_id)
                ? ($source_related_id['ID'] ?? 0)
                : $source_related_id;

            if (empty($source_id)) continue;

            $related = get_posts([
                'post_type'   => 'vacatures',
                'numberposts' => 1,
                'meta_key'    => '_source_post_id',
                'meta_value'  => $source_id,
                'post_status' => 'any',
            ]);

            if (!empty($related)) {
                $mapped[] = $related[0]->ID;
            }
        }

        update_field('related_vacature', $mapped, $post_id);

    } else {

        update_field('related_vacature', [], $post_id);
    }

    // Taxonomies
    $taxonomies = $data['taxonomies'] ?? [];

    if (!empty($taxonomies)) {
        nl_be_save_taxonomies($post_id, $taxonomies);
    }
}

/**
 * -------------------------------------------------------
 * Save taxonomies on BE site
 * -------------------------------------------------------
 */
function nl_be_save_taxonomies($post_id, $taxonomies) {

    foreach ($taxonomies as $taxonomy => $terms) {

        if (empty($terms)) {
            wp_set_post_terms($post_id, [], $taxonomy);
            continue;
        }

        $term_ids = [];

        foreach ($terms as $term_data) {

            $name = $term_data['name'] ?? '';

            if (empty($name)) continue;

            $existing = term_exists($name, $taxonomy);

            if (!$existing) {
                $existing = wp_insert_term($name, $taxonomy);
            }

            if (!is_wp_error($existing)) {
                $term_ids[] = (int) $existing['term_id'];
            }
        }

        wp_set_post_terms($post_id, $term_ids, $taxonomy);
    }
}

/**
 * -------------------------------------------------------
 * Delete post
 * -------------------------------------------------------
 */
function nl_be_receive_delete(WP_REST_Request $request) {

    $data           = $request->get_json_params();
    $source_post_id = $data['source_post_id'] ?? 0;
    $post_type      = $data['post_type']      ?? 'post';

    if (empty($source_post_id)) {
        return new WP_REST_Response(['error' => 'Missing source_post_id'], 400);
    }

    $existing_posts = get_posts([
        'post_type'   => $post_type,
        'numberposts' => 1,
        'meta_key'    => '_source_post_id',
        'meta_value'  => $source_post_id,
        'post_status' => 'any',
    ]);

    if (empty($existing_posts)) {
        return new WP_REST_Response(['error' => 'Post not found'], 404);
    }

    $result = wp_delete_post($existing_posts[0]->ID, true);

    if (!$result) {
        return new WP_REST_Response(['error' => 'Delete failed'], 500);
    }

    error_log('[BE Receiver] Deleted ' . $post_type . ': ' . $existing_posts[0]->ID);

    return new WP_REST_Response(['success' => true], 200);
}

/**
 * -------------------------------------------------------
 * Set featured image
 * -------------------------------------------------------
 */
function nl_be_receive_featured_image($image_url, $post_id) {

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    delete_post_thumbnail($post_id);

    @ini_set('memory_limit', '512M');

    $tmp = download_url($image_url, 300);

    if (is_wp_error($tmp)) {
        error_log('[BE Receiver] Featured image download failed: ' . $tmp->get_error_message());
        return;
    }

    $file_array = [
        'name'     => wp_basename(parse_url($image_url, PHP_URL_PATH)),
        'tmp_name' => $tmp,
    ];

    $attachment_id = media_handle_sideload($file_array, $post_id);

    if (is_wp_error($attachment_id)) {
        error_log('[BE Receiver] Featured image sideload failed: ' . $attachment_id->get_error_message());
        @unlink($tmp);
        return;
    }

    set_post_thumbnail($post_id, $attachment_id);
}

/**
 * -------------------------------------------------------
 * Import external image into media library
 * -------------------------------------------------------
 */
function nl_be_import_external_image($image_url, $post_id) {

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    @ini_set('memory_limit', '512M');

    add_filter('big_image_size_threshold', '__return_false');

    $tmp = download_url($image_url, 300);

    if (is_wp_error($tmp)) {
        error_log('[BE Receiver] Image download failed: ' . $tmp->get_error_message());
        return false;
    }

    $filename = basename(parse_url($image_url, PHP_URL_PATH));

    if (empty($filename)) {
        $filename = 'image-' . time() . '.jpg';
    }

    $file_array = [
        'name'     => $filename,
        'tmp_name' => $tmp,
    ];

    $attachment_id = media_handle_sideload($file_array, $post_id);

    if (is_wp_error($attachment_id)) {
        error_log('[BE Receiver] Image sideload failed: ' . $attachment_id->get_error_message());
        @unlink($tmp);
        return false;
    }

    return $attachment_id;
}