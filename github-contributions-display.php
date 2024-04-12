<?php
/*
Plugin Name: GitHub Contributions Display
Plugin URI: https://heavyweightdigital.co.za/
Description: Fetches and displays GitHub yearly contributions. Configure your GitHub username and personal access token in the settings.
Version: 1.0.0
Author: Byron Jacobs
Author URI: https://heavyweightdigital.co.za
License: GPL2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires at least: 5.0
Tested up to: 5.9
Requires PHP: 7.3
Text Domain: github-contributions-display
*/

/* ~~~~ */
/* Initializes the plugin, sets up the shortcode and adds an admin settings page. */
/* ~~~~ */

if (!defined('ABSPATH')) exit; // Exit if accessed directly

require_once('inc/simple_html_dom.php');

add_action('admin_menu', 'github_contributions_menu');

function github_contributions_menu()
{
    add_options_page('GitHub Contributions Settings', 'GitHub Contributions', 'manage_options', 'github-contributions', 'github_contributions_settings_page');
}

function github_contributions_settings_page()
{
?>
    <div class="wrap">
        <h2>GitHub Contributions</h2>
        <form method="post" action="options.php">
            <?php settings_fields('github-contributions-settings-group'); ?>
            <?php do_settings_sections('github-contributions-settings-group'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">GitHub Username</th>
                    <td><input type="text" name="github_username" value="<?php echo esc_attr(get_option('github_username')); ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">GitHub Personal Access Token</th>
                    <td><input type="text" name="github_token" value="<?php echo esc_attr(get_option('github_token')); ?>" /></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
<?php
}

add_action('admin_init', 'github_contributions_register_settings');

function github_contributions_register_settings()
{
    register_setting('github-contributions-settings-group', 'github_username');
    register_setting('github-contributions-settings-group', 'github_token');
}

add_shortcode('github_contributions', 'fetch_github_contributions_via_api');

function fetch_github_contributions_via_api()
{
    $username = get_option('github_username', 'byronomio'); // Default to your username if not set
    $token = get_option('github_token', '');

    $today = new DateTime(); // Today's date
    $oneYearAgo = (new DateTime())->sub(new DateInterval('P1Y')); // Date one year ago
    $formattedToday = $today->format('Y-m-d\TH:i:s\Z');
    $formattedOneYearAgo = $oneYearAgo->format('Y-m-d\TH:i:s\Z');

    $url = "https://api.github.com/graphql";
    $query = <<<GRAPHQL
    {
        user(login: "$username") {
            contributionsCollection(from: "$formattedOneYearAgo", to: "$formattedToday") {
                contributionCalendar {
                    totalContributions
                    weeks {
                        contributionDays {
                            contributionCount
                            date
                            weekday
                        }
                    }
                }
            }
        }
    }
    GRAPHQL;

    $args = array(
        'body' => json_encode(['query' => $query]),
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $token
        ),
        'method' => 'POST',
        'data_format' => 'body'
    );

    $response = wp_remote_post($url, $args);
    if (is_wp_error($response)) {
        error_log('Error retrieving data: ' . $response->get_error_message());
        return "Error retrieving data: " . $response->get_error_message();
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (!empty($data['data']['user']['contributionsCollection'])) {
        $totalContributions = $data['data']['user']['contributionsCollection']['contributionCalendar']['totalContributions'];
        $weeks = $data['data']['user']['contributionsCollection']['contributionCalendar']['weeks'];
        return generate_contributions_html($weeks, $totalContributions);
    } else {
        return "No contributions data found or access denied.";
    }
}

function generate_contributions_html($weeks, $totalContributions)
{
    // Function to generate HTML output for displaying contributions
    $html = "<div class='total_contributions'>Total contributions this year: $totalContributions</div>";
    $html .= "<div class='container github'><table class='ContributionCalendar-grid js-calendar-graph-table'>";

    $months = [];
    foreach ($weeks as $week) {
        foreach ($week['contributionDays'] as $day) {
            $date = new DateTime($day['date']);
            $month = $date->format('m');
            $weekNumber = $date->format('W');
            $months[$month][$weekNumber][] = $day;
        }
    }

    foreach ($months as $month => $weeks) {
        $monthName = (new DateTime("{$month}/01"))->format('F');
        $html .= "<td class='month-column'>";
        $html .= "<div class='month-label'>{$monthName}</div>";
        foreach ($weeks as $weekNumber => $days) {
            $html .= "<div class='week-column'>";
            foreach ($days as $day) {
                $level = calculate_contribution_level($day['contributionCount']);
                $tooltip = "{$day['date']} - Contributions: {$day['contributionCount']}";
                $html .= "<div class='day level-{$level}' title='{$tooltip}'></div>";
            }
            $html .= "</div>";
        }
        $html .= "</td>";
    }

    $html .= '</tbody></table></div>';
    return $html;
}

function calculate_contribution_level($count)
{
    // Function to determine contribution level based on count
    if ($count === 0) return 0;
    if ($count < 5) return 1;
    if ($count < 10) return 2;
    if ($count < 20) return 3;
    return 4;
}

add_action('wp_enqueue_scripts', 'github_contributions_scripts');

function github_contributions_scripts()
{
    wp_enqueue_style('github-contributions-css', plugins_url('/inc/assets/style.css', __FILE__));

}
?>