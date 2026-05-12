<?php
/**
 * Plugin Name: Clarif.io Connector
 * Description: Συνδέει το WooCommerce με το Clarif.io για τον υπολογισμό καθαρού κέρδους.
 * Version: 1.8.2
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

// ==========================================
// 2. ΔΗΜΙΟΥΡΓΙΑ ΜΕΝΟΥ
// ==========================================
function clarifio_register_settings_page() {

$icon_base64 = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA1MTIgNTEyIiB3aWR0aD0iMjAiIGhlaWdodD0iMjAiPjxwYXRoIGQ9Ik0zMDguNTE4LDI1MC41ODZhMTUwLjc1OCwxNTAuNzU4LDAsMCwxLDQuNTg1LDM4LjAyMnEwLDI2LjM5MS03LjYzMSw0Ny44NjR0LTIzLjI4NywzNC4xNTRqLTE1LjY2MywxMi42ODEtMzkuMiwxMi42ODF0LTM4LjkzNy0xMi42ODFqLTE1LjQtMTIuNjcyLTIzLjAzLTM0LjE1NHQtNy42MzItNDcuODY0cTAtMjYuOTA2LDcuNjMyLTQ4LjI1NHQyMy4wMy0zNC4wMjNxMTUuMzg2LTEyLjY3MiwzOC45MzctMTIuNjc4Yy4wMzEsMCwuMDYxLDAsLjA5MiwwbDkuMzItNTQuMzM1Yy0zLjA5NC0uMTU4LTYuMjIyLS4yNjItOS40MTItLjI2MnEtNDIuMTY5LDAtNzMuMjIxLDE4LjYyOXQtNDcuOTk1LDUyLjRxLTE2Ljk1NSwzMy43NjItMTYuOTQ2LDc4Ljc4NiwwLDQ0LjUsMTYuOTQ2LDc4LjAwOXQ0Ny45OTUsNTIuMjY1UTIwMC44MSw0MzcuOSwyNDIuOTgyLDQzNy45cTQyLjQzOCwwLDczLjQ4Mi0xOC43NTV0NDgtNTIuMjY1cTE2LjkzOC0zMy41MDksMTYuOTQ2LTc4LjAwOSwwLTM5LjE5Mi0xMi44NTMtNjkuODQ4WiIgZmlsbD0iI2ZmZiIvPjxwYXRoIGQ9Ik0zMTAuOTIxLDcxLjM2MywyODYuOTc2LDIwNi41MmwxMTkuMjYzLTYwLjdDMzk0LjEsMTAyLjEzMywzNTguODYsNzMuNzA5LDMxMC45MjEsNzEuMzYzWiIgZmlsbD0iIzA1OTY2OSIvPjwvc3ZnPg==';

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
                    <h1 style="margin: 0; color: #1e293b; font-size: 24px; display: flex; align-items: center; gap: 12px;">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="32" height="32" style="flex-shrink: 0;">
                            <path d="M308.518,250.586a150.758,150.758,0,0,1,4.585,38.022q0,26.391-7.631,47.864t-23.287,34.154q-15.663,12.681-39.2,12.681t-38.937-12.681q-15.4-12.672-23.03-34.154t-7.632-47.864q0-26.906,7.632-48.254t23.03-34.023q15.386-12.672,38.937-12.678c.031,0,.061,0,.092,0l9.32-54.335c-3.094-.158-6.222-.262-9.412-.262q-42.169,0-73.221,18.629t-47.995,52.4q-16.955,33.762-16.946,78.786,0,44.5,16.946,78.009t47.995,52.265Q200.81,437.9,242.982,437.9q42.438,0,73.482-18.755t48-52.265q16.938-33.509,16.946-78.009,0-39.192-12.853-69.848Z" fill="#0f172a"/>
                            <path d="M310.921,71.363,286.976,206.52l119.263-60.7C394.1,102.133,358.86,73.709,310.921,71.363Z" fill="#059669"/>
                        </svg> 
                        Clarif.io Workspace
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

                if (is_wp_error($status_response)) {
                    echo '<div class="notice notice-error"><p><strong>Σφάλμα Δικτύου:</strong> Αδυναμία επικοινωνίας με τους διακομιστές του Clarif.io. Παρακαλώ ελέγξτε τη σύνδεσή σας.</p></div>';
                } else {
                    $http_code = wp_remote_retrieve_response_code($status_response);
                    
                    if ($http_code === 200) {
                        $status_body = json_decode(wp_remote_retrieve_body($status_response));
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