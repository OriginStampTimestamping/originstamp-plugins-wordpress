<?php

/**
 * Plugin Name: OriginStamp
 * Plugin URI: http://www.originstamp.org
 * Description: Creates a tamper-proof timestamp of your content each time it is modified. The timestamp is created with the Bitcoin blockchain.
 * Version: 0.3
 * Author: Thomas Hepp, André Gernandt, Eugen Stroh
 * Author URI: https://github.com/thhepp/
 * License: The MIT License (MIT)
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

/**
 *  http://wordpress.org/plugins/about/readme.txt, http://generatewp.com/plugin-readme/
 */


// Define api key and email to save for future uses.
define("ORIGINSTAMP_SETTINGS", serialize(array(
    "api_key" => "",
    "email" => ""
)));

// Add font-awesome styles to Originstamp settings page.
function admin_register_head() {
    // font-awesome repo at cdnjs
    $url = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css';
    echo "<link rel='stylesheet' type='text/css' href='$url' />\n";
}
add_action('admin_head', 'admin_register_head');

//TODO: Check version of DB, add table name to options.
// Add a custom table to DB to store hashed data.
register_activation_hook( __FILE__, 'create_hash_data_table' );
function create_hash_data_table() {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    add_option("db_table_name", 'hash_data');
    $table_name = $wpdb->prefix . get_option('db_table_name');

    $sql = "CREATE TABLE $table_name (
		sha256 varchar(64) UNIQUE NOT NULL,
		time datetime DEFAULT CURRENT_TIMESTAMP,
        post_title tinytext NOT NULL,
        post_content longtext NOT NULL,
		PRIMARY KEY (sha256)
	) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

    try
    {
        $res = dbDelta($sql);
        if (!$res)
        {
            echo "Database table could not be created!";
        }
    }
    catch (Exception $e)
    {
        echo "An error occurred while creating data table:\n\n" . $e;
    }
}

function insert_hash_in_table($hash_string, $post_title, $post_content)
{
    global $wpdb;

    $table_name = $wpdb->prefix . get_option('db_table_name');
    $wpdb->insert($table_name,
        array('sha256' => $hash_string, 'post_title' => $post_title, 'post_content' => $post_content),
        array());
}

function retrieve_hash_from_table($hash_string)
{
    global $wpdb;
    $table_name = $wpdb->prefix . get_option('db_table_name');
    $sql = "SELECT * FROM $table_name WHERE sha256 = \"$hash_string\"";
    $result = $wpdb->get_row($sql);
    $data = $result->post_title . $result->post_content;

    return $data;
}

// Add uninstallation hook to delete data table after removing Plugin.
register_uninstall_hook(__FILE__, 'on_uninstall');
function on_uninstall()
{
    global $wpdb;

    $table_name = $wpdb->prefix . get_option('db_table_name');
    $sql = 'DROP TABLE IF EXISTS ' .  $table_name;
    $wpdb->query($sql);
}

add_action('init', 'download_hash_data');
add_action('template_redirect', 'download_hash_data');
function download_hash_data() {
    if (isset($_GET['d']))
    {
        $hash_string = $_GET['d'];
        $data = retrieve_hash_from_table($hash_string);
        if (!$data)
            exit('Hash string not found in database.');
        header("Content-type: application/x-msdownload",true,200);
        header("Content-Disposition: attachment; filename=$hash_string.txt");
        header("Pragma: no-cache");
        header("Expires: 0");
        echo $data;
        exit();
    }
}

function create_originstamp($post_id)
{
    // Create a SHA256 value from WP post or edit.
    if (wp_is_post_revision($post_id))
        return;
    $title = preg_replace(
            "/[\n\r ]+/",//Also more than one space
            " ",//space
            wp_strip_all_tags(get_the_title($post_id)));
    $content = preg_replace(
            "/[\n\r ]+/",//Also more than one space
            " ",//space
            wp_strip_all_tags(get_post_field('post_content', $post_id)));

    $data = $title . $content;
    $hash_string = hash('sha256', $data);
    $body['hash_string'] = $hash_string;

    insert_hash_in_table($hash_string, $title, $content);

    send_to_originstamp_api($body, $hash_string);
    send_confirm_email($data, $hash_string);
}

function send_to_originstamp_api($body, $hashString)
{
    // Send computed hash value to OriginStamp.
    $options = get_options();
    $body['email'] = $options['email'];

    $response = wp_remote_post('https://api.originstamp.org/api/' . $hashString, array(
        'method' => "POST",
        'timeout' => 45,
        'redirection' => 5,
        'httpversion' => "1.1",
        'blocking' => true,
        'headers' => array(
            'content-type' => "application/json",
            'Authorization' => $options['api_key']
        ),
        'body' => json_encode($body),
        'cookies' => array()

    ));

    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        $message = "Sorry, we had some issues posting your data to OriginStamp:\n";
        wp_mail($options['email'], "Originstamp: Error.", $message . $error_message);

        return $error_message;
    }

    return $response;
}

function send_confirm_email($data, $hash_string)
{
    // Send confirmation Email to user.
    // I no Email address provided, nothing will be sent.
    $instructions = "Please store this Email. You need to hash following value with a SHA256:\n\n";
    $header = "================ START TEXT =================\n";
    $footer = "\n================ END TEXT ===================";
    $options = get_options();
    if (!$options['email'])
    {
        return '';
    }
    $msg = $instructions . $header .$data . $footer;
    $headers = "Content-type: text/plain";
    $temp = fopen('php://temp', 'w+');
    fwrite($temp, $data);
    rewind($temp);
    fpassthru($temp);
    $response = wp_mail($options['email'], "OriginStamp " . $hash_string, $msg, $headers, array($temp));
    fclose($temp);

    return $response;
}

function originstamp_action_links($links)
{
    array_unshift($links, '<a href="' . admin_url('options-general.php?page=originstamp') . '">Settings</a>');
    return $links;
}

function get_hashes_for_api_key($offset, $records)
{
    // Get hash table for API key.
    /*POST fields for table request.
     email
     hash_string
     comment
     date_created
     api_key
     offset
     records*/
    $options = get_options();
    $body['api_key'] = $options['api_key'];

    if ($body['api_key'] == '')
        return array();

    // Start offset
    $body['offset'] = $offset;
    // Number of records
    $body['records'] = $records;

    $response = wp_remote_post('https://api.originstamp.org/api/table', array(
        'method' => "POST",
        'timeout' => 45,
        'redirection' => 5,
        'httpversion' => "1.1",
        'blocking' => true,
        'headers' => array(
            'content-type' => "application/json",
            'Authorization' => $options['api_key']
        ),
        'body' => json_encode($body),
        'cookies' => array()

    ));

    return $response;
}

function originstamp_admin_menu()
{
    register_setting('originstamp', 'originstamp');

    add_settings_section('originstamp', __('Settings'), 'settings_section', 'originstamp');
    add_settings_field('originstamp_description', __('Description'), 'description', 'originstamp', 'originstamp');
    add_settings_field('originstamp_api_key', __('API Key'), 'api_key', 'originstamp', 'originstamp');
    add_settings_field('originstamp_sender_email', __('Sender Email'), 'sender_email', 'originstamp', 'originstamp');
    add_options_page(__('OriginStamp'), __('OriginStamp'), 'manage_options', 'originstamp', 'originstamp_admin_page');
    add_settings_field('originstamp_db_status', __('DB status'), 'get_db_status', 'originstamp', 'originstamp');
    add_settings_field('oroginstamp_hash_table', __('Hash table'), 'hashes_for_api_key', 'originstamp', 'originstamp');
}

add_action('save_post', 'create_originstamp');
add_action('admin_menu', 'originstamp_admin_menu');
add_action('wp_head', 'hashes_for_api_key');
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'originstamp_action_links');

function settings_section()
{
    ;
}

function description()
{
    echo '<p> This plugin saves and stores every single stage of your posts. Anytime you hit the save button while creating or editing a post, we will save the stage of your work in local data table. The timestamp is secure within the Bitcoin network and verifiable to anyone who is in possession of a coyp of the data.</p><br>';
    echo '<p> <b>What content is saved?</b>';
    echo '<p class="description">We save the post title, the post body as plain text where all layout tags and line breaks are removed.</p>';
    echo '<p> <b>What content is timestamped?</b>';
    echo '<p class="description">We save the post title, the post body as plain text. To hash the post we concatenate the post title and the post body with a space in between. The sha256 of the generated string is being sent to originStamp to be timestamped.</p>';
    echo '<p> <b>How to verify a timestamp?</b>';
    echo '<p class="description">In order to verify the timestamp you would have to download the data, copy the string that is stored in the text file and then use any sha256 calculator of your choice to hash the string. After that go to OriginStamp and search for the hash. There you will also find further instructions and features.</p>';
}

function get_db_status()
{
    global $wpdb;
    $table_name = $wpdb->prefix . get_option('db_table_name');
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name)
    {
        echo '<p style="color: rgb(0, 150, 136)">Database table created: "' . $table_name . '".</p>';
    }
    else
    {
        echo '<p style="color: rgb(255, 152, 0)">ERROR: Data table does not exist!</p>';
    }
    echo '<p class="description">Here you can check status of the database that stores hashed post data.</p>';
}

function get_options()
{
    $options = (array)get_option('originstamp');
    return $options;
}

function originstamp_admin_page()
{
    ?>
    <div class="wrap">
        <h2><?php _e('OriginStamp'); ?></h2>
        <?php if (!empty($options['invalid_emails']) && $_GET['settings-updated']) : ?>
            <div class="error">
                <p><?php  ?></p>
            </div>
        <?php endif; ?>

        <form action="options.php" method="post">
            <?php settings_fields('originstamp'); ?>
            <?php do_settings_sections('originstamp'); ?>
            <p class="submit">
                <input type="submit" class="button-primary" value="<?php esc_attr_e('Save Changes'); ?>"/>
            </p>
        </form>
    </div>
    <?php
}

function api_key()
{
    // Read in API key.
    $options = get_options();
    ?>
    <input title="API key" type="text" name="originstamp[api_key]" size="40" value="<?php echo $options['api_key'] ?>"/>
    <p class="description"><?php _e('An API key is required to create timestamps. Receive your personal key here:') ?>
       <a href="https://www.originstamp.org/dev">https://www.originstamp.org/dev</a></p>
    <?php
}

function sender_email()
{
    // Optional:
    $options = get_options();
    ?>
    <input title="Email" type="text" name="originstamp[email]" size="40" value="<?php echo $options['email'] ?>"/>
    <p class="description"><?php _e('Please provide an Email address so that we can send your data. You need to store your data to be able to verify it.') ?>
    <?php
}

function parse_table($response_json_body)
{
    echo '<table style="display: inline-table;">';
    echo '<tr><th>Date created</th><th>Hash string (SHA256)</th><th>Status</th><th>Data</th></tr>';
    foreach ($response_json_body->hashes as $hash) {
        // From milliseconds to seconds.
        $date_created = $hash->date_created / 1000;
        $submit_status = $hash->submit_status->multi_seed;
        $hash_string = $hash->hash_string;
        $db_res = retrieve_hash_from_table($hash_string);
        echo '<tr>';
            echo '<td>' . gmdate("Y-m-d H:i:s", $date_created). '</td>';

            echo '<td>';
            echo '<a href="https://originstamp.org/s/'
                . $hash_string
                . '"'
                . ' target="_blank"'
                . '">'
                . $hash_string
                . '</a>';
            echo '</td>';
            echo '<td>';
            if ($submit_status == 3)
            {
                try
                {
                    echo '<i style="color: rgb(0, 150, 136)" class="fa fa-check-circle-o" aria-hidden="true"></i>';
                }
                catch (Exception $e)
                {
                    echo $submit_status;
                }
            }
            else
            {
                try
                {
                    echo '<i style="color: rgb(255, 152, 0)" class="fa fa-clock-o" aria-hidden="true"></i>';
                }
                catch (Exception $e)
                {
                    echo $submit_status;
                }
            }
            echo '</td>';
            echo '<td>';
                echo "<a href=\"?page=originstamp&d=$hash_string\" title=\"download\" target=\"_blank\">download</a>";
            echo '</td>';
        echo '</tr>';
    }
    echo '</table>';
}

function hashes_for_api_key()
{
    // Maximum number of pages the API will return.
    $limit = 25;

    // Get first record, to determine, how many records there are overall.
    $get_page_info = get_hashes_for_api_key(0, 1);
    if (!$get_page_info)
        return;

    // Handle errors.
    if (is_wp_error($get_page_info)) {
        $error_message = $get_page_info->get_error_message();
        echo '<p style="color: rgb(255, 152, 0)">An error occurred while retrieving hash table:</p><br/>' . $error_message;
        return;
    }

    // Extract bory from response.
    $page_info_json_obj = json_decode($get_page_info['body']);

    // Total number of records in the database.
    $total = $page_info_json_obj->total_records;

    // Overall number of pages
    $num_of_pages = ceil($page_info_json_obj->total_records / $limit);

    // GET current page
    $page = min($num_of_pages, filter_input(INPUT_GET, 'p', FILTER_VALIDATE_INT, array(
        'options' => array(
            'default'   => 1,
            'min_range' => 1,
        ),
    )));
    // Calculate the offset for the query
    $offset = ($page - 1) * $limit;

    // Some information to display to the user
    $start = $offset + 1;
    $end = min(($offset + $limit), $total);

    // The "back" link
    $prevlink = ($page > 1) ? '<a href="?page=originstamp&p=1" title="First page">&laquo;</a> <a href="?page=originstamp' . '&p=' . ($page - 1) . '" title="Previous page">&lsaquo;</a>' : '<span class="disabled">&laquo;</span> <span class="disabled">&lsaquo;</span>';

    // The "forward" link
    $nextlink = ($page < $num_of_pages) ? '<a href="?page=originstamp' . '&p=' . ($page + 1) . '" title="Next page">&rsaquo;</a> <a href="?page=originstamp' . '&p=' . $num_of_pages . '" title="Last page">&raquo;</a>' : '<span class="disabled">&rsaquo;</span> <span class="disabled">&raquo;</span>';

    // Display the paging information
    echo '<p class="description">A list of all your hashes submitted with API key above: <br></p>';
    echo '<div id="paging"><p>', $prevlink, ' Page ', $page, ' of ', $num_of_pages, ' pages, displaying ', $start, '-', $end, ' of ', $total, ' results ', $nextlink, ' </p></div>';

    // Get data from API.
    $response = get_hashes_for_api_key($offset, $limit);
    $response_json_body = json_decode($response['body']);

    // Parse response.
    parse_table($response_json_body);

    return;
}

?>
