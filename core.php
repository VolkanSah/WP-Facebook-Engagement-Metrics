<?php
/**
 * Plugin Name
 *
 * @package           Facebook Engagement Metrics
 * @author            Volkan Kücükbudak
 * @copyright         2023 WordPress-Webmaster.de
 * @license           Privat
 *
 * @wordpress-plugin
 * Plugin Name:       Facebook Engagement Metrics
 * Plugin URI:        https://wordPress-webmaster.de
 * Description:       The Facebook Engagement Metrics WordPress Plugin displays engagement metrics such as likes, shares, and comments for published links on your WordPress dashboard. This tool is essential for social media and SEO marketing as it provides insights into the performance of your Facebook posts and allows you to optimize your content strategy accordingly. Use our plugin to monitor the impact of your social media marketing efforts, identify top-performing posts, and track engagement trends over time. Stay ahead of the competition and improve your online presence with our easy-to-use Facebook engagement metrics plugin for WordPress.
 * Version:           1.0.0
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            Your Name
 * Author URI:        https://github.com/VolkanSah/WP-Facebook-Engagement-Metrics/
 * Text Domain:       fb-em
 * License:           GPL v2 or later
 * License URI:       https://wordPress-webmaster.de/licenses/wordpress-global.txt
 * Update URI:        https://github.com/VolkanSah/WP-Facebook-Engagement-Metrics/
 */
// Create Option Page
function fb_engagement_metrics_options_page() {
        add_options_page(
        'Facebook Engagement Metrics',
        'Facebook Engagement Metrics',
        'manage_options',
        'fb-engagement-metrics',
        'fb_engagement_metrics_options_page_html'
    );
}
add_action('admin_menu', 'fb_engagement_metrics_options_page');
// Create Option Page HTML
function fb_engagement_metrics_options_page_html() {
    if (!current_user_can('manage_options')) {
        return;
    }
    if (isset($_GET['settings-updated'])) {
        add_settings_error('fb_engagement_metrics_messages', 'fb_engagement_metrics_message', 'Einstellungen gespeichert.', 'updated');
    }
    settings_errors('fb_engagement_metrics_messages');
    ?>
    <div class="wrap">
        <h1><?= esc_html(get_admin_page_title()); ?></h1>
        <form action="options.php" method="post">
            <?php
            settings_fields('fb_engagement_metrics');
            do_settings_sections('fb-engagement-metrics');
            submit_button('Einstellungen speichern');
            ?>
        </form>
    </div>
    <?php
}
function fb_engagement_metrics_settings_init() {
    register_setting('fb_engagement_metrics', 'fb_engagement_metrics_options');
    add_settings_section(
        'fb_engagement_metrics_section',
        'Einstellungen',
        null,
        'fb-engagement-metrics'
    );
    add_settings_field(
        'fb_engagement_metrics_app_id',
        'App-ID',
        'fb_engagement_metrics_app_id_callback',
        'fb-engagement-metrics',
        'fb_engagement_metrics_section'
    );
    add_settings_field(
        'fb_engagement_metrics_app_secret',
        'App-Geheimcode',
        'fb_engagement_metrics_app_secret_callback',
        'fb-engagement-metrics',
        'fb_engagement_metrics_section'
    );
}
add_action('admin_init', 'fb_engagement_metrics_settings_init');
function fb_engagement_metrics_app_id_callback() {
    $options = get_option('fb_engagement_metrics_options');
    ?>
    <input type="text" name="fb_engagement_metrics_options[app_id]" value="<?= esc_attr($options['app_id']); ?>" size="50">
    <?php
}
function fb_engagement_metrics_app_secret_callback() {
    $options = get_option('fb_engagement_metrics_options');
    ?>
    <input type="text" name="fb_engagement_metrics_options[app_secret]" value="<?= esc_attr($options['app_secret']); ?>" size="50">
    <?php
}
function generate_facebook_access_token($app_id, $app_secret) {
    $token_url = "https://graph.facebook.com/oauth/access_token?client_id={$app_id}&client_secret={$app_secret}&grant_type=client_credentials";
    $response = file_get_contents($token_url);
    $json = json_decode($response, true);
    return $json['access_token'];
}
function fetch_facebook_metrics($url, $access_token) {
    $request_url = "https://graph.facebook.com/v13.0/?id=" . urlencode($url) . "&fields=engagement&access_token=" . $access_token;
    $response = file_get_contents($request_url);
    $json = json_decode($response, true);
    $likes = $json['engagement']['reaction_count'];
    $shares = $json['engagement']['share_count'];
    $comments = $json['engagement']['comment_count'];

    return compact('likes', 'shares', 'comments');
}

function get_published_links() {
    $links = [];
    $query = new WP_Query([
        'post_type' => ['post', 'page'],
        'post_status' => 'publish',
        'posts_per_page' => -1
    ]);

    while ($query->have_posts()) {
        $query->the_post();
        $links[] = get_permalink();
    }
    wp_reset_postdata();

    return $links;
}

function display_facebook_metrics() {
    $options = get_option('fb_engagement_metrics_options');
    $app_id = $options['app_id'];
    $app_secret = $options['app_secret'];
    $access_token = generate_facebook_access_token($app_id, $app_secret);
    $links = get_published_links();
    echo "<table>";
    echo "<tr><th>Titel</th><th>Likes</th><th>Shares</th><th>Comments</th></tr>";
    foreach ($links as $link) {
        $post_id = url_to_postid($link);
        $post_title = get_the_title($post_id);
        $metrics = fetch_facebook_metrics($link, $access_token);
        echo "<tr>";
        echo "<td><a href='{$link}'>{$post_title}</a></td>";
        echo "<td>{$metrics['likes']}</td>";
        echo "<td>{$metrics['shares']}</td>";
        echo "<td>{$metrics['comments']}</td>";
        echo "</tr>";
    }
    echo "</table>";
}
function add_facebook_metrics_dashboard_widget() {
    wp_add_dashboard_widget('facebook_metrics_dashboard_widget', 'Facebook Engagement Metrics', 'display_facebook_metrics');
}
add_action('wp_dashboard_setup', 'add_facebook_metrics_dashboard_widget');
