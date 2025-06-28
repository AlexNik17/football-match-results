<?php

/**
 * Plugin Name: Football Match Results
 * Description: Displays match results, team info, and league data via Football-Data API.
 * Version: 1.0.0
 * Author: Alexander Nikitenko
 */

if (!defined('ABSPATH')) exit;

// Define constants
// define('FMR_API_KEY', 'ac454ff29ef0434ca443a67c258f54eb');
define('FMR_API_ENDPOINT', 'https://api.football-data.org/v4/');
define('FMR_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('FMR_CACHE_EXPIRATION', apply_filters('fmr_cache_lifetime', 3600)); // 1 hour


// Register admin settings page for API key
add_action('admin_menu', 'fmr_add_settings_page');
function fmr_add_settings_page()
{
    add_options_page(
        'Football Match Results Settings',
        'FMR Settings',
        'manage_options',
        'fmr-settings',
        'fmr_render_settings_page'
    );
}

add_action('admin_init', 'fmr_register_settings');
function fmr_register_settings()
{
    register_setting('fmr_settings_group', 'fmr_api_key');

    add_settings_section(
        'fmr_api_section',
        'API Configuration',
        null,
        'fmr-settings'
    );

    add_settings_field(
        'fmr_api_key_field',
        'API Key',
        'fmr_api_key_field_callback',
        'fmr-settings',
        'fmr_api_section'
    );
}

function fmr_api_key_field_callback()
{
    $value = esc_attr(get_option('fmr_api_key', ''));
    echo '<input type="text" name="fmr_api_key" value="' . $value . '" class="regular-text" />';
}

function fmr_get_api_key()
{
    return get_option('fmr_api_key', '');
}

function fmr_render_settings_page()
{
?>
    <div class="wrap">
        <h1>Football Match Results – Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('fmr_settings_group');
            do_settings_sections('fmr-settings');
            submit_button();
            ?>
        </form>
    </div>
<?php
}

// Enqueue styles
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('fmr-style', plugin_dir_url(__FILE__) . 'css/fmr-style.css');
});

// Enqueue block editor assets
add_action('enqueue_block_editor_assets', function () {
    wp_enqueue_script(
        'fmr-block',
        plugin_dir_url(__FILE__) . 'build/index.js',
        ['wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n'],
        filemtime(plugin_dir_path(__FILE__) . 'build/index.js')
    );
});

// Load plugin translations from the /languages directory
add_action('init', function () {
    load_plugin_textdomain('fmr', false, dirname(plugin_basename(__FILE__)) . '/languages');
});


// Register Gutenberg block
add_action('init', function () {
    register_block_type('fmr/data-block', [
        'render_callback' => 'fmr_render_matches_block',
        'attributes' => [
            'league' => ['type' => 'string', 'default' => 'PL'],
            'from' => ['type' => 'string'],
            'to' => ['type' => 'string'],
            'style' => ['type' => 'string', 'default' => 'default'],
            'status' => ['type' => 'string', 'default' => ''],
        ],
    ]);
});

//  Render a list of teams for a given league.
function fmr_render_teams($league, $style = 'default')
{
    $data = fmr_get_api_data("competitions/{$league}/teams");

    if (empty($data['teams'])) return '<p>No teams found.</p>';

    ob_start();
    echo '<table class="fmr-table fmr-style-' . esc_attr($style) . '">';
    echo '<thead><tr><th>Logo</th><th>Team Name</th><th>Short Name</th><th>Website</th></tr></thead><tbody>';
    foreach ($data['teams'] as $team) {
        echo '<tr>';
        echo '<td><img src="' . esc_url($team['crest']) . '" alt="' . esc_attr($team['name']) . '" width="30" /></td>';
        echo '<td>' . esc_html($team['name']) . '</td>';
        echo '<td>' . esc_html($team['shortName'] ?? '-') . '</td>';
        echo '<td>';
        if (!empty($team['website'])) {
            echo '<a href="' . esc_url($team['website']) . '" target="_blank">Website</a>';
        } else {
            echo '-';
        }
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    return ob_get_clean();
}


// Render block on frontend
function fmr_render_matches_block($attributes)
{
    $type = $attributes['type'] ?? 'matches';
    $style = $attributes['style'] ?? 'default';

    switch ($type) {
        case 'matches':
        case 'results':
            return fmr_render_matches_shortcode([
                'league' => $attributes['league'] ?? 'PL',
                'from'   => $attributes['from'] ?? date('Y-m-d', strtotime('-7 days')),
                'to'     => $attributes['to'] ?? date('Y-m-d'),
                'style'  => $style,
                'status' => $attributes['status'] ?? '',
            ]);

        case 'teams':
            return fmr_render_teams($attributes['league'] ?? 'PL', $style);

        default:
            return '<p>Invalid block type selected.</p>';
    }
}

// Fetch and cache data from Football Data API
function fmr_get_api_data($endpoint, $params = [])
{
    $url = FMR_API_ENDPOINT . $endpoint;
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    $cache_key = 'fmr_' . md5($url);
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return $cached;
    }

    $response = wp_remote_get($url, [
        'headers' => [
            'X-Auth-Token' => fmr_get_api_key(),
        ],
    ]);

    if (is_wp_error($response)) {
        error_log('FMR API error: ' . $response->get_error_message());
        return [];
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (empty($data)) {
        return [];
    }

    if (!empty($data)) {
        set_transient($cache_key, $data, FMR_CACHE_EXPIRATION);
    }

    return $data;
}

// Shortcode fallback
add_shortcode('fmr_matches', 'fmr_render_matches_shortcode');
function fmr_render_matches_shortcode($atts)
{
    $atts = shortcode_atts([
        'league' => 'PL',
        'from' => date('Y-m-d', strtotime('-7 days')),
        'to' => date('Y-m-d'),
        'style'  => 'default',
        'status' => '',
    ], $atts);

    $params = [
        'dateFrom' => $atts['from'],
        'dateTo' => $atts['to'],
    ];

    if (!empty($atts['status'])) {
        $params['status'] = $atts['status'];
    }

    $matches = fmr_get_api_data('competitions/' . $atts['league'] . '/matches', $params);

    if (empty($matches['matches'])) return '<p>' . esc_html__('No matches found.', 'fmr') . '</p>';

    ob_start();
    echo '<table class="fmr-table fmr-style-' . esc_attr($atts['style']) . '">';
    echo '<thead><tr><th>Date</th><th>Home</th><th>Score</th><th>Away</th></tr></thead><tbody>';
    foreach ($matches['matches'] as $match) {
        echo '<tr>';
        echo '<td data-label="Date">' . esc_html(date('Y-m-d', strtotime($match['utcDate']))) . '</td>';
        echo '<td data-label="Home">' . esc_html($match['homeTeam']['name']) . '</td>';
        $homeScore = $match['score']['fullTime']['home'] ?? '-';
        $awayScore = $match['score']['fullTime']['away'] ?? '-';
        echo '<td data-label="Score">' . esc_html($homeScore . ' - ' . $awayScore) . '</td>';
        echo '<td data-label="Away">' . esc_html($match['awayTeam']['name']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    return ob_get_clean();
}
