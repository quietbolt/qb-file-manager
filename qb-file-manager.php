<?php
/*
Plugin Name: QB File Manager by QuietBolt
Description: A simple and powerful WordPress admin file manager. Browse folders, create and edit files, upload assets, and manage everything from the WP dashboard with a clean UI.
Version: 1.0
Author: quietbolt
Author URI: https://github.com/quietbolt
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: qb-file-manager
Tags: file manager, admin tools, file editor, folder browser, developer tool, file upload, file system
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.2
*/

// Security checks
defined('ABSPATH') or die('No direct script access allowed.');

class QB_Root_File_Manager {
    private $base_dir;
    // Define file extension categories as class properties
    private $editable_extensions;
    private $image_extensions;
    private $pdf_extensions;
    private $archive_extensions;
    private $allowed_upload_extensions; // Consolidated list for upload validation

    public function __construct() {
        // Set the base directory to the WordPress root
        $this->base_dir = ABSPATH;

        // Initialize file extension categories
        $this->editable_extensions = ['php', 'html', 'css', 'js', 'txt', 'json', 'xml', 'md', 'log', 'ini', 'htaccess', 'svg', 'csv', 'yml'];
        $this->image_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'ico', 'bmp'];
        $this->pdf_extensions = ['pdf'];
        $this->archive_extensions = ['zip', 'tar', 'gz', 'rar'];
        // Combine for upload validation
        $this->allowed_upload_extensions = array_merge(
            $this->editable_extensions,
            $this->image_extensions,
            $this->pdf_extensions,
            $this->archive_extensions
        );

        // Add admin menu and action hooks
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts_and_styles']);

        // Register AJAX actions for logged-in users
        add_action('wp_ajax_qb_fm_list_items', [$this, 'ajax_list_items']);
        add_action('wp_ajax_qb_fm_create_folder', [$this, 'ajax_create_folder']);
        add_action('wp_ajax_qb_fm_create_file', [$this, 'ajax_create_file']);
        add_action('wp_ajax_qb_fm_upload_file', [$this, 'ajax_upload_file']);
        add_action('wp_ajax_qb_fm_delete_item', [$this, 'ajax_delete_item']);
        add_action('wp_ajax_qb_fm_get_file_content', [$this, 'ajax_get_file_content']);
        add_action('wp_ajax_qb_fm_save_file_content', [$this, 'ajax_save_file_content']);
    }

    /**
     * Sanitize and validate the requested path to prevent directory traversal.
     * Ensures the path stays within the base directory.
     *
     * @param string $path The path to sanitize.
     * @return string The sanitized and validated relative path.
     */
    private function sanitize_and_validate_path($path) {
        // Remove any leading/trailing slashes and decode URL entities
        $path = trim(urldecode($path), '/');

        // Resolve path to its canonicalized absolute pathname
        $full_requested_path = realpath($this->base_dir . $path);

        // If realpath fails (e.g., path doesn't exist), default to base_dir
        if ($full_requested_path === false) {
            return '';
        }

        // Ensure the resolved path is within the base directory
        // This is the most critical security check for directory traversal
        $base_dir_realpath = realpath($this->base_dir);
        if (strpos($full_requested_path, $base_dir_realpath) !== 0) {
            return ''; // Path is outside the base directory, return empty (root)
        }

        // Return the relative path from the base directory
        return ltrim(str_replace($base_dir_realpath, '', $full_requested_path), '/');
    }

    /**
     * Adds the file manager menu item to the WordPress admin sidebar.
     */
    public function admin_menu() {
        add_menu_page(
            esc_html__('QB File Manager', 'qb-file-manager'), // Page title
            esc_html__('QB File Manager', 'qb-file-manager'), // Menu item title
            'manage_options', // Only users with 'manage_options' capability can access
            'qb-root-file-manager',
            [$this, 'render_admin_page'],
            'dashicons-media-archive', // Icon
            30
        );
    }

    /**
     * Enqueues scripts and styles for the admin page.
     */
    public function enqueue_scripts_and_styles() {
        // Only enqueue on our plugin page
        if (isset($_GET['page']) && $_GET['page'] === 'qb-root-file-manager') {
            // Enqueue custom styles
            wp_enqueue_style(
                'qb-file-manager-styles',
                plugin_dir_url(__FILE__) . 'css/qb-file-manager.css',
                [],
                '1.9.3' // Updated version for cache busting
            );

            // Enqueue custom JavaScript
            wp_enqueue_script(
                'qb-file-manager-script',
                plugin_dir_url(__FILE__) . 'js/qb-file-manager.js',
                [], // No jQuery dependency needed for vanilla JS
                '1.9.3', // Updated version for cache busting
                true // In footer
            );

            // Pass PHP variables to JavaScript
            wp_localize_script(
                'qb-file-manager-script',
                'qbFileManager',
                [
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce'    => wp_create_nonce('qb_file_manager_nonce'),
                    'base_dir_name' => basename(ABSPATH), // For display purposes
                    // Pass the defined extensions for JS to use for UI logic
                    'editable_extensions' => $this->editable_extensions,
                    'image_extensions' => $this->image_extensions,
                    'pdf_extensions' => $this->pdf_extensions,
                    'archive_extensions' => $this->archive_extensions,
                ]
            );
        }
    }

    /**
     * Helper to check permissions and nonce for AJAX requests.
     */
    private function check_ajax_security() {
        // Nonce verification is done here for all AJAX requests
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'qb_file_manager_nonce')) {
            wp_send_json_error(['message' => esc_html__('Security check failed. Please refresh the page.', 'qb-file-manager')]);
            die();
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => esc_html__('Unauthorized access.', 'qb-file-manager')]);
            die(); // Terminate execution after sending JSON error
        }
    }

    /**
     * Determines the type of file for UI purposes.
     * @param string $filename
     * @return string 'image', 'pdf', 'code', 'archive', 'other'
     */
    private function get_file_display_type($filename) {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // Use the class properties defined in __construct
        if (in_array($ext, $this->image_extensions, true)) {
            return 'image';
        } elseif (in_array($ext, $this->pdf_extensions, true)) {
            return 'pdf';
        } elseif (in_array($ext, $this->editable_extensions, true)) {
            return 'code'; // Treat all editable as 'code' for now, or refine
        } elseif (in_array($ext, $this->archive_extensions, true)) {
            return 'archive';
        } else {
            return 'other';
        }
    }

    /**
     * AJAX handler to list items in a directory.
     */
    public function ajax_list_items() {
        $this->check_ajax_security();

        $dir_relative = isset($_POST['dir']) ? sanitize_text_field(wp_unslash($_POST['dir'])) : '';
        $full_path = realpath($this->base_dir . $this->sanitize_and_validate_path($dir_relative));

        if (!$full_path || !is_dir($full_path)) {
            wp_send_json_error(['message' => esc_html__('Directory not found or invalid path.', 'qb-file-manager')]);
            die();
        }

        $items_raw = scandir($full_path);
        if ($items_raw === false) {
            wp_send_json_error(['message' => esc_html__('Could not read directory contents. Check permissions.', 'qb-file-manager')]);
            die();
        }

        $items_raw = array_diff($items_raw, ['.', '..']);
        $directories = [];
        $files = [];

        foreach ($items_raw as $item) {
            $item_full_path = trailingslashit($full_path) . $item;
            $item_relative_path = trailingslashit($dir_relative) . $item;

            if (is_dir($item_full_path)) {
                $directories[] = [
                    'name' => $item,
                    'type' => 'folder',
                    'size' => '-', // Folders don't have a direct size
                    'modified' => gmdate('Y-m-d H:i:s', filemtime($item_full_path)), // Use gmdate
                    'relative_path' => $item_relative_path,
                ];
            } else {
                $file_size = filesize($item_full_path);
                $files[] = [
                    'name' => $item,
                    'type' => $this->get_file_display_type($item), // 'image', 'pdf', 'code', 'archive', 'other'
                    'size' => size_format($file_size),
                    'modified' => gmdate('Y-m-d H:i:s', filemtime($item_full_path)), // Use gmdate
                    'relative_path' => $item_relative_path,
                ];
            }
        }
        // Sort folders first, then files, both alphabetically
        usort($directories, function($a, $b) { return strcasecmp($a['name'], $b['name']); });
        usort($files, function($a, $b) { return strcasecmp($a['name'], $b['name']); });
        $sorted_items = array_merge($directories, $files);

        wp_send_json_success([
            'current_dir' => $dir_relative,
            'items'       => $sorted_items,
        ]);
        die(); // Terminate execution after sending JSON success
    }

    /**
     * AJAX handler to create a new folder.
     */
    public function ajax_create_folder() {
        $this->check_ajax_security();

        $dir_relative = isset($_POST['dir']) ? sanitize_text_field(wp_unslash($_POST['dir'])) : '';
        $folder_name = isset($_POST['name']) ? sanitize_file_name(wp_unslash($_POST['name'])) : '';

        if (empty($folder_name)) {
            wp_send_json_error(['message' => esc_html__('Folder name cannot be empty.', 'qb-file-manager')]);
            die();
        }

        $full_path = realpath($this->base_dir . $this->sanitize_and_validate_path($dir_relative));
        if (!$full_path || !is_dir($full_path)) {
            wp_send_json_error(['message' => esc_html__('Target directory not found or invalid path.', 'qb-file-manager')]);
            die();
        }

        $new_folder_path = trailingslashit($full_path) . $folder_name;

        if (file_exists($new_folder_path)) {
            wp_send_json_error(['message' => esc_html__('Folder already exists.', 'qb-file-manager')]);
            die();
        }

        if (mkdir($new_folder_path, 0755, true)) {
            wp_send_json_success(['message' => esc_html__('Folder created successfully.', 'qb-file-manager')]);
            die();
        } else {
            wp_send_json_error(['message' => esc_html__('Failed to create folder. Check permissions.', 'qb-file-manager')]);
            die();
        }
    }

    /**
     * AJAX handler to create a new file.
     */
    public function ajax_create_file() {
        $this->check_ajax_security();

        $dir_relative = isset($_POST['dir']) ? sanitize_text_field(wp_unslash($_POST['dir'])) : '';
        $file_name = isset($_POST['name']) ? sanitize_file_name(wp_unslash($_POST['name'])) : '';

        if (empty($file_name)) {
            wp_send_json_error(['message' => esc_html__('File name cannot be empty.', 'qb-file-manager')]);
            die();
        }

        $full_path = realpath($this->base_dir . $this->sanitize_and_validate_path($dir_relative));
        if (!$full_path || !is_dir($full_path)) {
            wp_send_json_error(['message' => esc_html__('Target directory not found or invalid path.', 'qb-file-manager')]);
            die();
        }

        $new_file_path = trailingslashit($full_path) . $file_name;

        if (file_exists($new_file_path)) {
            wp_send_json_error(['message' => esc_html__('File already exists.', 'qb-file-manager')]);
            die();
        }

        if (file_put_contents($new_file_path, '') !== false) {
            wp_send_json_success(['message' => esc_html__('File created successfully.', 'qb-file-manager')]);
            die();
        } else {
            wp_send_json_error(['message' => esc_html__('Failed to create file. Check permissions.', 'qb-file-manager')]);
            die();
        }
    }

    /**
     * AJAX handler to upload a file.
     */
    public function ajax_upload_file() {
        $this->check_ajax_security();

        $dir_relative = isset($_POST['dir']) ? sanitize_text_field(wp_unslash($_POST['dir'])) : '';
        $full_path = realpath($this->base_dir . $this->sanitize_and_validate_path($dir_relative));

        if (!$full_path || !is_dir($full_path)) {
            wp_send_json_error(['message' => esc_html__('Target directory not found or invalid path.', 'qb-file-manager')]);
            die();
        }

        if (!isset($_FILES['file_upload']) || !isset($_FILES['file_upload']['error']) || UPLOAD_ERR_OK !== (int) $_FILES['file_upload']['error']) {
            $error_code = isset($_FILES['file_upload']['error']) ? (int) $_FILES['file_upload']['error'] : esc_html__('unknown', 'qb-file-manager');
            wp_send_json_error(['message' => sprintf(esc_html__('File upload failed with error code: %s', 'qb-file-manager'), $error_code)]);
            die();
        }

        $uploaded_file = $_FILES['file_upload'];
        $uploaded_file_name = sanitize_file_name($uploaded_file['name']);
        $target_file_path = trailingslashit($full_path) . $uploaded_file_name;

        // Use the consolidated allowed_upload_extensions property
        $file_ext = strtolower(pathinfo($uploaded_file_name, PATHINFO_EXTENSION));

        if (!in_array($file_ext, $this->allowed_upload_extensions, true)) {
            wp_send_json_error(['message' => sprintf(esc_html__('File type not allowed. Allowed types: %s', 'qb-file-manager'), esc_html(implode(', ', $this->allowed_upload_extensions)))]);
            die();
        }

        if (move_uploaded_file($uploaded_file['tmp_name'], $target_file_path)) {
            wp_send_json_success(['message' => esc_html__('File uploaded successfully.', 'qb-file-manager')]);
            die();
        } else {
            wp_send_json_error(['message' => esc_html__('Failed to upload file. Check permissions.', 'qb-file-manager')]);
            die();
        }
    }

    /**
     * AJAX handler to delete an item (file or folder).
     */
    public function ajax_delete_item() {
        $this->check_ajax_security();

        $item_relative = isset($_POST['path']) ? sanitize_text_field(wp_unslash($_POST['path'])) : '';
        $full_path_to_delete = realpath($this->base_dir . $this->sanitize_and_validate_path($item_relative));

        if (!$full_path_to_delete || !file_exists($full_path_to_delete) || strpos($full_path_to_delete, realpath($this->base_dir)) !== 0) {
            wp_send_json_error(['message' => esc_html__('Invalid path or item not found.', 'qb-file-manager')]);
            die();
        }

        if (is_dir($full_path_to_delete)) {
            // Attempt to delete recursively
            if ($this->rrmdir($full_path_to_delete)) {
                wp_send_json_success(['message' => esc_html__('Folder deleted successfully.', 'qb-file-manager')]);
                die();
            } else {
                // Specific error for non-empty directory
                if (is_dir($full_path_to_delete) && count(array_diff(scandir($full_path_to_delete), ['.', '..'])) > 0) {
                    wp_send_json_error(['message' => esc_html__('Failed to delete folder: Directory is not empty. Please delete its contents first.', 'qb-file-manager')]);
                } else {
                    wp_send_json_error(['message' => esc_html__('Failed to delete folder. Check permissions.', 'qb-file-manager')]);
                }
                die();
            }
        } else {
            if (unlink($full_path_to_delete)) {
                wp_send_json_success(['message' => esc_html__('File deleted successfully.', 'qb-file-manager')]);
                die();
            } else {
                wp_send_json_error(['message' => esc_html__('Failed to delete file. Check permissions.', 'qb-file-manager')]);
                die();
            }
        }
    }

    /**
     * Recursively removes a directory and its contents.
     * Returns true on success, false on failure (e.g., permissions, not empty).
     *
     * @param string $dir The directory path to remove.
     * @return bool True on success, false on failure.
     */
    private function rrmdir($dir) {
        if (!is_dir($dir)) {
            return false;
        }
        $objects = scandir($dir);
        if ($objects === false) {
            return false; // Could not read directory
        }
        foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
                $path = $dir . "/" . $object;
                if (is_dir($path)) {
                    if (!$this->rrmdir($path)) {
                        return false; // If recursive delete fails, propagate failure
                    }
                } else {
                    if (!unlink($path)) {
                        return false; // If file delete fails, propagate failure
                    }
                }
            }
        }
        return rmdir($dir); // Attempt to remove the now empty directory
    }

    /**
     * AJAX handler to get file content for editing.
     */
    public function ajax_get_file_content() {
        $this->check_ajax_security();

        $file_relative = isset($_POST['path']) ? sanitize_text_field(wp_unslash($_POST['path'])) : '';
        $full_path_to_file = realpath($this->base_dir . $this->sanitize_and_validate_path($file_relative));

        if (!$full_path_to_file || !is_file($full_path_to_file) || strpos($full_path_to_file, realpath($this->base_dir)) !== 0) {
            wp_send_json_error(['message' => esc_html__('Invalid file path or file not found.', 'qb-file-manager')]);
            die();
        }

        $content = file_get_contents($full_path_to_file);
        if ($content === false) {
            wp_send_json_error(['message' => esc_html__('Failed to read file content. Check permissions.', 'qb-file-manager')]);
            die();
        }

        wp_send_json_success(['content' => $content]);
        die();
    }

    /**
     * AJAX handler to save file content.
     */
    public function ajax_save_file_content() {
        $this->check_ajax_security();

        $file_relative = isset($_POST['path']) ? sanitize_text_field(wp_unslash($_POST['path'])) : '';
        $file_content = isset($_POST['content']) ? wp_unslash($_POST['content']) : ''; // No sanitization here, save raw content

        $full_path_to_file = realpath($this->base_dir . $this->sanitize_and_validate_path($file_relative));

        if (!$full_path_to_file || !is_file($full_path_to_file) || strpos($full_path_to_file, realpath($this->base_dir)) !== 0) {
            wp_send_json_error(['message' => esc_html__('Invalid file path or file not found.', 'qb-file-manager')]);
            die();
        }

        if (file_put_contents($full_path_to_file, $file_content) !== false) {
            wp_send_json_success(['message' => esc_html__('File saved successfully.', 'qb-file-manager')]);
            die();
        } else {
            wp_send_json_error(['message' => esc_html__('Failed to save file. Check permissions.', 'qb-file-manager')]);
            die();
        }
    }

    /**
     * Renders the main admin page content, which is now mostly a container for the JS app.
     */
    public function render_admin_page() {
        ?>
        <div class="wrap qb-file-manager-wrap">
            <h1 class="qb-main-title">QB File Manager <span class="qb-brand-by">by QuietBolt</span></h1>

            <div class="qb-main-content-area">
                <!-- Top Header Actions -->
                <div class="qb-header-actions-top">
                    <form id="qb-create-folder-form" class="qb-action-form-inline">
                        <span class="dashicons dashicons-plus-alt"></span>
                        <input type="text" name="new_folder_name" placeholder="<?php esc_attr_e('New Directory', 'qb-file-manager'); ?>" required class="qb-input-text-small">
                        <button type="submit" class="qb-button qb-button-success qb-button-small">
                            <?php esc_html_e('Create', 'qb-file-manager'); ?>
                        </button>
                    </form>

                    <form id="qb-upload-file-form" class="qb-action-form-inline" enctype="multipart/form-data">
                        <span class="dashicons dashicons-upload"></span>
                        <input type="file" name="file_upload" required class="qb-input-file-small">
                        <button type="submit" class="qb-button qb-button-primary qb-button-small">
                            <?php esc_html_e('Upload', 'qb-file-manager'); ?>
                        </button>
                    </form>

                    <form id="qb-create-file-form" class="qb-action-form-inline">
                        <span class="dashicons dashicons-media-default"></span>
                        <input type="text" name="new_file_name" placeholder="<?php esc_attr_e('New file name', 'qb-file-manager'); ?>" required class="qb-input-text-small">
                        <button type="submit" class="qb-button qb-button-success qb-button-small">
                            <?php esc_html_e('Create File', 'qb-file-manager'); ?>
                        </button>
                    </form>
                </div>

                <div id="qb-breadcrumbs" class="qb-breadcrumbs">
                    <!-- Breadcrumbs will be loaded by JS -->
                    <span class="qb-loading-spinner"></span> <?php esc_html_e('Loading...', 'qb-file-manager'); ?>
                </div>

                <!-- Main File/Folder Grid Container -->
                <div id="qb-file-list-container" class="qb-file-grid-container">
                    <ul class="qb-file-grid">
                        <!-- File/Folder items will be rendered here by JS -->
                        <li class="qb-empty-folder-message"><span class="qb-loading-spinner"></span> <?php esc_html_e('Loading files...', 'qb-file-manager'); ?></li>
                    </ul>
                </div>

                <!-- File Editor Area (hidden by default, shown by JS) -->
                <div id="qb-file-editor-area" class="qb-editor hidden">
                    <div class="qb-editor-header">
                        <h2 class="qb-editor-title"><?php esc_html_e('Editing:', 'qb-file-manager'); ?> <span id="qb-editing-filename"></span></h2>
                        <div class="qb-editor-actions-inline">
                            <button id="qb-save-file-btn" class="qb-button qb-button-primary qb-button-small">
                                <span class="dashicons dashicons-saved"></span> <?php esc_html_e('Save', 'qb-file-manager'); ?>
                            </button>
                            <button id="qb-cancel-edit-btn" class="qb-button qb-button-secondary qb-button-small">
                                <span class="dashicons dashicons-no"></span> <?php esc_html_e('Cancel', 'qb-file-manager'); ?>
                            </button>
                        </div>
                    </div>
                    <textarea id="qb-editor-textarea" rows="25" class="qb-editor-textarea"></textarea>
                </div>

            </div>

            <!-- Custom Confirmation Modal -->
            <div id="qb-confirm-modal" class="qb-modal hidden">
                <div class="qb-modal-content">
                    <h3 class="qb-modal-title"><?php esc_html_e('Confirm Deletion', 'qb-file-manager'); ?></h3>
                    <p class="qb-modal-message"><?php esc_html_e('Are you sure you want to delete', 'qb-file-manager'); ?> <strong id="qb-modal-item-name"></strong>? <?php esc_html_e('This action cannot be undone.', 'qb-file-manager'); ?></p>
                    <div class="qb-modal-actions">
                        <button id="qb-cancel-delete" class="qb-button qb-button-secondary">
                            <span class="dashicons dashicons-no"></span> <?php esc_html_e('Cancel', 'qb-file-manager'); ?>
                        </button>
                        <button id="qb-confirm-delete" class="qb-button qb-button-danger">
                            <span class="dashicons dashicons-trash"></span> <?php esc_html_e('Delete', 'qb-file-manager'); ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Custom Notification Area -->
            <div id="qb-notification-area" class="qb-notification-area"></div>

        </div>
        <?php
    }
}

new QB_Root_File_Manager();
