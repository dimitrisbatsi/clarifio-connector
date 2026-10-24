<?php
/**
 * Plugin Name: Clarif.io Connector
 * Description: Connects WooCommerce to Clarif.io to calculate net profit.
 * Version: 1.14.0
 * Text Domain: clarifio-connector
 * Domain Path: /languages
 * Author: Clarif.io Team
 * Author URI: https://www.clarif.io
 * 
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Ασφάλεια

require 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

// 1. Δηλώνεις το URL του GitHub Repository σου
$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/dimitrisbatsi/clarifio-connector',
    __FILE__,
    'clarifio-connector'
);

// ΣΗΜΑΝΤΙΚΟ: ΔΕΝ βάζουμε πλέον GitHub token εδώ -- οτιδήποτε μπει σε αυτό το αρχείο
// στέλνεται σε κάθε πελάτη που εγκαθιστά το plugin, άρα ΔΕΝ μπορεί ποτέ να μείνει μυστικό.
// Το repo www.github.com/dimitrisbatsi/clarifio-connector πρέπει να είναι PUBLIC για να
// δουλεύουν τα auto-updates χωρίς authentication.

// 2. (Προαιρετικό) Του λες να κοιτάει τα "Releases" στο GitHub και όχι τα απλά commits
// Αυτό προστατεύει τους πελάτες από το να πάρουν ημιτελή κώδικα.
$myUpdateChecker->getVcsApi()->enableReleaseAssets();

define( 'CLARIFIO_API_BASE_URL', 'https://api.clarif.io' );

// Μεταφράσεις: τα αγγλικά είναι η γλώσσα του κώδικα, τα ελληνικά ζουν στο /languages (clarifio-connector-el.mo).
add_action('init', function () {
    load_plugin_textdomain('clarifio-connector', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

// Σύνδεσμος προς το app με τη γλώσσα του admin (?lang=), ώστε να ανοίγει στην ίδια γλώσσα.
function clarifio_app_url($path = '/') {
    $lang = strpos(get_user_locale(), 'el') === 0 ? 'el' : 'en';
    $path = '/' . ltrim($path, '/');
    $separator = strpos($path, '?') === false ? '?' : '&';
    return 'https://app.clarif.io' . $path . $separator . 'lang=' . $lang;
}

// Δηλώνουμε συμβατότητα με το HPOS (High-Performance Order Storage) του WooCommerce.
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// ==========================================
// 0. ΕΛΕΓΧΟΣ ΕΞΑΡΤΗΣΕΩΝ (WOOCOMMERCE)
// ==========================================
add_action('plugins_loaded', 'clarifio_check_dependencies');
function clarifio_check_dependencies() {
    // Αν δεν υπάρχει το WooCommerce, σταματάμε την εκτέλεση του υπόλοιπου κώδικα
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'clarifio_missing_wc_notice');
        return; 
    }
    
    // Αν υπάρχει, φορτώνουμε κανονικά το plugin!
    clarifio_init_plugin();
}

function clarifio_missing_wc_notice() {
    echo '<div class="error"><p>' . wp_kses_post(__('<strong>Clarif.io Connector:</strong> The plugin requires <strong>WooCommerce</strong> to be installed and activated.', 'clarifio-connector')) . '</p></div>';
}

// ==========================================
// 1. ΑΡΧΙΚΟΠΟΙΗΣΗ PLUGIN (Τρέχει μόνο αν υπάρχει το WC)
// ==========================================
function clarifio_init_plugin() {
    add_action('admin_menu', 'clarifio_register_settings_page');
    add_action('admin_notices', 'clarifio_display_expired_notice');
    // add_action('woocommerce_order_status_completed', 'clarifio_send_order_to_api', 10, 1);
    add_action('woocommerce_order_status_changed', 'clarifio_trigger_order_webhook', 10, 4);
    add_action('woocommerce_product_options_pricing', 'clarifio_add_cost_field');
    add_action('woocommerce_process_product_meta', 'clarifio_save_cost_field');
    add_action('wp_ajax_clarifio_sync_products', 'clarifio_handle_sync');
    add_action('woocommerce_update_product', 'clarifio_sync_single_product_on_save', 10, 1);
    add_action('woocommerce_new_product', 'clarifio_sync_single_product_on_save', 10, 1);
    // Το 'woocommerce_update_product' ΔΕΝ πυροδοτείται όταν αποθηκεύεται μεμονωμένη
    // παραλλαγή (variation) -- χρειάζεται ξεχωριστό hook.
    add_action('woocommerce_save_product_variation', 'clarifio_sync_single_product_on_save', 10, 1);
    add_action('wp_ajax_clarifio_get_orders_for_sync', 'clarifio_ajax_get_orders_for_sync');
    add_action('wp_ajax_clarifio_process_order_chunk', 'clarifio_ajax_process_order_chunk');
    // Background jobs (Action Scheduler) για το live sync παραγγελιών/προϊόντων
    add_action('clarifio_process_order_sync', 'clarifio_run_order_sync', 10, 2);
    add_action('clarifio_process_product_sync', 'clarifio_run_product_sync', 10, 1);
}

function clarifio_display_expired_notice() {
    if ( get_option( 'clarifio_subscription_expired' ) === 'yes' ) {
        echo '<div class="notice notice-error"><p>' . wp_kses_post(sprintf(__('<strong>Clarif.io:</strong> Data sync has stopped because your subscription has expired. <a href="%s" target="_blank">Go to billing</a> to renew it.', 'clarifio-connector'), esc_url(clarifio_app_url('/account/billing')))) . '</p></div>';
    }
}

// ==========================================
// 2. ΔΗΜΙΟΥΡΓΙΑ ΜΕΝΟΥ
// ==========================================
function clarifio_register_settings_page() {
    // Το SVG σου καθαρισμένο από το φόντο
    $icon_base64 = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA1MTIuNTYgNTEyLjU2Ij48cGF0aCBmaWxsPSJjdXJyZW50Q29sb3IiIGQ9Ik0zMTAuMjY5LDI1Mi4yMzZhMTUwLjc5LDE1MC43OSwwLDAsMSw0LjU4NSwzOC4wMjFxMCwyNi4zOTItNy42MzIsNDcuODY1dC0yMy4yODYsMzQuMTU0cS0xNS42NjMsMTIuNjgxLTM5LjIsMTIuNjgxVDIwNS44LDM3Mi4yNzZxLTE1LjQtMTIuNjc0LTIzLjAzMS0zNC4xNTR0LTcuNjMxLTQ3Ljg2NXEwLTI2LjkwNiw3LjYzMS00OC4yNTNUMjA1LjgsMjA3Ljk4MXExNS4zODYtMTIuNjczLDM4LjkzNi0xMi42NzljLjAzMiwwLC4wNjEsMCwuMDkzLDBsOS4zMTktNTQuMzM1Yy0zLjA5NC0uMTU4LTYuMjIxLS4yNjItOS40MTItLjI2MnEtNDIuMTY4LDAtNzMuMjIsMTguNjI5dC00OCw1Mi40cS0xNi45NTMsMzMuNzYxLTE2Ljk0Niw3OC43ODUsMCw0NC41LDE2Ljk0Niw3OC4wMDl0NDgsNTIuMjY1cTMxLjA0OSwxOC43Niw3My4yMiwxOC43NTUsNDIuNDM5LDAsNzMuNDgzLTE4Ljc1NXQ0OC01Mi4yNjVxMTYuOTM4LTMzLjUwOSwxNi45NDctNzguMDA5LDAtMzkuMTkyLTEyLjg1My02OS44NDhaIi8+PHBhdGggZmlsbD0iY3VycmVudENvbG9yIiBkPSJNMzEyLjY3Miw3My4wMTIsMjg4LjcyNywyMDguMTY5bDExOS4yNjMtNjAuN0MzOTUuODQ2LDEwMy43ODIsMzYwLjYxMSw3NS4zNTksMzEyLjY3Miw3My4wMTJaIi8+PC9zdmc+';

    add_menu_page(
        'Clarif.io Dashboard', 
        'Clarif.io', 
        'manage_woocommerce', 
        'clarifio-settings', 
        'clarifio_settings_html', 
        $icon_base64, 
        56
    );
}

// ==========================================
// 3. TO UI ΤΟΥ PLUGIN (Settings / Onboarding)
// ==========================================
function clarifio_settings_html() {
    if (isset($_POST['clarifio_api_key'])) {
        update_option('clarifio_api_key', sanitize_text_field($_POST['clarifio_api_key']));
        update_option('clarifio_default_cost_perc', floatval($_POST['clarifio_default_cost_perc']));
        clarifio_send_handshake(true);
        echo '<div class="notice notice-success is-dismissible"><p>✅ ' . esc_html__('Clarif.io settings were saved successfully.', 'clarifio-connector') . '</p></div>';
    }

    $api_key = get_option('clarifio_api_key', '');
    $default_perc = get_option('clarifio_default_cost_perc', '60');
    
    $default_tab = empty($api_key) ? 'welcome' : 'dashboard';
    $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : $default_tab;
    ?>
    
    <div class="wrap">
        <script>
        // Κοινά helpers για τα AJAX μηνύματα: μετατρέπουν ΟΠΟΙΟΔΗΠΟΤΕ σφάλμα (string, object, jqXHR) σε
        // αναγνώσιμο κείμενο -- αλλιώς η σύνθεση με string έδινε "[object Object]".
        window.clarifioI18n = <?php echo wp_json_encode([
                'unknownError' => __('Unknown error', 'clarifio-connector'),
                'success' => __('Success:', 'clarifio-connector'),
                'error' => __('Error:', 'clarifio-connector'),
                'requestFailed' => __('Could not reach the server:', 'clarifio-connector'),
                'syncCatalog' => __('Start catalog sync', 'clarifio-connector'),
                'syncInProgress' => __('Sync in progress...', 'clarifio-connector'),
                'preparing' => __('Preparing data...', 'clarifio-connector'),
                'noOrdersTitle' => __('No orders:', 'clarifio-connector'),
                'noOrdersMsg' => __('No orders found to sync.', 'clarifio-connector'),
                'sendingOrders' => __('Sending orders %1$s–%2$s of %3$s...', 'clarifio-connector'),
                'completed' => __('Completed', 'clarifio-connector'),
                'ordersSynced' => __('%1$s orders were synced to Clarif.io.', 'clarifio-connector'),
                'stoppedAfter' => __('Stopped after %1$s of %2$s orders:', 'clarifio-connector')
            ]); ?>;
        // Αντικαθιστά %1$s, %2$s... με τα ορίσματα (όπως το sprintf της PHP).
        window.clarifioFmt = function (s) {
            var a = arguments;
            return s.replace(/%(\d+)\$s/g, function (m, i) { return a[i]; });
        };
        window.clarifioBtnHtml = function (label, spinning) {
            return '<span class="dashicons dashicons-update-alt" style="margin-top: 5px;' + (spinning ? ' animation: rotation 2s infinite linear;' : '') + '"></span> ' + jQuery('<div>').text(label).html();
        };
        window.clarifioErrText = function (e) {
            if (!e) return clarifioI18n.unknownError;
            if (typeof e === 'string') return e;
            if (e instanceof Error) return e.message;
            if (e.responseJSON && e.responseJSON.data) return window.clarifioErrText(e.responseJSON.data);
            if (e.status !== undefined && e.statusText !== undefined) return 'HTTP ' + e.status + ' ' + e.statusText;
            if (e.message) return String(e.message);
            if (e.Message) return String(e.Message);
            try { return JSON.stringify(e); } catch (x) { return String(e); }
        };
        window.clarifioShow = function ($el, type, label, msg) {
            var $p = jQuery('<p>').append(jQuery('<strong>').text(label + ' ')).append(document.createTextNode(msg));
            $el.empty().append(jQuery('<div class="notice notice-' + type + ' inline" style="border-radius: 6px;">').append($p));
        };
        </script>
        <?php if (!empty($api_key)): ?>
            <div style="background: #fff; padding: 20px; border-left: 4px solid #2563eb; box-shadow: 0 1px 3px rgba(0,0,0,.05); margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between;">
                <div>
                   <h1 style="margin: 0; display: flex; align-items: center;">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 300" style="height: 36px; width: auto; flex-shrink: 0;">
                            <path d="M172.1,147.678a66.734,66.734,0,0,1,2.031,16.838,63.013,63.013,0,0,1-3.38,21.2,33.282,33.282,0,0,1-10.312,15.125q-6.937,5.616-17.362,5.616t-17.243-5.616a33.64,33.64,0,0,1-10.2-15.125,62.963,62.963,0,0,1-3.38-21.2,63.213,63.213,0,0,1,3.38-21.369,33.7,33.7,0,0,1,10.2-15.068q6.813-5.612,17.243-5.614h.041L147.247,98.4c-1.37-.07-2.755-.116-4.168-.116q-18.675,0-32.426,8.25a55.322,55.322,0,0,0-21.255,23.2q-7.509,14.952-7.5,34.891,0,19.706,7.5,34.547a55.862,55.862,0,0,0,21.255,23.146q13.75,8.309,32.426,8.306,18.8,0,32.542-8.306a55.9,55.9,0,0,0,21.258-23.146q7.5-14.84,7.5-34.547,0-17.356-5.692-30.933Z" fill="#0f172a"/>
                            <path d="M173.167,68.308l-10.6,59.855,52.817-26.882C210,81.934,194.4,69.347,173.167,68.308Z" fill="#059669"/>
                            <path d="M376.871,226.641a56.771,56.771,0,0,1-27.943-6.817,51.605,51.605,0,0,1,0-90.509,56.77,56.77,0,0,1,27.943-6.816,70.388,70.388,0,0,1,26.711,4.922q12.12,4.927,18.9,13.634l-16.026,17.8a32.638,32.638,0,0,0-7.191-6.533,38.556,38.556,0,0,0-9.349-4.64,32.155,32.155,0,0,0-10.376-1.7,29.526,29.526,0,0,0-14.9,3.691A26.918,26.918,0,0,0,354.475,159.8a29.125,29.125,0,0,0-3.7,14.768,27.607,27.607,0,0,0,3.8,14.486,29.079,29.079,0,0,0,10.376,10.224,28.531,28.531,0,0,0,14.794,3.882,34.81,34.81,0,0,0,10.067-1.42,32.787,32.787,0,0,0,8.938-4.261,40.134,40.134,0,0,0,7.705-6.816l15.821,17.8q-6.987,8.143-19.314,13.16A68.6,68.6,0,0,1,376.871,226.641Z" fill="#0f172a"/>
                            <path d="M449.4,224.747V84.628h29.176V224.747Z" fill="#0f172a"/>
                            <path d="M555.007,226.641a45.133,45.133,0,0,1-24.451-6.817A48.972,48.972,0,0,1,513.3,201.268q-6.37-11.739-6.369-26.888t6.472-26.793a48.74,48.74,0,0,1,17.567-18.367,47.822,47.822,0,0,1,25.272-6.721,47.221,47.221,0,0,1,14.691,2.177,39.955,39.955,0,0,1,11.712,5.964,39.535,39.535,0,0,1,8.526,8.71,31.657,31.657,0,0,1,4.932,10.6l-6.164-.947V124.581h28.97V224.747H589.525V200.7l6.575-.568a31.325,31.325,0,0,1-5.342,10.035,39.889,39.889,0,0,1-9.144,8.426,47.438,47.438,0,0,1-12.225,5.87A46.812,46.812,0,0,1,555.007,226.641Zm8.013-23.29a28.011,28.011,0,0,0,14.382-3.6,24.544,24.544,0,0,0,9.555-10.13,32.645,32.645,0,0,0,3.39-15.243,31.649,31.649,0,0,0-3.39-14.958,25.348,25.348,0,0,0-9.555-10.13A27.449,27.449,0,0,0,563.02,145.6a25.848,25.848,0,0,0-23.629,13.823A30.869,30.869,0,0,0,535.9,174.38a31.835,31.835,0,0,0,3.493,15.243,25.433,25.433,0,0,0,9.554,10.13A27.044,27.044,0,0,0,563.02,203.351Z" fill="#0f172a"/>
                            <path d="M656.3,224.747V124.581h28.149l1.028,32.19-4.932-6.628a39.365,39.365,0,0,1,8.013-14.106A40.381,40.381,0,0,1,701.6,126.1a35.926,35.926,0,0,1,15.717-3.6,39.863,39.863,0,0,1,6.781.567,38.655,38.655,0,0,1,5.547,1.326l-7.807,29.539a33.145,33.145,0,0,0-5.959-1.989,30.248,30.248,0,0,0-7.192-.851,25.431,25.431,0,0,0-9.142,1.609,21.657,21.657,0,0,0-7.294,4.544,21.16,21.16,0,0,0-6.575,15.621v51.882Z" fill="#0f172a"/>
                            <path d="M771.356,103.942q-8.427,0-13.15-3.787T753.48,89.362a13.11,13.11,0,0,1,4.83-10.508q4.824-4.069,13.046-4.072,8.423,0,13.15,3.882t4.725,10.7a13.044,13.044,0,0,1-4.828,10.6Q779.575,103.943,771.356,103.942ZM756.974,224.747V124.581h29.175V224.747Z" fill="#0f172a"/>
                            <path d="M817.791,149.764V127.042h69.036v22.722Zm16.231,74.983V117.575a32.274,32.274,0,0,1,16.952-28.781,37.963,37.963,0,0,1,18.594-4.355,41.691,41.691,0,0,1,13.561,2.178,33.842,33.842,0,0,1,10.89,5.964l-8.629,19.692a38.858,38.858,0,0,0-5.446-2.556,14.523,14.523,0,0,0-5.034-1.041,14.965,14.965,0,0,0-6.472,1.231,8.068,8.068,0,0,0-3.8,3.6,12.838,12.838,0,0,0-1.234,5.965V224.747H834.022Z" fill="#0f172a"/>
                            <path d="M925.453,226.83q-8.013,0-12.328-4.166t-4.314-11.929a15.2,15.2,0,0,1,4.623-11.456q4.623-4.446,12.019-4.449,7.806,0,12.123,4.166t4.315,11.739a15.614,15.614,0,0,1-4.521,11.645Q932.848,226.828,925.453,226.83Z" fill="#059669"/>
                            <path d="M990.38,103.942q-8.427,0-13.151-3.787T972.5,89.362a13.109,13.109,0,0,1,4.829-10.508q4.826-4.069,13.047-4.072,8.421,0,13.149,3.882t4.726,10.7a13.044,13.044,0,0,1-4.828,10.6Q998.6,103.943,990.38,103.942Z" fill="#059669"/>
                            <rect x="975.997" y="124.581" width="29.176" height="100.166" fill="#0f172a"/>
                            <path d="M1095.167,226.641q-16.644,0-29.69-6.722a52.142,52.142,0,0,1-20.546-18.462,48.832,48.832,0,0,1-7.5-26.888,48.378,48.378,0,0,1,7.5-26.793,53.127,53.127,0,0,1,20.546-18.461q13.045-6.816,29.69-6.816a62.722,62.722,0,0,1,29.484,6.816,52.386,52.386,0,0,1,20.444,18.461,48.9,48.9,0,0,1,7.4,26.793,49.359,49.359,0,0,1-7.4,26.888,51.426,51.426,0,0,1-20.444,18.462A63.433,63.433,0,0,1,1095.167,226.641Zm0-23.48a28.151,28.151,0,0,0,14.383-3.692,26.2,26.2,0,0,0,9.965-10.225,29.947,29.947,0,0,0,3.595-14.675,30.347,30.347,0,0,0-3.595-14.863,26.2,26.2,0,0,0-9.965-10.225,29.923,29.923,0,0,0-28.971.094,27.41,27.41,0,0,0-13.561,24.994,27.771,27.771,0,0,0,3.493,14.675,26.893,26.893,0,0,0,10.068,10.225A28.54,28.54,0,0,0,1095.167,203.161Z" fill="#0f172a"/>
                        </svg>                        
                        <span style="font-size: 20px; color: #64748b; font-weight: normal; margin-left: 14px; padding-left: 14px; border-left: 2px solid #e2e8f0;">
                            Workspace
                        </span>
                    </h1>
                    <p style="margin: 5px 0 0 0; color: #64748b; font-size: 14px;"><?php esc_html_e('Your daily financial compass for e-commerce.', 'clarifio-connector'); ?></p>
                </div>
                <div>
                    <a href="<?php echo esc_url(clarifio_app_url('/')); ?>" target="_blank" class="button" style="display: flex; align-items: center; gap: 8px; padding: 8px 16px;">
                        <span class="dashicons dashicons-external"></span> <?php esc_html_e('Open app', 'clarifio-connector'); ?>
                    </a>
                </div>
            </div>

            <h2 class="nav-tab-wrapper">
                <a href="?page=clarifio-settings&tab=dashboard" class="nav-tab <?php echo $active_tab == 'dashboard' ? 'nav-tab-active' : ''; ?>">📊 <?php esc_html_e('Overview', 'clarifio-connector'); ?></a>
                <a href="?page=clarifio-settings&tab=sync" class="nav-tab <?php echo $active_tab == 'sync' ? 'nav-tab-active' : ''; ?>">📦 <?php esc_html_e('Products & Costs', 'clarifio-connector'); ?></a>
                <a href="?page=clarifio-settings&tab=sync_orders" class="nav-tab <?php echo $active_tab == 'sync_orders' ? 'nav-tab-active' : ''; ?>">🔄 <?php esc_html_e('Order History', 'clarifio-connector'); ?></a>
                <a href="?page=clarifio-settings&tab=settings" class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>">⚙️ <?php esc_html_e('Connection & Settings', 'clarifio-connector'); ?></a>
                <a href="?page=clarifio-settings&tab=debug" class="nav-tab <?php echo $active_tab == 'debug' ? 'nav-tab-active' : ''; ?>">🛠️ <?php esc_html_e('System Status', 'clarifio-connector'); ?></a>
            </h2>
        <?php endif; ?>

        <div style="background: #fff; padding: 30px; border: 1px solid #e2e8f0; <?php echo empty($api_key) ? 'border-top: 1px solid #e2e8f0; border-radius: 8px;' : 'border-top: none;'; ?> box-shadow: 0 1px 3px rgba(0,0,0,.05);">
            
            <?php 
            // --- TAB 0: ONBOARDING / WELCOME ---
            if ($active_tab == 'welcome' || empty($api_key)) : ?>
                <div style="text-align: center; max-width: 650px; margin: 40px auto;">
                    <span class="dashicons dashicons-chart-area" style="font-size: 80px; width: 80px; height: 80px; color: #2563eb;"></span>
                    <h1 style="font-size: 36px; margin-bottom: 15px; color: #0f172a; font-weight: 700;"><?php esc_html_e('Financial clarity for your e-shop.', 'clarifio-connector'); ?></h1>
                    <p style="font-size: 18px; color: #475569; line-height: 1.6;">
                        <?php echo wp_kses_post(__('Revenue is vanity, profit is sanity. Clarif.io connects to your WooCommerce store and calculates your <strong>real Net Profit</strong> in real time, automatically subtracting purchase costs (COGS), shipping and payment fees.', 'clarifio-connector')); ?>
                    </p>
                    
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 25px; border-radius: 12px; margin: 40px 0;">
                        <h3 style="margin-top: 0; color: #1e293b; font-size: 20px;"><?php esc_html_e('Start your free trial', 'clarifio-connector'); ?></h3>
                        <p style="color: #64748b; font-size: 15px;"><?php esc_html_e('Find out which products leave you real profit and which ones "eat" your budget. No commitment, no credit card.', 'clarifio-connector'); ?></p>
                        <a href="<?php echo esc_url(clarifio_app_url('/register')); ?>" target="_blank" class="button button-primary button-hero" style="background: #2563eb; border-color: #1d4ed8; padding: 10px 30px; font-weight: 600;"><?php esc_html_e('Create account', 'clarifio-connector'); ?></a>
                    </div>

                    <form method="post" action="?page=clarifio-settings&tab=settings" style="background: #fff; border: 1px solid #e2e8f0; padding: 30px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); text-align: left;">
                        <h3 style="margin-top: 0; color: #0f172a;"><?php esc_html_e('Connect an existing account', 'clarifio-connector'); ?></h3>
                        <p style="color: #64748b; font-size: 14px;"><?php esc_html_e('If you have already registered, paste your store\'s unique API Key below. You will find it under "Stores" in the Clarif.io app.', 'clarifio-connector'); ?></p>
                        <input type="password" name="clarifio_api_key" placeholder="<?php echo esc_attr__('e.g. cl_live_8f7d6a5...', 'clarifio-connector'); ?>" style="width: 100%; padding: 12px; margin: 15px 0; font-size: 16px; border-radius: 6px; border: 1px solid #cbd5e1;" required>
                        <input type="hidden" name="clarifio_default_cost_perc" value="60"> 
                        <?php submit_button(__('Connect securely', 'clarifio-connector'), 'primary', 'submit', false, ['style' => 'width: 100%; font-size: 16px; padding: 12px; height: auto; border-radius: 6px; background: #0f172a; border-color: #0f172a;']); ?>
                    </form>
                </div>
            
            <?php 
            // --- TAB 1: DASHBOARD ---
            elseif ($active_tab == 'dashboard' && !empty($api_key)) :
                clarifio_send_handshake();
                
                $status_response = wp_remote_get(CLARIFIO_API_BASE_URL .'/api/v1/Stores/status', array(
                    'headers' => array('X-Clarifio-ApiKey' => $api_key, 'X-Clarifio-Store-Url' => home_url()),
                    'timeout' => 10, 'sslverify' => true
                ));

                $stats_response = wp_remote_get(CLARIFIO_API_BASE_URL .'/api/v1/Stores/plugin-summary', array(
                    'headers' => array('X-Clarifio-ApiKey' => $api_key, 'X-Clarifio-Store-Url' => home_url()),
                    'timeout' => 10, 'sslverify' => true
                ));

                clarifio_check_api_response_for_expiration($stats_response);
                $is_expired = get_option('clarifio_subscription_expired') === 'yes';

                if (is_wp_error($status_response)) {
                    echo '<div class="notice notice-error"><p>' . wp_kses_post(__('<strong>Network error:</strong> Could not reach the Clarif.io servers. Please check your connection.', 'clarifio-connector')) . '</p></div>';
                } else {
                    $http_code = wp_remote_retrieve_response_code($status_response);
                    
                    if ($http_code === 200) {
                        $status_body = json_decode(wp_remote_retrieve_body($status_response));

                        if ($is_expired) {
                            // --- UI ΓΙΑ ΛΗΓΜΕΝΗ ΣΥΝΔΡΟΜΗ ---
                            ?>
                            <div style="border: 1px solid #ef4444; background: #fef2f2; padding: 30px; border-radius: 8px; text-align: center;">
                                <span class="dashicons dashicons-warning" style="color: #ef4444; font-size: 50px; width: 50px; height: 50px;"></span>
                                <h3 style="color: #b91c1c; font-size: 20px; margin-top: 15px;"><?php esc_html_e('Your subscription has expired', 'clarifio-connector'); ?></h3>
                                <p style="font-size: 15px; color: #7f1d1d;"><?php esc_html_e('Order sync and statistics have stopped. Please renew your plan to keep tracking your profits.', 'clarifio-connector'); ?></p>
                                <a href="<?php echo esc_url(clarifio_app_url('/account/billing')); ?>" target="_blank" class="button button-primary button-large" style="background: #ef4444; border-color: #dc2626; margin-top: 15px;"><?php esc_html_e('Renew subscription', 'clarifio-connector'); ?></a>
                            </div>
                            <?php
                        } else {
                            ?>
                            <div style="border: 1px solid #10b981; background: #ecfdf5; padding: 20px; border-radius: 8px; margin-bottom: 30px; display: flex; align-items: center; gap: 15px;">
                                <span class="dashicons dashicons-shield" style="color: #10b981; font-size: 40px; width: 40px; height: 40px;"></span>
                                <div>
                                    <h3 style="margin: 0; color: #065f46; font-size: 18px;"><?php esc_html_e('Secure connection active', 'clarifio-connector'); ?></h3>
                                    <p style="margin: 5px 0 0 0; font-size: 14px; color: #047857;"><?php echo wp_kses_post(sprintf(__('The e-shop <strong>%s</strong> is syncing in real time.', 'clarifio-connector'), esc_html($status_body->storeName))); ?></p>
                                </div>
                            </div>
                            
                            <?php 
                            if (!is_wp_error($stats_response) && wp_remote_retrieve_response_code($stats_response) === 200) {
                                $stats = json_decode(wp_remote_retrieve_body($stats_response));
                                ?>
                                <div style="display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; margin-bottom: 20px;">
                                    <h2 style="margin: 0; color: #0f172a;"><?php esc_html_e('This month\'s summary', 'clarifio-connector'); ?></h2>
                                    <span style="color: #64748b; font-size: 13px;"><?php esc_html_e('Updated in real time', 'clarifio-connector'); ?></span>
                                </div>

                                <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                                    <div style="flex: 1; min-width: 200px; background: #fff; border: 1px solid #e2e8f0; padding: 20px; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
                                        <span style="color: #64748b; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;"><?php esc_html_e('Gross revenue', 'clarifio-connector'); ?></span>
                                        <div style="font-size: 28px; font-weight: 700; color: #1e293b; margin-top: 8px;"><?php echo wp_kses_post(wc_price($stats->totalRevenue, ['currency' => !empty($stats->currency) ? $stats->currency : get_woocommerce_currency()])); ?></div>
                                        <p style="margin: 5px 0 0 0; font-size: 12px; color: #94a3b8;"><?php esc_html_e('Total revenue before deductions', 'clarifio-connector'); ?></p>
                                    </div>

                                    <div style="flex: 1; min-width: 200px; background: #fff; border: 1px solid #10b981; padding: 20px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(16, 185, 129, 0.1);">
                                        <span style="color: #047857; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;"><?php esc_html_e('Net profit', 'clarifio-connector'); ?></span>
                                        <div style="font-size: 28px; font-weight: 700; color: #10b981; margin-top: 8px;"><?php echo wp_kses_post(wc_price($stats->totalNetProfit, ['currency' => !empty($stats->currency) ? $stats->currency : get_woocommerce_currency()])); ?></div>
                                        <p style="margin: 5px 0 0 0; font-size: 12px; color: #059669;"><?php esc_html_e('The money that stays in the business', 'clarifio-connector'); ?></p>
                                    </div>

                                    <div style="flex: 1; min-width: 200px; background: #fff; border: 1px solid #e2e8f0; padding: 20px; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
                                        <span style="color: #64748b; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;"><?php esc_html_e('Average margin', 'clarifio-connector'); ?></span>
                                        <div style="font-size: 28px; font-weight: 700; color: #3b82f6; margin-top: 8px;"><?php echo number_format_i18n($stats->averageMargin, 1); ?>%</div>
                                        <p style="margin: 5px 0 0 0; font-size: 12px; color: #94a3b8;"><?php esc_html_e('Store profitability health', 'clarifio-connector'); ?></p>
                                    </div>
                                </div>

                                <p style="margin-top: 30px; font-size: 15px; color: #475569;">
                                    <?php esc_html_e('For deeper analysis, customer LTV and per-product reports, go to the full app.', 'clarifio-connector'); ?>
                                </p>
                                <a href="<?php echo esc_url(clarifio_app_url('/')); ?>" target="_blank" class="button button-primary button-large" style="background: #0f172a; border-color: #0f172a; border-radius: 6px;">
                                    <?php echo wp_kses_post(__('Go to the web app &rarr;', 'clarifio-connector')); ?>
                                </a>
                                <?php
                            }
                        }
                    } else {
                        echo '<div class="notice notice-error"><p>⚠️ ' . wp_kses_post(__('<strong>Authentication error:</strong> The API Key appears to be invalid or has been revoked. Please check your settings.', 'clarifio-connector')) . '</p></div>';
                    }
                }
            
            // --- TAB 2: ΡΥΘΜΙΣΕΙΣ ---
            elseif ($active_tab == 'settings') : ?>
                <div style="max-width: 800px;">
                    <h2 style="font-size: 20px; color: #0f172a; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px;"><?php esc_html_e('Connection & Algorithm Settings', 'clarifio-connector'); ?></h2>
                    <p style="color: #64748b; font-size: 14px; margin-bottom: 25px;"><?php esc_html_e('Manage how your store communicates with the data analytics platform.', 'clarifio-connector'); ?></p>

                    <form method="post" action="?page=clarifio-settings&tab=settings">
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="clarifio_api_key" style="font-weight: 600;"><?php esc_html_e('Store API Key', 'clarifio-connector'); ?></label>
                                </th>
                                <td>
                                    <input type="password" id="clarifio_api_key" name="clarifio_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" style="border-radius: 4px;">
                                    <p class="description"><?php esc_html_e('The secret key that secures the encrypted transfer of your data.', 'clarifio-connector'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="clarifio_default_cost_perc" style="font-weight: 600;"><?php esc_html_e('Default purchase cost (COGS)', 'clarifio-connector'); ?></label>
                                </th>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <input type="number" id="clarifio_default_cost_perc" name="clarifio_default_cost_perc" value="<?php echo esc_attr($default_perc); ?>" step="0.1" min="0" max="100" style="width: 80px; border-radius: 4px;"> 
                                        <span style="font-weight: bold; color: #475569;"><?php esc_html_e('% of the retail price', 'clarifio-connector'); ?></span>
                                    </div>
                                    <p class="description" style="max-width: 500px;">
                                        <?php esc_html_e('If a product has no explicitly filled "Cost" field, the Clarif.io algorithm will use this percentage as a safety net.', 'clarifio-connector'); ?> <br>
                                        <em><?php esc_html_e('Example: if set to 60% and you sell something for 100, the system will assume you bought it for 60.', 'clarifio-connector'); ?></em>
                                    </p>
                                </td>
                            </tr>
                        </table>
                        <p class="submit">
                            <?php submit_button(__('Save changes', 'clarifio-connector'), 'primary', 'submit', false, ['style' => 'background: #2563eb; border-color: #1d4ed8; border-radius: 6px;']); ?>
                        </p>
                    </form>
                </div>

            <?php 
            // --- TAB 3: ΣΥΓΧΡΟΝΙΣΜΟΣ ΠΡΟΙΟΝΤΩΝ ---
            elseif ($active_tab == 'sync') : ?>
                <div style="max-width: 800px;">
                    <h2 style="font-size: 20px; color: #0f172a; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px;"><?php esc_html_e('Product Database', 'clarifio-connector'); ?></h2>
                    <p style="color: #64748b; font-size: 15px; margin-bottom: 25px; line-height: 1.6;">
                        <?php esc_html_e('The foundation of accurate financial calculations. This function collects all your active products, SKUs and costs, building the unified catalog in Clarif.io.', 'clarifio-connector'); ?>
                    </p>

                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-left: 4px solid #f59e0b; padding: 20px; border-radius: 6px; margin-bottom: 25px;">
                        <h4 style="margin: 0 0 10px 0; color: #b45309;"><?php esc_html_e('When should you use this function?', 'clarifio-connector'); ?></h4>
                        <ul style="margin: 0; padding-left: 20px; color: #475569;">
                            <li><?php echo wp_kses_post(__('During the plugin\'s <strong>initial installation</strong>.', 'clarifio-connector')); ?></li>
                            <li><?php echo wp_kses_post(__('If you made a <strong>bulk change of purchase prices (costs)</strong> in WooCommerce (e.g. via a CSV file or an ERP).', 'clarifio-connector')); ?></li>
                        </ul>
                        <p style="margin: 10px 0 0 0; font-size: 13px; color: #64748b;">
                            <?php esc_html_e('*Note: You do not need to press this every day. When you edit or create a product, the plugin updates Clarif.io automatically in the background.', 'clarifio-connector'); ?>
                        </p>
                    </div>

                    <script> var clarifio_security_nonce = '<?php echo wp_create_nonce("clarifio_ajax_nonce"); ?>'; </script>
                    
                    <button type="button" id="clarifio-sync-btn" class="button button-primary button-large" style="background: #0f172a; border-color: #0f172a; border-radius: 6px; padding: 5px 20px;">
                        <span class="dashicons dashicons-update-alt" style="margin-top: 5px;"></span> <?php esc_html_e('Start catalog sync', 'clarifio-connector'); ?>
                    </button>
                    <div id="clarifio-sync-result" style="margin-top: 20px;"></div>

                    <script>
                    // Το JavaScript σου παραμένει ως έχει (μην ξεχάσεις να περνάς το security nonce στο $.post)
                    jQuery(document).ready(function($) {
                        $('#clarifio-sync-btn').on('click', function(e) {
                            e.preventDefault();
                            var $btn = $(this);
                            $btn.prop('disabled', true).html(clarifioBtnHtml(clarifioI18n.syncInProgress, true));
                            $('#clarifio-sync-result').html('');

                            $.post(ajaxurl, { action: 'clarifio_sync_products', security: clarifio_security_nonce }, function(response) {
                                $btn.prop('disabled', false).html(clarifioBtnHtml(clarifioI18n.syncCatalog, false));
                                if(response.success) {
                                    clarifioShow($('#clarifio-sync-result'), 'success', '✅ ' + clarifioI18n.success, clarifioErrText(response.data.message || response.data));
                                } else {
                                    clarifioShow($('#clarifio-sync-result'), 'error', '❌ ' + clarifioI18n.error, clarifioErrText(response.data));
                                }
                            }).fail(function(xhr) {
                                $btn.prop('disabled', false).html(clarifioBtnHtml(clarifioI18n.syncCatalog, false));
                                clarifioShow($('#clarifio-sync-result'), 'error', '❌ ' + clarifioI18n.requestFailed, clarifioErrText(xhr));
                            });
                        });
                    });
                    </script>
                    <style> @keyframes rotation { from { transform: rotate(0deg); } to { transform: rotate(359deg); } } </style>
                </div>

            <?php 
            // --- TAB 4: ΣΥΓΧΡΟΝΙΣΜΟΣ ΠΑΡΑΓΓΕΛΙΩΝ ---
            elseif ($active_tab == 'sync_orders') : ?>
                <div style="max-width: 800px;">
                    <h2 style="font-size: 20px; color: #0f172a; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px;"><?php esc_html_e('Order History Analysis', 'clarifio-connector'); ?></h2>
                    <p style="color: #64748b; font-size: 15px; margin-bottom: 25px; line-height: 1.6;">
                        <?php echo wp_kses_post(__('Discover the hidden value of your older data. Bulk sync sends your sales history to Clarif.io, allowing the algorithm to build the historical charts and calculate <strong>Customer Lifetime Value (LTV)</strong>.', 'clarifio-connector')); ?>
                    </p>
                    
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-left: 4px solid #3b82f6; padding: 20px; border-radius: 6px; margin-bottom: 25px;">
                        <p style="margin: 0; color: #475569; font-size: 14px;">
                            <?php echo wp_kses_post(__('<strong>Infrastructure note:</strong> The process is designed to be "invisible" to your server. Orders are grouped and sent in batches (of 50), ensuring no slowdown of your e-shop during the sync.', 'clarifio-connector')); ?>
                        </p>
                    </div>

                    <script> var clarifio_security_nonce = '<?php echo wp_create_nonce("clarifio_ajax_nonce"); ?>'; </script>

                    <button type="button" id="clarifio-sync-orders-btn" class="button button-primary button-large" style="background: #2563eb; border-color: #1d4ed8; border-radius: 6px; padding: 5px 25px;">
                        ▶ <?php esc_html_e('Start history analysis', 'clarifio-connector'); ?>
                    </button>
                    
                    <div id="clarifio-progress-wrapper" style="display: none; margin-top: 25px; background: #fff; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                            <span id="clarifio-sync-status-text" style="font-weight: 600; color: #1e293b;"><?php esc_html_e('Preparing data...', 'clarifio-connector'); ?></span>
                            <span id="clarifio-sync-percent" style="font-weight: bold; color: #2563eb;">0%</span>
                        </div>
                        <div style="width: 100%; background-color: #f1f5f9; border-radius: 999px; overflow: hidden; height: 12px;">
                            <div id="clarifio-progress-bar" style="width: 0%; height: 100%; background-color: #2563eb; transition: width 0.4s ease; border-radius: 999px;"></div>
                        </div>
                    </div>

                    <div id="clarifio-sync-orders-result" style="margin-top: 20px;"></div>

                    <script>
                    jQuery(document).ready(function($) {
                        var CHUNK_SIZE = 50;
                        var $btn = $('#clarifio-sync-orders-btn');
                        var $wrapper = $('#clarifio-progress-wrapper');
                        var $result = $('#clarifio-sync-orders-result');

                        function setProgress(done, total, text) {
                            var pct = total > 0 ? Math.round((done / total) * 100) : 0;
                            $('#clarifio-sync-status-text').text(text);
                            $('#clarifio-sync-percent').text(pct + '%');
                            $('#clarifio-progress-bar').css('width', pct + '%');
                        }

                        $btn.on('click', async function(e) {
                            e.preventDefault();
                            $btn.prop('disabled', true);
                            $result.empty();
                            $wrapper.show();
                            setProgress(0, 0, clarifioI18n.preparing);

                            var done = 0;
                            var total = 0;
                            try {
                                var idsResponse = await $.post(ajaxurl, { action: 'clarifio_get_orders_for_sync', security: clarifio_security_nonce });
                                if (!idsResponse.success) throw idsResponse.data;

                                var ids = idsResponse.data.order_ids || [];
                                total = ids.length;
                                if (total === 0) {
                                    $wrapper.hide();
                                    clarifioShow($result, 'warning', 'ℹ️ ' + clarifioI18n.noOrdersTitle, clarifioI18n.noOrdersMsg);
                                    return;
                                }

                                for (var i = 0; i < total; i += CHUNK_SIZE) {
                                    var chunk = ids.slice(i, i + CHUNK_SIZE);
                                    setProgress(done, total, clarifioFmt(clarifioI18n.sendingOrders, i + 1, i + chunk.length, total));
                                    var chunkResponse = await $.post(ajaxurl, { action: 'clarifio_process_order_chunk', security: clarifio_security_nonce, order_ids: chunk });
                                    if (!chunkResponse.success) throw chunkResponse.data;
                                    done += chunk.length;
                                }

                                setProgress(total, total, clarifioI18n.completed);
                                clarifioShow($result, 'success', '✅ ' + clarifioI18n.success, clarifioFmt(clarifioI18n.ordersSynced, done));
                            } catch (err) {
                                // Σταματάμε στο πρώτο σφάλμα (π.χ. 402 όριο/λήξη συνδρομής) για να μη στέλνουμε άσκοπα τα υπόλοιπα τμήματα.
                                clarifioShow($result, 'error', '❌ ' + clarifioFmt(clarifioI18n.stoppedAfter, done, total), clarifioErrText(err));
                            } finally {
                                $btn.prop('disabled', false);
                            }
                        });
                    });
                    </script>
                </div>

            <?php 
            // --- TAB 5: DEBUG LOGS ---
            elseif ($active_tab == 'debug') : ?>
                <div style="max-width: 100%;">
                    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; margin-bottom: 20px;">
                        <h2 style="margin: 0; font-size: 20px; color: #0f172a;"><?php esc_html_e('Event Log', 'clarifio-connector'); ?></h2>
                        <span style="color: #64748b; font-size: 13px;"><?php esc_html_e('The last 50 entries are kept', 'clarifio-connector'); ?></span>
                    </div>
                    <p style="color: #64748b; font-size: 14px;"><?php esc_html_e('Use this screen to troubleshoot, or send a screenshot to Clarif.io support if you run into a problem.', 'clarifio-connector'); ?></p>
                    
                    <table class="wp-list-table widefat fixed striped" style="margin-top: 20px; border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0;">
                        <thead style="background: #f8fafc;">
                            <tr>
                                <th style="width: 160px; font-weight: 600; color: #1e293b;">Timestamp</th>
                                <th style="font-weight: 600; color: #1e293b;"><?php esc_html_e('Event', 'clarifio-connector'); ?></th>
                                <th style="width: 120px; font-weight: 600; color: #1e293b;"><?php esc_html_e('Status', 'clarifio-connector'); ?></th>
                                <th style="width: 38%; font-weight: 600; color: #1e293b;"><?php esc_html_e('Details (request / response)', 'clarifio-connector'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $logs = array_reverse(get_option('clarifio_debug_logs', []));
                            if (empty($logs)) {
                                echo '<tr><td colspan="4" style="text-align: center; padding: 30px; color: #64748b;">' . esc_html__('No communication events have been logged yet.', 'clarifio-connector') . '</td></tr>';
                            } else {
                                foreach ($logs as $log) : 
                                    $code_num = is_numeric($log['code']) ? (int) $log['code'] : 0;
                                    $is_success = ($log['code'] === 'Async' || ($code_num >= 200 && $code_num < 300));
                                    $status_color = $is_success ? '#10b981' : '#ef4444';
                                    $status_bg = $is_success ? '#ecfdf5' : '#fef2f2';
                                    $status_label = ($log['code'] === 'Async' || $code_num > 0) ? $log['code'] : __('No response', 'clarifio-connector');
                                ?>
                                    <tr>
                                        <td style="color: #475569; font-size: 13px;"><?php echo esc_html($log['time']); ?></td>
                                        <td style="font-weight: 500; color: #334155;"><?php echo esc_html($log['event']); ?></td>
                                        <td>
                                            <span style="background: <?php echo $status_bg; ?>; color: <?php echo $status_color; ?>; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 600;">
                                                <?php echo esc_html($status_label); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <details>
                                                <summary style="cursor: pointer; color: #2563eb; font-size: 12px;"><?php esc_html_e('View', 'clarifio-connector'); ?></summary>
                                                <p style="margin: 10px 0 4px 0; font-weight: 600; font-size: 12px;"><?php esc_html_e('Request', 'clarifio-connector'); ?></p>
                                                <pre style="max-height: 220px; overflow: auto; white-space: pre-wrap; word-break: break-all; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px; font-size: 11px;"><?php echo esc_html(clarifio_pretty_json($log['payload'])); ?></pre>
                                                <p style="margin: 10px 0 4px 0; font-weight: 600; font-size: 12px;"><?php esc_html_e('Response', 'clarifio-connector'); ?></p>
                                                <pre style="max-height: 220px; overflow: auto; white-space: pre-wrap; word-break: break-all; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px; font-size: 11px;"><?php echo esc_html(isset($log['response']) && $log['response'] !== '' ? clarifio_pretty_json($log['response']) : __('(no response)', 'clarifio-connector')); ?></pre>
                                            </details>
                                        </td>
                                    </tr>
                                <?php endforeach; 
                            } ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

// ==========================================
// ΥΠΟΛΟΙΠΕΣ ΣΥΝΑΡΤΗΣΕΙΣ (Events, Sync, Log)
// ==========================================

// Κεντρική κλήση προς το Clarif.io API. Είναι ΠΑΝΤΑ blocking, ώστε να καταγράφεται η πραγματική
// απάντηση (κωδικός + body). Το προηγούμενο fire-and-forget έκρυβε κάθε σφάλμα (401/402/403/500)
// και δεν άφηνε κανένα ίχνος στα logs.
function clarifio_api_post($path, $payload, $timeout = 15) {
    $response = wp_remote_post(CLARIFIO_API_BASE_URL . $path, [
        'headers'   => ['Content-Type' => 'application/json', 'X-Clarifio-ApiKey' => get_option('clarifio_api_key'), 'X-Clarifio-Store-Url' => home_url()],
        'body'      => wp_json_encode($payload),
        'timeout'   => $timeout,
        'sslverify' => true
    ]);

    clarifio_check_api_response_for_expiration($response);

    if (is_wp_error($response)) {
        return ['code' => 0, 'body' => $response->get_error_message(), 'ok' => false];
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    return ['code' => $code, 'body' => wp_remote_retrieve_body($response), 'ok' => ($code >= 200 && $code < 300)];
}

// Αναγνώσιμο μήνυμα σφάλματος από το αποτέλεσμα του clarifio_api_post().
function clarifio_api_error_message($result) {
    $decoded = json_decode($result['body'], true);
    if (is_array($decoded)) {
        // Συμβόλαιο API: { code, message, params }. Ο κωδικός μεταφράζεται στη γλώσσα του admin,
        // το message (αγγλικό) μένει ως fallback για κωδικούς που δεν γνωρίζει αυτή η έκδοση.
        if (!empty($decoded['code']) && is_string($decoded['code'])) {
            $params = isset($decoded['params']) && is_array($decoded['params']) ? $decoded['params'] : [];
            $translated = clarifio_translate_api_code($decoded['code'], $params);
            if ($translated !== null) return $translated;
        }
        foreach (['message', 'Message', 'title'] as $key) {
            if (!empty($decoded[$key]) && is_string($decoded[$key])) return $decoded[$key];
        }
    }

    $body = trim((string) $result['body']);
    if ($body !== '') return mb_substr($body, 0, 300);

    return $result['code'] ? 'HTTP ' . $result['code'] : __('Could not reach Clarif.io', 'clarifio-connector');
}

// Κωδικός σφάλματος του API -> μεταφρασμένο μήνυμα (null αν ο κωδικός δεν είναι γνωστός).
function clarifio_translate_api_code($code, $params = []) {
    switch ($code) {
        case 'ORIGIN_MISMATCH': return __('Origin mismatch: this API key does not belong to this domain.', 'clarifio-connector');
        case 'SUBSCRIPTION_EXPIRED': return __('Your subscription has expired. Please upgrade your plan.', 'clarifio-connector');
        case 'TRIAL_EXPIRED': return __('Your 14-day trial has ended.', 'clarifio-connector');
        case 'TRIAL_ORDER_LIMIT_REACHED': return __('Your trial limit (500 orders) has been reached.', 'clarifio-connector');
        case 'TRIAL_ORDER_LIMIT_WOULD_BE_EXCEEDED': return __('Your trial limit would be exceeded. Please upgrade.', 'clarifio-connector');
        case 'MONTHLY_ORDER_LIMIT_REACHED': return __('You have reached your monthly order limit.', 'clarifio-connector');
        case 'STORE_NOT_FOUND': return __('Store not found.', 'clarifio-connector');
        case 'UNEXPECTED_ERROR': return __('An unexpected error occurred. Please try again.', 'clarifio-connector');
        case 'PRODUCT_LIMIT_REACHED': return sprintf(__('You have reached the limit of %d products. Please upgrade your plan.', 'clarifio-connector'), (int) ($params['limit'] ?? 0));
        case 'PRODUCT_SYNC_LIMIT_EXCEEDED': return sprintf(__('Sync failed. You exceed the limit of %d products.', 'clarifio-connector'), (int) ($params['limit'] ?? 0));
    }
    return null;
}

// Handshake: δηλώνει στο Clarif.io το νόμισμα και τη χώρα του WooCommerce. Το νόμισμα του καταστήματος
// ορίζεται μόνο από εδώ (δεν το δηλώνει ο χρήστης). Στέλνεται ξανά μόνο όταν αλλάξουν τα στοιχεία
// (ή κάθε 12 ώρες), ώστε να μη χτυπάμε το API σε κάθε άνοιγμα της οθόνης.
function clarifio_send_handshake($force = false) {
    if (!get_option('clarifio_api_key')) return;

    $currency = get_woocommerce_currency();
    $country  = get_option('woocommerce_default_country', '');
    $signature = md5(get_option('clarifio_api_key') . '|' . $currency . '|' . $country);

    if (!$force && get_transient('clarifio_handshake_' . $signature)) return;

    $result = clarifio_api_post('/api/v1/Stores/handshake', ['currency' => $currency, 'country' => $country], 10);
    clarifio_log_event('Store Handshake (' . $currency . ', ' . ($country ?: '-') . ')', wp_json_encode(['currency' => $currency, 'country' => $country]), $result['code'], $result['body']);

    if ($result['ok']) set_transient('clarifio_handshake_' . $signature, 1, 12 * HOUR_IN_SECONDS);
}

function clarifio_pretty_json($text) {
    $decoded = json_decode((string) $text, true);
    if (is_array($decoded)) {
        return wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return (string) $text;
}

function clarifio_build_order_payload($order) {
    $items = [];
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        $items[] = [
            'sku' => clarifio_get_valid_sku($product),
            'quantity' => (int) $item->get_quantity(),
            'totalPrice' => (float) $item->get_total()
        ];
    }

    // --- ΕΞΑΓΩΓΗ ΣΤΟΙΧΕΙΩΝ ΠΕΛΑΤΗ ---
    $woo_customer_id = $order->get_customer_id();
    $customer_role = 'guest'; // Προεπιλογή για μη εγγεγραμμένους

    if ($woo_customer_id > 0) {
        $user = get_userdata($woo_customer_id);
        $customer_role = ($user && !empty($user->roles)) ? $user->roles[0] : 'customer'; // π.χ. 'customer', 'wholesale_customer' κτλ.
    }

    $created = $order->get_date_created();

    return [
        'orderId' => (int) $order->get_id(),
        // Στέλνουμε πραγματικό UTC timestamp. Πριν, η ώρα του site (π.χ. Ελλάδα, UTC+2/+3) μαρκαριζόταν
        // λανθασμένα ως UTC και οι παραγγελίες γύρω από τα μεσάνυχτα έπεφταν σε λάθος ημέρα.
        'orderDate' => $created ? gmdate('Y-m-d\TH:i:s\Z', $created->getTimestamp()) : null,
        'total' => (float) $order->get_total(),
        // Συνολικό ΦΠΑ (προϊόντα + μεταφορικά): το Clarif.io το αφαιρεί από τον τζίρο και το κέρδος.
        'totalTax' => (float) $order->get_total_tax(),
        'shipping' => (float) $order->get_shipping_total(),
        'status' => $order->get_status(),
        'currency' => $order->get_currency(),
        'paymentMethod' => $order->get_payment_method(),
        'customerEmail' => $order->get_billing_email(),
        'customerFirstName' => $order->get_billing_first_name(),
        'customerLastName' => $order->get_billing_last_name(),
        'customerRole' => $customer_role,
        'wooCustomerId' => $woo_customer_id > 0 ? $woo_customer_id : null,
        'items' => $items
    ];
}

function clarifio_trigger_order_webhook($order_id, $old_status, $new_status, $order) {
    // Στέλνουμε webhook για ΚΑΘΕ αλλαγή status εκτός από τα states όπου η παραγγελία δεν αποτελεί
    // πώληση (δεν έχει οριστικοποιηθεί ή η πληρωμή απέτυχε). Έτσι καλύπτονται αυτόματα και τυχόν
    // custom statuses από πρόσθετα τρίτων (π.χ. shipping/pickup plugins).
    if (!in_array($new_status, clarifio_excluded_order_statuses(), true)) {
        clarifio_queue_order_sync($order_id);
    }
}

function clarifio_excluded_order_statuses() {
    return ['pending', 'checkout-draft', 'failed'];
}

// Το live sync τρέχει στο παρασκήνιο μέσω Action Scheduler (ενσωματωμένο στο WooCommerce): ο πελάτης
// δεν περιμένει την κλήση στο checkout και τα προσωρινά σφάλματα ξαναδοκιμάζονται αυτόματα.
function clarifio_queue_order_sync($order_id, $attempt = 1) {
    if (!get_option('clarifio_api_key')) return;

    if (function_exists('as_enqueue_async_action')) {
        as_enqueue_async_action('clarifio_process_order_sync', [(int) $order_id, (int) $attempt], 'clarifio');
    } else {
        clarifio_run_order_sync($order_id, $attempt);
    }
}

function clarifio_run_order_sync($order_id, $attempt = 1) {
    $order = wc_get_order($order_id);
    if (!$order || !get_option('clarifio_api_key')) return;

    $payload = clarifio_build_order_payload($order);
    $result = clarifio_api_post('/api/v1/webhook/order-event', $payload);

    clarifio_log_event("Order Webhook (#$order_id, attempt $attempt)", wp_json_encode($payload), $result['code'], $result['body']);

    // Ξαναδοκιμάζουμε μόνο σε σφάλμα δικτύου ή 5xx. Τα 4xx (άκυρο key, λήξη συνδρομής, origin mismatch)
    // δεν θα διορθωθούν μόνα τους, οπότε μένουν στα logs για να τα δει ο χρήστης.
    $retryable = ($result['code'] === 0 || $result['code'] >= 500);
    if ($retryable && $attempt < 4 && function_exists('as_schedule_single_action')) {
        as_schedule_single_action(time() + (60 * pow(2, $attempt)), 'clarifio_process_order_sync', [(int) $order_id, (int) $attempt + 1], 'clarifio');
    }
}

function clarifio_add_cost_field() {
    woocommerce_wp_text_input( array(
        'id'          => '_clarifio_cost',
        'label'       => sprintf(__('Clarif.io Cost (%s)', 'clarifio-connector'), get_woocommerce_currency_symbol()),
        'description' => __('Enter the purchase cost for an accurate profit calculation.', 'clarifio-connector'),
        'desc_tip'    => true,
        'type'        => 'number',
        'custom_attributes' => array( 'step' => '0.01', 'min' => '0' )
    ));
}

function clarifio_save_cost_field( $post_id ) {
    $cost = isset( $_POST['_clarifio_cost'] ) ? sanitize_text_field( $_POST['_clarifio_cost'] ) : '';
    update_post_meta( $post_id, '_clarifio_cost', $cost );
}

function clarifio_get_product_cost($product) {
    $manual_cost = get_post_meta($product->get_id(), '_clarifio_cost', true);
    if (!empty($manual_cost)) return floatval($manual_cost);
    $default_perc = floatval(get_option('clarifio_default_cost_perc', 60)) / 100;
    return clarifio_get_price_ex_tax($product) * $default_perc;
}

// Τιμή χωρίς ΦΠΑ, ανεξάρτητα από το αν το κατάστημα εισάγει τιμές με ή χωρίς ΦΠΑ. Το κόστος αγοράς
// δεν περιέχει ΦΠΑ, οπότε το fallback % πρέπει να εφαρμόζεται στην τιμή προ ΦΠΑ.
function clarifio_get_price_ex_tax($product) {
    if ($product->get_price() === '' || $product->get_price() === null) return 0.0;
    return (float) wc_get_price_excluding_tax($product);
}

function clarifio_get_valid_sku($product) {
    if (!$product) return 'deleted-product';

    $sku = $product->get_sku();

    // Αν το SKU είναι κενό, δημιουργούμε ένα εικονικό βάσει του ID
    if (empty($sku)) {
        return 'WC-' . $product->get_id();
    }

    return $sku;
}

function clarifio_build_product_row($product) {
    return [
        'sku' => clarifio_get_valid_sku($product),
        'name' => $product->get_name(),
        'costPrice' => clarifio_get_product_cost($product),
        'retailPrice' => clarifio_get_price_ex_tax($product)
    ];
}

function clarifio_handle_sync() {
    // 1. Έλεγχος Nonce (CSRF Protection)
    check_ajax_referer('clarifio_ajax_nonce', 'security');

    // 2. Έλεγχος Δικαιωμάτων (Μόνο Admin/Shop Manager)
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(__('Unauthorized access.', 'clarifio-connector'));
    }

    if (!get_option('clarifio_api_key')) wp_send_json_error(__('No API Key has been set.', 'clarifio-connector'));

    $products = wc_get_products(['status' => 'publish', 'limit' => -1, 'return' => 'objects']);
    $payload = [];

    foreach ($products as $product) {
        $payload[] = clarifio_build_product_row($product);

        // Μεταβλητά προϊόντα (π.χ. Μέγεθος/Χρώμα): το wc_get_products() επιστρέφει μόνο το
        // "γονικό" προϊόν, όχι τις παραλλαγές. Χωρίς αυτό, μια παραγγελία για συγκεκριμένη
        // παραλλαγή δεν θα έβρισκε ταίριασμα SKU στον κατάλογο και το κόστος της θα έπεφτε
        // σε 0 -- εμφανίζοντας τεχνητά μεγαλύτερο κέρδος απ' ό,τι πραγματικά υπάρχει.
        if ($product->is_type('variable')) {
            foreach ($product->get_children() as $variation_id) {
                $variation = wc_get_product($variation_id);
                if ($variation) $payload[] = clarifio_build_product_row($variation);
            }
        }
    }

    if (empty($payload)) wp_send_json_error(__('No products found to sync.', 'clarifio-connector'));

    $result = clarifio_api_post('/api/v1/Products/bulk-sync', $payload, 45);

    // Στα logs αποθηκεύουμε σύνοψη (πλήθος + δείγμα) και όχι ολόκληρο τον κατάλογο.
    clarifio_log_event(
        'Product Sync (manual, ' . count($payload) . ' products)',
        wp_json_encode(['count' => count($payload), 'sample' => array_slice($payload, 0, 3)]),
        $result['code'],
        $result['body']
    );

    if ($result['ok']) {
        wp_send_json_success(['message' => sprintf(__('Successfully sent %d products!', 'clarifio-connector'), count($payload))]);
    }

    wp_send_json_error(sprintf(__('The API returned code %1$s: %2$s', 'clarifio-connector'), $result['code'], clarifio_api_error_message($result)));
}

function clarifio_log_event($event_name, $request_body, $response_code, $response_body) {
    $logs = get_option('clarifio_debug_logs', []);
    if (!is_array($logs)) $logs = [];

    $logs[] = [
        'time'     => current_time('mysql'),
        'event'    => $event_name,
        'payload'  => mb_substr((string) $request_body, 0, 6000),
        'code'     => $response_code,
        'response' => mb_substr((string) $response_body, 0, 2000)
    ];

    // autoload=false: τα logs δεν πρέπει να φορτώνονται σε κάθε request του site.
    update_option('clarifio_debug_logs', array_slice($logs, -50), false);
}

// Ενημέρωση προϊόντος: στο παρασκήνιο, με dedupe ώστε πολλαπλά saves του ίδιου προϊόντος
// (π.χ. μεταβολές αποθέματος από παραγγελίες) να μη δημιουργούν δεκάδες κλήσεις.
function clarifio_sync_single_product_on_save($product_id) {
    if (!get_option('clarifio_api_key')) return;

    if (function_exists('as_enqueue_async_action')) {
        $args = [(int) $product_id];
        if (function_exists('as_has_scheduled_action') && as_has_scheduled_action('clarifio_process_product_sync', $args, 'clarifio')) return;
        as_enqueue_async_action('clarifio_process_product_sync', $args, 'clarifio');
    } else {
        clarifio_run_product_sync($product_id);
    }
}

function clarifio_run_product_sync($product_id) {
    $product = wc_get_product($product_id);
    if (!$product || !get_option('clarifio_api_key')) return;

    $row = clarifio_build_product_row($product);
    $result = clarifio_api_post('/api/v1/Products/bulk-sync', [$row]);

    clarifio_log_event("Product Auto-Sync (#$product_id - {$row['sku']})", wp_json_encode([$row]), $result['code'], $result['body']);
}

// ==========================================
// BULK ORDER SYNC LOGIC
// ==========================================

// Βήμα 1: Επιστρέφει μόνο τα IDs για να μην βαρύνει η μνήμη
function clarifio_ajax_get_orders_for_sync() {
    // 1. Έλεγχος Nonce (CSRF Protection)
    check_ajax_referer('clarifio_ajax_nonce', 'security');

    // 2. Έλεγχος Δικαιωμάτων (Μόνο Admin/Shop Manager)
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(__('Unauthorized access.', 'clarifio-connector'));
    }

    // Ίδια λογική με το live sync: όλα τα statuses (και τα custom) εκτός από όσα δεν αποτελούν πώληση.
    $excluded = array_map(function ($s) { return 'wc-' . $s; }, clarifio_excluded_order_statuses());
    $statuses = array_values(array_diff(array_keys(wc_get_order_statuses()), $excluded));

    $orders = wc_get_orders([
        'limit'  => -1,
        'status'  => $statuses,
        'return' => 'ids',
        'orderby' => 'date',
        'order' => 'DESC'
    ]);

    wp_send_json_success(['order_ids' => $orders]);
}

// Βήμα 2: Παίρνει 50 IDs, τα κάνει JSON και τα χτυπάει στο .NET
function clarifio_ajax_process_order_chunk() {
    // 1. Έλεγχος Nonce (CSRF Protection)
    check_ajax_referer('clarifio_ajax_nonce', 'security');

    // 2. Έλεγχος Δικαιωμάτων (Μόνο Admin/Shop Manager)
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(__('Unauthorized access.', 'clarifio-connector'));
    }

    if (!get_option('clarifio_api_key')) wp_send_json_error(__('No API Key has been set.', 'clarifio-connector'));

    $order_ids = isset($_POST['order_ids']) ? array_map('intval', (array) $_POST['order_ids']) : [];
    if (empty($order_ids)) wp_send_json_error(__('No orders were provided for processing.', 'clarifio-connector'));

    $payload = [];
    foreach ($order_ids as $order_id) {
        $order = wc_get_order($order_id);
        if ($order) $payload[] = clarifio_build_order_payload($order);
    }

    $result = clarifio_api_post('/api/v1/webhook/bulk-sync-orders', $payload, 45);

    $summary = wp_json_encode(['orders' => count($payload), 'first_id' => $order_ids[0], 'last_id' => end($order_ids)]);
    clarifio_log_event('Bulk Sync Orders (' . count($payload) . ' orders)', $summary, $result['code'], $result['body']);

    if (!$result['ok']) {
        // π.χ. 402 υπέρβαση ορίου/λήξη συνδρομής, 403 origin mismatch, 401 άκυρο API key
        wp_send_json_error(sprintf(__('The API returned code %1$s: %2$s', 'clarifio-connector'), $result['code'], clarifio_api_error_message($result)));
    }

    // Μαρκάρουμε τις παραγγελίες ως συγχρονισμένες. Μέσω του order object (όχι update_post_meta),
    // ώστε να δουλεύει σωστά και με HPOS.
    foreach ($order_ids as $oid) {
        $o = wc_get_order($oid);
        if ($o) {
            $o->update_meta_data('_clarifio_sync_status', 'yes');
            $o->save_meta_data();
        }
    }

    wp_send_json_success('Chunk processed');
}

function clarifio_check_api_response_for_expiration($response) {
    if (!is_wp_error($response)) {
        $status_code = (int) wp_remote_retrieve_response_code($response);
        if ($status_code === 402) {
            update_option('clarifio_subscription_expired', 'yes');
        } elseif ($status_code === 200 || $status_code === 201) {
            update_option('clarifio_subscription_expired', 'no');
        }
    }
}
