<?php

add_action('rest_api_init', function () {

    /**
     * Create / Update post
     */
    register_rest_route('nl-be-sync/v1', '/post', [
        'methods'             => 'POST',
        'callback'            => 'nl_be_receive_post',
        'permission_callback' => 'nl_be_verify_secret',
    ]);

    /**
     * Delete post
     */
    register_rest_route('nl-be-sync/v1', '/post/delete', [
        'methods'             => 'POST',
        'callback'            => 'nl_be_receive_delete',
        'permission_callback' => 'nl_be_verify_secret',
    ]);
});

/**
 * Verify secret key
 */
// function nl_be_verify_secret() {

//     $secret = $_SERVER['HTTP_X_SYNC_SECRET'] ?? '';

//     return $secret === 'c7A$kL9vP2!rX5@Zq8TnYwM4e3s1u0';
// }
function nl_be_verify_secret(WP_REST_Request $request) {

    $secret = $request->get_header('x-sync-secret');

    error_log('SECRET RECEIVED: ' . $secret);

    return $secret === 'c7A$kL9vP2!rX5@Zq8TnYwM4e3s1u0';
}
/**
 * Create or update post
 */
function nl_be_receive_post(WP_REST_Request $request) {

    $data = $request->get_json_params();

    $source_post_id = $data['source_post_id'] ?? 0;
    $be_title       = $data['be_title'] ?? '';
    $be_content     = $data['be_content'] ?? '';
    $be_excerpt     = $data['be_excerpt'] ?? '';
    $be_slug        = $data['be_slug'] ?? '';
    $post_date      = $data['post_date'] ?? current_time('mysql');
    $image_url      = $data['image_url'] ?? '';

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
     * Find existing post
     */
    $existing_posts = get_posts([
        'post_type'   => 'vacatures',
        'numberposts' => 1,
        'meta_key'    => '_source_post_id',
        'meta_value'  => $source_post_id,
    ]);

    $post_data = [
        'post_type'     => 'vacatures',
        'post_status'   => 'publish',
        'post_title'    => $be_title,
        'post_content'  => $content,
        'post_excerpt'  => $be_excerpt,
        'post_name'     => $slug,
        'post_date'     => $post_date,
        'post_date_gmt' => get_gmt_from_date($post_date),
    ];

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
        return new WP_REST_Response(['error' => 'Failed to save post'], 500);
    }

    update_post_meta($post_id, '_source_modified', current_time('mysql'));

    /**
     * Featured image
     */
    if (!empty($image_url)) {
        nl_be_receive_featured_image($image_url, $post_id);
    }

    return new WP_REST_Response(['success' => true, 'post_id' => $post_id], 200);
}

/**
 * Delete post
 */
function nl_be_receive_delete(WP_REST_Request $request) {

    $data           = $request->get_json_params();
    $source_post_id = $data['source_post_id'] ?? 0;

    if (empty($source_post_id)) {
        return new WP_REST_Response(['error' => 'Missing source_post_id'], 400);
    }

    $existing_posts = get_posts([
        'post_type'   => 'vacatures',
        'numberposts' => 1,
        'meta_key'    => '_source_post_id',
        'meta_value'  => $source_post_id,
    ]);

    if (empty($existing_posts)) {
        return new WP_REST_Response(['error' => 'Post not found'], 404);
    }

    $result = wp_delete_post($existing_posts[0]->ID, true);

    if (!$result) {
        return new WP_REST_Response(['error' => 'Delete failed'], 500);
    }

    return new WP_REST_Response(['success' => true], 200);
}

/**
 * Set featured image on receive
 */
function nl_be_receive_featured_image($image_url, $post_id) {

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    delete_post_thumbnail($post_id);

    $tmp = download_url($image_url, 300);

    if (is_wp_error($tmp)) {
        return;
    }

    $file_array = [
        'name'     => wp_basename(parse_url($image_url, PHP_URL_PATH)),
        'tmp_name' => $tmp,
    ];

    $attachment_id = media_handle_sideload($file_array, $post_id);

    if (is_wp_error($attachment_id)) {
        @unlink($tmp);
        return;
    }

    set_post_thumbnail($post_id, $attachment_id);
}