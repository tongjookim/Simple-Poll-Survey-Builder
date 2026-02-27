<?php
/*
Plugin Name: Simple Poll & Survey Builder
Description: Create and manage engaging polls and surveys directly on your WordPress site.
Version: 1.0.0
Author: The Suwan News Company
Author URI: https://www.swn.kr
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Text Domain: simple-poll-survey-builder
Requires at least: 5.0
Tested up to: 6.9
*/

if (!defined('ABSPATH')) exit;

// 1. DB 설정 및 초기화
register_activation_hook(__FILE__, 'cps_install_v9');
function cps_install_v9() {
    global $wpdb;
    $table_polls = $wpdb->prefix . 'cps_polls';
    $table_logs = $wpdb->prefix . 'cps_poll_logs';
    $charset_collate = $wpdb->get_charset_collate();

    $sql1 = "CREATE TABLE $table_polls (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        title text NOT NULL,
        options longtext NOT NULL,
        btn_color varchar(20) DEFAULT '#4285f4',
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    $sql2 = "CREATE TABLE $table_logs (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        poll_id mediumint(9) NOT NULL,
        user_id bigint(20) DEFAULT 0,
        selected_option text NOT NULL,
        ip_address varchar(50),
        voted_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql1);
    dbDelta($sql2);

    add_option('cps_only_member', 'no');
    add_option('cps_ip_check', 'yes');
}

// 2. 번역 로드 및 관리자 메뉴
add_action('plugins_loaded', function() {
    load_plugin_textdomain('simple-poll-survey-builder', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

add_action('admin_menu', function() {
    $slug = 'cps-polls';
    add_menu_page(__('Polls', 'simple-poll-survey-builder'), __('Polls', 'simple-poll-survey-builder'), 'manage_options', $slug, 'cps_poll_list_page', 'dashicons-chart-bar');
    add_submenu_page($slug, __('Add New Poll', 'simple-poll-survey-builder'), __('Add New Poll', 'simple-poll-survey-builder'), 'manage_options', 'cps-poll-add', 'cps_poll_form_page');
    add_submenu_page($slug, __('Poll Logs', 'simple-poll-survey-builder'), __('Poll Logs', 'simple-poll-survey-builder'), 'manage_options', 'cps-poll-logs', 'cps_poll_logs_page');
    add_submenu_page($slug, __('Settings', 'simple-poll-survey-builder'), __('Settings', 'simple-poll-survey-builder'), 'manage_options', 'cps-settings', 'cps_settings_page');
    add_submenu_page($slug, __('Help!', 'simple-poll-survey-builder'), __('Help!', 'simple-poll-survey-builder'), 'manage_options', 'cps-help', 'cps_help_page');
});

// 3. 관리자 CSS/JS (컬러피커 & 드래그)
add_action('admin_enqueue_scripts', function($hook) {
    if (strpos($hook, 'cps-poll-add') !== false) {
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        wp_enqueue_script('jquery-ui-sortable');
    }
});

// 4. 투표 처리 (PRG 패턴: 새로고침 오류 방지)
add_action('init', 'cps_handle_vote_submission');
function cps_handle_vote_submission() {
    if (isset($_POST['cps_vote_action']) && !empty($_POST['poll_id'])) {
        global $wpdb;
        $poll_id = intval($_POST['poll_id']);
        $ip = $_SERVER['REMOTE_ADDR'];
        $redirect_url = remove_query_arg(['poll_msg', 'show_res'], wp_get_referer());

        if (get_option('cps_only_member') === 'yes' && !is_user_logged_in()) {
            wp_redirect(add_query_arg(['poll_msg' => 'need_login', 'show_res' => $poll_id], $redirect_url));
            exit;
        }

        $voted = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}cps_poll_logs WHERE poll_id = %d AND ip_address = %s", $poll_id, $ip));
        if (get_option('cps_ip_check') === 'yes' && $voted) {
            wp_redirect(add_query_arg(['poll_msg' => 'already', 'show_res' => $poll_id], $redirect_url));
            exit;
        }

        $wpdb->insert($wpdb->prefix . 'cps_poll_logs', [
            'poll_id' => $poll_id,
            'user_id' => get_current_user_id(),
            'selected_option' => sanitize_text_field($_POST['vote_choice']),
            'ip_address' => $ip
        ]);

        wp_redirect(add_query_arg('show_res', $poll_id, $redirect_url));
        exit;
    }
}

// --- [관리자] 설문 목록 ---
function cps_poll_list_page() {
    global $wpdb;
    if (isset($_GET['delete'])) $wpdb->delete($wpdb->prefix . 'cps_polls', ['id' => intval($_GET['delete'])]);
    $polls = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}cps_polls ORDER BY id DESC");
    ?>
    <div class="wrap">
        <h1><?php _e('Poll List', 'simple-poll-survey-builder'); ?> <a href="?page=cps-poll-add" class="page-title-action"><?php _e('Add New', 'simple-poll-survey-builder'); ?></a></h1>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr><th><?php _e('Title', 'simple-poll-survey-builder'); ?></th><th><?php _e('Shortcode', 'simple-poll-survey-builder'); ?></th><th><?php _e('Actions', 'simple-poll-survey-builder'); ?></th></tr></thead>
            <tbody>
                <?php foreach($polls as $p): ?>
                <tr>
                    <td><strong><?php echo esc_html($p->title); ?></strong></td>
                    <td><code>[my_poll id="<?php echo $p->id; ?>"]</code></td>
                    <td>
                        <a href="?page=cps-poll-add&edit=<?php echo $p->id; ?>"><?php _e('Edit', 'simple-poll-survey-builder'); ?></a> | 
                        <a href="?page=cps-polls&delete=<?php echo $p->id; ?>" style="color:red;" onclick="return confirm('<?php _e('Are you sure?', 'simple-poll-survey-builder'); ?>')"><?php _e('Delete', 'simple-poll-survey-builder'); ?></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

// --- [관리자] 설문 추가/수정 (이미지 1, 2 버그 해결) ---
function cps_poll_form_page() {
    global $wpdb;
    $id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
    $poll = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}cps_polls WHERE id = %d", $id)) : null;

    if (isset($_POST['save_poll'])) {
        $data = [
            'title' => sanitize_text_field($_POST['title']),
            'options' => json_encode(array_values(array_filter($_POST['options'])), JSON_UNESCAPED_UNICODE),
            'btn_color' => sanitize_hex_color($_POST['btn_color'])
        ];
        if ($id) $wpdb->update($wpdb->prefix . 'cps_polls', $data, ['id' => $id]);
        else $wpdb->insert($wpdb->prefix . 'cps_polls', $data);
        echo "<script>location.href='?page=cps-polls';</script>"; exit;
    }

    $options = $poll ? json_decode($poll->options) : [__('Like', 'simple-poll-survey-builder'), __('Dislike', 'simple-poll-survey-builder')];
    ?>
    <div class="wrap">
        <h1><?php echo $id ? __('Edit Poll', 'simple-poll-survey-builder') : __('Add New Poll', 'simple-poll-survey-builder'); ?></h1>
        <style>
            .cps-admin-box { background:#fff; border:1px solid #ccd0d4; padding:20px; max-width:700px; margin-top:20px; border-radius:4px; }
            .opt-item { display:flex; align-items:center; margin-bottom:10px; background:#f6f7f7; padding:10px; border:1px solid #dcdcde; border-radius:4px; }
            .opt-item .handle { cursor:move; margin-right:10px; color:#8c8f94; }
            .opt-item input { flex:1; margin-right:10px !important; }
            .opt-item .remove-opt { color:#d63638; cursor:pointer; font-size:20px; }
            .ui-state-highlight { height:50px; background:#f0f6fb; border:1px dashed #2271b1; margin-bottom:10px; }
        </style>

        <div class="cps-admin-box">
            <form method="post">
                <table class="form-table">
                    <tr><th><?php _e('Title', 'simple-poll-survey-builder'); ?></th><td><input type="text" name="title" value="<?php echo $poll ? esc_attr($poll->title) : ''; ?>" class="regular-text" required></td></tr>
                    <tr><th><?php _e('Button Color', 'simple-poll-survey-builder'); ?></th><td><input type="text" name="btn_color" value="<?php echo $poll ? esc_attr($poll->btn_color) : '#4285f4'; ?>" class="cps-color-field"></td></tr>
                    <tr><th><?php _e('Options', 'simple-poll-survey-builder'); ?></th><td>
                        <div id="opt-list">
                            <?php foreach($options as $opt): ?>
                            <div class="opt-item">
                                <span class="handle dashicons dashicons-menu"></span>
                                <input type="text" name="options[]" value="<?php echo esc_attr($opt); ?>" required>
                                <span class="remove-opt dashicons dashicons-no-alt"></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="button button-secondary" id="add-opt-btn" style="margin-top:10px;">
                            <span class="dashicons dashicons-plus-alt2" style="vertical-align:middle"></span> <?php _e('Add Option', 'simple-poll-survey-builder'); ?>
                        </button>
                    </td></tr>
                </table>
                <?php submit_button(__('Save Poll', 'simple-poll-survey-builder'), 'primary', 'save_poll'); ?>
            </form>
        </div>
    </div>
    <script>
    jQuery(document).ready(function($) {
        if($.isFunction($.fn.wpColorPicker)) { $('.cps-color-field').wpColorPicker(); }
        $("#opt-list").sortable({ handle: '.handle', axis: 'y', placeholder: 'ui-state-highlight' });
        $('#add-opt-btn').on('click', function() {
            const html = `
                <div class="opt-item" style="display:none;">
                    <span class="handle dashicons dashicons-menu"></span>
                    <input type="text" name="options[]" required placeholder="<?php echo esc_js(__('Option text', 'simple-poll-survey-builder')); ?>">
                    <span class="remove-opt dashicons dashicons-no-alt"></span>
                </div>`;
            const $new = $(html); $('#opt-list').append($new); $new.fadeIn(200).find('input').focus();
        });
        $(document).on('click', '.remove-opt', function() { if($('.opt-item').length > 1) $(this).parent().remove(); });
    });
    </script>
    <?php
}

// --- [관리자] 참여 로그 & 환경설정 ---
function cps_poll_logs_page() {
    global $wpdb;
    $logs = $wpdb->get_results("SELECT l.*, p.title FROM {$wpdb->prefix}cps_poll_logs l LEFT JOIN {$wpdb->prefix}cps_polls p ON l.poll_id = p.id ORDER BY voted_at DESC LIMIT 50");
    echo '<div class="wrap"><h1>' . __('Poll Logs', 'simple-poll-survey-builder') . '</h1><table class="wp-list-table widefat fixed striped"><thead><tr><th>' . __('Poll', 'simple-poll-survey-builder') . '</th><th>' . __('Choice', 'simple-poll-survey-builder') . '</th><th>IP</th><th>' . __('Date', 'simple-poll-survey-builder') . '</th></tr></thead><tbody>';
    foreach($logs as $l) echo "<tr><td>".esc_html($l->title)."</td><td>".esc_html($l->selected_option)."</td><td>".esc_html($l->ip_address)."</td><td>{$l->voted_at}</td></tr>";
    echo '</tbody></table></div>';
}

function cps_settings_page() {
    if(isset($_POST['save_cps_settings'])) {
        update_option('cps_only_member', $_POST['cps_only_member']);
        update_option('cps_ip_check', isset($_POST['cps_ip_check']) ? 'yes' : 'no');
        echo '<div class="updated"><p>' . __('Settings saved.', 'simple-poll-survey-builder') . '</p></div>';
    }
    ?>
    <div class="wrap"><h1><?php _e('Poll Settings', 'simple-poll-survey-builder'); ?></h1>
        <form method="post">
            <table class="form-table">
                <tr><th><?php _e('Participation Permission', 'simple-poll-survey-builder'); ?></th><td>
                    <label><input type="radio" name="cps_only_member" value="no" <?php checked(get_option('cps_only_member'),'no');?>> <?php _e('All visitors can participate', 'simple-poll-survey-builder'); ?></label><br>
                    <label><input type="radio" name="cps_only_member" value="yes" <?php checked(get_option('cps_only_member'),'yes');?>> <?php _e('Only logged-in members can participate', 'simple-poll-survey-builder'); ?></label>
                </td></tr>
                <tr><th><?php _e('Duplicate Participation', 'simple-poll-survey-builder'); ?></th><td><label><input type="checkbox" name="cps_ip_check" value="yes" <?php checked(get_option('cps_ip_check'),'yes');?>> <?php _e('Restrict duplicate participation by IP', 'simple-poll-survey-builder'); ?></label></td></tr>
            </table><input type="submit" name="save_cps_settings" class="button button-primary" value="<?php _e('Save Settings', 'simple-poll-survey-builder'); ?>">
        </form>
    </div>
    <?php
}

function cps_help_page() {
    ?>
    <div class="wrap">
        <h1><?php _e('Help!', 'simple-poll-survey-builder'); ?></h1>
        <div class="card" style="max-width:800px; padding:20px; margin-top:20px;">
            <h2>1. <?php _e('How to use Shortcode', 'simple-poll-survey-builder'); ?></h2>
            <p><?php _e('Copy and paste the shortcode below into your page or post.', 'simple-poll-survey-builder'); ?></p>
            <code>[my_poll id="POLL_ID"]</code>
        </div>
        <div class="card" style="max-width:800px; padding:20px; margin-top:20px;">
            <h2>2. <?php _e('Plugin Author', 'simple-poll-survey-builder'); ?></h2>
            <p>Produced by <strong>수완뉴스(The Suwan News)</strong> <a href="http://www.swn.kr" target="_blank">www.swn.kr</a></p>
            <form action="https://www.paypal.com/cgi-bin/webscr" method="post" target="_top">
                <input type="hidden" name="cmd" value="_s-xclick" />
                <input type="hidden" name="hosted_button_id" value="5UXSGLXRN7WDE" />
                <input type="image" src="https://www.paypalobjects.com/en_US/i/btn/btn_donate_SM.gif" border="0" name="submit" title="PayPal - The safer, easier way to pay online!" alt="Donate with PayPal button" />
            </form>
        </div>
    </div>
    <?php
}

// --- [프론트엔드] 숏코드 렌더링 (이미지 3, 4 문제 해결) ---
function cps_render_poll($atts) {
    global $wpdb;
    $a = shortcode_atts(['id' => 0], $atts);
    $poll = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}cps_polls WHERE id = %d", $a['id']));
    if (!$poll) return "";

    $options = json_decode($poll->options);
    $btn_color = $poll->btn_color ?: '#801e58';
    $show_res = (isset($_GET['show_res']) && $_GET['show_res'] == $poll->id);
    $poll_msg_key = (isset($_GET['poll_msg']) && isset($_GET['show_res']) && $_GET['show_res'] == $poll->id) ? $_GET['poll_msg'] : '';

    $alert_msg = "";
    if ($poll_msg_key === 'need_login') $alert_msg = __('Please participate after logging in.', 'simple-poll-survey-builder');
    elseif ($poll_msg_key === 'already') $alert_msg = __('You have already participated in this poll.', 'simple-poll-survey-builder');

    ob_start(); ?>
    <style>
        .cps-card { border:1px solid #f0f0f0; padding:30px; max-width:450px; border-radius:20px; background:#fff; box-shadow:0 10px 30px rgba(0,0,0,0.05); margin:20px auto; font-family:sans-serif; }
        .cps-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; font-size:13px; }
        .cps-title { font-size:19px; font-weight:bold; color:#222; margin-bottom:25px; line-height:1.4; }
        .cps-option { display:block; margin-bottom:12px; cursor:pointer; font-size:15px; color:#444; }
        .cps-option input { margin-right:10px; accent-color: <?php echo $btn_color; ?>; }
        .cps-btn { width:100%; background:<?php echo $btn_color; ?>; color:#fff; border:none; padding:15px; border-radius:12px; font-weight:bold; cursor:pointer; margin-top:10px; }
        
        .cps-modal { display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); backdrop-filter:blur(3px); }
        .cps-modal-content { background:#fff; margin:10% auto; padding:40px; width:90%; max-width:480px; border-radius:25px; position:relative; }
        .cps-bar-bg { background:#f5f5f5; height:12px; border-radius:6px; margin:8px 0 20px; overflow:hidden; }
        .cps-fill { background:<?php echo $btn_color; ?>; height:100%; transition:width 1s ease-out; }
    </style>

    <?php if($alert_msg): ?><script>alert(`<?php echo esc_js($alert_msg); ?>`);</script><?php endif; ?>

    <div class="cps-card">
        <div class="cps-header">
            <span style="color:#888;">📊 <?php _e('Poll', 'simple-poll-survey-builder'); ?></span>
            <a href="javascript:void(0)" onclick="document.getElementById('m-<?php echo $poll->id; ?>').style.display='block'" style="color:<?php echo $btn_color; ?>; text-decoration:none; font-weight:bold;"><?php _e('View Results', 'simple-poll-survey-builder'); ?></a>
        </div>
        <div class="cps-title"><?php echo esc_html($poll->title); ?></div>
        <form method="post">
            <input type="hidden" name="cps_vote_action" value="1"><input type="hidden" name="poll_id" value="<?php echo $poll->id; ?>">
            <?php foreach($options as $opt): ?>
                <label class="cps-option"><input type="radio" name="vote_choice" value="<?php echo esc_attr($opt); ?>" required> <?php echo esc_html($opt); ?></label>
            <?php endforeach; ?>
            <button type="submit" class="cps-btn"><?php _e('Vote', 'simple-poll-survey-builder'); ?></button>
        </form>
    </div>

    <div id="m-<?php echo $poll->id; ?>" class="cps-modal" style="<?php echo ($show_res && $poll_msg_key !== 'need_login') ? 'display:block;' : ''; ?>">
        <div class="cps-modal-content">
            <span style="position:absolute; right:25px; top:20px; cursor:pointer; font-size:30px; color:#aaa;" onclick="document.getElementById('m-<?php echo $poll->id; ?>').style.display='none'">&times;</span>
            <h2 style="margin:0 0 10px 0; font-size:22px;"><?php _e('Poll Results', 'simple-poll-survey-builder'); ?></h2>
            <p style="color:#666; margin-bottom:30px;"><?php echo esc_html($poll->title); ?></p>
            <?php
            $total = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cps_poll_logs WHERE poll_id = %d", $poll->id));
            foreach($options as $opt) {
                $c = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cps_poll_logs WHERE poll_id = %d AND selected_option = %s", $poll->id, $opt));
                $p = ($total > 0) ? round(($c / $total) * 100, 1) : 0;
                echo "<div style='display:flex; justify-content:space-between; font-size:14px; font-weight:bold;'><span>".esc_html($opt)."</span> <span>" . sprintf(__('%d votes (%s%%)', 'simple-poll-survey-builder'), $c, $p) . "</span></div>";
                echo "<div class='cps-bar-bg'><div class='cps-fill' style='width:{$p}%'></div></div>";
            }
            ?>
            <div style="text-align:center; font-size:14px; color:#999; border-top:1px solid #eee; padding-top:20px;"><?php printf(__('Total Participants: %s', 'simple-poll-survey-builder'), number_format($total)); ?></div>
        </div>
    </div>
    <script>window.onclick=function(e){if(e.target.className=='cps-modal')e.target.style.display='none';}</script>
    <?php return ob_get_clean();
}
add_shortcode('my_poll', 'cps_render_poll');