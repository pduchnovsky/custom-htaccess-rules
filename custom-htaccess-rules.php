<?php
/*
Plugin Name: Custom .htaccess rules manager
Description: Manage custom .htaccess rules (top and bottom blocks) with shell-mode syntax highlighting and auto-expanding editor.
Version: 1.0.0
Plugin URI: https://github.com/pduchnovsky/custom-htaccess-rules
Author: pd
Author URI: https://duchnovsky.com
License: GPL-2.0+
License URI: http://www.gnu.org/licenses/gpl-2.0.txt
Text Domain: custom-htaccess-rules
Domain Path: /languages
*/

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants.
if (!defined('pd_cht_prefix')) {
    define('pd_cht_prefix', 'pd_cht_');
}
if (!defined(pd_cht_prefix . 'target_file')) {
    define(pd_cht_prefix . 'target_file', ABSPATH . '.htaccess');
}
if (!defined(pd_cht_prefix . 'backup_dir')) {
    define(pd_cht_prefix . 'backup_dir', WP_CONTENT_DIR . '/uploads/htaccess-backups/');
}

/**
 * Loads the plugin's text domain for internationalization.
 */
add_action('plugins_loaded', function () {
    load_plugin_textdomain('custom-htaccess-rules', false, basename(dirname(__FILE__)) . '/languages');
});

// Register plugin lifecycle hooks.
register_activation_hook(__FILE__, 'pd_cht_activate');
register_deactivation_hook(__FILE__, 'pd_cht_deactivate');
register_uninstall_hook(__FILE__, 'pd_cht_uninstall');

/**
 * Plugin activation tasks.
 * Ensures the backup directory exists and sets a default cleanup option.
 */
function pd_cht_activate() {
    if (!file_exists(pd_cht_backup_dir)) {
        wp_mkdir_p(pd_cht_backup_dir);
    }
    add_option(pd_cht_prefix . 'cleanup_on_uninstall', 'delete');
}

/**
 * Plugin deactivation tasks.
 */
function pd_cht_deactivate() {
    // No specific actions needed on deactivation for this plugin.
}

/**
 * Plugin uninstallation tasks.
 * Handles deletion of backup directory based on user option and deletes plugin option.
 */
function pd_cht_uninstall() {
    $cleanup_option = get_option(pd_cht_prefix . 'cleanup_on_uninstall', 'delete');

    if ($cleanup_option === 'delete') {
        pd_cht_delete_backup_directory();
    }

    delete_option(pd_cht_prefix . 'cleanup_on_uninstall');
}

/**
 * Deletes the backup directory and its contents recursively.
 * Uses WordPress Filesystem API for robust deletion.
 *
 * @return bool True on success, false on failure.
 */
function pd_cht_delete_backup_directory() {
    if (!file_exists(pd_cht_backup_dir)) {
        return true;
    }

    global $wp_filesystem;
    if (empty($wp_filesystem)) {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        $creds = request_filesystem_credentials(site_url() . '/wp-admin/', '', false, false, null);
        if (!WP_Filesystem($creds)) {
            return false;
        }
    }

    if ($wp_filesystem) {
        return $wp_filesystem->rmdir(pd_cht_backup_dir, true);
    }

    return false;
}

/**
 * Adds the options page to the WordPress admin menu.
 */
add_action('admin_menu', 'pd_cht_add_options_page');
function pd_cht_add_options_page() {
    add_options_page(
        'Custom .htaccess', // Page title - intentionally not translated.
        'Custom .htaccess', // Menu title - intentionally not translated.
        'manage_options',
        'pd_cht_custom_htaccess', // Changed this slug to be unique
        'pd_cht_settings_page'
    );
}

/**
 * Enqueues scripts and styles for the settings page.
 *
 * @param string $hook The current admin page hook.
 */
add_action('admin_enqueue_scripts', 'pd_cht_enqueue_admin_scripts');
function pd_cht_enqueue_admin_scripts($hook) {
    // The hook name now reflects the updated menu slug
    if ($hook !== 'settings_page_pd_cht_custom_htaccess') {
        return;
    }

    wp_enqueue_code_editor(['type' => 'shell']);
    wp_enqueue_script('wp-theme-plugin-editor');
    wp_enqueue_style('wp-codemirror');

    wp_add_inline_style('wp-codemirror', '.CodeMirror { height: auto !important; max-height: none !important; }');
}

/**
 * Renders the custom .htaccess settings page.
 */
function pd_cht_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'custom-htaccess-rules'));
    }

    $message = '';
    $error = '';

    if (!file_exists(pd_cht_backup_dir)) {
        if (!wp_mkdir_p(pd_cht_backup_dir)) {
            $error = esc_html__('Failed to create backup directory. Please check permissions.', 'custom-htaccess-rules');
        }
    }

    // Handle form submission for saving rules.
    if (isset($_POST['custom_htaccess_top']) && isset($_POST['custom_htaccess_bottom'])) {
        check_admin_referer('save_custom_htaccess');

        // For .htaccess rules (code-like input), wp_unslash and trim are used.
        // Stricter sanitization (e.g., sanitize_textarea_field) would strip valid .htaccess characters.
        $top_rules = trim(wp_unslash($_POST['custom_htaccess_top']));
        $bottom_rules = trim(wp_unslash($_POST['custom_htaccess_bottom']));

        if (!pd_cht_create_backup()) {
            $error = esc_html__('Failed to create a backup of the .htaccess file. Please check directory permissions for wp-content/uploads/htaccess-backups/. Rules were not saved.', 'custom-htaccess-rules');
        } else {
            $result = pd_cht_update_custom_htaccess($top_rules, $bottom_rules);

            if ($result === true) {
                $message = esc_html__('Custom rules saved successfully.', 'custom-htaccess-rules');
            } else {
                // translators: %s: Error message from file update function.
                $error_message_template = esc_html__('Failed to update the file: %s. Check file permissions or server logs.', 'custom-htaccess-rules');
                $error = sprintf(
                    $error_message_template,
                    esc_html($result)
                );
            }
        }
    }

    // Handle backup restoration.
    if (isset($_POST['pd_cht_restore_backup_nonce']) && wp_verify_nonce(wp_unslash($_POST['pd_cht_restore_backup_nonce']), 'pd_cht_restore_backup')) {
        $backup_file = isset($_POST['pd_cht_backup_file']) ? sanitize_text_field(wp_unslash($_POST['pd_cht_backup_file'])) : '';
        $backup_path = pd_cht_backup_dir . $backup_file;

        if (!empty($backup_file) && file_exists($backup_path)) {
            global $wp_filesystem;
            if (empty($wp_filesystem)) {
                require_once(ABSPATH . 'wp-admin/includes/file.php');
                $creds = request_filesystem_credentials(site_url() . '/wp-admin/', '', false, false, null);
                WP_Filesystem($creds);
            }

            if ($wp_filesystem && $wp_filesystem->copy($backup_path, pd_cht_target_file, true, FS_CHMOD_FILE)) {
                // translators: %s: Name of the restored backup file.
                $message = sprintf(esc_html__('Successfully restored backup from %s.', 'custom-htaccess-rules'), esc_html($backup_file));
            } else {
                // translators: %s: Name of the backup file that failed to restore.
                $error = sprintf(esc_html__('Failed to restore backup from %s. Check file permissions.', 'custom-htaccess-rules'), esc_html($backup_file));
                if ($wp_filesystem && !$wp_filesystem->is_writable(pd_cht_target_file)) {
                    $error .= ' ' . esc_html__('The .htaccess file is not writable.', 'custom-htaccess-rules');
                }
            }
        } else {
            $error = esc_html__('Invalid backup file selected.', 'custom-htaccess-rules');
        }
    }

    // Handle cleanup option save.
    if (isset($_POST['pd_cht_save_cleanup_option_nonce']) && wp_verify_nonce(wp_unslash($_POST['pd_cht_save_cleanup_option_nonce']), 'pd_cht_save_cleanup_option')) {
        $new_cleanup_option = isset($_POST['pd_cht_cleanup_on_uninstall']) ? sanitize_text_field(wp_unslash($_POST['pd_cht_cleanup_on_uninstall'])) : '';
        if (in_array($new_cleanup_option, ['delete', 'keep'])) {
            update_option(pd_cht_prefix . 'cleanup_on_uninstall', $new_cleanup_option);
            $message = esc_html__('Cleanup option updated successfully.', 'custom-htaccess-rules');
        } else {
            $error = esc_html__('Invalid cleanup option selected.', 'custom-htaccess-rules');
        }
    }

    $current_top = pd_cht_get_current_custom_htaccess_rules('top');
    $current_bottom = pd_cht_get_current_custom_htaccess_rules('bottom');
    $cleanup_option = get_option(pd_cht_prefix . 'cleanup_on_uninstall', 'delete');
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Custom .htaccess Rules', 'custom-htaccess-rules'); ?></h1>
        <?php if ($message): ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html($message); ?></p></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html($error); ?></p></div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('save_custom_htaccess'); ?>

            <h2><?php esc_html_e('Top of File', 'custom-htaccess-rules'); ?></h2>
            <p class="description"><?php esc_html_e('Rules entered here will be placed at the very beginning of your .htaccess file. Be cautious, incorrect rules can break your site.', 'custom-htaccess-rules'); ?></p>
            <textarea id="custom_htaccess_top" name="custom_htaccess_top" rows="15" style="width:100%;"><?php echo esc_textarea($current_top); ?></textarea>

            <h2><?php esc_html_e('Bottom of File', 'custom-htaccess-rules'); ?></h2>
            <p class="description"><?php esc_html_e('Rules entered here will be placed at the very end of your .htaccess file. Be cautious, incorrect rules can break your site.', 'custom-htaccess-rules'); ?></p>
            <textarea id="custom_htaccess_bottom" name="custom_htaccess_bottom" rows="15" style="width:100%;"><?php echo esc_textarea($current_bottom); ?></textarea>

            <p class="submit">
                <input type="submit" class="button button-primary" value="<?php esc_attr_e('Save Rules', 'custom-htaccess-rules'); ?>">
            </p>
        </form>

        <hr>

        <h2><?php esc_html_e('.htaccess Backups', 'custom-htaccess-rules'); ?></h2>
        <p class="description"><?php esc_html_e('A backup is automatically created when you save rules. You can restore from a previous backup here.', 'custom-htaccess-rules'); ?></p>

        <?php
        $backup_files = pd_cht_get_backup_files();
        if (!empty($backup_files)) {
            ?>
            <form method="post">
                <?php wp_nonce_field('pd_cht_restore_backup', 'pd_cht_restore_backup_nonce'); ?>
                <label for="pd_cht_backup_file"><strong><?php esc_html_e('Select a backup to restore:', 'custom-htaccess-rules'); ?></strong></label>
                <select name="pd_cht_backup_file" id="pd_cht_backup_file">
                    <?php
                    foreach ($backup_files as $file) {
                        echo '<option value="' . esc_attr($file) . '">' . esc_html($file) . '</option>';
                    }
                    ?>
                </select>
                <input type="submit" class="button button-secondary" value="<?php esc_attr_e('Restore Selected Backup', 'custom-htaccess-rules'); ?>" onclick="return confirm('<?php esc_attr_e('Are you sure you want to restore this backup? This will overwrite your current .htaccess file. Proceed with caution.', 'custom-htaccess-rules'); ?>');">
            </form>
            <?php
        } else {
            ?>
            <p><?php esc_html_e('No backups found. Backups are automatically created when you save rules.', 'custom-htaccess-rules'); ?></p>
            <?php
        }
        ?>

        <hr>

        <h2><?php esc_html_e('Uninstall Options', 'custom-htaccess-rules'); ?></h2>
        <p class="description"><?php esc_html_e('Choose how you want the plugin to behave when it is uninstalled (deleted from WordPress).', 'custom-htaccess-rules'); ?></p>
        <form method="post">
            <?php wp_nonce_field('pd_cht_save_cleanup_option', 'pd_cht_save_cleanup_option_nonce'); ?>
            <input type="radio" id="pd_cht_cleanup_delete" name="pd_cht_cleanup_on_uninstall" value="delete" <?php checked($cleanup_option, 'delete'); ?>>
            <label for="pd_cht_cleanup_delete"><?php esc_html_e('Delete all plugin data (including .htaccess backups) upon uninstallation.', 'custom-htaccess-rules'); ?></label><br>
            <input type="radio" id="pd_cht_cleanup_keep" name="pd_cht_cleanup_on_uninstall" value="keep" <?php checked($cleanup_option, 'keep'); ?>>
            <label for="pd_cht_cleanup_keep"><?php esc_html_e('Keep .htaccess backups on the server upon uninstallation.', 'custom-htaccess-rules'); ?></label>
            <p class="submit">
                <input type="submit" class="button button-secondary" value="<?php esc_attr_e('Save Uninstall Option', 'custom-htaccess-rules'); ?>">
            </p>
        </form>

    </div>

    <script>
    document.addEventListener("DOMContentLoaded", function () {
        ["custom_htaccess_top", "custom_htaccess_bottom"].forEach(id => {
            const textarea = document.getElementById(id);
            if (textarea) {
                if (window.wp && window.wp.codeEditor && window.wp.codeEditor.initialize) {
                    const editor = wp.codeEditor.initialize(textarea, {
                        codemirror: {
                            mode: 'shell',
                            lineNumbers: true,
                            indentUnit: 4,
                            tabSize: 4,
                            lineWrapping: true
                        }
                    });

                    if (editor && editor.codemirror) {
                        editor.codemirror.setOption('viewportMargin', Infinity);
                        editor.codemirror.refresh();
                    }
                } else {
                    console.warn('wp.codeEditor is not available. CodeMirror editor might not be initialized.');
                }
            }
        });
    });
    </script>
    <?php
}

/**
 * Retrieves custom .htaccess rules from a specific block within the .htaccess file.
 * Uses WP_Filesystem API for reading the file content.
 *
 * @param string $position 'top' or 'bottom' to specify which block to retrieve.
 * @return string The rules within the specified block, or an empty string if not found or on error.
 */
function pd_cht_get_current_custom_htaccess_rules($position = 'top') {
    if (!file_exists(pd_cht_target_file)) {
        return '';
    }

    global $wp_filesystem;
    if (empty($wp_filesystem)) {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        $creds = request_filesystem_credentials(site_url() . '/wp-admin/', '', false, false, null);
        WP_Filesystem($creds);
    }

    $content = '';
    if ($wp_filesystem && $wp_filesystem->exists(pd_cht_target_file)) {
        $content = $wp_filesystem->get_contents(pd_cht_target_file);
    }

    if ($content === false || empty($content)) {
        return '';
    }

    $block = $position === 'top' ? 'CustomRulesTop' : 'CustomRulesBottom';

    if (preg_match('/# BEGIN ' . preg_quote($block, '/') . '(.*?)# END ' . preg_quote($block, '/') . '/s', $content, $matches)) {
        return trim($matches[1]);
    }

    return '';
}

/**
 * Updates custom .htaccess rules using an atomic write approach for safety.
 * Uses WP_Filesystem API for all file operations (reading, writing, renaming, chmod).
 *
 * @param string $top_rules The rules for the top block.
 * @param string $bottom_rules The rules for the bottom block.
 * @return bool|string True on success, error message string on failure.
 */
function pd_cht_update_custom_htaccess($top_rules, $bottom_rules) {
    $top_block = "# BEGIN CustomRulesTop\n" . trim($top_rules) . "\n# END CustomRulesTop";
    $bottom_block = "# BEGIN CustomRulesBottom\n" . trim($bottom_rules) . "\n# END CustomRulesBottom";

    global $wp_filesystem;
    if (empty($wp_filesystem)) {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        $creds = request_filesystem_credentials(site_url() . '/wp-admin/', '', false, false, null);
        if (!WP_Filesystem($creds)) {
            return esc_html__('Failed to initialize WordPress Filesystem. Please check your file permissions or FTP/SSH credentials.', 'custom-htaccess-rules');
        }
    }

    $current_content = '';
    if ($wp_filesystem->exists(pd_cht_target_file)) {
        $current_content = $wp_filesystem->get_contents(pd_cht_target_file);
        if ($current_content === false) {
            return esc_html__('Failed to read current .htaccess file content.', 'custom-htaccess-rules');
        }
    }

    // Remove existing custom blocks.
    $content_without_top = preg_replace('/# BEGIN CustomRulesTop(.*?)# END CustomRulesTop/s', '', $current_content);
    $content_without_both = preg_replace('/# BEGIN CustomRulesBottom(.*?)# END CustomRulesBottom/s', '', $content_without_top);

    // Clean up extra newlines.
    $content_without_both = preg_replace("/\n{2,}/", "\n\n", $content_without_both);
    $content_without_both = trim($content_without_both);

    // Assemble the new content.
    $new_content_parts = [];
    if (!empty($top_block)) {
        $new_content_parts[] = $top_block;
    }
    if (!empty($content_without_both)) {
        $new_content_parts[] = $content_without_both;
    }
    if (!empty($bottom_block)) {
        $new_content_parts[] = $bottom_block;
    }

    $new_content = implode("\n\n", $new_content_parts) . "\n";

    // Atomic write implementation.
    $temp_file = pd_cht_target_file . '.temp_' . uniqid();

    if (!$wp_filesystem->put_contents($temp_file, $new_content, FS_CHMOD_FILE)) {
        if ($wp_filesystem->exists($temp_file)) {
            $wp_filesystem->delete($temp_file);
        }
        return esc_html__('Failed to write to temporary file. Check permissions for the .htaccess directory.', 'custom-htaccess-rules');
    }

    if (!$wp_filesystem->move($temp_file, pd_cht_target_file, true)) {
        if ($wp_filesystem->exists($temp_file)) {
            $wp_filesystem->delete($temp_file);
        }
        return esc_html__('Failed to rename temporary file to .htaccess. Check permissions or if the file is in use.', 'custom-htaccess-rules');
    }

    return true;
}

/**
 * Creates a backup of the current .htaccess file.
 *
 * @return bool True on success, false on failure.
 */
function pd_cht_create_backup() {
    if (!file_exists(pd_cht_target_file)) {
        return true;
    }

    global $wp_filesystem;
    if (empty($wp_filesystem)) {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        $creds = request_filesystem_credentials(site_url() . '/wp-admin/', '', false, false, null);
        if (!WP_Filesystem($creds)) {
            return false;
        }
    }

    if (!$wp_filesystem->exists(pd_cht_backup_dir)) {
        if (!$wp_filesystem->mkdir(pd_cht_backup_dir, FS_CHMOD_DIR)) {
            return false;
        }
    }

    $timestamp = current_time('Ymd-His');
    $backup_filename = '.htaccess-backup-' . $timestamp . '.bak';
    $backup_path = pd_cht_backup_dir . $backup_filename;

    if ($wp_filesystem->copy(pd_cht_target_file, $backup_path, true, FS_CHMOD_FILE)) {
        return true;
    } else {
        return false;
    }
}

/**
 * Gets a list of available .htaccess backup files, limited to the latest 10.
 *
 * @return array An array of backup filenames.
 */
function pd_cht_get_backup_files() {
    $backups = [];
    global /* @var WP_Filesystem_Base $wp_filesystem */ $wp_filesystem;
    if (empty($wp_filesystem)) {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        $creds = request_filesystem_credentials(site_url() . '/wp-admin/', '', false, false, null);
        if (!WP_Filesystem($creds)) {
            return $backups;
        }
    }

    if ($wp_filesystem->exists(pd_cht_backup_dir) && $wp_filesystem->is_dir(pd_cht_backup_dir)) {
        $files = $wp_filesystem->dirlist(pd_cht_backup_dir);
        if (is_array($files)) {
            foreach ($files as $filename => $file_info) {
                if (str_starts_with($filename, '.htaccess-backup-') && str_ends_with($filename, '.bak')) {
                    $backups[] = $filename;
                }
            }
        }
    }
    rsort($backups);
    return array_slice($backups, 0, 10);
}
