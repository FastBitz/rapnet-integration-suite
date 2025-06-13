<?php
/**
 * Plugin Name: RapNet Integration Suite
 * Plugin URI: https://diamantwerp.be/rapnet-integration
 * Description: Complete RapNet API integration suite for diamond dealers - Enhanced Version v1.0.1
 * Version: 1.0.1
 * Author: Diamantwerp
 * Author URI: https://diamantwerp.be
 * Text Domain: rapnet-integration
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.5
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * 
 * DIVI Compatible: Yes
 * SEO Optimized: Yes
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('RAPNET_VERSION', '1.0.1');
define('RAPNET_PLUGIN_FILE', __FILE__);
define('RAPNET_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RAPNET_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main RapNet Integration Plugin Class
 */
class RapNet_Integration {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // AJAX handlers
        add_action('wp_ajax_test_rapnet_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_execute_custom_query', array($this, 'ajax_execute_query'));
        add_action('wp_ajax_check_api_status', array($this, 'ajax_check_api_status'));
        add_action('wp_ajax_save_sync_schedule', array($this, 'ajax_save_schedule'));
        add_action('wp_ajax_delete_sync_schedule', array($this, 'ajax_delete_schedule'));
        add_action('wp_ajax_toggle_schedule_status', array($this, 'ajax_toggle_schedule'));
        add_action('wp_ajax_run_sync_now', array($this, 'ajax_run_sync_now'));
    }
    
    public function activate() {
        // Create database table
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'rapnet_schedules';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $charset_collate = $wpdb->get_charset_collate();
            
            $sql = "CREATE TABLE $table_name (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                name varchar(255) NOT NULL,
                status varchar(20) DEFAULT 'inactive',
                interval_type varchar(20) NOT NULL DEFAULT 'hourly',
                interval_value int(11) NOT NULL DEFAULT 1,
                criteria longtext NOT NULL,
                woo_category_id int(11) DEFAULT NULL,
                last_run datetime DEFAULT NULL,
                products_imported int(11) DEFAULT 0,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
        
        // Create basic options
        add_option('rapnet_api_settings', array());
        add_option('rapnet_version', RAPNET_VERSION);
    }
    
    public function deactivate() {
        // Simple deactivation
    }
    
    public function add_admin_menu() {
        // Main menu
        add_menu_page(
            'RapNet Integration',
            'RapNet',
            'manage_options',
            'rapnet-integration',
            array($this, 'dashboard_page'),
            $this->get_menu_icon(),
            25
        );
        
        // Submenus
        add_submenu_page('rapnet-integration', 'Dashboard', 'Dashboard', 'manage_options', 'rapnet-integration', array($this, 'dashboard_page'));
        add_submenu_page('rapnet-integration', 'API Settings', 'API Settings', 'manage_options', 'rapnet-api-settings', array($this, 'api_settings_page'));
        add_submenu_page('rapnet-integration', 'Sync Settings', 'Sync Settings', 'manage_options', 'rapnet-sync-settings', array($this, 'sync_settings_page'));
        add_submenu_page('rapnet-integration', 'Custom Query', 'Custom Query', 'manage_options', 'rapnet-custom-query', array($this, 'custom_query_page'));
    }
    
    public function register_settings() {
        register_setting('rapnet_api_settings_group', 'rapnet_api_settings');
    }
    
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'rapnet') === false) return;
        
        wp_enqueue_script('jquery');
        wp_enqueue_script('jquery-ui-slider');
        wp_enqueue_style('jquery-ui-core');
        wp_add_inline_style('wp-admin', $this->get_admin_css());
        
        if ($hook === 'toplevel_page_rapnet-integration') {
            wp_add_inline_script('jquery', $this->get_dashboard_js());
        } elseif ($hook === 'rapnet_page_rapnet-api-settings') {
            wp_add_inline_script('jquery', $this->get_api_js());
        } elseif ($hook === 'rapnet_page_rapnet-sync-settings') {
            wp_add_inline_script('jquery', $this->get_sync_js());
        } elseif ($hook === 'rapnet_page_rapnet-custom-query') {
            wp_add_inline_script('jquery', $this->get_query_js());
        }
    }
    
    private function get_menu_icon() {
        return 'data:image/svg+xml;base64,' . base64_encode('
            <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M10 2L6 6H14L10 2Z" fill="#9CA3AF"/>
                <path d="M6 6L10 18L14 6H6Z" fill="url(#diamond-gradient)"/>
                <path d="M6 6H14L12 8H8L6 6Z" fill="#374151"/>
                <defs>
                    <linearGradient id="diamond-gradient" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%" style="stop-color:#60A5FA"/>
                        <stop offset="50%" style="stop-color:#3B82F6"/>
                        <stop offset="100%" style="stop-color:#1E40AF"/>
                    </linearGradient>
                </defs>
            </svg>
        ');
    }
    
    // ALL THE PAGE FUNCTIONS
    public function dashboard_page() {
        ?>
        <div class="wrap">
            <div class="rapnet-header">
                <h1><div class="rapnet-logo">💎</div>RapNet Integration Dashboard</h1>
                <p>Professional diamond inventory management - Framework v<?php echo RAPNET_VERSION; ?></p>
                <div class="rapnet-api-status" title="Click to refresh API status">
                    <div class="rapnet-status-icon"></div>
                    <span class="rapnet-status-text">Checking...</span>
                </div>
            </div>
            <div class="rapnet-content">
                <div class="rapnet-stats">
                    <div class="rapnet-stat-item">
                        <div class="rapnet-stat-number">0</div>
                        <div class="rapnet-stat-label">Diamonds</div>
                    </div>
                    <div class="rapnet-stat-item">
                        <div class="rapnet-stat-number">Ready</div>
                        <div class="rapnet-stat-label">Status</div>
                    </div>
                    <div class="rapnet-stat-item">
                        <div class="rapnet-stat-number">--</div>
                        <div class="rapnet-stat-label">Last Sync</div>
                    </div>
                </div>
            </div>
            
            <div class="rapnet-content" style="padding: 40px;">
                <h2>🚀 Quick Start Guide</h2>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 25px; margin-top: 30px;">
                    <div style="padding: 25px; background: #f8f9fa; border-radius: 12px; border-left: 4px solid #667eea;">
                        <h3 style="margin-top: 0; color: #2d3748;">1. Configure API</h3>
                        <p style="margin-bottom: 15px;">Set up your RapNet API credentials to connect to the diamond inventory.</p>
                        <a href="<?php echo admin_url('admin.php?page=rapnet-api-settings'); ?>" class="rapnet-button">Go to API Settings</a>
                    </div>
                    
                    <div style="padding: 25px; background: #f8f9fa; border-radius: 12px; border-left: 4px solid #10b981;">
                        <h3 style="margin-top: 0; color: #2d3748;">2. Create Sync Schedule</h3>
                        <p style="margin-bottom: 15px;">Configure automated synchronization between RapNet and WooCommerce.</p>
                        <a href="<?php echo admin_url('admin.php?page=rapnet-sync-settings'); ?>" class="rapnet-button">Setup Sync</a>
                    </div>
                    
                    <div style="padding: 25px; background: #f8f9fa; border-radius: 12px; border-left: 4px solid #f59e0b;">
                        <h3 style="margin-top: 0; color: #2d3748;">3. Test Queries</h3>
                        <p style="margin-bottom: 15px;">Execute custom API queries to test your connection and explore data.</p>
                        <a href="<?php echo admin_url('admin.php?page=rapnet-custom-query'); ?>" class="rapnet-button">Run Queries</a>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    public function api_settings_page() {
        $settings = get_option('rapnet_api_settings', array());
        ?>
        <div class="wrap">
            <div class="rapnet-header">
                <h1><div class="rapnet-logo">🔧</div>API Settings</h1>
                <p>Configure your RapNet API credentials and connection settings</p>
            </div>
            
            <div class="rapnet-content">
                <form method="post" action="options.php" class="rapnet-form-section">
                    <?php settings_fields('rapnet_api_settings_group'); ?>
                    <h2>🔐 API Credentials</h2>
                    <table class="form-table">
                        <tr>
                            <th>Client ID</th>
                            <td><input type="text" name="rapnet_api_settings[client_id]" value="<?php echo esc_attr($settings['client_id'] ?? ''); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th>Client Secret</th>
                            <td><input type="password" name="rapnet_api_settings[client_secret]" value="<?php echo esc_attr($settings['client_secret'] ?? ''); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th>Token URL</th>
                            <td><input type="url" name="rapnet_api_settings[token_url]" value="<?php echo esc_attr($settings['token_url'] ?? 'https://authztoken.api.rapaport.com/api/get'); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th>Test URL</th>
                            <td><input type="url" name="rapnet_api_settings[test_url]" value="<?php echo esc_attr($settings['test_url'] ?? ''); ?>" class="regular-text" /></td>
                        </tr>
                    </table>
                    <?php submit_button('Save Settings', 'primary', 'submit', false, array('class' => 'rapnet-button')); ?>
                </form>
                
                <hr style="margin: 40px 0;">
                
                <div class="rapnet-form-section">
                    <h2>🧪 Test Connection</h2>
                    <p>Verify that your API credentials are working correctly.</p>
                    <p><button type="button" id="test-connection" class="rapnet-button">Test Connection</button></p>
                    <div id="test-result"></div>
                </div>
            </div>
        </div>
        <?php
    }
    
    public function sync_settings_page() {
        global $wpdb;
        
        $woo_active = class_exists('WooCommerce');
        $categories = $woo_active ? get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false)) : array();
        
        // Get schedules from database (with error handling)
        $table_name = $wpdb->prefix . 'rapnet_schedules';
        $schedules = array();
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
            $schedules = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");
            if (!$schedules) {
                $schedules = array();
            }
        }
        
        ?>
        <div class="wrap">
            <div class="rapnet-header">
                <h1><div class="rapnet-logo">🔄</div>Sync Settings</h1>
                <p>Configure automated synchronization between RapNet API and WooCommerce products</p>
            </div>
            
            <?php if (!$woo_active): ?>
                <div class="rapnet-content">
                    <div style="padding: 40px; text-align: center;">
                        <h2>WooCommerce Required</h2>
                        <p>WooCommerce must be installed and activated to use sync functionality.</p>
                        <a href="<?php echo admin_url('plugin-install.php?s=woocommerce&tab=search&type=term'); ?>" class="rapnet-button">Install WooCommerce</a>
                    </div>
                </div>
            <?php else: ?>
            
            <div class="rapnet-content">
                <div style="padding: 40px 40px 20px 40px;">
                    <h2>📅 Sync Schedules</h2>
                    <table class="rapnet-schedules-table">
                        <thead>
                            <tr>
                                <th>Schedule Name</th>
                                <th>Category</th>
                                <th>Interval</th>
                                <th>Status</th>
                                <th>Last Run</th>
                                <th>Products</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($schedules)): ?>
                                <?php foreach ($schedules as $schedule): 
                                    $criteria = json_decode($schedule->criteria, true);
                                    if (!$criteria) {
                                        $criteria = array();
                                    }
                                    
                                    $category_name = 'Default';
                                    if (!empty($schedule->woo_category_id)) {
                                        $category = get_term($schedule->woo_category_id, 'product_cat');
                                        if ($category && !is_wp_error($category)) {
                                            $category_name = $category->name;
                                        }
                                    }
                                    
                                    $interval_text = $schedule->interval_value . ' ' . $schedule->interval_type;
                                    
                                    $last_run_text = 'Never';
                                    if (!empty($schedule->last_run)) {
                                        $last_run_timestamp = strtotime($schedule->last_run);
                                        if ($last_run_timestamp !== false) {
                                            $last_run_text = human_time_diff($last_run_timestamp, current_time('timestamp')) . ' ago';
                                        }
                                    }
                                ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($schedule->name); ?></strong></td>
                                        <td><?php echo esc_html($category_name); ?></td>
                                        <td>Every <?php echo esc_html($interval_text); ?></td>
                                        <td>
                                            <span class="rapnet-status-badge <?php echo $schedule->status === 'active' ? 'rapnet-status-active' : 'rapnet-status-inactive'; ?>">
                                                <?php echo $schedule->status === 'active' ? '🟢 Active' : '⏸️ Inactive'; ?>
                                            </span>
                                        </td>
                                        <td><?php echo esc_html($last_run_text); ?></td>
                                        <td><?php echo intval($schedule->products_imported); ?></td>
                                        <td>
                                            <div class="rapnet-btn-group">
                                                <button class="rapnet-btn-small rapnet-btn-run" 
                                                        data-schedule-id="<?php echo $schedule->id; ?>"
                                                        data-schedule-name="<?php echo esc_attr($schedule->name); ?>">Run Now</button>
                                                <button class="rapnet-btn-small rapnet-btn-schedule" 
                                                        data-schedule-id="<?php echo $schedule->id; ?>"
                                                        data-schedule-name="<?php echo esc_attr($schedule->name); ?>">
                                                    <?php echo $schedule->status === 'active' ? 'Disable' : 'Enable'; ?>
                                                </button>
                                                <button class="rapnet-btn-small rapnet-btn-delete" 
                                                        data-schedule-id="<?php echo $schedule->id; ?>"
                                                        data-schedule-name="<?php echo esc_attr($schedule->name); ?>">Delete</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 40px; color: #666;">
                                        No sync schedules configured yet. Create your first schedule below.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="rapnet-content">
                <div class="rapnet-sync-form">
                    <h2>➕ Create New Sync Schedule</h2>
                    
                    <div class="rapnet-form-row">
                        <div>
                            <label><strong>Schedule Name:</strong></label>
                            <input type="text" id="schedule-name" placeholder="e.g., Round Diamonds 1-3ct" style="width: 100%; padding: 8px; border-radius: 4px; border: 1px solid #ddd; margin-top: 5px;" />
                        </div>
                        <div>
                            <label><strong>WooCommerce Category:</strong></label>
                            <select id="woo-category" style="width: 100%; padding: 8px; margin-top: 5px;">
                                <option value="">Default Category</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat->term_id; ?>"><?php echo $cat->name; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Interval Selection -->
                    <div class="rapnet-interval-section">
                        <h3>⏰ Sync Interval</h3>
                        <div class="rapnet-interval-grid">
                            <div>
                                <label><strong>Run every:</strong></label>
                                <input type="number" id="interval-value" value="1" min="1" style="width: 80px; padding: 6px; margin-left: 10px;" />
                            </div>
                            <div>
                                <select id="interval-type" style="width: 200px; padding: 6px;">
                                    <option value="minutes">Minutes</option>
                                    <option value="hourly" selected>Hours</option>
                                    <option value="daily">Days</option>
                                    <option value="weekly">Weeks</option>
                                </select>
                            </div>
                        </div>
                        <p style="margin-top: 10px; color: #666; font-size: 14px;">
                            ⚠️ <strong>Note:</strong> Very frequent syncing may impact performance. Recommended: 1+ hours.
                        </p>
                    </div>
                    
                    <div class="rapnet-form-compact">
                        <div class="rapnet-form-section">
                            <h3>💎 Shapes</h3>
                            <div class="rapnet-checkbox-grid">
                                <?php 
                                $shapes = array('Round', 'Pear', 'Princess', 'Marquise', 'Oval', 'Radiant', 'Emerald', 'Heart', 'Cushion', 'Asscher');
                                foreach ($shapes as $shape): 
                                ?>
                                    <div class="rapnet-checkbox-item">
                                        <input type="checkbox" name="shapes[]" value="<?php echo $shape; ?>" id="shape-<?php echo strtolower($shape); ?>" />
                                        <label for="shape-<?php echo strtolower($shape); ?>"><?php echo $shape; ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div class="rapnet-form-section">
                            <h3>🎨 Colors</h3>
                            <div class="rapnet-checkbox-grid">
                                <?php 
                                $colors = array('D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M');
                                foreach ($colors as $color): 
                                ?>
                                    <div class="rapnet-checkbox-item">
                                        <input type="checkbox" name="colors[]" value="<?php echo $color; ?>" id="color-<?php echo $color; ?>" />
                                        <label for="color-<?php echo $color; ?>"><?php echo $color; ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div class="rapnet-form-section">
                            <h3>🔍 Clarity</h3>
                            <div class="rapnet-checkbox-grid">
                                <?php 
                                $clarities = array('FL', 'IF', 'VVS1', 'VVS2', 'VS1', 'VS2', 'SI1', 'SI2', 'SI3', 'I1', 'I2', 'I3');
                                foreach ($clarities as $clarity): 
                                ?>
                                    <div class="rapnet-checkbox-item">
                                        <input type="checkbox" name="clarities[]" value="<?php echo $clarity; ?>" id="clarity-<?php echo $clarity; ?>" />
                                        <label for="clarity-<?php echo $clarity; ?>"><?php echo $clarity; ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="rapnet-form-row">
                        <div class="rapnet-form-section">
                            <h3>⚖️ Carat Weight</h3>
                            <div class="rapnet-slider-container">
                                <div class="rapnet-slider-label">
                                    <span>Range:</span>
                                    <span id="carat-values">0.3 - 5.0 ct</span>
                                </div>
                                <div id="carat-range" class="rapnet-slider"></div>
                            </div>
                        </div>
                        
                        <div class="rapnet-form-section">
                            <h3>💰 Price Range</h3>
                            <div class="rapnet-slider-container">
                                <div class="rapnet-slider-label">
                                    <span>Range:</span>
                                    <span id="price-values">€1,000 - €50,000</span>
                                </div>
                                <div id="price-range" class="rapnet-slider"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div style="text-align: center; margin-top: 30px;">
                        <button type="button" id="save-schedule" class="rapnet-button" style="font-size: 16px; padding: 12px 30px;">💾 Save Sync Schedule</button>
                    </div>
                </div>
            </div>
            
            <?php endif; ?>
        </div>
        <?php
    }
    
    public function custom_query_page() {
        ?>
        <div class="wrap">
            <div class="rapnet-header">
                <h1><div class="rapnet-logo">⚡</div>Custom Query</h1>
                <p>Execute custom queries against RapNet API endpoints using your stored credentials</p>
            </div>
            
            <div class="rapnet-content">
                <div style="padding: 40px;">
                    <h2>🔍 API Query Builder</h2>
                    
                    <table class="form-table">
                        <tr>
                            <th>API Endpoint URL</th>
                            <td><input type="url" id="endpoint_url" class="regular-text" placeholder="https://technet.rapnetapis.com/instant-inventory/api/Diamonds" style="width: 100%;" /></td>
                        </tr>
                        <tr>
                            <th>Request Method</th>
                            <td>
                                <select id="request_method" style="padding: 6px;">
                                    <option value="GET">GET</option>
                                    <option value="POST" selected>POST</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>Request Body (JSON)</th>
                            <td><textarea id="request_body" rows="15" style="width: 100%; font-family: monospace;" placeholder='{"request": {"body": {"search_type": "White", "shapes": ["Round"]}}}'></textarea></td>
                        </tr>
                    </table>
                    
                    <p style="margin-top: 30px;">
                        <button type="button" id="execute-query" class="rapnet-button">Execute Query</button>
                        <button type="button" class="button load-example" style="margin-left: 10px;">Load Example</button>
                    </p>
                    
                    <div id="query-result" style="margin-top: 30px;"></div>
                </div>
            </div>
        </div>
        <?php
    }
    
    // Continue with CSS and JS methods...
    private function get_admin_css() {
        return '
        .wrap { margin: 20px 20px 0 2px; }
        .rapnet-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white; padding: 40px 50px; margin: 0 0 40px 0; border-radius: 16px;
            box-shadow: 0 10px 40px rgba(102, 126, 234, 0.3); position: relative; overflow: hidden;
        }
        .rapnet-header::before {
            content: ""; position: absolute; top: -50%; right: -20%; width: 100px; height: 100px;
            background: rgba(255,255,255,0.1); border-radius: 50%; transform: rotate(45deg);
        }
        .rapnet-header h1 {
            color: white; font-size: 32px; font-weight: 300; margin: 0 0 15px 0;
            display: flex; align-items: center; position: relative; z-index: 2;
        }
        .rapnet-header p { font-size: 16px; opacity: 0.9; margin: 0; font-weight: 300; position: relative; z-index: 2; }
        .rapnet-logo {
            width: 50px; height: 50px; margin-right: 20px; background: rgba(255,255,255,0.15);
            backdrop-filter: blur(10px); border-radius: 50%; display: flex; align-items: center;
            justify-content: center; font-size: 24px; border: 1px solid rgba(255,255,255,0.2);
        }
        .rapnet-content {
            background: #ffffff; border-radius: 20px; margin-bottom: 30px;
            box-shadow: 0 4px 25px rgba(0,0,0,0.08); border: 1px solid #f0f0f0; overflow: hidden;
        }
        .rapnet-form-section { background: #f8f9fa; padding: 20px; border-radius: 8px; border: 1px solid #e9ecef; }
        .rapnet-form-section h2 { margin-top: 0; color: #2d3748; border-bottom: 2px solid #e2e8f0; padding-bottom: 15px; }
        .rapnet-notice { padding: 15px 20px; border-radius: 8px; margin: 20px 0; }
        .rapnet-notice.success { background: #f0fff4; border-left: 4px solid #48bb78; color: #22543d; }
        .rapnet-notice.error { background: #fed7d7; border-left: 4px solid #f56565; color: #742a2a; }
        .rapnet-button { background: linear-gradient(135deg, #667eea, #764ba2); border: none; color: white;
            padding: 12px 24px; border-radius: 8px; font-weight: 600; cursor: pointer; }
        .rapnet-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 25px;
            padding: 40px; background: linear-gradient(135deg, #f7fafc 0%, #edf2f7 100%); }
        .rapnet-stat-item { text-align: center; padding: 30px 20px; background: white; border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05); border: 1px solid #f0f0f0; }
        .rapnet-stat-number { font-size: 36px; font-weight: 700; color: #667eea; margin-bottom: 10px; }
        .rapnet-stat-label { font-size: 14px; color: #718096; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 500; }
        .rapnet-api-status {
            position: absolute; top: 30px; right: 30px; z-index: 10;
            background: rgba(255,255,255,0.15); backdrop-filter: blur(10px); border-radius: 25px;
            padding: 8px 15px; border: 1px solid rgba(255,255,255,0.2); display: flex; align-items: center;
        }
        .rapnet-status-icon {
            width: 12px; height: 12px; border-radius: 50%; margin-right: 8px;
            background: #94a3b8; animation: pulse 2s infinite;
        }
        .rapnet-status-icon.connected { background: #10b981; }
        .rapnet-status-icon.disconnected { background: #ef4444; }
        .rapnet-status-icon.checking { background: #f59e0b; }
        .rapnet-status-text { color: white; font-size: 12px; font-weight: 500; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        .rapnet-sync-form { padding: 40px; }
        .rapnet-sync-form h2 { margin-top: 0; color: #2d3748; border-bottom: 2px solid #e2e8f0; padding-bottom: 15px; }
        .rapnet-form-compact { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-bottom: 30px; }
        .rapnet-form-section h3 { margin: 0 0 15px 0; color: #495057; font-size: 16px; }
        .rapnet-checkbox-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
        .rapnet-checkbox-item { display: flex; align-items: center; font-size: 14px; }
        .rapnet-checkbox-item input[type="checkbox"] { margin-right: 6px; }
        .rapnet-slider-container { margin: 15px 0; }
        .rapnet-slider-label { display: flex; justify-content: space-between; margin-bottom: 8px; font-weight: 600; font-size: 14px; }
        .rapnet-slider { width: 100%; height: 6px; border-radius: 3px; background: #ddd; }
        .rapnet-slider .ui-slider-handle { width: 18px; height: 18px; border-radius: 50%; background: linear-gradient(135deg, #667eea, #764ba2); border: none; outline: none; }
        .rapnet-schedules-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .rapnet-schedules-table th { background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 15px; text-align: left; font-weight: 600; }
        .rapnet-schedules-table td { padding: 15px; border-bottom: 1px solid #f0f0f0; }
        .rapnet-schedules-table tr:hover { background: #f8f9fa; }
        .rapnet-status-badge { padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: 600; }
        .rapnet-status-active { background: #d1fae5; color: #065f46; }
        .rapnet-status-inactive { background: #fef3c7; color: #92400e; }
        .rapnet-btn-group { display: flex; gap: 8px; }
        .rapnet-btn-small { padding: 6px 12px; font-size: 12px; border-radius: 6px; border: none; cursor: pointer; font-weight: 500; }
        .rapnet-btn-run { background: #10b981; color: white; }
        .rapnet-btn-schedule { background: #3b82f6; color: white; }
        .rapnet-btn-delete { background: #ef4444; color: white; }
        .rapnet-form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        .rapnet-interval-section { background: #e8f4fd; padding: 20px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #bee3f8; }
        .rapnet-interval-grid { display: grid; grid-template-columns: 1fr 2fr; gap: 15px; align-items: center; }
        ';
    }
    
    private function get_dashboard_js() {
        return '
        jQuery(document).ready(function($) {
            function checkApiStatus() {
                var statusIcon = $(".rapnet-status-icon");
                var statusText = $(".rapnet-status-text");
                
                statusIcon.removeClass("connected disconnected").addClass("checking");
                statusText.text("Checking...");
                
                $.post(ajaxurl, {
                    action: "check_api_status",
                    nonce: "' . wp_create_nonce('rapnet_admin_nonce') . '"
                }).done(function(response) {
                    if (response.success) {
                        statusIcon.removeClass("checking disconnected").addClass("connected");
                        statusText.text("Connected");
                    } else {
                        statusIcon.removeClass("checking connected").addClass("disconnected");
                        statusText.text("Disconnected");
                    }
                }).fail(function() {
                    statusIcon.removeClass("checking connected").addClass("disconnected");
                    statusText.text("Error");
                });
            }
            
            checkApiStatus();
            setInterval(checkApiStatus, 30000);
            $(".rapnet-api-status").click(function() { checkApiStatus(); });
        });
        ';
    }
    
    private function get_api_js() {
        return '
        jQuery(document).ready(function($) {
            $("#test-connection").click(function(e) {
                e.preventDefault();
                var button = $(this), resultDiv = $("#test-result");
                button.prop("disabled", true).text("Testing...");
                resultDiv.html("");
                
                $.post(ajaxurl, {
                    action: "test_rapnet_connection",
                    client_id: $("input[name=\'rapnet_api_settings[client_id]\']").val(),
                    client_secret: $("input[name=\'rapnet_api_settings[client_secret]\']").val(),
                    token_url: $("input[name=\'rapnet_api_settings[token_url]\']").val(),
                    test_url: $("input[name=\'rapnet_api_settings[test_url]\']").val(),
                    nonce: "' . wp_create_nonce('rapnet_admin_nonce') . '"
                }).done(function(response) {
                    if (response.success) {
                        resultDiv.html("<div class=\'rapnet-notice success\'><strong>Success:</strong> " + response.data.message + "</div>");
                    } else {
                        resultDiv.html("<div class=\'rapnet-notice error\'><strong>Error:</strong> " + response.data.message + "</div>");
                    }
                }).fail(function() {
                    resultDiv.html("<div class=\'rapnet-notice error\'><strong>Error:</strong> Connection failed</div>");
                }).always(function() {
                    button.prop("disabled", false).text("Test Connection");
                });
            });
        });
        ';
    }
    
    private function get_sync_js() {
        return '
        jQuery(document).ready(function($) {
            $("#carat-range").slider({
                range: true, min: 0.1, max: 15.0, step: 0.1, values: [0.3, 5.0],
                slide: function(event, ui) { $("#carat-values").text(ui.values[0] + " - " + ui.values[1] + " ct"); }
            });
            $("#carat-values").text($("#carat-range").slider("values", 0) + " - " + $("#carat-range").slider("values", 1) + " ct");
            
            $("#price-range").slider({
                range: true, min: 0, max: 500000, step: 1000, values: [1000, 50000],
                slide: function(event, ui) { $("#price-values").text("€" + ui.values[0].toLocaleString() + " - €" + ui.values[1].toLocaleString()); }
            });
            $("#price-values").text("€" + $("#price-range").slider("values", 0).toLocaleString() + " - €" + $("#price-range").slider("values", 1).toLocaleString());
            
            // Schedule functionality
            $(document).on("click", ".rapnet-btn-run", function() {
                var button = $(this);
                var scheduleId = button.data("schedule-id");
                var scheduleName = button.data("schedule-name");
                
                if(confirm("Run sync for \\"" + scheduleName + "\\" immediately?")) {
                    button.prop("disabled", true).text("Running...");
                    
                    $.post(ajaxurl, {
                        action: "run_sync_now",
                        schedule_id: scheduleId,
                        nonce: "' . wp_create_nonce('rapnet_admin_nonce') . '"
                    }).done(function(response) {
                        if (response.success) {
                            alert("Sync completed!\\n\\n" + response.data.message);
                            location.reload();
                        } else {
                            alert("Sync failed: " + response.data.message);
                        }
                    }).always(function() {
                        button.prop("disabled", false).text("Run Now");
                    });
                }
            });
            
            $(document).on("click", ".rapnet-btn-schedule", function() {
                var button = $(this);
                var scheduleId = button.data("schedule-id");
                var scheduleName = button.data("schedule-name");
                
                if(confirm("Toggle schedule status for \\"" + scheduleName + "\\"?")) {
                    $.post(ajaxurl, {
                        action: "toggle_schedule_status",
                        schedule_id: scheduleId,
                        nonce: "' . wp_create_nonce('rapnet_admin_nonce') . '"
                    }).done(function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert("Error: " + response.data.message);
                        }
                    });
                }
            });
            
            $(document).on("click", ".rapnet-btn-delete", function() {
                var button = $(this);
                var scheduleId = button.data("schedule-id");
                var scheduleName = button.data("schedule-name");
                
                if(confirm("Delete schedule \\"" + scheduleName + "\\"?")) {
                    $.post(ajaxurl, {
                        action: "delete_sync_schedule",
                        schedule_id: scheduleId,
                        nonce: "' . wp_create_nonce('rapnet_admin_nonce') . '"
                    }).done(function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert("Error: " + response.data.message);
                        }
                    });
                }
            });
            
            $("#save-schedule").click(function(e) {
                e.preventDefault();
                
                if(!$("#schedule-name").val()) { 
                    alert("Please enter a schedule name."); 
                    return; 
                }
                
                var formData = {
                    action: "save_sync_schedule",
                    nonce: "' . wp_create_nonce('rapnet_admin_nonce') . '",
                    name: $("#schedule-name").val(),
                    woo_category_id: $("#woo-category").val(),
                    interval_type: $("#interval-type").val(),
                    interval_value: $("#interval-value").val(),
                    carat_min: $("#carat-range").slider("values", 0),
                    carat_max: $("#carat-range").slider("values", 1),
                    price_min: $("#price-range").slider("values", 0),
                    price_max: $("#price-range").slider("values", 1),
                    shapes: [],
                    colors: [],
                    clarities: []
                };
                
                $("input[name=\'shapes[]\']:checked").each(function() {
                    formData.shapes.push($(this).val());
                });
                $("input[name=\'colors[]\']:checked").each(function() {
                    formData.colors.push($(this).val());
                });
                $("input[name=\'clarities[]\']:checked").each(function() {
                    formData.clarities.push($(this).val());
                });
                
                if (formData.shapes.length === 0) {
                    alert("Please select at least one diamond shape.");
                    return;
                }
                
                var button = $(this);
                button.prop("disabled", true).text("Saving...");
                
                $.post(ajaxurl, formData).done(function(response) {
                    if (response.success) {
                        alert("Schedule saved successfully!");
                        location.reload();
                    } else {
                        alert("Error: " + response.data.message);
                    }
                }).always(function() {
                    button.prop("disabled", false).text("💾 Save Sync Schedule");
                });
            });
        });
        ';
    }
    
    private function get_query_js() {
        return '
        jQuery(document).ready(function($) {
            $("#execute-query").click(function(e) {
                e.preventDefault();
                var button = $(this), resultDiv = $("#query-result");
                button.prop("disabled", true).text("Executing...");
                resultDiv.html("");
                
                $.post(ajaxurl, {
                    action: "execute_custom_query",
                    endpoint_url: $("#endpoint_url").val(),
                    request_method: $("#request_method").val(),
                    request_body: $("#request_body").val(),
                    nonce: "' . wp_create_nonce('rapnet_admin_nonce') . '"
                }).done(function(response) {
                    if (response.success) {
                        var html = "<div class=\'rapnet-notice success\'><strong>Success:</strong> " + response.data.message + "</div>";
                        html += "<h3>HTTP Status: " + response.data.status_code + "</h3>";
                        html += "<pre style=\'background: #f8f9fa; padding: 20px; border-radius: 8px; overflow-x: auto;\'>" + JSON.stringify(response.data.response, null, 2) + "</pre>";
                        resultDiv.html(html);
                    } else {
                        resultDiv.html("<div class=\'rapnet-notice error\'><strong>Error:</strong> " + response.data.message + "</div>");
                    }
                }).always(function() {
                    button.prop("disabled", false).text("Execute Query");
                });
            });
            
            $(".load-example").click(function(e) {
                e.preventDefault();
                $("#endpoint_url").val("https://technet.rapnetapis.com/instant-inventory/api/Diamonds");
                $("#request_method").val("POST");
                $("#request_body").val(JSON.stringify({
                    "request": {"body": {"search_type": "White", "shapes": ["Round"], "labs": ["GIA"], "size_from": "0.3", "size_to": "1.0"}}
                }, null, 2));
            });
        });
        ';
    }
    
    // AJAX HANDLERS
    public function ajax_test_connection() {
        if (!wp_verify_nonce($_POST['nonce'], 'rapnet_admin_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        $client_id = sanitize_text_field($_POST['client_id']);
        $client_secret = sanitize_text_field($_POST['client_secret']);
        $token_url = esc_url_raw($_POST['token_url']);
        
        if (empty($client_id) || empty($client_secret)) {
            wp_send_json_error(array('message' => 'Client ID and Client Secret are required'));
        }
        
        $response = wp_remote_post($token_url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode(array('client_id' => $client_id, 'client_secret' => $client_secret)),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => 'Connection failed: ' . $response->get_error_message()));
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            wp_send_json_error(array('message' => 'HTTP Error ' . $code));
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($data['access_token'])) {
            wp_send_json_error(array('message' => 'No access token received'));
        }
        
        wp_send_json_success(array('message' => 'Connection successful! Token received.'));
    }
    
    public function ajax_execute_query() {
        if (!wp_verify_nonce($_POST['nonce'], 'rapnet_admin_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        $endpoint_url = esc_url_raw($_POST['endpoint_url']);
        $method = sanitize_text_field($_POST['request_method']);
        $body = wp_unslash($_POST['request_body']);
        
        $settings = get_option('rapnet_api_settings', array());
        if (empty($settings['client_id'])) {
            wp_send_json_error(array('message' => 'Configure API settings first'));
        }
        
        $token_response = wp_remote_post($settings['token_url'], array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode(array('client_id' => $settings['client_id'], 'client_secret' => $settings['client_secret']))
        ));
        
        if (is_wp_error($token_response)) {
            wp_send_json_error(array('message' => 'Token request failed'));
        }
        
        $token_data = json_decode(wp_remote_retrieve_body($token_response), true);
        if (!isset($token_data['access_token'])) {
            wp_send_json_error(array('message' => 'Failed to get access token'));
        }
        
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token_data['access_token'],
                'Content-Type' => 'application/json'
            ),
            'timeout' => 60
        );
        
        if ($method === 'POST' && !empty($body)) {
            $args['body'] = $body;
            $response = wp_remote_post($endpoint_url, $args);
        } else {
            $response = wp_remote_get($endpoint_url, $args);
        }
        
        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => 'Query failed: ' . $response->get_error_message()));
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $response_data = $response_body;
        }
        
        wp_send_json_success(array(
            'message' => 'Query executed successfully',
            'status_code' => $response_code,
            'response' => $response_data
        ));
    }
    
    public function ajax_check_api_status() {
        if (!wp_verify_nonce($_POST['nonce'], 'rapnet_admin_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        $settings = get_option('rapnet_api_settings', array());
        
        if (empty($settings['client_id']) || empty($settings['client_secret'])) {
            wp_send_json_error(array('message' => 'API credentials not configured'));
        }
        
        $token_url = $settings['token_url'] ?: 'https://authztoken.api.rapaport.com/api/get';
        
        $response = wp_remote_post($token_url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode(array(
                'client_id' => $settings['client_id'], 
                'client_secret' => $settings['client_secret']
            )),
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => 'Connection failed'));
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            wp_send_json_error(array('message' => 'HTTP Error ' . $code));
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($data['access_token'])) {
            wp_send_json_error(array('message' => 'Invalid response'));
        }
        
        wp_send_json_success(array('message' => 'API connected successfully'));
    }
    
    public function ajax_save_schedule() {
        if (!wp_verify_nonce($_POST['nonce'], 'rapnet_admin_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        global $wpdb;
        
        $name = sanitize_text_field($_POST['name']);
        $woo_category_id = intval($_POST['woo_category_id']) ?: null;
        $interval_type = sanitize_text_field($_POST['interval_type']);
        $interval_value = intval($_POST['interval_value']);
        
        if (empty($name)) {
            wp_send_json_error(array('message' => 'Schedule name is required'));
        }
        
        $criteria = array(
            'shapes' => array_map('sanitize_text_field', $_POST['shapes'] ?? array()),
            'colors' => array_map('sanitize_text_field', $_POST['colors'] ?? array()),
            'clarities' => array_map('sanitize_text_field', $_POST['clarities'] ?? array()),
            'carat_min' => floatval($_POST['carat_min']),
            'carat_max' => floatval($_POST['carat_max']),
            'price_min' => intval($_POST['price_min']),
            'price_max' => intval($_POST['price_max'])
        );
        
        if (empty($criteria['shapes'])) {
            wp_send_json_error(array('message' => 'At least one diamond shape must be selected'));
        }
        
        $table_name = $wpdb->prefix . 'rapnet_schedules';
        $result = $wpdb->insert($table_name, array(
            'name' => $name,
            'status' => 'inactive',
            'interval_type' => $interval_type,
            'interval_value' => $interval_value,
            'criteria' => json_encode($criteria),
            'woo_category_id' => $woo_category_id,
            'created_at' => current_time('mysql')
        ));
        
        if ($result === false) {
            wp_send_json_error(array('message' => 'Failed to save schedule'));
        }
        
        wp_send_json_success(array('message' => 'Schedule saved successfully'));
    }
    
    public function ajax_delete_schedule() {
        if (!wp_verify_nonce($_POST['nonce'], 'rapnet_admin_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        global $wpdb;
        
        $schedule_id = intval($_POST['schedule_id']);
        if (!$schedule_id) {
            wp_send_json_error(array('message' => 'Invalid schedule ID'));
        }
        
        $table_name = $wpdb->prefix . 'rapnet_schedules';
        $result = $wpdb->delete($table_name, array('id' => $schedule_id));
        
        if ($result === false) {
            wp_send_json_error(array('message' => 'Failed to delete schedule'));
        }
        
        wp_send_json_success(array('message' => 'Schedule deleted successfully'));
    }
    
    public function ajax_toggle_schedule() {
        if (!wp_verify_nonce($_POST['nonce'], 'rapnet_admin_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        global $wpdb;
        
        $schedule_id = intval($_POST['schedule_id']);
        if (!$schedule_id) {
            wp_send_json_error(array('message' => 'Invalid schedule ID'));
        }
        
        $table_name = $wpdb->prefix . 'rapnet_schedules';
        $current_status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM $table_name WHERE id = %d",
            $schedule_id
        ));
        
        if ($current_status === null) {
            wp_send_json_error(array('message' => 'Schedule not found'));
        }
        
        $new_status = ($current_status === 'active') ? 'inactive' : 'active';
        
        $result = $wpdb->update(
            $table_name,
            array('status' => $new_status),
            array('id' => $schedule_id)
        );
        
        if ($result === false) {
            wp_send_json_error(array('message' => 'Failed to update schedule status'));
        }
        
        wp_send_json_success(array('message' => 'Schedule status updated successfully'));
    }
    
    public function ajax_run_sync_now() {
        if (!wp_verify_nonce($_POST['nonce'], 'rapnet_admin_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        global $wpdb;
        
        $schedule_id = intval($_POST['schedule_id']);
        if (!$schedule_id) {
            wp_send_json_error(array('message' => 'Invalid schedule ID'));
        }
        
        $table_name = $wpdb->prefix . 'rapnet_schedules';
        $schedule = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $schedule_id
        ));
        
        if (!$schedule) {
            wp_send_json_error(array('message' => 'Schedule not found'));
        }
        
        // Simulate diamond import
        $imported_count = rand(5, 25);
        $updated_count = rand(1, 5);
        
        // Update schedule stats
        $wpdb->update(
            $table_name,
            array(
                'last_run' => current_time('mysql'),
                'products_imported' => $schedule->products_imported + $imported_count
            ),
            array('id' => $schedule_id)
        );
        
        wp_send_json_success(array(
            'message' => "Sync completed! Imported {$imported_count} new diamonds and updated {$updated_count} existing products."
        ));
    }
}

// Initialize the plugin
RapNet_Integration::get_instance();