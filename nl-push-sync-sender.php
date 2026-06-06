<?php
/**
 * Plugin Name: NL → BE Push Sync Sender
 */

if (!defined('ABSPATH')) exit;

class NL_BE_Push_Sync_Sender {

    private $endpoint = 'http://10.10.10.242/wp-json/nl-be/v1/receive';
    private $secret   = 'c7A$kL9vP2!rX5@Zq8TnYwM4e3s1u0';

    public function __construct() {
        add_action('save_post', [$this, 'send_post'], 20, 3);
    }

    public function send_post($post_id, $post, $update) {

error_log('SYNC TRIGGERED for post: ' . $post_id);

        // autosave / revision
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;

        // Only publish
        if ($post->post_status !== 'publish') return;

        // Only post type
        if ($post->post_type !== 'post') return;

        // Prevent loop
        if (get_post_meta($post_id, '_synced_from_be', true)) return;

        // ACF
        $acf = function_exists('get_fields') ? get_fields($post_id) : [];

        $data = [
            'source_id' => $post_id,
            'title'     => $post->post_title,
            'content'   => $post->post_content,
            'excerpt'   => $post->post_excerpt,
            'slug'      => $post->post_name,
            'date'      => $post->post_date,
            'acf'       => $acf,
        ];

        // Featured Image
        if (has_post_thumbnail($post_id)) {
            $data['featured_image'] = get_the_post_thumbnail_url($post_id, 'full');
        }

        // wp_remote_post($this->endpoint, [
        //     'method'  => 'POST',
        //     'timeout' => 20,
        //     'headers' => [
        //         'Content-Type'  => 'application/json',
        //         'Authorization' => 'Bearer ' . $this->secret,
        //     ],
        //     'body' => json_encode($data),
        // ]);


        // test
        $response = wp_remote_post($this->endpoint, [
    'method'  => 'POST',
    'timeout' => 20,
    'headers' => [
        'Content-Type'  => 'application/json',
        'Authorization' => 'Bearer ' . $this->secret,
    ],
    'body' => json_encode($data),
]);

// ✅ CHECK RESULT
if (is_wp_error($response)) {

    error_log('SYNC ERROR: ' . $response->get_error_message());

} else {

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    error_log('SYNC RESPONSE CODE: ' . $code);
    error_log('SYNC BODY: ' . $body);
}


    }
}

new NL_BE_Push_Sync_Sender();