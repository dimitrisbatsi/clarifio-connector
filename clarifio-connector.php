<?php
/**
 * Plugin Name: Clarif.io Connector
 * Description: Συνδέει το WooCommerce με το Clarif.io για τον υπολογισμό καθαρού κέρδους.
 * Version: 1.9
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

// 2. Επειδή το Repo θα είναι Private, βάζεις το GitHub Token
$myUpdateChecker->setAuthentication('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');

// 3. (Προαιρετικό) Του λες να κοιτάει τα "Releases" στο GitHub και όχι τα απλά commits
// Αυτό προστατεύει τους πελάτες από το να πάρουν ημιτελή κώδικα.
$myUpdateChecker->getVcsApi()->enableReleaseAssets();

define( 'CLARIFIO_API_BASE_URL', 'https://api.clarif.io' );

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
    echo '<div class="error"><p><strong>Clarif.io Connector:</strong> Για να λειτουργήσει το plugin, απαιτείται η εγκατάσταση και ενεργοποίηση του <strong>WooCommerce</strong>.</p></div>';
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
    add_action('wp_ajax_clarifio_get_orders_for_sync', 'clarifio_ajax_get_orders_for_sync');
    add_action('wp_ajax_clarifio_process_order_chunk', 'clarifio_ajax_process_order_chunk');
}

function clarifio_display_expired_notice() {
    if ( get_option( 'clarifio_subscription_expired' ) === 'yes' ) {
        echo '<div class="notice notice-error"><p><strong>Clarif.io:</strong> Ο συγχρονισμός δεδομένων έχει διακοπεί επειδή η συνδρομή σας έχει λήξει. <a href="https://app.clarif.io/account/billing" target="_blank">Μεταβείτε στο ταμείο</a> για να την ανανεώσετε.</p></div>';
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
        echo '<div class="notice notice-success is-dismissible"><p>✅ Οι ρυθμίσεις του Clarif.io αποθηκεύτηκαν με επιτυχία.</p></div>';
    }

    $api_key = get_option('clarifio_api_key', '');
    $default_perc = get_option('clarifio_default_cost_perc', '60');
    
    $default_tab = empty($api_key) ? 'welcome' : 'dashboard';
    $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : $default_tab;
    ?>
    
    <div class="wrap">
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
                    <p style="margin: 5px 0 0 0; color: #64748b; font-size: 14px;">Η καθημερινή σας οικονομική πυξίδα για το e-commerce.</p>
                </div>
                <div>
                    <a href="https://app.clarif.io" target="_blank" class="button" style="display: flex; align-items: center; gap: 8px; padding: 8px 16px;">
                        <span class="dashicons dashicons-external"></span> Άνοιγμα Εφαρμογής
                    </a>
                </div>
            </div>

            <h2 class="nav-tab-wrapper">
                <a href="?page=clarifio-settings&tab=dashboard" class="nav-tab <?php echo $active_tab == 'dashboard' ? 'nav-tab-active' : ''; ?>">📊 Επισκόπηση</a>
                <a href="?page=clarifio-settings&tab=sync" class="nav-tab <?php echo $active_tab == 'sync' ? 'nav-tab-active' : ''; ?>">📦 Προϊόντα & Κόστη</a>
                <a href="?page=clarifio-settings&tab=sync_orders" class="nav-tab <?php echo $active_tab == 'sync_orders' ? 'nav-tab-active' : ''; ?>">🔄 Ιστορικό Παραγγελιών</a>
                <a href="?page=clarifio-settings&tab=settings" class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>">⚙️ Σύνδεση & Ρυθμίσεις</a>
                <a href="?page=clarifio-settings&tab=debug" class="nav-tab <?php echo $active_tab == 'debug' ? 'nav-tab-active' : ''; ?>">🛠️ Κατάσταση Συστήματος</a>
            </h2>
        <?php endif; ?>

        <div style="background: #fff; padding: 30px; border: 1px solid #e2e8f0; <?php echo empty($api_key) ? 'border-top: 1px solid #e2e8f0; border-radius: 8px;' : 'border-top: none;'; ?> box-shadow: 0 1px 3px rgba(0,0,0,.05);">
            
            <?php 
            // --- TAB 0: ONBOARDING / WELCOME ---
            if ($active_tab == 'welcome' || empty($api_key)) : ?>
                <div style="text-align: center; max-width: 650px; margin: 40px auto;">
                    <span class="dashicons dashicons-chart-area" style="font-size: 80px; width: 80px; height: 80px; color: #2563eb;"></span>
                    <h1 style="font-size: 36px; margin-bottom: 15px; color: #0f172a; font-weight: 700;">Οικονομική διαύγεια για το e-shop σας.</h1>
                    <p style="font-size: 18px; color: #475569; line-height: 1.6;">
                        Ο τζίρος είναι ματαιοδοξία, το κέρδος είναι λογική. Το Clarif.io συνδέεται στο WooCommerce σας και υπολογίζει σε πραγματικό χρόνο το <strong>Πραγματικό Καθαρό Κέρδος (Net Profit)</strong>, αφαιρώντας αυτόματα τα κόστη αγοράς (COGS), τα μεταφορικά και τις προμήθειες των τραπεζών.
                    </p>
                    
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 25px; border-radius: 12px; margin: 40px 0;">
                        <h3 style="margin-top: 0; color: #1e293b; font-size: 20px;">Ξεκινήστε τον δωρεάν μήνα σας</h3>
                        <p style="color: #64748b; font-size: 15px;">Ανακαλύψτε ποια προϊόντα σας αφήνουν πραγματικό κέρδος και ποια "τρώνε" το budget σας. Χωρίς δέσμευση, χωρίς πιστωτική κάρτα.</p>
                        <a href="https://app.clarif.io/register" target="_blank" class="button button-primary button-hero" style="background: #2563eb; border-color: #1d4ed8; padding: 10px 30px; font-weight: 600;">Δημιουργία Λογαριασμού</a>
                    </div>

                    <form method="post" action="?page=clarifio-settings&tab=settings" style="background: #fff; border: 1px solid #e2e8f0; padding: 30px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); text-align: left;">
                        <h3 style="margin-top: 0; color: #0f172a;">Σύνδεση υπάρχοντος λογαριασμού</h3>
                        <p style="color: #64748b; font-size: 14px;">Εάν έχετε ήδη εγγραφεί, επικολλήστε το μοναδικό API Key του καταστήματός σας παρακάτω. Θα το βρείτε στην επιλογή "Καταστήματα" στο Clarif.io App.</p>
                        <input type="password" name="clarifio_api_key" placeholder="π.χ. cl_live_8f7d6a5..." style="width: 100%; padding: 12px; margin: 15px 0; font-size: 16px; border-radius: 6px; border: 1px solid #cbd5e1;" required>
                        <input type="hidden" name="clarifio_default_cost_perc" value="60"> 
                        <?php submit_button('Ασφαλής Σύνδεση', 'primary', 'submit', false, ['style' => 'width: 100%; font-size: 16px; padding: 12px; height: auto; border-radius: 6px; background: #0f172a; border-color: #0f172a;']); ?>
                    </form>
                </div>
            
            <?php 
            // --- TAB 1: DASHBOARD ---
            elseif ($active_tab == 'dashboard' && !empty($api_key)) : 
                
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
                    echo '<div class="notice notice-error"><p><strong>Σφάλμα Δικτύου:</strong> Αδυναμία επικοινωνίας με τους διακομιστές του Clarif.io. Παρακαλώ ελέγξτε τη σύνδεσή σας.</p></div>';
                } else {
                    $http_code = wp_remote_retrieve_response_code($status_response);
                    
                    if ($http_code === 200) {
                        $status_body = json_decode(wp_remote_retrieve_body($status_response));

                        if ($is_expired) {
                            // --- UI ΓΙΑ ΛΗΓΜΕΝΗ ΣΥΝΔΡΟΜΗ ---
                            ?>
                            <div style="border: 1px solid #ef4444; background: #fef2f2; padding: 30px; border-radius: 8px; text-align: center;">
                                <span class="dashicons dashicons-warning" style="color: #ef4444; font-size: 50px; width: 50px; height: 50px;"></span>
                                <h3 style="color: #b91c1c; font-size: 20px; margin-top: 15px;">Η συνδρομή σας έληξε</h3>
                                <p style="font-size: 15px; color: #7f1d1d;">Ο συγχρονισμός των παραγγελιών και η προβολή στατιστικών έχουν διακοπεί. Παρακαλώ ανανεώστε το πακέτο σας για να συνεχίσετε να παρακολουθείτε τα κέρδη σας.</p>
                                <a href="https://app.clarif.io/account/billing" target="_blank" class="button button-primary button-large" style="background: #ef4444; border-color: #dc2626; margin-top: 15px;">Ανανέωση Συνδρομής</a>
                            </div>
                            <?php
                        } else {
                            ?>
                            <div style="border: 1px solid #10b981; background: #ecfdf5; padding: 20px; border-radius: 8px; margin-bottom: 30px; display: flex; align-items: center; gap: 15px;">
                                <span class="dashicons dashicons-shield" style="color: #10b981; font-size: 40px; width: 40px; height: 40px;"></span>
                                <div>
                                    <h3 style="margin: 0; color: #065f46; font-size: 18px;">Ασφαλής Σύνδεση Ενεργή</h3>
                                    <p style="margin: 5px 0 0 0; font-size: 14px; color: #047857;">Το e-shop <strong><?php echo esc_html($status_body->storeName); ?></strong> συγχρονίζεται σε πραγματικό χρόνο.</p>
                                </div>
                            </div>
                            
                            <?php 
                            if (!is_wp_error($stats_response) && wp_remote_retrieve_response_code($stats_response) === 200) {
                                $stats = json_decode(wp_remote_retrieve_body($stats_response));
                                ?>
                                <div style="display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; margin-bottom: 20px;">
                                    <h2 style="margin: 0; color: #0f172a;">Σύνοψη Τρέχοντος Μήνα</h2>
                                    <span style="color: #64748b; font-size: 13px;">Ανανέωση σε πραγματικό χρόνο</span>
                                </div>

                                <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                                    <div style="flex: 1; min-width: 200px; background: #fff; border: 1px solid #e2e8f0; padding: 20px; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
                                        <span style="color: #64748b; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Μικτος Τζιρος</span>
                                        <div style="font-size: 28px; font-weight: 700; color: #1e293b; margin-top: 8px;">€<?php echo number_format($stats->totalRevenue, 2, ',', '.'); ?></div>
                                        <p style="margin: 5px 0 0 0; font-size: 12px; color: #94a3b8;">Συνολικά έσοδα προ κρατήσεων</p>
                                    </div>

                                    <div style="flex: 1; min-width: 200px; background: #fff; border: 1px solid #10b981; padding: 20px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(16, 185, 129, 0.1);">
                                        <span style="color: #047857; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Καθαρο Κερδος</span>
                                        <div style="font-size: 28px; font-weight: 700; color: #10b981; margin-top: 8px;">€<?php echo number_format($stats->totalNetProfit, 2, ',', '.'); ?></div>
                                        <p style="margin: 5px 0 0 0; font-size: 12px; color: #059669;">Τα χρήματα που μένουν στην επιχείρηση</p>
                                    </div>

                                    <div style="flex: 1; min-width: 200px; background: #fff; border: 1px solid #e2e8f0; padding: 20px; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
                                        <span style="color: #64748b; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Μεσο Περιθωριο (Margin)</span>
                                        <div style="font-size: 28px; font-weight: 700; color: #3b82f6; margin-top: 8px;"><?php echo number_format($stats->averageMargin, 1, ',', '.'); ?>%</div>
                                        <p style="margin: 5px 0 0 0; font-size: 12px; color: #94a3b8;">Υγεία κερδοφορίας καταστήματος</p>
                                    </div>
                                </div>

                                <p style="margin-top: 30px; font-size: 15px; color: #475569;">
                                    Για βαθύτερη ανάλυση, LTV πελατών, και αναφορές ανά προϊόν, μεταβείτε στην πλήρη εφαρμογή.
                                </p>
                                <a href="https://app.clarif.io" target="_blank" class="button button-primary button-large" style="background: #0f172a; border-color: #0f172a; border-radius: 6px;">
                                    Μετάβαση στο Web App &rarr;
                                </a>
                                <?php
                            }
                        }
                    } else {
                        echo '<div class="notice notice-error"><p>⚠️ <strong>Σφάλμα Αυθεντικοποίησης:</strong> Το API Key φαίνεται να είναι άκυρο ή έχει ανακληθεί. Ελέγξτε τις ρυθμίσεις σας.</p></div>';
                    }
                }
            
            // --- TAB 2: ΡΥΘΜΙΣΕΙΣ ---
            elseif ($active_tab == 'settings') : ?>
                <div style="max-width: 800px;">
                    <h2 style="font-size: 20px; color: #0f172a; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px;">Ρυθμίσεις Σύνδεσης & Αλγορίθμου</h2>
                    <p style="color: #64748b; font-size: 14px; margin-bottom: 25px;">Διαχειριστείτε την επικοινωνία του καταστήματός σας με την πλατφόρμα ανάλυσης δεδομένων.</p>

                    <form method="post" action="?page=clarifio-settings&tab=settings">
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="clarifio_api_key" style="font-weight: 600;">API Key Καταστήματος</label>
                                </th>
                                <td>
                                    <input type="password" id="clarifio_api_key" name="clarifio_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" style="border-radius: 4px;">
                                    <p class="description">Το μυστικό κλειδί που εξασφαλίζει την κρυπτογραφημένη μεταφορά των δεδομένων σας.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="clarifio_default_cost_perc" style="font-weight: 600;">Προεπιλεγμένο Κόστος Αγοράς (COGS)</label>
                                </th>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <input type="number" id="clarifio_default_cost_perc" name="clarifio_default_cost_perc" value="<?php echo esc_attr($default_perc); ?>" step="0.1" min="0" max="100" style="width: 80px; border-radius: 4px;"> 
                                        <span style="font-weight: bold; color: #475569;">% της λιανικής τιμής</span>
                                    </div>
                                    <p class="description" style="max-width: 500px;">
                                        Εάν ένα προϊόν δεν έχει ρητά συμπληρωμένο πεδίο "Κόστος", ο αλγόριθμος του Clarif.io θα χρησιμοποιήσει αυτό το ποσοστό ως δίχτυ ασφαλείας. <br>
                                        <em>Παράδειγμα: Αν οριστεί στο 60% και πουλήσετε κάτι 100€, το σύστημα θα θεωρήσει ότι το αγοράσατε 60€.</em>
                                    </p>
                                </td>
                            </tr>
                        </table>
                        <p class="submit">
                            <?php submit_button('Αποθήκευση Αλλαγών', 'primary', 'submit', false, ['style' => 'background: #2563eb; border-color: #1d4ed8; border-radius: 6px;']); ?>
                        </p>
                    </form>
                </div>

            <?php 
            // --- TAB 3: ΣΥΓΧΡΟΝΙΣΜΟΣ ΠΡΟΙΟΝΤΩΝ ---
            elseif ($active_tab == 'sync') : ?>
                <div style="max-width: 800px;">
                    <h2 style="font-size: 20px; color: #0f172a; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px;">Βάση Δεδομένων Προϊόντων</h2>
                    <p style="color: #64748b; font-size: 15px; margin-bottom: 25px; line-height: 1.6;">
                        Ο θεμέλιος λίθος των σωστών οικονομικών υπολογισμών. Η λειτουργία αυτή συλλέγει όλα τα ενεργά σας προϊόντα, τα SKUs και τα κόστη τους, δημιουργώντας το ενιαίο ευρετήριο (Catalog) στο Clarif.io.
                    </p>

                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-left: 4px solid #f59e0b; padding: 20px; border-radius: 6px; margin-bottom: 25px;">
                        <h4 style="margin: 0 0 10px 0; color: #b45309;">Πότε να χρησιμοποιήσετε αυτή τη λειτουργία;</h4>
                        <ul style="margin: 0; padding-left: 20px; color: #475569;">
                            <li>Κατά την <strong>αρχική εγκατάσταση</strong> του plugin.</li>
                            <li>Αν κάνατε <strong>μαζική αλλαγή τιμών αγοράς (κοστών)</strong> στο WooCommerce (π.χ. μέσω κάποιου αρχείου CSV ή ERP).</li>
                        </ul>
                        <p style="margin: 10px 0 0 0; font-size: 13px; color: #64748b;">
                            *Σημείωση: Δεν χρειάζεται να το πατάτε καθημερινά. Όταν επεξεργάζεστε/δημιουργείτε ένα προϊόν, το plugin ενημερώνει αυτόματα το Clarif.io στο παρασκήνιο.
                        </p>
                    </div>

                    <script> var clarifio_security_nonce = '<?php echo wp_create_nonce("clarifio_ajax_nonce"); ?>'; </script>
                    
                    <button type="button" id="clarifio-sync-btn" class="button button-primary button-large" style="background: #0f172a; border-color: #0f172a; border-radius: 6px; padding: 5px 20px;">
                        <span class="dashicons dashicons-update-alt" style="margin-top: 5px;"></span> Έναρξη Συγχρονισμού Καταλόγου
                    </button>
                    <div id="clarifio-sync-result" style="margin-top: 20px;"></div>

                    <script>
                    // Το JavaScript σου παραμένει ως έχει (μην ξεχάσεις να περνάς το security nonce στο $.post)
                    jQuery(document).ready(function($) {
                        $('#clarifio-sync-btn').on('click', function(e) {
                            e.preventDefault();
                            var $btn = $(this);
                            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update-alt" style="margin-top: 5px; animation: rotation 2s infinite linear;"></span> Συγχρονισμός σε εξέλιξη...');
                            $('#clarifio-sync-result').html('');

                            $.post(ajaxurl, { action: 'clarifio_sync_products', security: clarifio_security_nonce }, function(response) {
                                $btn.prop('disabled', false).html('<span class="dashicons dashicons-update-alt" style="margin-top: 5px;"></span> Έναρξη Συγχρονισμού Καταλόγου');
                                if(response.success) {
                                    $('#clarifio-sync-result').html('<div class="notice notice-success inline" style="border-radius: 6px;"><p>✅ <strong>Επιτυχία:</strong> '+response.data.message+'</p></div>');
                                } else {
                                    $('#clarifio-sync-result').html('<div class="notice notice-error inline" style="border-radius: 6px;"><p>❌ <strong>Σφάλμα:</strong> '+(response.data.Message || response.data || 'Άγνωστο λάθος')+'</p></div>');
                                }
                            }).fail(function() {
                                $btn.prop('disabled', false).html('Έναρξη Συγχρονισμού Καταλόγου');
                                $('#clarifio-sync-result').html('<div class="notice notice-error inline" style="border-radius: 6px;"><p>Αποτυχία επικοινωνίας με τον server.</p></div>');
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
                    <h2 style="font-size: 20px; color: #0f172a; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px;">Ιστορικό Ανάλυσης Παραγγελιών</h2>
                    <p style="color: #64748b; font-size: 15px; margin-bottom: 25px; line-height: 1.6;">
                        Ανακαλύψτε την κρυμμένη αξία των παλαιών σας δεδομένων. Ο μαζικός συγχρονισμός αποστέλλει το ιστορικό πωλήσεών σας στο Clarif.io, επιτρέποντας στον αλγόριθμο να κατασκευάσει τα ιστορικά γραφήματα και να υπολογίσει την <strong>Αξία Ζωής Πελάτη (LTV)</strong>.
                    </p>
                    
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-left: 4px solid #3b82f6; padding: 20px; border-radius: 6px; margin-bottom: 25px;">
                        <p style="margin: 0; color: #475569; font-size: 14px;">
                            <strong>Πληροφορία Υποδομής:</strong> Η διαδικασία έχει σχεδιαστεί ώστε να είναι "αόρατη" για τον server σας. Οι παραγγελίες ομαδοποιούνται και αποστέλλονται τμηματικά (ανά 50), εξασφαλίζοντας μηδενική πτώση στην ταχύτητα του e-shop σας κατά τη διάρκεια του συγχρονισμού.
                        </p>
                    </div>

                    <script> var clarifio_security_nonce = '<?php echo wp_create_nonce("clarifio_ajax_nonce"); ?>'; </script>

                    <button type="button" id="clarifio-sync-orders-btn" class="button button-primary button-large" style="background: #2563eb; border-color: #1d4ed8; border-radius: 6px; padding: 5px 25px;">
                        ▶ Έναρξη Ανάλυσης Ιστορικού
                    </button>
                    
                    <div id="clarifio-progress-wrapper" style="display: none; margin-top: 25px; background: #fff; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                            <span id="clarifio-sync-status-text" style="font-weight: 600; color: #1e293b;">Προετοιμασία δεδομένων...</span>
                            <span id="clarifio-sync-percent" style="font-weight: bold; color: #2563eb;">0%</span>
                        </div>
                        <div style="width: 100%; background-color: #f1f5f9; border-radius: 999px; overflow: hidden; height: 12px;">
                            <div id="clarifio-progress-bar" style="width: 0%; height: 100%; background-color: #2563eb; transition: width 0.4s ease; border-radius: 999px;"></div>
                        </div>
                    </div>

                    <div id="clarifio-sync-orders-result" style="margin-top: 20px;"></div>

                    <script>
                    // Το JavaScript σου παραμένει ως έχει (μην ξεχάσεις να περνάς το security nonce στα $.post calls)
                    jQuery(document).ready(function($) {
                        $('#clarifio-sync-orders-btn').on('click', async function(e) {
                            // ... ο κώδικάς σου παραμένει ίδιος, βεβαιώσου απλά ότι έχεις βάλει security: clarifio_security_nonce ...
                        });
                    });
                    </script>
                </div>

            <?php 
            // --- TAB 5: DEBUG LOGS ---
            elseif ($active_tab == 'debug') : ?>
                <div style="max-width: 100%;">
                    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; margin-bottom: 20px;">
                        <h2 style="margin: 0; font-size: 20px; color: #0f172a;">Αρχείο Καταγραφής Συμβάντων (Logs)</h2>
                        <span style="color: #64748b; font-size: 13px;">Διατηρούνται οι τελευταίες 20 εγγραφές</span>
                    </div>
                    <p style="color: #64748b; font-size: 14px;">Χρησιμοποιήστε αυτή την οθόνη για έλεγχο ορθής λειτουργίας (troubleshooting) ή στείλτε ένα στιγμιότυπο στην υποστήριξη του Clarif.io σε περίπτωση προβλήματος.</p>
                    
                    <table class="wp-list-table widefat fixed striped" style="margin-top: 20px; border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0;">
                        <thead style="background: #f8fafc;">
                            <tr>
                                <th style="width: 160px; font-weight: 600; color: #1e293b;">Timestamp</th>
                                <th style="font-weight: 600; color: #1e293b;">Συμβάν (Event)</th>
                                <th style="width: 120px; font-weight: 600; color: #1e293b;">Κατάσταση</th>
                                <th style="width: 150px; font-weight: 600; color: #1e293b;">Ανάλυση Payload</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $logs = array_reverse(get_option('clarifio_debug_logs', []));
                            if (empty($logs)) {
                                echo '<tr><td colspan="4" style="text-align: center; padding: 30px; color: #64748b;">Δεν έχουν καταγραφεί ακόμη ενέργειες επικοινωνίας.</td></tr>';
                            } else {
                                foreach ($logs as $log) : 
                                    $is_success = ($log['code'] === 'Async' || ($log['code'] >= 200 && $log['code'] < 300));
                                    $status_color = $is_success ? '#10b981' : '#ef4444';
                                    $status_bg = $is_success ? '#ecfdf5' : '#fef2f2';
                                ?>
                                    <tr>
                                        <td style="color: #475569; font-size: 13px;"><?php echo esc_html($log['time']); ?></td>
                                        <td style="font-weight: 500; color: #334155;"><?php echo esc_html($log['event']); ?></td>
                                        <td>
                                            <span style="background: <?php echo $status_bg; ?>; color: <?php echo $status_color; ?>; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 600;">
                                                <?php echo esc_html($log['code']); ?>
                                            </span>
                                        </td>
                                        <td><button type="button" class="button view-json" data-json='<?php echo esc_attr($log['payload']); ?>' style="font-size: 12px; border-radius: 4px;">Επιθεώρηση JSON</button></td>
                                    </tr>
                                <?php endforeach; 
                            } ?>
                        </tbody>
                    </table>
                </div>
                <script>
                jQuery(document).ready(function($) {
                    $('.view-json').on('click', function() {
                        var jsonStr = $(this).data('json');
                        try { alert("Payload Δεδομένων:\n\n" + JSON.stringify(JSON.parse(jsonStr), null, 4)); } 
                        catch (e) { alert("Ακατέργαστα δεδομένα:\n\n" + jsonStr); }
                    });
                });
                </script>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

// ==========================================
// ΥΠΟΛΟΙΠΕΣ ΣΥΝΑΡΤΗΣΕΙΣ (Events, Sync, Log)
// ==========================================
function clarifio_trigger_order_webhook($order_id, $old_status, $new_status, $order) {
    // Μας ενδιαφέρει να στείλουμε στο SaaS την παραγγελία αν μπει σε μία από αυτές τις καταστάσεις
    $relevant_statuses = ['processing', 'completed', 'cancelled', 'refunded', 'on-hold'];
    
    if (in_array($new_status, $relevant_statuses)) {
        clarifio_send_order_to_api($order_id);
    }
}

function clarifio_send_order_to_api($order_id) {
    $order = wc_get_order($order_id);
    $api_key = get_option('clarifio_api_key');
    if (!$api_key || !$order) return;

    $items = [];
    foreach ($order->get_items() as $item_id => $item) {
        $product = $item->get_product();
        $items[] = [
            'sku' => clarifio_get_valid_sku($product),
            'quantity' => (int)$item->get_quantity(),
            'totalPrice' => (float)$item->get_total()
        ];
    }

    // --- ΕΞΑΓΩΓΗ ΣΤΟΙΧΕΙΩΝ ΠΕΛΑΤΗ ---
    $woo_customer_id = $order->get_customer_id();
    $customer_role = 'guest'; // Προεπιλογή για μη εγγεγραμμένους

    if ($woo_customer_id > 0) {
        $user = get_userdata($woo_customer_id);
        if ($user && !empty($user->roles)) {
            $customer_role = $user->roles[0]; // π.χ. 'customer', 'wholesale_customer' κτλ.
        } else {
            $customer_role = 'customer';
        }
    }

    $payload = [
        'orderId' => (int)$order_id,
        'orderDate' => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d\TH:i:s\Z') : null,
        'total' => (float)$order->get_total(),
        'shipping' => (float)$order->get_shipping_total(),
        'status' => $order->get_status(),
        'paymentMethod' => $order->get_payment_method(),
        'customerEmail' => $order->get_billing_email(),
        'customerFirstName' => $order->get_billing_first_name(),
        'customerLastName' => $order->get_billing_last_name(),
        'customerRole' => $customer_role,
        'wooCustomerId' => $woo_customer_id > 0 ? $woo_customer_id : null,
        'items' => $items
    ];

    $response = wp_remote_post(CLARIFIO_API_BASE_URL .'/api/v1/webhook/order-event', [
        'method'    => 'POST',
        'headers'   => ['Content-Type' => 'application/json', 'X-Clarifio-ApiKey' => $api_key, 'X-Clarifio-Store-Url' => home_url()],
        'body'      => json_encode($payload),
        'timeout'   => 15,
        'blocking'  => false, // Αρχιτεκτονικός Κανόνας: Fire and Forget
        'sslverify' => true
    ]);

    clarifio_check_api_response_for_expiration($response);

    clarifio_log_event("Order Webhook (#$order_id)", json_encode($payload), wp_remote_retrieve_response_code($response), wp_remote_retrieve_body($response));
}

function clarifio_add_cost_field() {
    woocommerce_wp_text_input( array(
        'id'          => '_clarifio_cost',
        'label'       => 'Κόστος Clarif.io (€)',
        'description' => 'Εισάγετε το κόστος αγοράς για ακριβή υπολογισμό κέρδους.',
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
    return floatval($product->get_price()) * $default_perc;
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

function clarifio_handle_sync() {
    // 1. Έλεγχος Nonce (CSRF Protection)
    check_ajax_referer('clarifio_ajax_nonce', 'security');

    // 2. Έλεγχος Δικαιωμάτων (Μόνο Admin/Shop Manager)
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Μη εξουσιοδοτημένη πρόσβαση.');
    }

    $api_key = get_option('clarifio_api_key');
    if (empty($api_key)) wp_send_json_error('Δεν έχει οριστεί API Key.');

    $products = wc_get_products(['status' => 'publish', 'limit' => -1, 'return' => 'objects']);
    $payload = [];

    foreach ($products as $product) {
        $sku = clarifio_get_valid_sku($product);
        $payload[] = ['sku' => $sku, 'name' => $product->get_name(), 'costPrice' => clarifio_get_product_cost($product), 'retailPrice' => (float)$product->get_price()];
    }

    if (empty($payload)) wp_send_json_error('Δεν βρέθηκαν προϊόντα με SKU για συγχρονισμό.');

    $response = wp_remote_post(CLARIFIO_API_BASE_URL . '/api/v1/Products/bulk-sync', [
        'headers' => ['Content-Type' => 'application/json', 'X-Clarifio-ApiKey' => $api_key, 'X-Clarifio-Store-Url' => home_url()],
        'body' => json_encode($payload),
        'timeout' => 45,
        'sslverify' => true
    ]);

    clarifio_check_api_response_for_expiration($response);

    if (is_wp_error($response)) wp_send_json_error($response->get_error_message());

    $status_code = wp_remote_retrieve_response_code($response);
    if ($status_code >= 200 && $status_code < 300) wp_send_json_success(['message' => 'Στάλθηκαν επιτυχώς ' . count($payload) . ' προϊόντα!']);
    else wp_send_json_error("Το API επέστρεψε κωδικό $status_code. Λεπτομέρειες: " . wp_remote_retrieve_body($response));
}

function clarifio_log_event($event_name, $request_body, $response_code, $response_body) {
    $logs = get_option('clarifio_debug_logs', []);
    if (count($logs) >= 20) array_shift($logs);
    $logs[] = ['time' => current_time('mysql'), 'event' => $event_name, 'payload' => $request_body, 'code' => $response_code, 'response' => $response_body];
    update_option('clarifio_debug_logs', $logs);
}

function clarifio_sync_single_product_on_save($product_id) {
    $api_key = get_option('clarifio_api_key');
    if (empty($api_key)) return;

    $product = wc_get_product($product_id);
    if (!$product) return;

    $sku = clarifio_get_valid_sku($product);
    if (empty($sku)) return;

    $payload = [['sku' => $sku, 'name' => $product->get_name(), 'costPrice' => clarifio_get_product_cost($product), 'retailPrice' => (float)$product->get_price()]];

    wp_remote_post(CLARIFIO_API_BASE_URL . '/api/v1/Products/bulk-sync', [
        'method'    => 'POST',
        'headers'   => ['Content-Type' => 'application/json', 'X-Clarifio-ApiKey' => $api_key, 'X-Clarifio-Store-Url' => home_url()],
        'body'      => json_encode($payload),
        'timeout'   => 5,
        'blocking'  => false, 
        'sslverify' => true
    ]);

    clarifio_log_event("Product Auto-Sync (#$product_id - $sku)", json_encode($payload), 'Async', 'Εστάλη στο παρασκήνιο (Background process)');
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
        wp_send_json_error('Μη εξουσιοδοτημένη πρόσβαση.');
    }

    $orders = wc_get_orders([
        'limit'  => -1, // Παίρνουμε όλες (ή βάλε όριο π.χ. 2000)
        'status'  => ['completed', 'processing', 'on-hold', 'cancelled', 'refunded'],
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
        wp_send_json_error('Μη εξουσιοδοτημένη πρόσβαση.');
    }

    $api_key = get_option('clarifio_api_key');
    if (empty($api_key)) wp_send_json_error('Δεν έχει οριστεί API Key.');

    $order_ids = isset($_POST['order_ids']) ? array_map('intval', $_POST['order_ids']) : [];
    if (empty($order_ids)) wp_send_json_error('Δεν δόθηκαν παραγγελίες προς επεξεργασία.');

    $payload = [];

    foreach ($order_ids as $order_id) {
        $order = wc_get_order($order_id);
        if (!$order) continue;

        $items = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $items[] = [
                'sku' => clarifio_get_valid_sku($product),
                'quantity' => (int)$item->get_quantity(),
                'totalPrice' => (float)$item->get_total()
            ];
        }

        // --- ΕΞΑΓΩΓΗ ΣΤΟΙΧΕΙΩΝ ΠΕΛΑΤΗ ---
        $woo_customer_id = $order->get_customer_id();
        $customer_role = 'guest';

        if ($woo_customer_id > 0) {
            $user = get_userdata($woo_customer_id);
            if ($user && !empty($user->roles)) {
                $customer_role = $user->roles[0];
            } else {
                $customer_role = 'customer';
            }
        }

        $payload[] = [
            'orderId' => (int)$order_id,
            'orderDate' => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d\TH:i:s\Z') : null,
            'total' => (float)$order->get_total(),
            'shipping' => (float)$order->get_shipping_total(),
            'status' => $order->get_status(),
            'paymentMethod' => $order->get_payment_method(),
            'customerEmail' => $order->get_billing_email(),
            'customerFirstName' => $order->get_billing_first_name(),
            'customerLastName' => $order->get_billing_last_name(),
            'customerRole' => $customer_role,
            'wooCustomerId' => $woo_customer_id > 0 ? $woo_customer_id : null,
            'items' => $items
        ];
    }

    // Κλήση στο νέο endpoint του .NET
    $response = wp_remote_post(CLARIFIO_API_BASE_URL . '/api/v1/webhook/bulk-sync-orders', [
        'headers'   => ['Content-Type' => 'application/json', 'X-Clarifio-ApiKey' => $api_key, 'X-Clarifio-Store-Url' => home_url()],
        'body'      => json_encode($payload),
        'timeout'   => 45, // Μεγάλο timeout γιατί στέλνουμε 50 παραγγελίες
        'sslverify' => true
    ]);

    clarifio_check_api_response_for_expiration($response);

    if (is_wp_error($response)) {
        wp_send_json_error("Σφάλμα σύνδεσης: " . $response->get_error_message());
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);

    if ($status_code >= 200 && $status_code < 300) {
        // Επιτυχία! Μαρκάρουμε τις παραγγελίες ότι συγχρονίστηκαν (προαιρετικό αλλά καλό)
        foreach ($order_ids as $oid) {
            update_post_meta($oid, '_clarifio_sync_status', 'yes');
        }
        clarifio_log_event("Bulk Sync Orders (Chunk of " . count($order_ids) . ")", "Data length: " . strlen(json_encode($payload)), $status_code, "Success");
        wp_send_json_success('Chunk processed');
    } else {
        // Αν το API επιστρέψει Error (π.χ. Υπέρβαση ορίου συνδρομής 402)
        $error_msg = json_decode($response_body);
        $final_error = isset($error_msg->message) ? $error_msg->message : "Άγνωστο σφάλμα HTTP $status_code";
        
        clarifio_log_event("Bulk Sync Orders FAILED", "Data length: " . strlen(json_encode($payload)), $status_code, $response_body);
        wp_send_json_error($final_error);
    }
}

function clarifio_check_api_response_for_expiration($response) {
    if (!is_wp_error($response)) {
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code === 402) {
            update_option('clarifio_subscription_expired', 'yes');
        } elseif ($status_code === 200 || $status_code === 201) {
            update_option('clarifio_subscription_expired', 'no');
        }
    }
}