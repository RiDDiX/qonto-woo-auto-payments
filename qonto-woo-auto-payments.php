<?php
/**
 * Plugin Name: Auto Payment Matcher for Qonto
 * Plugin URI:  https://github.com/RiDDiX/qonto-woo-auto-payments
 * Description: Prüft Qonto-Zahlungseingänge (inkl. externe Konten wie N26) und setzt passende WooCommerce-Bestellungen automatisch von "Wartestellung" auf "in Bearbeitung".
 * Version:     1.5.0
 * Author:      RiDDiX
 * Author URI:  https://riddix.de
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: auto-payment-matcher-for-qonto
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * WC requires at least: 4.0
 * WC tested up to:      9.6
 */

if (!defined('ABSPATH')) exit;

add_action( 'before_woocommerce_init', function() {
  if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
  }
});

class Qonto_Woo_Auto_Payments {
  const OPT = 'qonto_woo_auto_payments_settings';
  const OPT_STATE = 'qonto_woo_auto_payments_state';
  const CRON_HOOK = 'qonto_woo_auto_payments_cron';

  private static $run_log = [];

  public static function init() {
    add_action('admin_menu', [__CLASS__, 'admin_menu']);
    add_action('admin_init', [__CLASS__, 'register_settings']);

    add_filter('cron_schedules', [__CLASS__, 'cron_schedules']);
    add_action(self::CRON_HOOK, [__CLASS__, 'cron_run']);

    // AJAX Handler für Live-Test
    add_action('wp_ajax_qonto_run_test', [__CLASS__, 'ajax_run_test']);
    
    // AJAX Handler für manuelle Transaktionssuche
    add_action('wp_ajax_qonto_search_transactions', [__CLASS__, 'ajax_search_transactions']);

    register_activation_hook(__FILE__, [__CLASS__, 'activate']);
    register_deactivation_hook(__FILE__, [__CLASS__, 'deactivate']);
  }

  /* -------------------------
   *  Settings UI
   * ------------------------- */
  public static function admin_menu() {
    add_submenu_page(
      'woocommerce',
      'Qonto Zahlungen',
      'Qonto Zahlungen',
      'manage_woocommerce',
      'auto-payment-matcher-for-qonto',
      [__CLASS__, 'settings_page']
    );
  }

  public static function register_settings() {
    register_setting('qonto_woo_auto_payments', self::OPT, [
      'sanitize_callback' => [__CLASS__, 'sanitize_settings']
    ]);

    add_settings_section('qonto_section', 'Qonto API Einstellungen', function() {
      echo '<p>API Key Auth nutzt den Header <code>Authorization: {login}:{secret-key}</code>.</p>';
    }, 'auto-payment-matcher-for-qonto');

    add_settings_field('login', 'Qonto API Login', [__CLASS__, 'field_login'], 'auto-payment-matcher-for-qonto', 'qonto_section');
    add_settings_field('secret', 'Qonto Secret Key', [__CLASS__, 'field_secret'], 'auto-payment-matcher-for-qonto', 'qonto_section');

    add_settings_field('account_name', 'Kontoname (Match)', [__CLASS__, 'field_account_name'], 'auto-payment-matcher-for-qonto', 'qonto_section');
    add_settings_field('only_external', 'Nur externes Konto', [__CLASS__, 'field_only_external'], 'auto-payment-matcher-for-qonto', 'qonto_section');
    add_settings_field('only_completed', 'Nur completed', [__CLASS__, 'field_only_completed'], 'auto-payment-matcher-for-qonto', 'qonto_section');
    add_settings_field('debug', 'Debug-Log', [__CLASS__, 'field_debug'], 'auto-payment-matcher-for-qonto', 'qonto_section');

    add_settings_section('qonto_section2', 'Matching & Workflow', function() {
      echo '<p>Es werden nur Bestellungen mit Status <b>Wartestellung (on-hold)</b> geändert. Match erfolgt über Bestellnummer in Verwendungszweck/Label/Note/Reference.</p>';
    }, 'auto-payment-matcher-for-qonto');

    add_settings_field('regex', 'Bestellnummer Regex', [__CLASS__, 'field_regex'], 'auto-payment-matcher-for-qonto', 'qonto_section2');
    add_settings_field('min_amount', 'Mindestbetrag (EUR, optional)', [__CLASS__, 'field_min_amount'], 'auto-payment-matcher-for-qonto', 'qonto_section2');
    add_settings_field('amount_tolerance', 'Betragstoleranz (EUR)', [__CLASS__, 'field_amount_tolerance'], 'auto-payment-matcher-for-qonto', 'qonto_section2');
    add_settings_field('require_amount_match', 'Betragsabgleich erforderlich', [__CLASS__, 'field_require_amount_match'], 'auto-payment-matcher-for-qonto', 'qonto_section2');
    add_settings_field('enable_name_matching', 'Name-Matching aktivieren', [__CLASS__, 'field_enable_name_matching'], 'auto-payment-matcher-for-qonto', 'qonto_section2');

    add_settings_section('qonto_section3', 'Manuell testen', [__CLASS__, 'render_test_section'], 'auto-payment-matcher-for-qonto');
    
    add_settings_section('qonto_section4', 'Transaktionssuche', [__CLASS__, 'render_search_section'], 'auto-payment-matcher-for-qonto');

    add_action('admin_post_qonto_woo_auto_payments_run_now', [__CLASS__, 'run_now']);
  }

  public static function sanitize_settings($input) {
    $old = self::get_settings();

    $out = [];
    $out['login'] = isset($input['login']) ? sanitize_text_field($input['login']) : '';
    $out['account_name'] = isset($input['account_name']) ? sanitize_text_field($input['account_name']) : 'MEL';
    $out['only_external'] = !empty($input['only_external']) ? 1 : 0;
    $out['only_completed'] = !empty($input['only_completed']) ? 1 : 0;
    $out['debug'] = !empty($input['debug']) ? 1 : 0;

    $out['regex'] = isset($input['regex']) ? trim((string)$input['regex']) : '';
    if ($out['regex'] === '') {
      // Default: findet Bestellnummern in verschiedenen Formaten
      // Best.Nr.123, Best-Nr.:123, Bestellung #123, Order 123, Rechnung 123, #123, etc.
      $out['regex'] = '/(?:Best\\.?-?Nr\\.?:?\\s*#?|Bestellnr\\.?:?\\s*#?|Bestell-?Nr\\.?:?\\s*#?|Bestellung\\s*#?|Order\\s*#?|Rechnung\\s*#?|Rechnungs?-?Nr\\.?:?\\s*#?|Auftrags?-?Nr\\.?:?\\s*#?|Auftrag\\s*#?|Invoice\\s*#?|Inv\\.?\\s*#?|#)\\s*(\\d{3,10})|\\b(\\d{3,10})\\b/i';
    } else {
      // Regex-Validierung: ReDoS-Schutz und Syntaxprüfung
      if (!self::validate_regex($out['regex'])) {
        add_settings_error('qonto_woo_auto_payments', 'invalid_regex', 'Ungültiger oder unsicherer Regex - Standard wird verwendet.', 'error');
        $out['regex'] = '';
      }
    }

    $out['min_amount'] = isset($input['min_amount']) ? floatval(str_replace(',', '.', $input['min_amount'])) : 0.0;
    $out['amount_tolerance'] = isset($input['amount_tolerance']) ? floatval(str_replace(',', '.', $input['amount_tolerance'])) : 0.01;
    $out['require_amount_match'] = !empty($input['require_amount_match']) ? 1 : 0;
    $out['enable_name_matching'] = !empty($input['enable_name_matching']) ? 1 : 0;

    // Secret: nur überschreiben wenn Feld nicht leer ist
    if (!empty($input['secret'])) {
      $out['secret_enc'] = self::encrypt_secret((string)$input['secret']);
    } else {
      $out['secret_enc'] = isset($old['secret_enc']) ? $old['secret_enc'] : '';
    }

    return $out;
  }

  public static function settings_page() {
    if (!current_user_can('manage_woocommerce')) return;

    echo '<div class="wrap"><h1>Auto Payment Matcher for Qonto</h1>';
    
    // Sicherheitswarnungen anzeigen
    $security_issues = self::security_check();
    if (!empty($security_issues)) {
      echo '<div class="notice notice-warning"><p><strong>⚠️ Sicherheitshinweise:</strong></p><ul>';
      foreach ($security_issues as $issue) {
        echo '<li>' . esc_html($issue) . '</li>';
      }
      echo '</ul></div>';
    }
    
    echo '<p>Cron läuft alle <b>6 Stunden</b>. Unterstützt interne Qonto-Konten UND externe Konten (z.B. N26 "MEL").</p>';

    echo '<form method="post" action="options.php">';
    settings_fields('qonto_woo_auto_payments');
    do_settings_sections('auto-payment-matcher-for-qonto');
    submit_button();
    echo '</form>';
    
    // Live-Konsole JavaScript
    self::render_console_script();
    
    echo '</div>';
  }

  public static function render_test_section() {
    $nonce = wp_create_nonce('qonto_run_test');
    echo '<p><b>Einmaliger Testlauf</b> mit Live-Konsole.</p>';
    echo '<p><button type="button" id="qonto-run-test" class="button button-primary" data-nonce="' . esc_attr($nonce) . '">Jetzt prüfen</button></p>';
    echo '<div id="qonto-console" style="display:none; margin-top:15px;">';
    echo '<h4 style="margin-bottom:10px;">Konsole:</h4>';
    echo '<div id="qonto-console-output" style="background:#1e1e1e; color:#d4d4d4; font-family:monospace; font-size:12px; padding:15px; max-height:400px; overflow-y:auto; border-radius:4px; white-space:pre-wrap;"></div>';
    echo '</div>';
  }

  public static function render_search_section() {
    $nonce = wp_create_nonce('qonto_search');
    ?>
    <p><b>Manuelle Transaktionssuche</b> - Suche nach Bestellnummer, Verwendungszweck oder Absendername.</p>
    <table class="form-table" role="presentation">
      <tr>
        <th scope="row">Suchbegriff</th>
        <td>
          <input type="text" id="qonto-search-term" class="regular-text" placeholder="z.B. 12345 oder Max Mustermann" />
          <p class="description">Bestellnummer, Verwendungszweck oder Name des Absenders</p>
        </td>
      </tr>
      <tr>
        <th scope="row">Suchtyp</th>
        <td>
          <select id="qonto-search-type">
            <option value="all">Alle Felder durchsuchen</option>
            <option value="reference">Verwendungszweck / Referenz</option>
            <option value="name">Absendername</option>
            <option value="amount">Betrag (EUR)</option>
          </select>
        </td>
      </tr>
      <tr>
        <th scope="row">Zeitraum</th>
        <td>
          <select id="qonto-search-days">
            <option value="7">Letzte 7 Tage</option>
            <option value="30" selected>Letzte 30 Tage</option>
            <option value="90">Letzte 90 Tage</option>
            <option value="180">Letzte 180 Tage</option>
          </select>
        </td>
      </tr>
    </table>
    <p>
      <button type="button" id="qonto-search-btn" class="button button-secondary" data-nonce="<?php echo esc_attr($nonce); ?>">
        🔍 Transaktionen suchen
      </button>
    </p>
    <div id="qonto-search-results" style="display:none; margin-top:15px;">
      <h4 style="margin-bottom:10px;">Suchergebnisse:</h4>
      <div id="qonto-search-output" style="background:#f8f9fa; border:1px solid #ddd; padding:15px; max-height:500px; overflow-y:auto; border-radius:4px;"></div>
    </div>
    <?php
  }

  public static function render_console_script() {
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
      var $btn = $('#qonto-run-test');
      var $console = $('#qonto-console');
      var $output = $('#qonto-console-output');
      
      // Nonce aus sicherem data-Attribut lesen
      var securityNonce = $btn.data('nonce');
      
      function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
      }
      
      function log(msg, type) {
        var color = '#d4d4d4';
        if (type === 'success') color = '#4ec9b0';
        else if (type === 'error') color = '#f14c4c';
        else if (type === 'warning') color = '#cca700';
        else if (type === 'info') color = '#3794ff';
        else if (type === 'header') color = '#c586c0';
        
        var time = new Date().toLocaleTimeString('de-DE');
        // XSS-Schutz: HTML escapen
        var safeMsg = escapeHtml(msg);
        $output.append('<span style="color:#6a9955;">[' + time + ']</span> <span style="color:' + color + ';">' + safeMsg + '</span>\n');
        $output.scrollTop($output[0].scrollHeight);
      }
      
      $btn.on('click', function() {
        $btn.prop('disabled', true).text('Läuft...');
        $console.show();
        $output.html('');
        
        log('=== QONTO ZAHLUNGSABGLEICH GESTARTET ===', 'header');
        log('Verbinde mit Qonto API...', 'info');
        
        $.ajax({
          url: ajaxurl,
          type: 'POST',
          data: {
            action: 'qonto_run_test',
            _wpnonce: securityNonce
          },
          success: function(response) {
            if (response.success && response.data && response.data.log) {
              response.data.log.forEach(function(entry) {
                log(entry.msg || '', entry.type || 'info');
              });
              log('', '');
              log('=== ABGLEICH ABGESCHLOSSEN ===', 'header');
              var matched = parseInt(response.data.matched, 10) || 0;
              if (matched > 0) {
                log('✅ ' + matched + ' Bestellung(en) aktualisiert!', 'success');
              } else {
                log('ℹ️ Keine passenden Bestellungen gefunden.', 'info');
              }
            } else {
              log('Fehler: Unbekannter Fehler', 'error');
            }
          },
          error: function(xhr, status, error) {
            log('Verbindungsfehler', 'error');
          },
          complete: function() {
            $btn.prop('disabled', false).text('Jetzt prüfen');
          }
        });
      });
      
      // === TRANSAKTIONSSUCHE ===
      var $searchBtn = $('#qonto-search-btn');
      var $searchResults = $('#qonto-search-results');
      var $searchOutput = $('#qonto-search-output');
      var searchNonce = $searchBtn.data('nonce');
      
      $searchBtn.on('click', function() {
        var searchTerm = $('#qonto-search-term').val().trim();
        var searchType = $('#qonto-search-type').val();
        var searchDays = $('#qonto-search-days').val();
        
        if (!searchTerm) {
          alert('Bitte einen Suchbegriff eingeben.');
          return;
        }
        
        $searchBtn.prop('disabled', true).text('Suche läuft...');
        $searchResults.show();
        $searchOutput.html('<p style="color:#666;">🔄 Suche Transaktionen...</p>');
        
        $.ajax({
          url: ajaxurl,
          type: 'POST',
          data: {
            action: 'qonto_search_transactions',
            _wpnonce: searchNonce,
            search_term: searchTerm,
            search_type: searchType,
            days: searchDays
          },
          success: function(response) {
            if (response.success && response.data) {
              var results = response.data.results || [];
              var html = '';
              
              if (results.length === 0) {
                html = '<div style="padding:20px; text-align:center; color:#666;">';
                html += '<p style="font-size:16px;">❌ Keine Transaktionen gefunden</p>';
                html += '<p>Suchbegriff: <strong>' + escapeHtml(searchTerm) + '</strong></p>';
                html += '</div>';
              } else {
                html = '<p style="margin-bottom:15px;"><strong>✅ ' + results.length + ' Transaktion(en) gefunden:</strong></p>';
                html += '<table style="width:100%; border-collapse:collapse; font-size:13px;">';
                html += '<thead><tr style="background:#f0f0f0;">';
                html += '<th style="padding:8px; border:1px solid #ddd; text-align:left;">Datum</th>';
                html += '<th style="padding:8px; border:1px solid #ddd; text-align:left;">Absender</th>';
                html += '<th style="padding:8px; border:1px solid #ddd; text-align:right;">Betrag</th>';
                html += '<th style="padding:8px; border:1px solid #ddd; text-align:left;">Verwendungszweck</th>';
                html += '<th style="padding:8px; border:1px solid #ddd; text-align:left;">Status</th>';
                html += '</tr></thead><tbody>';
                
                results.forEach(function(tx) {
                  var statusColor = tx.status === 'completed' ? '#28a745' : '#ffc107';
                  html += '<tr>';
                  html += '<td style="padding:8px; border:1px solid #ddd;">' + escapeHtml(tx.date || '-') + '</td>';
                  html += '<td style="padding:8px; border:1px solid #ddd;">' + escapeHtml(tx.counterparty || '-') + '</td>';
                  html += '<td style="padding:8px; border:1px solid #ddd; text-align:right; font-weight:bold; color:#28a745;">' + escapeHtml(tx.amount || '-') + '</td>';
                  html += '<td style="padding:8px; border:1px solid #ddd; max-width:300px; word-break:break-word;">' + escapeHtml(tx.reference || '-') + '</td>';
                  html += '<td style="padding:8px; border:1px solid #ddd;"><span style="color:' + statusColor + ';">' + escapeHtml(tx.status || '-') + '</span></td>';
                  html += '</tr>';
                });
                
                html += '</tbody></table>';
              }
              
              $searchOutput.html(html);
            } else {
              var errMsg = (response.data && response.data.message) ? response.data.message : 'Unbekannter Fehler';
              $searchOutput.html('<p style="color:#dc3545;">❌ Fehler: ' + escapeHtml(errMsg) + '</p>');
            }
          },
          error: function() {
            $searchOutput.html('<p style="color:#dc3545;">❌ Verbindungsfehler</p>');
          },
          complete: function() {
            $searchBtn.prop('disabled', false).text('🔍 Transaktionen suchen');
          }
        });
      });
    });
    </script>
    <?php
  }

  public static function ajax_run_test() {
    // Strikte Berechtigungsprüfung
    if (!current_user_can('manage_woocommerce') || !is_user_logged_in()) {
      wp_send_json_error(__('Keine Berechtigung', 'auto-payment-matcher-for-qonto'));
      return;
    }
    
    // Nonce-Prüfung mit strikter Validierung
    if (!check_ajax_referer('qonto_run_test', '_wpnonce', false)) {
      wp_send_json_error(__('Sicherheitsprüfung fehlgeschlagen', 'auto-payment-matcher-for-qonto'));
      return;
    }
    
    // Rate-Limiting: Max 1 Aufruf pro 10 Sekunden pro User
    $user_id = get_current_user_id();
    $rate_key = 'qonto_rate_' . $user_id;
    $last_run = get_transient($rate_key);
    if ($last_run !== false) {
      wp_send_json_error(__('Bitte warte 10 Sekunden zwischen den Aufrufen', 'auto-payment-matcher-for-qonto'));
      return;
    }
    set_transient($rate_key, time(), 10);
    
    self::$run_log = [];
    $matched = self::cron_run(true, true); // true = manual, true = return_log
    
    // Sensible Daten aus Log maskieren bevor sie an den Client gesendet werden
    $sanitized_log = self::sanitize_log_output(self::$run_log);
    
    wp_send_json_success([
      'log' => $sanitized_log,
      'matched' => intval($matched)
    ]);
  }

  public static function ajax_search_transactions() {
    // Strikte Berechtigungsprüfung
    if (!current_user_can('manage_woocommerce') || !is_user_logged_in()) {
      wp_send_json_error(['message' => __('Keine Berechtigung', 'auto-payment-matcher-for-qonto')]);
      return;
    }
    
    // Nonce-Prüfung
    if (!check_ajax_referer('qonto_search', '_wpnonce', false)) {
      wp_send_json_error(['message' => __('Sicherheitsprüfung fehlgeschlagen', 'auto-payment-matcher-for-qonto')]);
      return;
    }
    
    // Rate-Limiting: Max 1 Suche pro 5 Sekunden pro User
    $user_id = get_current_user_id();
    $rate_key = 'qonto_search_rate_' . $user_id;
    $last_search = get_transient($rate_key);
    if ($last_search !== false) {
      wp_send_json_error(['message' => __('Bitte warte 5 Sekunden zwischen den Suchen', 'auto-payment-matcher-for-qonto')]);
      return;
    }
    set_transient($rate_key, time(), 5);
    
    // Eingaben validieren
    $search_term = isset($_POST['search_term']) ? sanitize_text_field(wp_unslash($_POST['search_term'])) : '';
    $search_type = isset($_POST['search_type']) ? sanitize_key(wp_unslash($_POST['search_type'])) : 'all';
    $days = isset($_POST['days']) ? absint(wp_unslash($_POST['days'])) : 30;
    
    // Whitelist für search_type
    $allowed_types = ['all', 'reference', 'name', 'amount'];
    if (!in_array($search_type, $allowed_types, true)) {
      $search_type = 'all';
    }
    
    if (empty($search_term)) {
      wp_send_json_error(['message' => __('Suchbegriff erforderlich', 'auto-payment-matcher-for-qonto')]);
      return;
    }
    
    // Maximaler Zeitraum: 180 Tage, Minimum: 1 Tag
    $days = max(1, min($days, 180));
    
    $s = self::get_settings();
    $login = $s['login'] ?? '';
    $secret = self::get_secret();
    
    if (!$login || !$secret) {
      wp_send_json_error(['message' => __('API-Zugangsdaten fehlen', 'auto-payment-matcher-for-qonto')]);
      return;
    }
    
    try {
      // Bank-Konto ermitteln
      $accounts_response = self::qonto_get($login, $secret, 'https://thirdparty.qonto.com/v2/bank_accounts?per_page=100');
      $accounts = $accounts_response['bank_accounts'] ?? [];
      
      if (empty($accounts)) {
        wp_send_json_error(['message' => __('Keine Bankkonten gefunden', 'auto-payment-matcher-for-qonto')]);
        return;
      }
      
      // Zielkonto wählen (gleiche Logik wie cron_run)
      $target_name = trim((string)($s['account_name'] ?? 'MEL'));
      $only_external = !empty($s['only_external']);
      $target = null;
      
      if ($target_name !== '') {
        foreach ($accounts as $a) {
          $name_match = !empty($a['name']) && mb_strtolower($a['name']) === mb_strtolower($target_name);
          $status_ok = ($a['status'] ?? '') === 'active';
          $external_ok = !$only_external || !empty($a['is_external_account']);
          if ($name_match && $status_ok && $external_ok) {
            $target = $a;
            break;
          }
        }
      }
      
      if (!$target && $only_external) {
        foreach ($accounts as $a) {
          if (!empty($a['is_external_account']) && ($a['status'] ?? '') === 'active') {
            $target = $a;
            break;
          }
        }
      }
      
      if (!$target) {
        foreach ($accounts as $a) {
          if (!empty($a['main']) && ($a['status'] ?? '') === 'active') {
            $target = $a;
            break;
          }
        }
      }
      
      if (!$target) {
        wp_send_json_error(['message' => __('Zielkonto nicht gefunden', 'auto-payment-matcher-for-qonto')]);
        return;
      }
      
      $bank_account_id = $target['id'];
      
      // Transaktionen abrufen
      $updated_from = gmdate('c', time() - $days * 24 * 3600);
      $qs = [
        'bank_account_id' => $bank_account_id,
        'side'            => 'credit',
        'updated_at_from' => $updated_from,
        'sort_by'         => 'updated_at:desc',
        'per_page'        => '100'
      ];
      
      $url = 'https://thirdparty.qonto.com/v2/transactions?' . http_build_query($qs);
      $tx_response = self::qonto_get($login, $secret, $url);
      $transactions = $tx_response['transactions'] ?? [];
      
      // Suche durchführen
      $results = [];
      $search_lower = mb_strtolower($search_term);
      
      foreach ($transactions as $t) {
        $match = false;
        
        $label = $t['label'] ?? '';
        $reference = $t['reference'] ?? '';
        $note = $t['note'] ?? '';
        $counterparty = $t['counterparty_name'] ?? ($t['label'] ?? '');
        $amount = isset($t['amount']) ? floatval($t['amount']) : 0.0;
        
        // Je nach Suchtyp
        switch ($search_type) {
          case 'reference':
            $haystack = mb_strtolower($label . ' ' . $reference . ' ' . $note);
            $match = mb_strpos($haystack, $search_lower) !== false;
            break;
            
          case 'name':
            $match = mb_strpos(mb_strtolower($counterparty), $search_lower) !== false;
            break;
            
          case 'amount':
            $search_amount = floatval(str_replace(',', '.', $search_term));
            $match = abs($amount - $search_amount) < 0.01;
            break;
            
          case 'all':
          default:
            $haystack = mb_strtolower($label . ' ' . $reference . ' ' . $note . ' ' . $counterparty);
            $match = mb_strpos($haystack, $search_lower) !== false;
            
            // Auch Betrag prüfen wenn numerisch
            if (!$match && is_numeric(str_replace(',', '.', $search_term))) {
              $search_amount = floatval(str_replace(',', '.', $search_term));
              $match = abs($amount - $search_amount) < 0.01;
            }
            break;
        }
        
        if ($match) {
          // Datum formatieren
          $date_str = '';
          $settled_at = $t['settled_at'] ?? $t['emitted_at'] ?? '';
          if ($settled_at) {
            $dt = date_create($settled_at);
            if ($dt) $date_str = $dt->format('d.m.Y H:i');
          }
          
          // Referenztext zusammenstellen
          $ref_text = trim($reference ?: $label);
          if ($note && $note !== $ref_text) {
            $ref_text .= ($ref_text ? ' | ' : '') . $note;
          }
          
          // IBAN maskieren für Ausgabe
          $iban = $t['local_iban'] ?? '';
          if ($iban && strlen($iban) > 8) {
            $iban = substr($iban, 0, 4) . str_repeat('*', strlen($iban) - 8) . substr($iban, -4);
          }
          
          $results[] = [
            'date' => $date_str,
            'counterparty' => $counterparty . ($iban ? ' (' . $iban . ')' : ''),
            'amount' => number_format($amount, 2, ',', '.') . ' ' . ($t['currency'] ?? 'EUR'),
            'reference' => $ref_text,
            'status' => $t['status'] ?? 'unknown'
          ];
        }
        
        // Maximal 50 Ergebnisse
        if (count($results) >= 50) break;
      }
      
      wp_send_json_success(['results' => $results]);
      
    } catch (Exception $e) {
      wp_send_json_error(['message' => $e->getMessage()]);
    }
  }

  private static function sanitize_log_output($log) {
    $sanitized = [];
    foreach ($log as $entry) {
      $msg = $entry['msg'] ?? '';
      // IBANs maskieren (zeige nur letzte 4 Zeichen)
      $msg = preg_replace_callback('/\b([A-Z]{2}\d{2}[A-Z0-9]{4,})\b/', function($m) {
        $iban = $m[1];
        if (strlen($iban) > 8) {
          return substr($iban, 0, 4) . str_repeat('*', strlen($iban) - 8) . substr($iban, -4);
        }
        return $iban;
      }, $msg);
      // UUIDs/Transaktions-IDs maskieren
      $msg = preg_replace('/([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})/i', '***-****-****', $msg);
      // Lange Hex-Strings maskieren (potentielle IDs)
      $msg = preg_replace('/\b([a-f0-9]{24,})\b/i', '***masked***', $msg);
      
      $sanitized[] = [
        'msg' => esc_html($msg),
        'type' => sanitize_key($entry['type'] ?? 'info')
      ];
    }
    return $sanitized;
  }

  private static function add_log($msg, $type = 'info') {
    self::$run_log[] = ['msg' => $msg, 'type' => $type];
  }

  public static function field_login() {
    $s = self::get_settings();
    printf('<input type="text" name="%s[login]" value="%s" class="regular-text" placeholder="syntech-gmbh-8328" />',
      esc_attr(self::OPT), esc_attr($s['login'] ?? '')
    );
  }

  public static function field_secret() {
    echo '<input type="password" name="' . esc_attr(self::OPT) . '[secret]" value="" class="regular-text" placeholder="(leer lassen = unverändert)" autocomplete="new-password" />';
    echo '<p class="description">Wird verschlüsselt in der DB gespeichert (mit WP SALTs als Key).</p>';
  }

  public static function field_account_name() {
    $s = self::get_settings();
    printf('<input type="text" name="%s[account_name]" value="%s" class="regular-text" />',
      esc_attr(self::OPT), esc_attr($s['account_name'] ?? 'MEL')
    );
    echo '<p class="description">Kontoname z.B. "MEL" für externes N26-Konto. Leer = Fallback auf externes oder Hauptkonto.</p>';
  }

  public static function field_only_external() {
    $s = self::get_settings();
    printf('<label><input type="checkbox" name="%s[only_external]" value="1" %s /> Nur externe Konten prüfen (z.B. N26)</label>',
      esc_attr(self::OPT),
      checked(!empty($s['only_external']), true, false)
    );
    echo '<p class="description">Aktivieren um nur bei Qonto hinterlegte externe Bankkonten zu überwachen.</p>';
  }

  public static function field_only_completed() {
    $s = self::get_settings();
    printf('<label><input type="checkbox" name="%s[only_completed]" value="1" %s /> Nur status=completed</label>',
      esc_attr(self::OPT),
      checked(!empty($s['only_completed']), true, false)
    );
  }

  public static function field_debug() {
    $s = self::get_settings();
    printf('<label><input type="checkbox" name="%s[debug]" value="1" %s /> Logge in WooCommerce Status-Logs</label>',
      esc_attr(self::OPT),
      checked(!empty($s['debug']), true, false)
    );
  }

  public static function field_regex() {
    $s = self::get_settings();
    printf('<textarea name="%s[regex]" rows="3" class="large-text code">%s</textarea>',
      esc_attr(self::OPT), esc_textarea($s['regex'] ?? '')
    );
    echo '<p class="description">Regex muss mind. eine Gruppe mit der Bestellnummer liefern (oder Zahl im Text finden).</p>';
  }

  public static function field_min_amount() {
    $s = self::get_settings();
    printf('<input type="text" name="%s[min_amount]" value="%s" class="small-text" />',
      esc_attr(self::OPT), esc_attr(isset($s['min_amount']) ? (string)$s['min_amount'] : '0')
    );
    echo '<p class="description">0 = deaktiviert. Wenn gesetzt, werden nur Eingänge >= Betrag gematched.</p>';
  }

  public static function field_amount_tolerance() {
    $s = self::get_settings();
    printf('<input type="text" name="%s[amount_tolerance]" value="%s" class="small-text" />',
      esc_attr(self::OPT), esc_attr(isset($s['amount_tolerance']) ? (string)$s['amount_tolerance'] : '0.01')
    );
    echo '<p class="description">Erlaubte Abweichung in EUR zwischen Transaktionsbetrag und Bestellbetrag (z.B. 0.01 für Cent-Rundung).</p>';
  }

  public static function field_require_amount_match() {
    $s = self::get_settings();
    printf('<label><input type="checkbox" name="%s[require_amount_match]" value="1" %s /> Transaktionsbetrag muss mit Bestellbetrag übereinstimmen</label>',
      esc_attr(self::OPT),
      checked(!empty($s['require_amount_match']), true, false)
    );
    echo '<p class="description"><strong>Empfohlen:</strong> Verhindert falsche Zuordnungen bei ähnlichen Bestellnummern.</p>';
  }

  public static function field_enable_name_matching() {
    $s = self::get_settings();
    printf('<label><input type="checkbox" name="%s[enable_name_matching]" value="1" %s /> Fallback auf Kundenname wenn keine Bestellnummer gefunden</label>',
      esc_attr(self::OPT),
      checked(!empty($s['enable_name_matching']), true, false)
    );
    echo '<p class="description">Wenn keine Bestellnummer im Verwendungszweck erkannt wird, wird nach dem vollständigen Kundennamen gesucht. <strong>Betragsabgleich ist hierbei immer erforderlich!</strong></p>';
  }

  /* -------------------------
   *  Cron scheduling
   * ------------------------- */
  public static function cron_schedules($schedules) {
    if (!isset($schedules['qonto_6hours'])) {
      $schedules['qonto_6hours'] = [
        'interval' => 6 * 60 * 60,
        'display'  => 'Every 6 hours (Qonto)'
      ];
    }
    return $schedules;
  }

  public static function activate() {
    if (!wp_next_scheduled(self::CRON_HOOK)) {
      wp_schedule_event(time() + 300, 'qonto_6hours', self::CRON_HOOK);
    }
  }

  public static function deactivate() {
    $ts = wp_next_scheduled(self::CRON_HOOK);
    if ($ts) wp_unschedule_event($ts, self::CRON_HOOK);
  }

  public static function run_now() {
    // Strikte Berechtigungsprüfung
    if (!current_user_can('manage_woocommerce') || !is_user_logged_in()) {
      wp_die(
        esc_html__('Keine Berechtigung', 'auto-payment-matcher-for-qonto'),
        esc_html__('Zugriff verweigert', 'auto-payment-matcher-for-qonto'),
        ['response' => 403]
      );
    }
    
    // Nonce mit strikter Sanitization
    $nonce = isset($_REQUEST['_wpnonce']) ? sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])) : '';
    if (!wp_verify_nonce($nonce, 'qonto_run_now')) {
      wp_die(
        esc_html__('Sicherheitsprüfung fehlgeschlagen. Bitte lade die Seite neu.', 'auto-payment-matcher-for-qonto'),
        esc_html__('Sicherheitsfehler', 'auto-payment-matcher-for-qonto'),
        ['response' => 403]
      );
    }
    
    // Rate-Limiting: Max 1 Aufruf pro 30 Sekunden
    $user_id = get_current_user_id();
    $rate_key = 'qonto_runnow_' . $user_id;
    if (get_transient($rate_key) !== false) {
      wp_die(
        esc_html__('Bitte warte 30 Sekunden zwischen den Aufrufen.', 'auto-payment-matcher-for-qonto'),
        esc_html__('Rate-Limit', 'auto-payment-matcher-for-qonto'),
        ['response' => 429]
      );
    }
    set_transient($rate_key, time(), 30);

    self::cron_run(true);

    wp_safe_redirect(admin_url('admin.php?page=auto-payment-matcher-for-qonto&run=1'));
    exit;
  }

  /* -------------------------
   *  Core logic
   * ------------------------- */
  private static function get_settings() {
    $s = get_option(self::OPT, []);
    if (!is_array($s)) $s = [];
    return $s;
  }

  private static function get_secret() {
    $s = self::get_settings();
    if (empty($s['secret_enc'])) return '';
    return self::decrypt_secret($s['secret_enc']);
  }

  private static function logger() {
    if (class_exists('WC_Logger')) return wc_get_logger();
    return null;
  }

  private static function log($msg, $level = 'info') {
    $s = self::get_settings();
    if (empty($s['debug'])) return;
    $logger = self::logger();
    if ($logger) $logger->log($level, $msg, ['source' => 'auto-payment-matcher-for-qonto']);
  }

  public static function cron_run($manual = false, $return_log = false) {
    if (!class_exists('WooCommerce')) {
      self::log('WooCommerce not active.', 'error');
      if ($return_log) self::add_log('WooCommerce nicht aktiv!', 'error');
      return $return_log ? 0 : null;
    }

    $s = self::get_settings();
    $login = $s['login'] ?? '';
    $secret = self::get_secret();
    if (!$login || !$secret) {
      self::log('Missing Qonto credentials (login/secret).', 'error');
      if ($return_log) self::add_log('Qonto API Zugangsdaten fehlen (Login/Secret)!', 'error');
      return $return_log ? 0 : null;
    }
    
    if ($return_log) self::add_log('Zugangsdaten geladen', 'success');

    // State: last_checked_updated_at + processed transaction UUIDs
    $state = get_option(self::OPT_STATE, []);
    if (!is_array($state)) $state = [];
    $updated_from = $state['last_updated_at'] ?? gmdate('c', time() - 48*3600); // initial: last 48h
    $processed = isset($state['processed_ids']) && is_array($state['processed_ids']) ? $state['processed_ids'] : [];

    try {
      if ($return_log) self::add_log('Rufe Bankkonten von Qonto ab...', 'info');
      $accounts_response = self::qonto_get($login, $secret, 'https://thirdparty.qonto.com/v2/bank_accounts?per_page=100');
      $accounts = $accounts_response['bank_accounts'] ?? [];
      if (!is_array($accounts) || empty($accounts)) throw new Exception('No bank_accounts in /v2/bank_accounts');
      
      if ($return_log) self::add_log('Bankkonten geladen: ' . count($accounts) . ' Konto(en) gefunden', 'success');

      // Konto wählen: Name match > external account > main=true
      $target_name = trim((string)($s['account_name'] ?? 'MEL'));
      $only_external = !empty($s['only_external']);
      $target = null;

      // Debug: Liste alle Konten
      if ($return_log) {
        self::add_log('Verfügbare Konten:', 'info');
        foreach ($accounts as $a) {
          $ext_flag = !empty($a['is_external_account']) ? ' [EXTERN]' : '';
          $main_flag = !empty($a['main']) ? ' [MAIN]' : '';
          self::add_log(sprintf('  • %s%s%s (Status: %s)', $a['name'] ?? 'Unbekannt', $ext_flag, $main_flag, $a['status'] ?? 'N/A'), 'info');
        }
      }

      // Suche nach Kontoname
      if ($target_name !== '') {
        foreach ($accounts as $a) {
          $name_match = !empty($a['name']) && mb_strtolower($a['name']) === mb_strtolower($target_name);
          $status_ok = ($a['status'] ?? '') === 'active';
          $external_ok = !$only_external || !empty($a['is_external_account']);
          
          if ($name_match && $status_ok && $external_ok) {
            $target = $a; break;
          }
        }
      }
      
      // Fallback: erstes externes Konto wenn only_external aktiviert
      if (!$target && $only_external) {
        foreach ($accounts as $a) {
          if (!empty($a['is_external_account']) && ($a['status'] ?? '') === 'active') { 
            $target = $a; break; 
          }
        }
      }
      
      // Fallback: Hauptkonto
      if (!$target) {
        foreach ($accounts as $a) {
          if (!empty($a['main']) && ($a['status'] ?? '') === 'active') { $target = $a; break; }
        }
      }
      if (!$target) throw new Exception('Target bank account not found');

      $bank_account_id = $target['id'];
      $is_external = !empty($target['is_external_account']);
      if ($return_log) {
        $ext_info = $is_external ? ' [EXTERNES KONTO]' : '';
        self::add_log('Zielkonto: ' . ($target['name'] ?? 'Unbekannt') . $ext_info . ' (IBAN: ' . ($target['iban'] ?? 'N/A') . ')', 'success');
      }

      // Transactions request
      $qs = [
        'bank_account_id' => $bank_account_id,
        'side'            => 'credit',
        'updated_at_from' => $updated_from,
        'sort_by'         => 'updated_at:asc',
        'per_page'        => '100'
      ];

      // optional: only completed
      if (!empty($s['only_completed'])) {
        // Qonto supports status[] filtering
        $qs['status[]'] = 'completed';
      }

      $url = 'https://thirdparty.qonto.com/v2/transactions?' . http_build_query($qs);
      if ($return_log) self::add_log('Rufe Transaktionen ab (seit ' . $updated_from . ')...', 'info');
      $tx = self::qonto_get($login, $secret, $url);
      $transactions = $tx['transactions'] ?? [];
      if (!is_array($transactions)) $transactions = [];
      
      if ($return_log) self::add_log(count($transactions) . ' Transaktion(en) gefunden', 'success');

      $max_updated_at = $updated_from;
      $matched_count = 0;
      
      // Hole alle on-hold Bestellungen für Statusanzeige
      if ($return_log) {
        $onhold_orders = wc_get_orders(['status' => 'on-hold', 'limit' => 200]);
        self::add_log(count($onhold_orders) . ' Bestellung(en) in Wartestellung', 'info');
        self::add_log('', '');
        self::add_log('--- Prüfe Transaktionen ---', 'header');
      }

      foreach ($transactions as $t) {
        $tid = $t['id'] ?? '';
        if (!$tid) continue;

        $t_updated = $t['updated_at'] ?? null;
        if ($t_updated && strcmp($t_updated, $max_updated_at) > 0) $max_updated_at = $t_updated;

        if (isset($processed[$tid])) continue; // already handled

        // Amount filter
        $amount = isset($t['amount']) ? floatval($t['amount']) : 0.0;
        $min_amount = isset($s['min_amount']) ? floatval($s['min_amount']) : 0.0;
        if ($min_amount > 0 && $amount < $min_amount) {
          $processed[$tid] = time();
          continue;
        }

        $text = self::transaction_text($t);
        $order_ids = self::extract_order_ids($text, $s['regex'] ?? '');
        
        if ($return_log) {
          $short_text = mb_substr($text, 0, 60) . (mb_strlen($text) > 60 ? '...' : '');
          self::add_log(sprintf('Tx: %.2f EUR | %s', $amount, $short_text), 'info');
        }

        // Gemeinsame Variablen für Transaktionsdetails
        $settled_at = $t['settled_at'] ?? $t['emitted_at'] ?? '';
        $emitted_at = $t['emitted_at'] ?? '';
        $reference = $t['reference'] ?? '';
        $label = $t['label'] ?? '';
        $counterparty = $t['counterparty_name'] ?? ($t['label'] ?? 'Unbekannt');
        $iban = $t['local_iban'] ?? '';
        $tx_currency = $t['currency'] ?? 'EUR';
        $tolerance = isset($s['amount_tolerance']) ? floatval($s['amount_tolerance']) : 0.01;
        $require_match = !empty($s['require_amount_match']);
        $enable_name_matching = !empty($s['enable_name_matching']);
        
        $order_matched = false;
        $match_type = 'order_number'; // 'order_number' oder 'name'

        // === STRATEGIE 1: Bestellnummer im Verwendungszweck ===
        if (!empty($order_ids)) {
          if ($return_log) self::add_log('  → Bestellnummer(n) erkannt: ' . implode(', ', $order_ids), 'info');

          foreach ($order_ids as $oid) {
            $order = wc_get_order($oid);
            if (!$order) continue;

            // Only move on-hold -> processing
            if ($order->get_status() !== 'on-hold') continue;

            // Duplikat-Schutz: Prüfe ob Bestellung bereits via Qonto bezahlt wurde
            if (self::order_already_matched($order)) {
              if ($return_log) self::add_log(sprintf('  → Bestellung #%s: Bereits via Qonto zugeordnet - überspringe', $order->get_order_number()), 'warning');
              continue;
            }

            $order_number = $order->get_order_number();
            $order_total = floatval($order->get_total());

            // Währungsvalidierung
            $order_currency = $order->get_currency();
            if (strtoupper($tx_currency) !== strtoupper($order_currency)) {
              self::log("Tx {$tid} currency mismatch: tx={$tx_currency} order={$order_currency}", 'warning');
              if ($return_log) self::add_log(sprintf('  → Bestellung #%s: Währung passt NICHT (Tx: %s, Bestellung: %s)', $order_number, $tx_currency, $order_currency), 'warning');
              continue;
            }

            // Bestelldatum-Validierung: Bestellung muss VOR der Transaktion erstellt worden sein
            if (!self::order_created_before_transaction($order, $t)) {
              self::log("Tx {$tid} date check failed for order {$order_number}: order created after transaction", 'warning');
              if ($return_log) self::add_log(sprintf('  → Bestellung #%s: Bestelldatum NACH Transaktionsdatum - überspringe', $order_number), 'warning');
              continue;
            }

            // Betragsabgleich: Transaktionsbetrag muss mit Bestellbetrag übereinstimmen
            if ($require_match) {
              $amount_diff = abs($amount - $order_total);
              if ($amount_diff > $tolerance) {
                self::log("Tx {$tid} matched order {$order_number} but amount mismatch: tx={$amount} order={$order_total} diff={$amount_diff}", 'warning');
                if ($return_log) self::add_log(sprintf('  → Bestellung #%s: Betrag passt NICHT (Tx: %.2f, Bestellung: %.2f)', $order_number, $amount, $order_total), 'warning');
                continue;
              }
            }

            // Match gefunden - Bestellung aktualisieren
            $order_matched = self::update_matched_order($order, $t, $amount, $match_type, null, $return_log);
            if ($order_matched) {
              $matched_count++;
            }
          }
        }
        
        // === STRATEGIE 2: Name-Matching als Fallback ===
        if (!$order_matched && empty($order_ids) && $enable_name_matching) {
          if ($return_log) self::add_log('  → Keine Bestellnummer erkannt, prüfe Name-Matching...', 'info');
          
          // Name-Matching: Betrag muss IMMER übereinstimmen!
          $name_matches = self::find_orders_by_name($counterparty, $amount, $tolerance);
          
          if (!empty($name_matches)) {
            if ($return_log) self::add_log(sprintf('  → %d Bestellung(en) mit passendem Namen und Betrag gefunden', count($name_matches)), 'info');
            
            // Bei mehreren Matches: nur wenn genau 1 Match, automatisch zuordnen
            if (count($name_matches) === 1) {
              $match = $name_matches[0];
              $order = $match['order'];
              $matched_name = $match['matched_name'];
              
              $order_matched = self::update_matched_order($order, $t, $amount, 'name', $matched_name, $return_log);
              if ($order_matched) {
                $matched_count++;
              }
            } else {
              // Mehrere Matches - nicht automatisch zuordnen (zu riskant)
              if ($return_log) {
                self::add_log('  ⚠ Mehrere Bestellungen mit gleichem Namen/Betrag - manuelle Prüfung erforderlich:', 'warning');
                foreach ($name_matches as $m) {
                  $o = $m['order'];
                  self::add_log(sprintf('    • Bestellung #%s (%s, %.2f EUR)', $o->get_order_number(), $m['matched_name'], $o->get_total()), 'warning');
                }
              }
              self::log("Tx {$tid} has multiple name matches ({$counterparty}, {$amount} EUR) - skipping auto-match", 'warning');
            }
          } else {
            if ($return_log) self::add_log('  → Kein Name-Match gefunden', 'warning');
          }
        } elseif (!$order_matched && empty($order_ids)) {
          if ($return_log) self::add_log('  → Keine Bestellnummer erkannt (Name-Matching deaktiviert)', 'warning');
        }

        // mark processed always (even if no matching order found)
        $processed[$tid] = time();
      }

      // cap processed map size
      if (count($processed) > 1000) {
        asort($processed); // oldest first
        $processed = array_slice($processed, -800, null, true);
      }

      $state['last_updated_at'] = $max_updated_at;
      $state['processed_ids'] = $processed;
      update_option(self::OPT_STATE, $state, false);

      self::log("Run complete. tx_count=" . count($transactions) . " matched=" . $matched_count . " updated_from=" . $updated_from . " new_last=" . $max_updated_at, 'info');
      
      if ($return_log) {
        self::add_log('', '');
        self::add_log('--- Zusammenfassung ---', 'header');
        self::add_log(sprintf('Transaktionen geprüft: %d', count($transactions)), 'info');
        self::add_log(sprintf('Bestellungen aktualisiert: %d', $matched_count), $matched_count > 0 ? 'success' : 'info');
      }
      
      return $return_log ? $matched_count : null;

    } catch (Exception $e) {
      self::log('Cron error: ' . $e->getMessage(), 'error');
      if ($return_log) self::add_log('FEHLER: ' . $e->getMessage(), 'error');
      return $return_log ? 0 : null;
    }
  }

  private static function qonto_get($login, $secret, $url) {
    $headers = [
      'Authorization' => $login . ':' . $secret,
      'Accept'        => 'application/json'
    ];

    $resp = wp_remote_get($url, [
      'timeout' => 20,
      'headers' => $headers,
      'sslverify' => true
    ]);

    if (is_wp_error($resp)) {
      // Fehler loggen aber nicht an Client weitergeben
      self::log('API connection error: ' . $resp->get_error_message(), 'error');
      throw new Exception(esc_html__('Verbindung zur Bank-API fehlgeschlagen', 'auto-payment-matcher-for-qonto'));
    }

    $code = wp_remote_retrieve_response_code($resp);
    $body = wp_remote_retrieve_body($resp);

    if ($code < 200 || $code >= 300) {
      // Detaillierte Fehler nur intern loggen
      self::log("API error {$code}: " . substr($body, 0, 200), 'error');
      
      // Generische Fehlermeldungen für verschiedene Status-Codes
      $error_msg = esc_html__('API-Fehler', 'auto-payment-matcher-for-qonto');
      if ($code === 401 || $code === 403) {
        $error_msg = esc_html__('Authentifizierung fehlgeschlagen - prüfe API-Zugangsdaten', 'auto-payment-matcher-for-qonto');
      } elseif ($code === 404) {
        $error_msg = esc_html__('Ressource nicht gefunden', 'auto-payment-matcher-for-qonto');
      } elseif ($code === 429) {
        $error_msg = esc_html__('Zu viele Anfragen - bitte später erneut versuchen', 'auto-payment-matcher-for-qonto');
      } elseif ($code >= 500) {
        $error_msg = esc_html__('Bank-API vorübergehend nicht erreichbar', 'auto-payment-matcher-for-qonto');
      }
      throw new Exception( esc_html( $error_msg ) );
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
      self::log('Invalid JSON response from API', 'error');
      throw new Exception(esc_html__('Ungültige API-Antwort', 'auto-payment-matcher-for-qonto'));
    }
    return $json;
  }

  private static function transaction_text($t) {
    $parts = [];
    foreach (['label','note','reference','transaction_id'] as $k) {
      if (!empty($t[$k])) $parts[] = (string)$t[$k];
    }
    // Some transfers embed details
    if (!empty($t['transfer']) && is_array($t['transfer'])) {
      foreach ($t['transfer'] as $k => $v) {
        if (is_scalar($v) && $v !== '') $parts[] = (string)$v;
      }
    }
    return trim(implode(' | ', $parts));
  }

  /**
   * Aktualisiert eine gematchte Bestellung mit Transaktionsdetails
   * 
   * @param WC_Order $order Die WooCommerce Bestellung
   * @param array $t Die Transaktion aus der Qonto API
   * @param float $amount Der Transaktionsbetrag
   * @param string $match_type 'order_number' oder 'name'
   * @param string|null $matched_name Der gematchte Kundenname (nur bei Name-Matching)
   * @param bool $return_log Ob Log-Ausgaben erzeugt werden sollen
   * @return bool True wenn erfolgreich aktualisiert
   */
  private static function update_matched_order($order, $t, $amount, $match_type = 'order_number', $matched_name = null, $return_log = false) {
    $tid = $t['id'] ?? '';
    $order_number = $order->get_order_number();
    $order_id = $order->get_id();
    $order_total = floatval($order->get_total());
    $tx_currency = $t['currency'] ?? 'EUR';
    
    // Transaktionsdetails
    $settled_at = $t['settled_at'] ?? $t['emitted_at'] ?? '';
    $emitted_at = $t['emitted_at'] ?? '';
    $reference = $t['reference'] ?? '';
    $label = $t['label'] ?? '';
    $counterparty = $t['counterparty_name'] ?? ($t['label'] ?? 'Unbekannt');
    $iban = $t['local_iban'] ?? '';
    
    // Datum formatieren
    $settled_date = '';
    if ($settled_at) {
      $dt = date_create($settled_at);
      if ($dt) $settled_date = $dt->format('d.m.Y H:i:s');
    }
    $emitted_date = '';
    if ($emitted_at) {
      $dt = date_create($emitted_at);
      if ($dt) $emitted_date = $dt->format('d.m.Y H:i:s');
    }
    
    // Detaillierter Bestellkommentar
    $note_lines = [];
    $note_lines[] = '✅ <strong>Zahlungseingang via Qonto bestätigt</strong>';
    $note_lines[] = '';
    
    // Match-Typ anzeigen
    if ($match_type === 'name') {
      $note_lines[] = '<strong>⚡ Zuordnung via Name-Matching:</strong>';
      $note_lines[] = sprintf('• Erkannter Absender: %s', $counterparty);
      $note_lines[] = sprintf('• Bestellkunde: %s', $matched_name ?: 'N/A');
      $note_lines[] = '';
    }
    
    $note_lines[] = '<strong>Transaktionsdetails:</strong>';
    $note_lines[] = sprintf('• Betrag: %.2f %s', $amount, $tx_currency);
    $note_lines[] = sprintf('• Bestellbetrag: %.2f EUR', $order_total);
    if ($settled_date) {
      $note_lines[] = sprintf('• Geldeingang (settled): %s', $settled_date);
    }
    if ($emitted_date && $emitted_date !== $settled_date) {
      $note_lines[] = sprintf('• Buchungsdatum (emitted): %s', $emitted_date);
    }
    $note_lines[] = sprintf('• Absender: %s', esc_html($counterparty));
    if ($iban) {
      // IBAN maskieren: nur erste 4 + letzte 4 Zeichen zeigen
      $masked_iban = $iban;
      if (strlen($iban) > 8) {
        $masked_iban = substr($iban, 0, 4) . str_repeat('*', strlen($iban) - 8) . substr($iban, -4);
      }
      $note_lines[] = sprintf('• IBAN: %s', esc_html($masked_iban));
    }
    if ($reference) {
      $note_lines[] = sprintf('• Referenz: %s', $reference);
    }
    if ($label && $label !== $counterparty) {
      $note_lines[] = sprintf('• Verwendungszweck: %s', $label);
    }
    $note_lines[] = sprintf('• Transaktions-ID: %s', $tid);
    $note_lines[] = '';
    $note_lines[] = sprintf('<em>Automatisch zugeordnet am %s (Match-Typ: %s)</em>', current_time('d.m.Y H:i:s'), $match_type === 'name' ? 'Kundenname' : 'Bestellnummer');
    
    // Duplikat-Schutz: Meta setzen bevor Status geändert wird
    $order->update_meta_data('_qonto_matched_tx_id', sanitize_text_field($tid));
    $order->update_meta_data('_qonto_matched_at', current_time('mysql'));
    $order->save_meta_data();
    
    $order->add_order_note(implode("\n", $note_lines));
    $order->update_status('processing', 'Automatisch: Zahlungseingang erkannt (Qonto).');
    
    $match_info = $match_type === 'name' ? " via name-match ({$counterparty})" : '';
    self::log("Matched Tx {$tid} to order {$order_number} (ID {$order_id}){$match_info} tx_amount={$amount} order_total={$order_total}", 'info');
    
    if ($return_log) {
      $match_label = $match_type === 'name' ? ' [NAME-MATCH]' : '';
      self::add_log(sprintf('  ✓ MATCH!%s Bestellung #%s auf "In Bearbeitung" gesetzt (%.2f EUR)', $match_label, $order_number, $amount), 'success');
    }
    
    return true;
  }

  private static function extract_order_ids($text, $regex) {
    $regex = $regex ?: '/\\b(\\d{3,10})\\b/';
    $ids = [];

    // Regex-Ausführung mit Fehlerbehandlung (kein @ Suppressor)
    $result = preg_match_all($regex, $text, $m);
    if ($result === false) {
      self::log('Regex-Fehler bei Ausführung (Code: ' . preg_last_error() . ')', 'error');
      return $ids;
    }
    if ($result > 0) {
      // Try all captured groups
      foreach ($m as $groupIndex => $arr) {
        if ($groupIndex === 0) continue;
        foreach ($arr as $val) {
          $val = trim((string)$val);
          if ($val === '') continue;
          if (ctype_digit($val)) $ids[] = intval($val);
        }
      }
      // If no groups, fallback to full matches (group 0)
      if (empty($ids) && !empty($m[0])) {
        foreach ($m[0] as $val) {
          $val = preg_replace('/\\D+/', '', (string)$val);
          if ($val !== '' && ctype_digit($val)) $ids[] = intval($val);
        }
      }
    }

    // de-duplicate
    $ids = array_values(array_unique(array_filter($ids, function($x){ return $x > 0; })));

    return $ids;
  }

  /**
   * Sucht Bestellungen nach Kundenname (Vor- und Nachname)
   * Gibt nur on-hold Bestellungen zurück, deren Betrag mit dem Transaktionsbetrag übereinstimmt
   */
  private static function find_orders_by_name($sender_name, $tx_amount, $tolerance = 0.01) {
    $matches = [];
    
    // Mindestlänge für Sendernamen erhöht (Sicherheit gegen zu kurze/generische Namen)
    if (empty($sender_name) || mb_strlen($sender_name) < 5) {
      return $matches;
    }
    
    // Sendername normalisieren (Kleinbuchstaben, extra Leerzeichen entfernen)
    $sender_normalized = mb_strtolower(trim(preg_replace('/\s+/', ' ', $sender_name)));
    
    // Hole on-hold Bestellungen mit Limit (DoS-Schutz)
    $orders = wc_get_orders([
      'status' => 'on-hold',
      'limit' => 200,
      'orderby' => 'date',
      'order' => 'DESC'
    ]);
    
    foreach ($orders as $order) {
      $order_total = floatval($order->get_total());
      
      // Betrag muss übereinstimmen (bei Name-Matching immer erforderlich!)
      if (abs($tx_amount - $order_total) > $tolerance) {
        continue;
      }
      
      // Duplikat-Schutz
      if (self::order_already_matched($order)) {
        continue;
      }
      
      // Kundenname aus Bestellung holen
      $billing_first = $order->get_billing_first_name();
      $billing_last = $order->get_billing_last_name();
      $billing_full = trim($billing_first . ' ' . $billing_last);
      $billing_full_normalized = mb_strtolower($billing_full);
      
      // Auch umgekehrte Reihenfolge prüfen (Nachname Vorname)
      $billing_reversed = trim($billing_last . ' ' . $billing_first);
      $billing_reversed_normalized = mb_strtolower($billing_reversed);
      
      // Shipping-Name als Fallback
      $shipping_first = $order->get_shipping_first_name();
      $shipping_last = $order->get_shipping_last_name();
      $shipping_full = trim($shipping_first . ' ' . $shipping_last);
      $shipping_full_normalized = mb_strtolower($shipping_full);
      
      // Firmenname als zusätzliche Match-Möglichkeit
      $billing_company = mb_strtolower(trim($order->get_billing_company()));
      
      // Prüfen ob der Sendername im Kundennamen enthalten ist oder umgekehrt
      $name_match = false;
      $matched_via = $billing_full;
      
      // Exakter Match (vollständiger Name)
      if ($sender_normalized === $billing_full_normalized || 
          $sender_normalized === $billing_reversed_normalized) {
        $name_match = true;
      }
      // Exakter Match mit Shipping-Name
      elseif (!empty($shipping_full_normalized) && $sender_normalized === $shipping_full_normalized) {
        $name_match = true;
        $matched_via = $shipping_full;
      }
      // Firmenname-Match (exakt)
      elseif (!empty($billing_company) && mb_strlen($billing_company) >= 5 && $sender_normalized === $billing_company) {
        $name_match = true;
        $matched_via = $order->get_billing_company();
      }
      // Sendername enthält den Kundennamen (Mindestlänge 7 für Teilmatches)
      elseif (mb_strlen($billing_full_normalized) >= 7 && mb_strpos($sender_normalized, $billing_full_normalized) !== false) {
        $name_match = true;
      }
      // Kundenname enthält den Sendernamen
      elseif (mb_strlen($sender_normalized) >= 7 && mb_strpos($billing_full_normalized, $sender_normalized) !== false) {
        $name_match = true;
      }
      // Umgekehrte Reihenfolge (Nachname Vorname)
      elseif (mb_strlen($billing_reversed_normalized) >= 7 && mb_strpos($sender_normalized, $billing_reversed_normalized) !== false) {
        $name_match = true;
      }
      // Firmenname als Teilmatch (Mindestlänge 7)
      elseif (!empty($billing_company) && mb_strlen($billing_company) >= 7 && 
              (mb_strpos($sender_normalized, $billing_company) !== false || mb_strpos($billing_company, $sender_normalized) !== false)) {
        $name_match = true;
        $matched_via = $order->get_billing_company();
      }
      
      if ($name_match) {
        $matches[] = [
          'order' => $order,
          'match_type' => 'name',
          'matched_name' => $matched_via
        ];
      }
    }
    
    return $matches;
  }

  /**
   * Validiert einen Regex auf Syntax und ReDoS-Anfälligkeit
   */
  private static function validate_regex($regex) {
    if (empty($regex)) return false;
    
    // Maximale Länge begrenzen
    if (strlen($regex) > 1000) return false;
    
    // Syntax-Test: @ ist hier beabsichtigt um ungültige Regex-Warnungen zu unterdrücken
    $test_result = @preg_match($regex, 'test_string_12345'); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    
    if ($test_result === false) return false;
    
    // ReDoS-Heuristik: verschachtelte Quantifizierer erkennen
    $pattern_body = $regex;
    // Delimiter entfernen
    if (preg_match('/^(.)(.*)\1[imsxADSUXJu]*$/s', $regex, $pm)) {
      $pattern_body = $pm[2];
    }
    
    // Gefährliche Muster: verschachtelte Quantifizierer wie (a+)+ oder (a*)*
    if (preg_match('/\([^)]*[+*][^)]*\)[+*]/', $pattern_body)) return false;
    if (preg_match('/\([^)]*\{[^}]+\}[^)]*\)[+*{]/', $pattern_body)) return false;
    
    // Praxistest: Regex darf bei langem String nicht zu lange dauern
    $test_string = str_repeat('a]1b2c3 ', 100);
    $start = microtime(true);
    @preg_match_all($regex, $test_string, $throwaway); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    $elapsed = microtime(true) - $start;
    
    // Wenn Test > 100ms dauert, ist der Regex potenziell gefährlich
    if ($elapsed > 0.1) return false;
    
    return true;
  }

  /**
   * Prüft ob eine Bestellung bereits via Qonto zugeordnet wurde (Duplikat-Schutz)
   */
  private static function order_already_matched($order) {
    $matched_tx = $order->get_meta('_qonto_matched_tx_id');
    return !empty($matched_tx);
  }

  /**
   * Prüft ob die Bestellung VOR der Transaktion erstellt wurde
   * Verhindert falsche Zuordnungen bei recycelten Bestellnummern
   */
  private static function order_created_before_transaction($order, $transaction) {
    $order_date = $order->get_date_created();
    if (!$order_date) return true; // Im Zweifel durchlassen
    
    $tx_date_str = $transaction['emitted_at'] ?? $transaction['settled_at'] ?? '';
    if (empty($tx_date_str)) return true; // Kein Transaktionsdatum → durchlassen
    
    $tx_date = date_create($tx_date_str);
    if (!$tx_date) return true;
    
    // Bestellung muss vor der Transaktion erstellt worden sein (mit 1 Stunde Puffer)
    $order_timestamp = $order_date->getTimestamp();
    $tx_timestamp = $tx_date->getTimestamp();
    
    return ($order_timestamp <= $tx_timestamp + 3600);
  }

  /* -------------------------
   *  Secret encryption helpers
   * ------------------------- */
  private static function enc_key() {
    // Derived from WP salts (site-specific)
    $k = (defined('AUTH_KEY') ? AUTH_KEY : '') . (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '') . (defined('LOGGED_IN_KEY') ? LOGGED_IN_KEY : '');
    if ($k === '') {
      $k = defined('NONCE_KEY') ? NONCE_KEY : 'fallback_key_' . get_site_url();
    }
    return hash('sha256', $k, true);
  }

  private static function hmac_key() {
    // Separate key for HMAC (integrity verification)
    $k = (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '') . (defined('AUTH_SALT') ? AUTH_SALT : '');
    if ($k === '') {
      $k = defined('NONCE_SALT') ? NONCE_SALT : 'hmac_fallback_' . get_site_url();
    }
    return hash('sha256', $k, true);
  }

  private static function encrypt_secret($plain) {
    if (!function_exists('openssl_encrypt')) {
      // OpenSSL ist erforderlich für sichere Verschlüsselung
      self::log('WARNUNG: OpenSSL nicht verfügbar - Secret kann nicht sicher gespeichert werden!', 'error');
      return '';
    }
    
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plain, 'AES-256-GCM', self::enc_key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    
    if ($cipher === false) {
      // Fallback auf CBC mit HMAC
      $cipher = openssl_encrypt($plain, 'AES-256-CBC', self::enc_key(), OPENSSL_RAW_DATA, $iv);
      if ($cipher === false) return '';
      
      // HMAC für Integritätsprüfung
      $hmac = hash_hmac('sha256', $iv . $cipher, self::hmac_key(), true);
      return 'aes2:' . base64_encode($hmac . $iv . $cipher);
    }
    
    // GCM-Modus mit integrierter Authentifizierung
    return 'gcm:' . base64_encode($iv . $tag . $cipher);
  }

  private static function decrypt_secret($enc) {
    if (!function_exists('openssl_decrypt')) {
      return '';
    }
    
    // Legacy Base64 (unsicher, wird NICHT mehr unterstützt)
    if (strpos($enc, 'b64:') === 0) {
      self::log('KRITISCH: Unsichere Base64-Verschlüsselung gefunden - Secret muss neu gespeichert werden!', 'error');
      return '';
    }
    
    // GCM-Modus (bevorzugt)
    if (strpos($enc, 'gcm:') === 0) {
      $raw = base64_decode(substr($enc, 4));
      if ($raw === false || strlen($raw) < 33) return '';
      
      $iv = substr($raw, 0, 16);
      $tag = substr($raw, 16, 16);
      $cipher = substr($raw, 32);
      
      $plain = openssl_decrypt($cipher, 'AES-256-GCM', self::enc_key(), OPENSSL_RAW_DATA, $iv, $tag);
      return $plain ?: '';
    }
    
    // CBC mit HMAC (Fallback)
    if (strpos($enc, 'aes2:') === 0) {
      $raw = base64_decode(substr($enc, 5));
      if ($raw === false || strlen($raw) < 49) return ''; // 32 HMAC + 16 IV + min 1 cipher
      
      $stored_hmac = substr($raw, 0, 32);
      $iv = substr($raw, 32, 16);
      $cipher = substr($raw, 48);
      
      // Integritätsprüfung
      $calc_hmac = hash_hmac('sha256', $iv . $cipher, self::hmac_key(), true);
      if (!hash_equals($stored_hmac, $calc_hmac)) {
        self::log('WARNUNG: Secret-Integritätsprüfung fehlgeschlagen!', 'error');
        return '';
      }
      
      $plain = openssl_decrypt($cipher, 'AES-256-CBC', self::enc_key(), OPENSSL_RAW_DATA, $iv);
      return $plain ?: '';
    }
    
    // Legacy AES (ohne HMAC)
    if (strpos($enc, 'aes:') === 0) {
      $raw = base64_decode(substr($enc, 4));
      if ($raw === false || strlen($raw) < 17) return '';
      $iv = substr($raw, 0, 16);
      $cipher = substr($raw, 16);
      $plain = openssl_decrypt($cipher, 'AES-256-CBC', self::enc_key(), OPENSSL_RAW_DATA, $iv);
      
      // Migration zu sichererem Format bei nächster Speicherung empfohlen
      if ($plain) {
        self::log('INFO: Legacy-Verschlüsselung gefunden - Secret wird bei nächster Speicherung aktualisiert', 'info');
      }
      return $plain ?: '';
    }
    
    return '';
  }

  /**
   * Prüft ob alle Sicherheitsvoraussetzungen erfüllt sind
   */
  private static function security_check() {
    $issues = [];
    
    if (!function_exists('openssl_encrypt')) {
      $issues[] = 'OpenSSL nicht verfügbar';
    }
    
    if (!defined('AUTH_KEY') || AUTH_KEY === 'put your unique phrase here') {
      $issues[] = 'WordPress Salt-Keys nicht konfiguriert';
    }
    
    if (!is_ssl()) {
      $remote_addr = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
      if (!in_array($remote_addr, ['127.0.0.1', '::1'], true)) {
        $issues[] = 'Kein HTTPS aktiv';
      }
    }
    
    return $issues;
  }
}

Qonto_Woo_Auto_Payments::init();
