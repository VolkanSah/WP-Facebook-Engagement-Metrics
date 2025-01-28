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
 * Plugin Name:       Facebook Engagement Metrics
 * Plugin URI:        https://wordPress-webmaster.de
 * Description:       The Facebook Engagement Metrics WordPress Plugin displays engagement metrics such as likes, shares, and comments for published links on your WordPress dashboard. This tool is essential for social media and SEO marketing as it provides insights into the performance of your Facebook posts and allows you to optimize your content strategy accordingly. Use our plugin to monitor the impact of your social media marketing efforts, identify top-performing posts, and track engagement trends over time. Stay ahead of the competition and improve your online presence with our easy-to-use Facebook engagement metrics plugin for WordPress.
 * Version:           1.0.0
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            Volkan Kücükbudak
 * Author URI:        https://github.com/VolkanSah/WP-Facebook-Engagement-Metrics/
 * Text Domain:       fb-em
 * License:           Privat
 * License URI:       
 * Update URI:        https://github.com/VolkanSah/WP-Facebook-Engagement-Metrics/
 */



/**************** NEUE FUNKTIONALITÄTEN ****************/

// Cron-Intervall hinzufügen
add_filter('cron_schedules', function($schedules) {
    $schedules['every_three_hours'] = [
        'interval' => 10800, // 3 Stunden
        'display'  => __('Alle 3 Stunden')
    ];
    return $schedules;
});

// Aktivierungs/Deaktivierungs-Hooks
register_activation_hook(__FILE__, 'fb_engagement_metrics_activate');
register_deactivation_hook(__FILE__, 'fb_engagement_metrics_deactivate');

function fb_engagement_metrics_activate() {
    if (!wp_next_scheduled('fb_engagement_metrics_cron_hook')) {
        wp_schedule_event(time(), 'every_three_hours', 'fb_engagement_metrics_cron_hook');
    }
}

function fb_engagement_metrics_deactivate() {
    wp_clear_scheduled_hook('fb_engagement_metrics_cron_hook');
}

/**************** ÜBERARBEITETE FUNKTIONEN ****************/

// Generiere Access Token mit Caching
function generate_facebook_access_token($app_id, $app_secret) {
    $transient_key = 'fb_access_token_' . md5($app_id . $app_secret);
    
    if ($token = get_transient($transient_key)) {
        return $token;
    }

    $response = wp_remote_get(
        "https://graph.facebook.com/oauth/access_token?client_id={$app_id}&client_secret={$app_secret}&grant_type=client_credentials"
    );

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) != 200) {
        error_log('Facebook API Fehler: ' . print_r($response, true));
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    if (isset($body['access_token'])) {
        set_transient($transient_key, $body['access_token'], DAY_IN_SECONDS); // 24h Cache
        return $body['access_token'];
    }

    return false;
}

// Holen der Metriken mit Caching und Batch-Processing
add_action('fb_engagement_metrics_cron_hook', 'fb_engagement_metrics_update_all');

function fb_engagement_metrics_update_all() {
    $options = get_option('fb_engagement_metrics_options');
    
    if (empty($options['app_id']) || empty($options['app_secret'])) {
        error_log('Facebook Metrics: App-ID oder Secret nicht konfiguriert');
        return;
    }

    if (!$access_token = generate_facebook_access_token($options['app_id'], $options['app_secret'])) {
        error_log('Facebook Metrics: Zugriffstoken konnte nicht generiert werden');
        return;
    }

    $links = get_published_links();
    $batches = array_chunk($links, 50); // Batchgröße 50 URLs

    foreach ($batches as $batch) {
        $batch_requests = [];
        
        foreach ($batch as $url) {
            $batch_requests[] = [
                'method' => 'GET',
                'relative_url' => '/v19.0/?id=' . urlencode($url) . '&fields=engagement'
            ];
        }

        $response = wp_remote_post('https://graph.facebook.com/', [
            'body' => [
                'access_token' => $access_token,
                'batch' => json_encode($batch_requests)
            ]
        ]);

        if (is_wp_error($response)) {
            error_log('Facebook Batch Fehler: ' . $response->get_error_message());
            continue;
        }

        $results = json_decode(wp_remote_retrieve_body($response), true);

        foreach ($results as $index => $result) {
            if (200 !== $result['code'] || empty($result['body'])) continue;
            
            $data = json_decode($result['body'], true);
            $metrics = [
                'likes' => $data['engagement']['reaction_count'] ?? 0,
                'shares' => $data['engagement']['share_count'] ?? 0,
                'comments' => $data['engagement']['comment_count'] ?? 0,
                'last_updated' => time()
            ];
            
            set_transient(
                'fb_metrics_' . md5($batch[$index]),
                $metrics,
                4 * HOUR_IN_SECONDS // 4 Stunden Cache
            );
        }
    }
}

/**************** DASHBOARD WIDGET ****************/

function display_facebook_metrics() {
    $links = get_published_links();
    
    echo '<div class="wrap">';
    echo '<h3>Facebook Engagement (zuletzt aktualisiert: ' 
         . date('d.m.Y H:i', get_transient('fb_metrics_last_update')) 
         . ')</h3>';
    echo '<table class="widefat striped">';
    echo '<thead><tr>
            <th>Beitrag</th>
            <th>Likes</th>
            <th>Shares</th>
            <th>Comments</th>
            <th>Aktualität</th>
          </tr></thead>';
    
    foreach ($links as $link) {
        $metrics = get_transient('fb_metrics_' . md5($link)) ?: [
            'likes' => '–',
            'shares' => '–',
            'comments' => '–',
            'last_updated' => 'N/A'
        ];
        
        echo sprintf(
            '<tr>
                <td><a href="%s" target="_blank">%s</a></td>
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
            $metrics['last_updated'] !== 'N/A' ? 
                human_time_diff($metrics['last_updated']) . ' her' : 'N/A'
        );
    }
    
    echo '</table>';
    echo '<p><small>Daten werden alle 3 Stunden aktualisiert. <a href="' . 
         admin_url('tools.php?page=fb-engagement-metrics&force_update=1') . 
         '">Jetzt aktualisieren</a></small></p>';
    echo '</div>';
}

/**************** MANUELLE AKTUALISIERUNG ****************/

add_action('admin_init', function() {
    if (current_user_can('manage_options') && 
        isset($_GET['force_update']) && 
        $_GET['force_update'] === '1') {
        fb_engagement_metrics_update_all();
        set_transient('fb_metrics_last_update', time(), YEAR_IN_SECONDS);
        wp_redirect(admin_url('tools.php?page=fb-engagement-metrics'));
        exit;
    }
});













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

/**
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
    // graph url can be changed in future. I use only basic code so check url if doesnt work
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

**/
