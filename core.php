<?php
/**
 * Plugin Name
 *
 * @package           Facebook Engagement Metrics
 * @author            Volkan Kücükbudak
 * @copyright         2018-2023 
 * @license           Privat
 *
 * @wordpress-plugin
 * Plugin Name:       Facebook Engagement Metrics v2
 * Plugin URI:        https://github.com/VolkanSah/WP-Facebook-Engagement-Metrics/
 * Description:       The Facebook Engagement Metrics WordPress Plugin displays engagement metrics such as likes, shares, and comments for published links on your WordPress dashboard. This tool is essential for social media and SEO marketing as it provides insights into the performance of your Facebook posts and allows you to optimize your content strategy accordingly. Use our plugin to monitor the impact of your social media marketing efforts, identify top-performing posts, and track engagement trends over time. Stay ahead of the competition and improve your online presence with our easy-to-use Facebook engagement metrics plugin for WordPress.
 * Version:           1.0.0
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            Volkan Kücükbudak
 * Author URI:        https://github.com/VolkanSah/
 * Text Domain:       fb-em
 * License:           Privat
 * License URI:       
 * Update URI:        https://github.com/VolkanSah/WP-Facebook-Engagement-Metrics/
 */



// Exit if accessed directly
if (!defined('ABSPATH')) exit;

/**************** ORIGINAL SETTINGS-TEIL (UNVERÄNDERT) ****************/

// Option Page
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

// Option Page HTML
function fb_engagement_metrics_options_page_html() {
    if (!current_user_can('manage_options')) return;

    if (isset($_GET['settings-updated'])) {
        add_settings_error('fb_engagement_metrics_messages', 'fb_engagement_metrics_message', 'Einstellungen gespeichert.', 'updated');
    }
    
    settings_errors('fb_engagement_metrics_messages'); ?>
    <div class="wrap">
        <h1><?= esc_html(get_admin_page_title()) ?></h1>
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

// Settings Init
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

// Settings Fields
function fb_engagement_metrics_app_id_callback() {
    $options = get_option('fb_engagement_metrics_options');
    echo '<input type="text" name="fb_engagement_metrics_options[app_id]" value="'.esc_attr($options['app_id']).'" size="50">';
}

function fb_engagement_metrics_app_secret_callback() {
    $options = get_option('fb_engagement_metrics_options');
    echo '<input type="text" name="fb_engagement_metrics_options[app_secret]" value="'.esc_attr($options['app_secret']).'" size="50">';
}

/**************** OPTIMIERTER FUNKTIONSTEIL (NEU) ****************/

// Cron-System
add_filter('cron_schedules', function($schedules) {
    $schedules['every_three_hours'] = [
        'interval' => 10800,
        'display'  => __('Alle 3 Stunden')
    ];
    return $schedules;
});

register_activation_hook(__FILE__, function() {
    if (!wp_next_scheduled('fb_engagement_metrics_cron_hook')) {
        wp_schedule_event(time(), 'every_three_hours', 'fb_engagement_metrics_cron_hook');
    }
});

register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('fb_engagement_metrics_cron_hook');
});

// Access Token Generation with Cache
function generate_facebook_access_token($app_id, $app_secret) {
    $transient_key = 'fb_access_token_'.md5($app_id.$app_secret);
    
    if ($token = get_transient($transient_key)) {
        return $token;
    }

    $response = wp_remote_get(
        "https://graph.facebook.com/oauth/access_token?client_id=$app_id&client_secret=$app_secret&grant_type=client_credentials"
    );

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) != 200) {
        error_log('Facebook API Fehler: '.print_r($response, true));
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    if (isset($body['access_token'])) {
        set_transient($transient_key, $body['access_token'], DAY_IN_SECONDS);
        return $body['access_token'];
    }

    return false;
}

// Cron-Job Handler
add_action('fb_engagement_metrics_cron_hook', function() {
    $options = get_option('fb_engagement_metrics_options');
    
    if (empty($options['app_id']) || empty($options['app_secret'])) {
        error_log('Facebook Metrics: Konfigurationsfehler');
        return;
    }

    if (!$access_token = generate_facebook_access_token($options['app_id'], $options['app_secret'])) {
        error_log('Facebook Metrics: Token-Generierung fehlgeschlagen');
        return;
    }

    $links = get_published_links();
    $batches = array_chunk($links, 50);

    foreach ($batches as $batch) {
        $requests = array_map(function($url) {
            return [
                'method' => 'GET',
                'relative_url' => '/v19.0/?id='.urlencode($url).'&fields=engagement'
            ];
        }, $batch);

        $response = wp_remote_post('https://graph.facebook.com/', [
            'body' => [
                'access_token' => $access_token,
                'batch' => json_encode($requests)
            ]
        ]);

        if (!is_wp_error($response)) {
            $results = json_decode(wp_remote_retrieve_body($response), true);
            foreach ($results as $index => $result) {
                if (200 === $result['code'] && isset($result['body'])) {
                    $data = json_decode($result['body'], true);
                    $metrics = [
                        'likes' => $data['engagement']['reaction_count'] ?? 0,
                        'shares' => $data['engagement']['share_count'] ?? 0,
                        'comments' => $data['engagement']['comment_count'] ?? 0,
                        'last_updated' => time()
                    ];
                    set_transient('fb_metrics_'.md5($batch[$index]), $metrics, 4*HOUR_IN_SECONDS);
                }
            }
        }
    }
    set_transient('fb_metrics_last_update', time(), YEAR_IN_SECONDS);
});

// Dashboard Widget
function display_facebook_metrics() {
    $links = get_published_links();
    
    echo '<div class="wrap"><table class="widefat striped"><thead>
          <tr><th>Beitrag</th><th>Likes</th><th>Shares</th><th>Comments</th><th>Aktualisierung</th></tr>
          </thead><tbody>';
    
    foreach ($links as $link) {
        $metrics = get_transient('fb_metrics_'.md5($link)) ?: [
            'likes' => '–',
            'shares' => '–',
            'comments' => '–',
            'last_updated' => 0
        ];
        
        echo sprintf(
            '<tr>
                <td><a href="%s">%s</a></td>
                <td>%s</td>
                <td>%s</td>
                <td>%s</td>
                <td>%s</td>
            </tr>',
            esc_url($link),
            esc_html(get_the_title(url_to_postid($link))),
            $metrics['likes'],
            $metrics['shares'],
            $metrics['comments'],
            $metrics['last_updated'] ? human_time_diff($metrics['last_updated']).' her' : '–'
        );
    }
    
    echo '</tbody></table>';
    echo '<p style="margin-top:15px"><small>Letzte Gesamtaktualisierung: '.
         date('d.m.Y H:i', get_transient('fb_metrics_last_update')).
         ' <a href="'.admin_url('options-general.php?page=fb-engagement-metrics&force_update=1').'">(Manuell aktualisieren)</a></small></p>';
}

add_action('wp_dashboard_setup', function() {
    wp_add_dashboard_widget(
        'facebook_metrics_dashboard_widget',
        'Facebook Engagement Metrics',
        'display_facebook_metrics'
    );
});

// Manuelle Aktualisierung
add_action('admin_init', function() {
    if (current_user_can('manage_options') && isset($_GET['force_update'])) {
        do_action('fb_engagement_metrics_cron_hook');
        wp_redirect(admin_url('options-general.php?page=fb-engagement-metrics'));
        exit;
    }
});

// Hilfsfunktionen
function get_published_links() {
    $query = new WP_Query([
        'post_type' => ['post', 'page'],
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids'
    ]);
    
    return array_map('get_permalink', $query->posts);
}
