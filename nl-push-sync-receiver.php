<?php
/**
 * Plugin Name: NL → BE Push Sync Receiver
 */

if (!defined('ABSPATH')) exit;

class NL_BE_Push_Sync_Receiver {

    private $secret = 'c7A$kL9vP2!rX5@Zq8TnYwM4e3s1u0';

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_route']);
    }

    public function register_route() {

        register_rest_route('nl-be/v1', '/receive', [
            'methods'  => 'POST',
            'callback' => [$this, 'handle'],
            'permission_callback' => [$this, 'authorize'],
        ]);
    }

    public function authorize($request) {

        $auth = $request->get_header('authorization');

        return $auth === 'Bearer ' . $this->secret;
    }

    public function handle($request) {

        $data = $request->get_json_params();

        if (empty($data['source_id'])) {
            return new WP_Error('no_id', 'Missing source_id', ['status' => 400]);
        }

        // Find existing post
        $existing = get_posts([
            'post_type'   => 'post',
            'numberposts' => 1,
            'meta_key'    => '_source_id',
            'meta_value'  => $data['source_id'],
        ]);

        $post_data = [
            'post_title'   => $data['title'],
            'post_content' => wpautop($data['content']),
            'post_excerpt' => $data['excerpt'],
            'post_name'    => sanitize_title($data['slug']),
            'post_status'  => 'publish',
            'post_date'    => $data['date'],
        ];

        if ($existing) {

            $post_data['ID'] = $existing[0]->ID;
            $post_id = wp_update_post($post_data);

        } else {

            $post_id = wp_insert_post($post_data);

            update_post_meta($post_id, '_source_id', $data['source_id']);
        }

        if (is_wp_error($post_id)) return $post_id;

        // Prevent loop
        update_post_meta($post_id, '_synced_from_be', true);

        /**
         * ACF fields
         */
        if (!empty($data['acf']) && function_exists('update_field')) {
            foreach ($data['acf'] as $key => $value) {
                update_field($key, $value, $post_id);
            }
        }

        /**
         * Featured Image
         */
        if (!empty($data['featured_image'])) {
            $this->set_featured_image($data['featured_image'], $post_id);
        }

        return [
            'status' => 'success',
            'post_id' => $post_id
        ];
    }

    private function set_featured_image($url, $post_id) {

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        if (has_post_thumbnail($post_id)) return;

        $tmp = download_url($url);

        if (is_wp_error($tmp)) return;

        $file = [
            'name'     => basename($url),
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload($file, $post_id);

        if (!is_wp_error($attachment_id)) {
            set_post_thumbnail($post_id, $attachment_id);
        }
    }
}

new NL_BE_Push_Sync_Receiver();