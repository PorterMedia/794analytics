<?php
/**
 * Plugin Name: 794 Analytics
 * Description: Tracks page views, unique page views, link clicks, unique clicks, CTR, UTM data, referrers, campaigns, internal-link destinations, and tour-date/event link locations inside the WordPress admin. Replaces the WP home dashboard with an immersive analytics overview. Includes CSV and PDF report export.
 * Version: 4.4.0
 * Author: Porter Media
 * Update URI: https://github.com/PorterMedia/794analytics
 */

if (!defined('ABSPATH')) {
    exit;
}

class Backlot_Click_Tracker {

    const DB_VERSION = '3.7.0';

    private static $instance = null;
    private $table_name;
    private $dashboard_data = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        global $wpdb;

        $this->table_name = $wpdb->prefix . 'backlot_clicks';

        // Ensure schema is current even when the plugin is updated by replacing files
        // (which does not re-run the activation hook).
        $this->maybe_upgrade();

        register_activation_hook(__FILE__, array($this, 'activate'));

        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        add_action('wp_ajax_backlot_track_click', array($this, 'track_click'));
        add_action('wp_ajax_nopriv_backlot_track_click', array($this, 'track_click'));

        add_action('wp_ajax_backlot_track_pageview', array($this, 'track_pageview'));
        add_action('wp_ajax_nopriv_backlot_track_pageview', array($this, 'track_pageview'));

        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        add_action('edit_form_after_title', array($this, 'render_analytics_below_slug'));

        // Replace the default WP home dashboard with our immersive overview.
        add_action('wp_dashboard_setup', array($this, 'setup_dashboard'), 9999);

        add_action('admin_post_backlot_export_clicks_csv', array($this, 'export_csv'));
        add_action('admin_post_backlot_backfill_locations', array($this, 'backfill_locations'));

        add_action('admin_init', array($this, 'register_settings'));
        add_action('init', array($this, 'maybe_schedule_cron'));
        add_action('backlot_prune_events', array($this, 'prune_old_events'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Self-hosted auto-updates from GitHub releases.
        $this->init_github_updater();
    }

    /**
     * Wire up GitHub-release auto-updates. Set the owner/repo below once; after
     * that, publishing a new tagged release on GitHub makes the update appear on
     * every site running this plugin — no manual zip uploads.
     */
    private function init_github_updater() {
        $owner = 'PorterMedia';  // GitHub username or org
        $repo  = '794analytics'; // repository name
        $token = '';             // leave empty for a PUBLIC repo; paste a fine-grained PAT only if PRIVATE

        if ($owner === '' || $repo === '') {
            return;
        }

        new Backlot_GitHub_Updater(__FILE__, $owner, $repo, $token);
    }

    /* ===== Settings ===== */

    private function get_settings() {
        $defaults = array(
            'retention_months'   => 12,
            'exclude_admins'     => 1,
            'dashboard_takeover' => 1,
            'geo_lookup'         => 1,
        );

        $saved = get_option('backlot_ct_settings', array());
        if (!is_array($saved)) {
            $saved = array();
        }

        return array_merge($defaults, $saved);
    }

    public function register_settings() {
        register_setting(
            'backlot_ct_settings_group',
            'backlot_ct_settings',
            array($this, 'sanitize_settings')
        );
    }

    public function sanitize_settings($input) {
        $months = isset($input['retention_months']) ? (int) $input['retention_months'] : 12;
        if ($months < 0) {
            $months = 0;
        }
        if ($months > 120) {
            $months = 120;
        }

        return array(
            'retention_months'   => $months,
            'exclude_admins'     => empty($input['exclude_admins']) ? 0 : 1,
            'dashboard_takeover' => empty($input['dashboard_takeover']) ? 0 : 1,
            'geo_lookup'         => empty($input['geo_lookup']) ? 0 : 1,
        );
    }

    public function settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $s = $this->get_settings();
        ?>
        <div class="wrap backlot-admin-report-wrap">
            <h1>794 Analytics &mdash; Settings</h1>
            <form method="post" action="options.php" class="backlot-filter-panel" style="max-width:760px;">
                <?php settings_fields('backlot_ct_settings_group'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Data retention</th>
                        <td>
                            <label>
                                Delete raw events older than
                                <input type="number" min="0" max="120" name="backlot_ct_settings[retention_months]" value="<?php echo esc_attr($s['retention_months']); ?>" style="width:80px;">
                                months
                            </label>
                            <p class="description">Runs once a day in the background. Set to <strong>0</strong> to keep all data forever.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Exclude admins</th>
                        <td>
                            <label>
                                <input type="checkbox" name="backlot_ct_settings[exclude_admins]" value="1" <?php checked($s['exclude_admins'], 1); ?>>
                                Don&rsquo;t record visits or clicks from logged-in administrators
                            </label>
                            <p class="description">Keeps your own browsing from inflating the numbers.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Home dashboard</th>
                        <td>
                            <label>
                                <input type="checkbox" name="backlot_ct_settings[dashboard_takeover]" value="1" <?php checked($s['dashboard_takeover'], 1); ?>>
                                Replace the WordPress home dashboard with the analytics overview
                            </label>
                            <p class="description">Turn off to leave the standard WordPress dashboard in place.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Visitor country</th>
                        <td>
                            <label>
                                <input type="checkbox" name="backlot_ct_settings[geo_lookup]" value="1" <?php checked($s['geo_lookup'], 1); ?>>
                                Look up each visitor&rsquo;s country
                            </label>
                            <p class="description">Uses your CDN&rsquo;s country header when available, otherwise a free lookup (cached per visitor for a day). Powers the Top Countries report. Only the 2-letter country code is stored &mdash; never the IP.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save settings'); ?>
            </form>
        </div>
        <?php
    }

    private function should_skip_tracking() {
        $s = $this->get_settings();

        if (!empty($s['exclude_admins']) && is_user_logged_in() && current_user_can('manage_options')) {
            return true;
        }

        return false;
    }

    /* ===== Geography ===== */

    /**
     * Resolve a visitor's country (ISO 3166-1 alpha-2). Prefers CDN/proxy headers
     * (free, instant) and falls back to a keyless API, cached per IP for a day so
     * we make at most one lookup per visitor per day. Returns '' when unknown.
     */
    private function get_country($ip) {
        $header_keys = array(
            'HTTP_CF_IPCOUNTRY',                // Cloudflare
            'HTTP_CLOUDFRONT_VIEWER_COUNTRY',   // AWS CloudFront
            'HTTP_X_GEO_COUNTRY',
            'HTTP_X_COUNTRY_CODE',
        );

        foreach ($header_keys as $hk) {
            if (!empty($_SERVER[$hk])) {
                $code = strtoupper(substr(sanitize_text_field(wp_unslash($_SERVER[$hk])), 0, 2));
                if (ctype_alpha($code) && $code !== 'XX' && $code !== 'T1') {
                    return $code;
                }
            }
        }

        if (!$ip) {
            return '';
        }

        $s = $this->get_settings();
        if (empty($s['geo_lookup'])) {
            return '';
        }

        $key = 'backlot_geo_' . md5($ip);
        $cached = get_transient($key);
        if ($cached !== false) {
            return ($cached === 'none') ? '' : $cached;
        }

        $code = '';
        $response = wp_remote_get('https://api.country.is/' . rawurlencode($ip), array('timeout' => 3));
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($body['country']) && ctype_alpha($body['country'])) {
                $code = strtoupper(substr($body['country'], 0, 2));
            }
        }

        set_transient($key, ($code === '') ? 'none' : $code, DAY_IN_SECONDS);

        return $code;
    }

    private function country_flag($code) {
        $code = strtoupper((string) $code);
        if (strlen($code) !== 2 || !ctype_alpha($code)) {
            return '';
        }

        return $this->cp_to_utf8(0x1F1E6 + (ord($code[0]) - 65))
             . $this->cp_to_utf8(0x1F1E6 + (ord($code[1]) - 65));
    }

    private function cp_to_utf8($cp) {
        if ($cp <= 0x7F) {
            return chr($cp);
        }
        if ($cp <= 0x7FF) {
            return chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
        }
        if ($cp <= 0xFFFF) {
            return chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
        }
        return chr(0xF0 | ($cp >> 18)) . chr(0x80 | (($cp >> 12) & 0x3F)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
    }

    private function country_name($code) {
        $code = strtoupper((string) $code);

        $names = array(
            'US' => 'United States', 'CA' => 'Canada', 'GB' => 'United Kingdom', 'IE' => 'Ireland',
            'AU' => 'Australia', 'NZ' => 'New Zealand', 'DE' => 'Germany', 'FR' => 'France',
            'NL' => 'Netherlands', 'BE' => 'Belgium', 'LU' => 'Luxembourg', 'ES' => 'Spain',
            'PT' => 'Portugal', 'IT' => 'Italy', 'CH' => 'Switzerland', 'AT' => 'Austria',
            'SE' => 'Sweden', 'NO' => 'Norway', 'DK' => 'Denmark', 'FI' => 'Finland',
            'IS' => 'Iceland', 'PL' => 'Poland', 'CZ' => 'Czechia', 'SK' => 'Slovakia',
            'HU' => 'Hungary', 'RO' => 'Romania', 'BG' => 'Bulgaria', 'GR' => 'Greece',
            'HR' => 'Croatia', 'SI' => 'Slovenia', 'RS' => 'Serbia', 'UA' => 'Ukraine',
            'RU' => 'Russia', 'EE' => 'Estonia', 'LV' => 'Latvia', 'LT' => 'Lithuania',
            'TR' => 'Turkey', 'IL' => 'Israel', 'AE' => 'United Arab Emirates', 'SA' => 'Saudi Arabia',
            'MX' => 'Mexico', 'BR' => 'Brazil', 'AR' => 'Argentina', 'CL' => 'Chile',
            'CO' => 'Colombia', 'PE' => 'Peru', 'UY' => 'Uruguay', 'EC' => 'Ecuador',
            'VE' => 'Venezuela', 'CR' => 'Costa Rica', 'PA' => 'Panama', 'DO' => 'Dominican Republic',
            'PR' => 'Puerto Rico', 'GT' => 'Guatemala', 'JP' => 'Japan', 'KR' => 'South Korea',
            'CN' => 'China', 'HK' => 'Hong Kong', 'TW' => 'Taiwan', 'SG' => 'Singapore',
            'MY' => 'Malaysia', 'TH' => 'Thailand', 'ID' => 'Indonesia', 'PH' => 'Philippines',
            'VN' => 'Vietnam', 'IN' => 'India', 'PK' => 'Pakistan', 'BD' => 'Bangladesh',
            'ZA' => 'South Africa', 'NG' => 'Nigeria', 'KE' => 'Kenya', 'GH' => 'Ghana',
            'EG' => 'Egypt', 'MA' => 'Morocco', 'TN' => 'Tunisia',
        );

        return isset($names[$code]) ? $names[$code] : $code;
    }

    /* ===== Data retention (scheduled pruning) ===== */

    public function maybe_schedule_cron() {
        if (!wp_next_scheduled('backlot_prune_events')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'backlot_prune_events');
        }
    }

    public function deactivate() {
        $timestamp = wp_next_scheduled('backlot_prune_events');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'backlot_prune_events');
        }
    }

    public function prune_old_events() {
        $s = $this->get_settings();
        $months = (int) $s['retention_months'];

        if ($months <= 0) {
            return;
        }

        global $wpdb;

        // Delete in capped batches so a first prune on a large table never holds
        // a long lock or times out the cron run.
        for ($i = 0; $i < 20; $i++) {
            $deleted = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$this->table_name}
                     WHERE viewed_at < DATE_SUB(NOW(), INTERVAL %d MONTH)
                     LIMIT 5000",
                    $months
                )
            );

            if (!$deleted) {
                break;
            }
        }
    }

    public function activate() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type VARCHAR(50) NOT NULL DEFAULT 'click',
            viewed_at DATETIME NOT NULL,
            clicked_at DATETIME NULL,
            page_id BIGINT(20) UNSIGNED NULL,
            page_title TEXT NULL,
            page_url TEXT NULL,
            link_url TEXT NULL,
            link_text TEXT NULL,
            link_target VARCHAR(50) NULL,
            link_classes TEXT NULL,
            link_category VARCHAR(100) NULL,
            event_location VARCHAR(255) NULL,
            country VARCHAR(2) NULL,
            user_id BIGINT(20) UNSIGNED NULL,
            ip_hash VARCHAR(64) NULL,
            session_id VARCHAR(100) NULL,
            user_agent TEXT NULL,
            referrer TEXT NULL,
            utm_source VARCHAR(255) NULL,
            utm_medium VARCHAR(255) NULL,
            utm_campaign VARCHAR(255) NULL,
            utm_content VARCHAR(255) NULL,
            utm_term VARCHAR(255) NULL,
            PRIMARY KEY (id),
            KEY event_type (event_type),
            KEY viewed_at (viewed_at),
            KEY clicked_at (clicked_at),
            KEY page_id (page_id),
            KEY user_id (user_id),
            KEY ip_hash (ip_hash),
            KEY session_id (session_id),
            KEY link_category (link_category),
            KEY country (country)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        $this->migrate_old_click_data();

        if (!wp_next_scheduled('backlot_prune_events')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'backlot_prune_events');
        }

        update_option('backlot_ct_db_version', self::DB_VERSION);
    }

    /**
     * Lightweight schema upgrade that runs on load. Adds columns introduced after
     * the initial install without requiring deactivate/reactivate. Gated by an
     * autoloaded version option so it is a cheap no-op on every normal request.
     */
    public function maybe_upgrade() {
        if (get_option('backlot_ct_db_version') === self::DB_VERSION) {
            return;
        }

        global $wpdb;

        // Only attempt column work if the table already exists.
        $table_exists = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $this->table_name)
        );

        if ($table_exists) {
            $has_event_location = $wpdb->get_var(
                $wpdb->prepare("SHOW COLUMNS FROM {$this->table_name} LIKE %s", 'event_location')
            );

            if (!$has_event_location) {
                // Appended at the end so MySQL can use an instant/in-place add.
                $wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN event_location VARCHAR(255) NULL");
            }

            $has_country = $wpdb->get_var(
                $wpdb->prepare("SHOW COLUMNS FROM {$this->table_name} LIKE %s", 'country')
            );

            if (!$has_country) {
                $wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN country VARCHAR(2) NULL");
                $wpdb->query("ALTER TABLE {$this->table_name} ADD KEY country (country)");
            }
        }

        update_option('backlot_ct_db_version', self::DB_VERSION);
    }

    private function migrate_old_click_data() {
        global $wpdb;

        $clicked_at_exists = $wpdb->get_var(
            $wpdb->prepare("SHOW COLUMNS FROM {$this->table_name} LIKE %s", 'clicked_at')
        );

        $viewed_at_exists = $wpdb->get_var(
            $wpdb->prepare("SHOW COLUMNS FROM {$this->table_name} LIKE %s", 'viewed_at')
        );

        if ($clicked_at_exists && $viewed_at_exists) {
            $wpdb->query(
                "UPDATE {$this->table_name}
                 SET viewed_at = clicked_at
                 WHERE (viewed_at IS NULL OR viewed_at = '0000-00-00 00:00:00')
                 AND clicked_at IS NOT NULL"
            );
        }

        $wpdb->query(
            "UPDATE {$this->table_name}
             SET event_type = 'click'
             WHERE event_type IS NULL
             OR event_type = ''"
        );
    }

    public function enqueue_scripts() {
        if (is_admin()) {
            return;
        }

        wp_register_script('backlot-click-tracker', '', array(), '3.2.0', true);
        wp_enqueue_script('backlot-click-tracker');

        $page_id = get_queried_object_id();
        $page_title = $page_id ? get_the_title($page_id) : wp_get_document_title();

        wp_localize_script('backlot-click-tracker', 'BacklotClickTracker', array(
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('backlot_click_tracker_nonce'),
            'pageId'    => $page_id,
            'pageTitle' => $page_title,
            'pageUrl'   => home_url(add_query_arg(array(), $_SERVER['REQUEST_URI'])),
        ));

        $inline_js = <<<JS
(function() {
    if (typeof BacklotClickTracker === 'undefined') {
        return;
    }

    function cleanText(text) {
        if (!text) {
            return '';
        }

        return text.replace(/\\s+/g, ' ').trim().substring(0, 500);
    }

    function getUrlParam(name) {
        var params = new URLSearchParams(window.location.search);
        return params.get(name) || '';
    }

    function getSessionId() {
        var key = 'backlot_session_id';
        var existing = localStorage.getItem(key);

        if (existing) {
            return existing;
        }

        var sessionId = 'blm_' + Math.random().toString(36).substring(2) + '_' + Date.now();
        localStorage.setItem(key, sessionId);

        return sessionId;
    }

    function isNavLink(anchor) {
        if (!anchor || !anchor.closest) {
            return false;
        }

        var optIn = anchor.getAttribute('data-blm-nav');
        if (optIn === 'true') {
            return true;
        }
        if (optIn === 'false') {
            return false;
        }

        return !!anchor.closest(
            'nav, [role="navigation"], .wp-block-navigation, .menu, .menu-item, ' +
            '.nav-menu, .navbar, .navigation, .main-navigation, .primary-menu, .footer-menu'
        );
    }

    function detectCategory(anchor) {
        var href = (anchor.href || '').toLowerCase();
        var classes = (anchor.className || '').toLowerCase();
        var text = cleanText(anchor.innerText || anchor.getAttribute('aria-label') || anchor.getAttribute('title') || '').toLowerCase();

        if (anchor.getAttribute('data-blm-category')) {
            return anchor.getAttribute('data-blm-category');
        }

        if (anchor.getAttribute('data-blm-service')) {
            return anchor.getAttribute('data-blm-service');
        }

        if (href.indexOf('spotify.com') !== -1 || text.indexOf('spotify') !== -1) {
            return 'Spotify';
        }

        if (href.indexOf('music.apple.com') !== -1 || href.indexOf('itunes.apple.com') !== -1 || text.indexOf('apple') !== -1) {
            return 'Apple Music';
        }

        if (href.indexOf('youtube.com') !== -1 || href.indexOf('youtu.be') !== -1 || text.indexOf('youtube') !== -1) {
            return 'YouTube';
        }

        if (href.indexOf('amazon.') !== -1 || text.indexOf('amazon') !== -1) {
            return 'Amazon Music';
        }

        if (href.indexOf('tidal.com') !== -1 || text.indexOf('tidal') !== -1) {
            return 'TIDAL';
        }

        if (href.indexOf('deezer.com') !== -1 || text.indexOf('deezer') !== -1) {
            return 'Deezer';
        }

        if (href.indexOf('pandora.com') !== -1 || text.indexOf('pandora') !== -1) {
            return 'Pandora';
        }

        if (href.indexOf('soundcloud.com') !== -1 || text.indexOf('soundcloud') !== -1) {
            return 'SoundCloud';
        }

        if (href.indexOf('ffm.to') !== -1 || href.indexOf('feature.fm') !== -1) {
            return 'Feature.fm';
        }

        if (classes.indexOf('dsp_link') !== -1 || classes.indexOf('dsp') !== -1) {
            return 'DSP Button';
        }

        if (text.indexOf('read more') !== -1) {
            return 'Read More';
        }

        if (text.indexOf('watch') !== -1 || text.indexOf('trailer') !== -1) {
            return 'Trailer / Video';
        }

        if (href.indexOf(window.location.hostname) !== -1) {
            return isNavLink(anchor) ? 'Navigation' : 'Internal Link';
        }

        return 'External Link';
    }

    function detectEventLocation(anchor) {
        // Explicit override wins (e.g. data-blm-event="Berlin, Germany — Felt Room").
        var explicit = anchor.getAttribute('data-blm-event') || anchor.getAttribute('data-blm-location');
        if (explicit) {
            return explicit.replace(/\s+/g, ' ').trim().substring(0, 250);
        }

        // Bandsintown widget rows: each event is a .bit-event containing
        // .bit-date, .bit-location*, and .bit-venue.
        var row = anchor.closest ? anchor.closest('.bit-event') : null;
        if (!row) {
            return '';
        }

        function firstText(selector) {
            var nodes = row.querySelectorAll(selector);
            for (var i = 0; i < nodes.length; i++) {
                // textContent (not innerText) so we still read responsive variants
                // that may be hidden via CSS at the current breakpoint.
                var t = (nodes[i].textContent || '').replace(/\s+/g, ' ').trim();
                if (t) {
                    return t;
                }
            }
            return '';
        }

        var location = firstText('[class*="bit-location"]');
        var venue = firstText('.bit-venue');
        var date = firstText('.bit-date');

        var parts = [];
        if (location) { parts.push(location); }
        if (venue) { parts.push(venue); }

        var label = parts.join(' \u2014 ');
        if (date && label) {
            label = label + ' (' + date + ')';
        }

        return label.substring(0, 250);
    }

    function shouldIgnore(anchor) {
        if (!anchor || !anchor.href) {
            return true;
        }

        var href = anchor.getAttribute('href');

        if (!href) {
            return true;
        }

        href = href.toLowerCase();

        if (
            href === '#' ||
            href.indexOf('javascript:') === 0 ||
            href.indexOf('mailto:') === 0 ||
            href.indexOf('tel:') === 0
        ) {
            return true;
        }

        return false;
    }

    function appendBaseData(data) {
        data.append('nonce', BacklotClickTracker.nonce);
        data.append('page_id', BacklotClickTracker.pageId || '');
        data.append('page_title', BacklotClickTracker.pageTitle || '');
        data.append('page_url', BacklotClickTracker.pageUrl || window.location.href);
        data.append('session_id', getSessionId());
        data.append('referrer', document.referrer || '');

        data.append('utm_source', getUrlParam('utm_source'));
        data.append('utm_medium', getUrlParam('utm_medium'));
        data.append('utm_campaign', getUrlParam('utm_campaign'));
        data.append('utm_content', getUrlParam('utm_content'));
        data.append('utm_term', getUrlParam('utm_term'));
    }

    function sendBeaconOrFetch(data) {
        if (navigator.sendBeacon) {
            navigator.sendBeacon(BacklotClickTracker.ajaxUrl, data);
            return;
        }

        fetch(BacklotClickTracker.ajaxUrl, {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            keepalive: true
        }).catch(function() {});
    }

    function sendPageView() {
        var data = new FormData();
        data.append('action', 'backlot_track_pageview');
        appendBaseData(data);
        sendBeaconOrFetch(data);
    }

    function sendClickData(anchor) {
        if (shouldIgnore(anchor)) {
            return;
        }

        var data = new FormData();

        data.append('action', 'backlot_track_click');
        appendBaseData(data);

        data.append('link_url', anchor.href || '');
        data.append('link_text', cleanText(anchor.innerText || anchor.getAttribute('aria-label') || anchor.getAttribute('title') || ''));
        data.append('link_target', anchor.getAttribute('target') || '');
        data.append('link_classes', anchor.className || '');
        data.append('link_category', detectCategory(anchor));
        data.append('event_location', detectEventLocation(anchor));

        sendBeaconOrFetch(data);
    }

    sendPageView();

    document.addEventListener('click', function(event) {
        var anchor = event.target.closest('a');

        if (!anchor) {
            return;
        }

        sendClickData(anchor);
    }, true);
})();
JS;

        wp_add_inline_script('backlot-click-tracker', $inline_js);
    }

    public function track_pageview() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'backlot_click_tracker_nonce')) {
            wp_die();
        }

        if ($this->is_bot()) {
            wp_die();
        }

        if ($this->should_skip_tracking()) {
            wp_die();
        }

        global $wpdb;

        $now = current_time('mysql');
        $page_id = isset($_POST['page_id']) ? absint($_POST['page_id']) : 0;
        $page_url = isset($_POST['page_url']) ? esc_url_raw(wp_unslash($_POST['page_url'])) : '';

        if (!$page_url) {
            wp_die();
        }

        $session_id = isset($_POST['session_id']) ? sanitize_text_field(wp_unslash($_POST['session_id'])) : '';
        $ip_address = $this->get_ip_address();
        $ip_hash = $ip_address ? hash('sha256', $ip_address . wp_salt('auth')) : '';
        $country = $this->get_country($ip_address);

        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id
                 FROM {$this->table_name}
                 WHERE event_type = 'pageview'
                 AND session_id = %s
                 AND page_id = %d
                 AND viewed_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                 LIMIT 1",
                $session_id,
                $page_id
            )
        );

        if ($existing) {
            wp_die();
        }

        $wpdb->insert(
            $this->table_name,
            array(
                'event_type'   => 'pageview',
                'viewed_at'    => $now,
                'clicked_at'   => $now,
                'page_id'      => $page_id,
                'page_title'   => isset($_POST['page_title']) ? sanitize_text_field(wp_unslash($_POST['page_title'])) : '',
                'page_url'     => $page_url,
                'user_id'      => get_current_user_id(),
                'ip_hash'      => $ip_hash,
                'country'      => $country,
                'session_id'   => $session_id,
                'user_agent'   => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '',
                'referrer'     => isset($_POST['referrer']) ? esc_url_raw(wp_unslash($_POST['referrer'])) : '',
                'utm_source'   => isset($_POST['utm_source']) ? sanitize_text_field(wp_unslash($_POST['utm_source'])) : '',
                'utm_medium'   => isset($_POST['utm_medium']) ? sanitize_text_field(wp_unslash($_POST['utm_medium'])) : '',
                'utm_campaign' => isset($_POST['utm_campaign']) ? sanitize_text_field(wp_unslash($_POST['utm_campaign'])) : '',
                'utm_content'  => isset($_POST['utm_content']) ? sanitize_text_field(wp_unslash($_POST['utm_content'])) : '',
                'utm_term'     => isset($_POST['utm_term']) ? sanitize_text_field(wp_unslash($_POST['utm_term'])) : '',
            )
        );

        wp_die();
    }

    public function track_click() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'backlot_click_tracker_nonce')) {
            wp_die();
        }

        if ($this->is_bot()) {
            wp_die();
        }

        if ($this->should_skip_tracking()) {
            wp_die();
        }

        global $wpdb;

        $now = current_time('mysql');
        $ip_address = $this->get_ip_address();
        $ip_hash = $ip_address ? hash('sha256', $ip_address . wp_salt('auth')) : '';
        $country = $this->get_country($ip_address);

        $wpdb->insert(
            $this->table_name,
            array(
                'event_type'    => 'click',
                'viewed_at'     => $now,
                'clicked_at'    => $now,
                'page_id'       => isset($_POST['page_id']) ? absint($_POST['page_id']) : 0,
                'page_title'    => isset($_POST['page_title']) ? sanitize_text_field(wp_unslash($_POST['page_title'])) : '',
                'page_url'      => isset($_POST['page_url']) ? esc_url_raw(wp_unslash($_POST['page_url'])) : '',
                'link_url'      => isset($_POST['link_url']) ? esc_url_raw(wp_unslash($_POST['link_url'])) : '',
                'link_text'     => isset($_POST['link_text']) ? sanitize_text_field(wp_unslash($_POST['link_text'])) : '',
                'link_target'   => isset($_POST['link_target']) ? sanitize_text_field(wp_unslash($_POST['link_target'])) : '',
                'link_classes'  => isset($_POST['link_classes']) ? sanitize_text_field(wp_unslash($_POST['link_classes'])) : '',
                'link_category' => isset($_POST['link_category']) ? sanitize_text_field(wp_unslash($_POST['link_category'])) : '',
                'event_location' => isset($_POST['event_location']) ? sanitize_text_field(wp_unslash($_POST['event_location'])) : '',
                'user_id'       => get_current_user_id(),
                'ip_hash'       => $ip_hash,
                'country'       => $country,
                'session_id'    => isset($_POST['session_id']) ? sanitize_text_field(wp_unslash($_POST['session_id'])) : '',
                'user_agent'    => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '',
                'referrer'      => isset($_POST['referrer']) ? esc_url_raw(wp_unslash($_POST['referrer'])) : '',
                'utm_source'    => isset($_POST['utm_source']) ? sanitize_text_field(wp_unslash($_POST['utm_source'])) : '',
                'utm_medium'    => isset($_POST['utm_medium']) ? sanitize_text_field(wp_unslash($_POST['utm_medium'])) : '',
                'utm_campaign'  => isset($_POST['utm_campaign']) ? sanitize_text_field(wp_unslash($_POST['utm_campaign'])) : '',
                'utm_content'   => isset($_POST['utm_content']) ? sanitize_text_field(wp_unslash($_POST['utm_content'])) : '',
                'utm_term'      => isset($_POST['utm_term']) ? sanitize_text_field(wp_unslash($_POST['utm_term'])) : '',
            )
        );

        wp_die();
    }

    private function is_bot() {
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))) : '';

        if (!$user_agent) {
            return false;
        }

        $bots = array(
            'bot',
            'crawl',
            'spider',
            'slurp',
            'facebookexternalhit',
            'whatsapp',
            'preview',
            'monitor',
            'pingdom',
            'lighthouse',
            'pagespeed',
            'gtmetrix',
            'semrush',
            'ahrefs',
            'mj12',
            'python',
            'python-requests',
            'curl',
            'wget',
            'headless',
            'phantom',
            'puppeteer',
            'playwright',
            'scrapy',
            'axios',
            'go-http-client',
            'okhttp',
            'java/',
            'libwww',
            'httrack',
            'node-fetch',
            'dataprovider',
            'siteimprove',
            'screaming frog',
            'yandex',
            'baidu',
            'bytespider',
            'heritrix',
            'ia_archiver',
            'feedfetcher',
            'embedly',
            'archive.org',
            'masscan',
            'zgrab',
            'censys'
        );

        foreach ($bots as $bot) {
            if (strpos($user_agent, $bot) !== false) {
                return true;
            }
        }

        return false;
    }

    private function get_ip_address() {
        $keys = array(
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'REMOTE_ADDR',
        );

        foreach ($keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = sanitize_text_field(wp_unslash($_SERVER[$key]));

                if (strpos($ip, ',') !== false) {
                    $parts = explode(',', $ip);
                    $ip = trim($parts[0]);
                }

                return $ip;
            }
        }

        return '';
    }

    private function calculate_ctr($clicks, $views) {
        if (!$views || $views <= 0) {
            return '0%';
        }

        return number_format(($clicks / $views) * 100, 2) . '%';
    }

    private function get_date_filters() {
        $date_from = isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : date('Y-m-d', strtotime('-30 days'));
        $date_to = isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : date('Y-m-d');

        return array($date_from, $date_to);
    }

    public function admin_assets($hook = '') {
        $css = <<<CSS
.backlot-admin-report-wrap {
    max-width: 1600px;
}

.backlot-filter-panel,
.backlot-report-section,
.backlot-below-slug-panel {
    background: #fff;
    border: 1px solid #dcdcde;
    border-radius: 18px;
    padding: 22px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.045);
}

.backlot-filter-panel {
    margin: 20px 0;
}

.backlot-report-section {
    margin-top: 24px;
}

.backlot-below-slug-panel {
    margin: 16px 0 24px;
}

.backlot-filter-panel label {
    display: inline-flex;
    flex-direction: column;
    gap: 6px;
    font-weight: 700;
    margin-right: 12px;
    margin-bottom: 12px;
}

.backlot-filter-panel input {
    min-height: 38px;
    border-radius: 8px;
}

.backlot-stat-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 18px;
    margin: 24px 0;
}

.backlot-mini-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 14px;
    margin: 16px 0 22px;
}

.backlot-stat-card,
.backlot-mini-card {
    background: linear-gradient(135deg, #050509 0%, #181826 100%);
    color: #fff;
    border-radius: 18px;
    padding: 20px;
    box-shadow: 0 14px 38px rgba(0,0,0,0.14);
    border: 1px solid rgba(255,255,255,0.08);
}

.backlot-stat-card .label,
.backlot-mini-card span {
    display: block;
    font-size: 11px;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    opacity: 0.72;
    margin-bottom: 10px;
}

.backlot-stat-card .value {
    font-size: 36px;
    line-height: 1;
    font-weight: 900;
}

.backlot-mini-card strong {
    display: block;
    font-size: 28px;
    line-height: 1;
    font-weight: 900;
}

.backlot-section-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 12px;
}

.backlot-section-header h2,
.backlot-section-header h3 {
    margin: 0 0 6px;
}

.backlot-section-header p {
    margin: 0;
    color: #646970;
}

.backlot-pill {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 999px;
    background: #f0f0f1;
    color: #1d2327;
    font-size: 12px;
    font-weight: 800;
}

.backlot-link-url {
    word-break: break-all;
}

.backlot-drill {
    border: 1px solid #dcdcde;
    border-radius: 12px;
    padding: 10px 16px;
    margin: 10px 0;
    background: #fff;
}

.backlot-drill > summary {
    cursor: pointer;
    font-weight: 700;
    color: #1d2327;
}

.backlot-drill[open] > summary {
    margin-bottom: 12px;
}

.backlot-drill .backlot-drill-meta {
    color: #646970;
    font-weight: 400;
}

.backlot-report-section table,
.backlot-below-slug-panel table {
    margin-top: 12px;
}

.backlot-report-section th,
.backlot-below-slug-panel th {
    font-weight: 800;
}

@media screen and (max-width: 782px) {
    .backlot-section-header {
        display: block;
    }
}

/* ===== Immersive home-dashboard overview ===== */
#dashboard-widgets-wrap #dashboard-widgets .postbox-container,
#dashboard-widgets-wrap #dashboard-widgets #postbox-container-1,
#dashboard-widgets-wrap #dashboard-widgets #postbox-container-2,
#dashboard-widgets-wrap #dashboard-widgets #postbox-container-3,
#dashboard-widgets-wrap #dashboard-widgets #postbox-container-4 {
    width: 100% !important;
    float: none !important;
}

#dashboard-widgets .meta-box-sortables {
    min-height: 0;
}

#backlot_dashboard_overview {
    border: none;
    background: transparent;
    box-shadow: none;
}

#backlot_dashboard_overview > .postbox-header {
    display: none;
}

#backlot_dashboard_overview .inside {
    margin: 0;
    padding: 0;
}

.backlot-dash {
    color: #1d2327;
}

.backlot-dash-hero {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 20px;
    margin: 4px 0 22px;
    flex-wrap: wrap;
}

.backlot-dash-hero h2 {
    margin: 0 0 4px;
    font-size: 26px;
    font-weight: 900;
    letter-spacing: -0.01em;
}

.backlot-dash-hero p {
    margin: 0;
    color: #646970;
}

.backlot-dash-hero .button {
    align-self: center;
}

.backlot-chart-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 18px;
    margin: 22px 0;
}

.backlot-chart-card {
    background: #fff;
    border: 1px solid #dcdcde;
    border-radius: 18px;
    padding: 20px 22px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.045);
}

.backlot-chart-card h3 {
    margin: 0 0 14px;
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #646970;
}

.backlot-chart-card canvas {
    width: 100% !important;
}

.backlot-canvas-holder {
    position: relative;
    height: 280px;
}

.backlot-canvas-holder.backlot-canvas-tall {
    height: 340px;
}

.backlot-canvas-holder canvas {
    height: 100% !important;
}

.backlot-dash-controls {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.backlot-presets {
    display: inline-flex;
    gap: 4px;
}

.backlot-range-form {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.backlot-range-form input[type="date"] {
    min-height: 32px;
    border-radius: 8px;
}

.backlot-range-form span {
    color: #646970;
}

.backlot-dash-cols {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 18px;
}

.backlot-rank {
    list-style: none;
    margin: 0;
    padding: 0;
}

.backlot-rank li {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 11px 0;
    border-bottom: 1px solid #f0f0f1;
}

.backlot-rank li:last-child {
    border-bottom: none;
}

.backlot-rank .rank-num {
    flex: 0 0 26px;
    height: 26px;
    width: 26px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 900;
    font-size: 12px;
    background: linear-gradient(135deg, #050509 0%, #181826 100%);
    color: #fff;
}

.backlot-rank .rank-body {
    flex: 1 1 auto;
    min-width: 0;
}

.backlot-rank .rank-title {
    display: block;
    font-weight: 700;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.backlot-rank .rank-sub {
    display: block;
    font-size: 12px;
    color: #646970;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.backlot-rank .rank-metric {
    flex: 0 0 auto;
    text-align: right;
}

.backlot-rank .rank-metric strong {
    display: block;
    font-size: 18px;
    font-weight: 900;
    line-height: 1.1;
}

.backlot-rank .rank-metric span {
    font-size: 11px;
    color: #646970;
}

.backlot-dash-empty {
    color: #646970;
    padding: 30px 0;
    text-align: center;
}

CSS;

        wp_register_style('backlot-click-tracker-admin', false);
        wp_enqueue_style('backlot-click-tracker-admin');
        wp_add_inline_style('backlot-click-tracker-admin', $css);

        // Charts only on the home dashboard (index.php), where we replace the widgets.
        $dash_settings = $this->get_settings();
        if ($hook === 'index.php' && current_user_can('manage_options') && !empty($dash_settings['dashboard_takeover'])) {
            wp_enqueue_script(
                'backlot-chartjs',
                'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
                array(),
                '4.4.1',
                true
            );

            $data = $this->get_dashboard_data();

            wp_add_inline_script(
                'backlot-chartjs',
                'window.BACKLOT_DASH = ' . wp_json_encode($data['charts']) . ';',
                'before'
            );

            wp_add_inline_script('backlot-chartjs', $this->dashboard_init_js(), 'after');
        }

        // PDF export libraries only on the 794 Analytics report page.
        if ($hook === 'toplevel_page_backlot-click-tracker' && current_user_can('manage_options')) {
            wp_enqueue_script(
                'backlot-jspdf',
                'https://cdn.jsdelivr.net/npm/jspdf@2.5.2/dist/jspdf.umd.min.js',
                array(),
                '2.5.2',
                true
            );

            wp_enqueue_script(
                'backlot-jspdf-autotable',
                'https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.2/dist/jspdf.plugin.autotable.min.js',
                array('backlot-jspdf'),
                '3.8.2',
                true
            );

            wp_add_inline_script('backlot-jspdf-autotable', $this->report_pdf_js(), 'after');
        }
    }

    public function admin_menu() {
        add_menu_page(
            '794 Analytics',
            '794 Analytics',
            'manage_options',
            'backlot-click-tracker',
            array($this, 'admin_page'),
            'dashicons-chart-area',
            58
        );

        add_submenu_page(
            'backlot-click-tracker',
            '794 Analytics Settings',
            'Settings',
            'manage_options',
            'backlot-ct-settings',
            array($this, 'settings_page')
        );
    }

    public function setup_dashboard() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = $this->get_settings();
        if (empty($settings['dashboard_takeover'])) {
            return;
        }

        global $wp_meta_boxes;

        // Wipe every default + third-party dashboard widget (priority 9999 runs last).
        $wp_meta_boxes['dashboard'] = array();

        // Hide the dismissible "Welcome" panel too.
        remove_action('welcome_panel', 'wp_welcome_panel');

        wp_add_dashboard_widget(
            'backlot_dashboard_overview',
            '794 Analytics — Overview',
            array($this, 'render_dashboard_overview')
        );

        // Force the dashboard into a single full-width column.
        add_filter('screen_layout_columns', function ($columns) {
            $columns['dashboard'] = 1;
            return $columns;
        });
        add_filter('get_user_option_screen_layout_dashboard', function () {
            return 1;
        });
    }

    private function get_dashboard_range() {
        $date_to = date('Y-m-d');
        $date_from = date('Y-m-d', strtotime('-29 days'));

        $req_from = isset($_GET['backlot_from']) ? sanitize_text_field(wp_unslash($_GET['backlot_from'])) : '';
        $req_to = isset($_GET['backlot_to']) ? sanitize_text_field(wp_unslash($_GET['backlot_to'])) : '';

        $is_date = function ($value) {
            return $value && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value);
        };

        if ($is_date($req_from)) {
            $date_from = $req_from;
        }
        if ($is_date($req_to)) {
            $date_to = $req_to;
        }

        // Keep the range the right way round.
        if (strtotime($date_from) > strtotime($date_to)) {
            $swap = $date_from;
            $date_from = $date_to;
            $date_to = $swap;
        }

        return array($date_from, $date_to);
    }

    private function get_dashboard_data() {
        if ($this->dashboard_data !== null) {
            return $this->dashboard_data;
        }

        global $wpdb;

        list($date_from, $date_to) = $this->get_dashboard_range();

        // Short-lived cache so repeated dashboard loads don't re-run the
        // aggregate queries every time. Keyed by range + schema version.
        $cache_key = 'backlot_dash_' . md5($date_from . '|' . $date_to . '|' . self::DB_VERSION);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            $this->dashboard_data = $cached;
            return $cached;
        }

        $range_start = $date_from . ' 00:00:00';
        $range_end = $date_to . ' 23:59:59';
        $params = array($range_start, $range_end);

        $where = "WHERE viewed_at BETWEEN %s AND %s";

        $total_pageviews = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_name} {$where} AND event_type = 'pageview'", $params));
        $unique_pageviews = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT session_id) FROM {$this->table_name} {$where} AND event_type = 'pageview'", $params));
        $total_clicks = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_name} {$where} AND event_type = 'click'", $params));
        $unique_clicks = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT session_id) FROM {$this->table_name} {$where} AND event_type = 'click'", $params));

        $top_links = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    link_url,
                    link_text,
                    link_category,
                    COUNT(*) AS click_count,
                    COUNT(DISTINCT session_id) AS unique_clicks
                 FROM {$this->table_name}
                 {$where}
                 AND event_type = 'click'
                 GROUP BY link_url
                 ORDER BY click_count DESC
                 LIMIT 8",
                $params
            )
        );

        $top_pages = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    page_url,
                    MAX(page_title) AS page_title,
                    SUM(CASE WHEN event_type = 'pageview' THEN 1 ELSE 0 END) AS pageviews,
                    COUNT(DISTINCT CASE WHEN event_type = 'pageview' THEN session_id END) AS unique_pageviews
                 FROM {$this->table_name}
                 {$where}
                 GROUP BY page_url
                 HAVING pageviews > 0
                 ORDER BY pageviews DESC
                 LIMIT 8",
                $params
            )
        );

        $category_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    link_category,
                    COUNT(*) AS click_count
                 FROM {$this->table_name}
                 {$where}
                 AND event_type = 'click'
                 GROUP BY link_category
                 ORDER BY click_count DESC
                 LIMIT 8",
                $params
            )
        );

        $country_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    country,
                    SUM(CASE WHEN event_type = 'pageview' THEN 1 ELSE 0 END) AS pageviews,
                    COUNT(DISTINCT CASE WHEN event_type = 'pageview' THEN session_id END) AS unique_pageviews
                 FROM {$this->table_name}
                 {$where}
                 AND country IS NOT NULL
                 AND country <> ''
                 GROUP BY country
                 HAVING pageviews > 0
                 ORDER BY pageviews DESC
                 LIMIT 10",
                $params
            )
        );

        $daily_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    DATE(viewed_at) AS d,
                    SUM(CASE WHEN event_type = 'pageview' THEN 1 ELSE 0 END) AS pageviews,
                    COUNT(DISTINCT CASE WHEN event_type = 'pageview' THEN session_id END) AS unique_visits,
                    SUM(CASE WHEN event_type = 'click' THEN 1 ELSE 0 END) AS clicks
                 FROM {$this->table_name}
                 {$where}
                 GROUP BY DATE(viewed_at)",
                $params
            )
        );

        // Index DB rows by date, then walk the full range so the axis is continuous.
        $by_day = array();
        foreach ($daily_rows as $row) {
            $by_day[$row->d] = $row;
        }

        $labels = array();
        $series_pageviews = array();
        $series_unique_visits = array();
        $series_clicks = array();

        $cursor = strtotime($date_from);
        $end = strtotime($date_to);
        while ($cursor <= $end) {
            $key = date('Y-m-d', $cursor);
            $labels[] = date('M j', $cursor);
            $series_pageviews[] = isset($by_day[$key]) ? (int) $by_day[$key]->pageviews : 0;
            $series_unique_visits[] = isset($by_day[$key]) ? (int) $by_day[$key]->unique_visits : 0;
            $series_clicks[] = isset($by_day[$key]) ? (int) $by_day[$key]->clicks : 0;
            $cursor = strtotime('+1 day', $cursor);
        }

        $cat_labels = array();
        $cat_values = array();
        foreach ($category_rows as $row) {
            $cat_labels[] = $row->link_category ? $row->link_category : 'Uncategorized';
            $cat_values[] = (int) $row->click_count;
        }

        $link_labels = array();
        $link_values = array();
        foreach ($top_links as $row) {
            $label = $row->link_text ? $row->link_text : $row->link_url;
            $link_labels[] = mb_strimwidth($label, 0, 40, '…');
            $link_values[] = (int) $row->click_count;
        }

        $this->dashboard_data = array(
            'date_from' => $date_from,
            'date_to' => $date_to,
            'kpis' => array(
                'pageviews' => $total_pageviews,
                'unique_pageviews' => $unique_pageviews,
                'clicks' => $total_clicks,
                'unique_clicks' => $unique_clicks,
                'ctr' => $this->calculate_ctr($total_clicks, $total_pageviews),
            ),
            'top_links' => $top_links,
            'top_pages' => $top_pages,
            'top_countries' => $country_rows,
            'charts' => array(
                'labels' => $labels,
                'pageviews' => $series_pageviews,
                'uniqueVisits' => $series_unique_visits,
                'clicks' => $series_clicks,
                'categories' => array(
                    'labels' => $cat_labels,
                    'values' => $cat_values,
                ),
                'topLinks' => array(
                    'labels' => array_reverse($link_labels),
                    'values' => array_reverse($link_values),
                ),
            ),
        );

        set_transient($cache_key, $this->dashboard_data, 5 * MINUTE_IN_SECONDS);

        return $this->dashboard_data;
    }

    public function render_dashboard_overview() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $data = $this->get_dashboard_data();
        $kpis = $data['kpis'];

        $from = $data['date_from'];
        $to = $data['date_to'];
        $today = date('Y-m-d');
        $day_count = (int) round((strtotime($to) - strtotime($from)) / DAY_IN_SECONDS) + 1;
        $dash_url = admin_url('index.php');

        $presets = array(7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days');
        ?>
        <div class="backlot-dash">
            <div class="backlot-dash-hero">
                <div>
                    <h2>Analytics Overview</h2>
                    <p>
                        <?php echo esc_html(number_format_i18n($day_count)); ?> days &middot;
                        <?php echo esc_html(date_i18n('M j, Y', strtotime($from))); ?>
                        &ndash;
                        <?php echo esc_html(date_i18n('M j, Y', strtotime($to))); ?>
                    </p>
                </div>

                <div class="backlot-dash-controls">
                    <div class="backlot-presets">
                        <?php foreach ($presets as $n => $label) :
                            $preset_from = date('Y-m-d', strtotime('-' . ($n - 1) . ' days'));
                            $is_active = ($to === $today && $day_count === $n);
                            $preset_url = add_query_arg(
                                array('backlot_from' => $preset_from, 'backlot_to' => $today),
                                $dash_url
                            );
                            ?>
                            <a class="button<?php echo $is_active ? ' button-primary' : ''; ?>" href="<?php echo esc_url($preset_url); ?>">
                                <?php echo esc_html($n . 'd'); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <form method="get" action="<?php echo esc_url($dash_url); ?>" class="backlot-range-form">
                        <input type="date" name="backlot_from" value="<?php echo esc_attr($from); ?>" max="<?php echo esc_attr($today); ?>">
                        <span>&ndash;</span>
                        <input type="date" name="backlot_to" value="<?php echo esc_attr($to); ?>" max="<?php echo esc_attr($today); ?>">
                        <button class="button button-primary" type="submit">Apply</button>
                    </form>

                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=backlot-click-tracker')); ?>">
                        Full report
                    </a>
                </div>
            </div>

            <div class="backlot-stat-grid">
                <div class="backlot-stat-card">
                    <div class="label">Page Views</div>
                    <div class="value"><?php echo esc_html(number_format_i18n($kpis['pageviews'])); ?></div>
                </div>
                <div class="backlot-stat-card">
                    <div class="label">Unique Views</div>
                    <div class="value"><?php echo esc_html(number_format_i18n($kpis['unique_pageviews'])); ?></div>
                </div>
                <div class="backlot-stat-card">
                    <div class="label">Clicks</div>
                    <div class="value"><?php echo esc_html(number_format_i18n($kpis['clicks'])); ?></div>
                </div>
                <div class="backlot-stat-card">
                    <div class="label">Unique Clicks</div>
                    <div class="value"><?php echo esc_html(number_format_i18n($kpis['unique_clicks'])); ?></div>
                </div>
                <div class="backlot-stat-card">
                    <div class="label">CTR</div>
                    <div class="value"><?php echo esc_html($kpis['ctr']); ?></div>
                </div>
            </div>

            <div class="backlot-chart-grid">
                <div class="backlot-chart-card">
                    <h3>Views, Unique Visits &amp; Clicks Over Time</h3>
                    <div class="backlot-canvas-holder"><canvas id="backlotTrendChart"></canvas></div>
                </div>
                <div class="backlot-chart-card">
                    <h3>Clicks by Category</h3>
                    <div class="backlot-canvas-holder"><canvas id="backlotCategoryChart"></canvas></div>
                </div>
            </div>

            <div class="backlot-chart-card" style="margin-bottom:18px;">
                <h3>Top Links by Clicks</h3>
                <div class="backlot-canvas-holder backlot-canvas-tall"><canvas id="backlotLinksChart"></canvas></div>
            </div>

            <?php if (!empty($data['top_countries'])) : ?>
            <div class="backlot-chart-card" style="margin-bottom:18px;">
                <h3>Top Countries</h3>
                <ol class="backlot-rank">
                    <?php $i = 1; foreach ($data['top_countries'] as $row) :
                        $flag = $this->country_flag($row->country);
                        ?>
                        <li>
                            <span class="rank-num"><?php echo esc_html($i++); ?></span>
                            <span class="rank-body">
                                <span class="rank-title"><?php echo ($flag ? $flag . ' ' : '') . esc_html($this->country_name($row->country)); ?></span>
                                <span class="rank-sub"><?php echo esc_html($row->country); ?></span>
                            </span>
                            <span class="rank-metric">
                                <strong><?php echo esc_html(number_format_i18n($row->pageviews)); ?></strong>
                                <span><?php echo esc_html(number_format_i18n($row->unique_pageviews)); ?> uniq</span>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
            <?php endif; ?>

            <div class="backlot-dash-cols">
                <div class="backlot-chart-card">
                    <h3>Top Clicked Links</h3>
                    <?php if (!empty($data['top_links'])) : ?>
                        <ol class="backlot-rank">
                            <?php $i = 1; foreach ($data['top_links'] as $row) : ?>
                                <li>
                                    <span class="rank-num"><?php echo esc_html($i++); ?></span>
                                    <span class="rank-body">
                                        <span class="rank-title"><?php echo esc_html($row->link_text ?: $row->link_url); ?></span>
                                        <span class="rank-sub">
                                            <?php echo esc_html($row->link_category ?: 'Uncategorized'); ?>
                                            &middot;
                                            <?php echo esc_html($row->link_url); ?>
                                        </span>
                                    </span>
                                    <span class="rank-metric">
                                        <strong><?php echo esc_html(number_format_i18n($row->click_count)); ?></strong>
                                        <span><?php echo esc_html(number_format_i18n($row->unique_clicks)); ?> uniq</span>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php else : ?>
                        <div class="backlot-dash-empty">No link clicks recorded in the last 30 days.</div>
                    <?php endif; ?>
                </div>

                <div class="backlot-chart-card">
                    <h3>Most Viewed Pages</h3>
                    <?php if (!empty($data['top_pages'])) : ?>
                        <ol class="backlot-rank">
                            <?php $i = 1; foreach ($data['top_pages'] as $row) : ?>
                                <li>
                                    <span class="rank-num"><?php echo esc_html($i++); ?></span>
                                    <span class="rank-body">
                                        <span class="rank-title"><?php echo esc_html($row->page_title ?: 'Untitled'); ?></span>
                                        <span class="rank-sub"><?php echo esc_html($row->page_url); ?></span>
                                    </span>
                                    <span class="rank-metric">
                                        <strong><?php echo esc_html(number_format_i18n($row->pageviews)); ?></strong>
                                        <span><?php echo esc_html(number_format_i18n($row->unique_pageviews)); ?> uniq</span>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php else : ?>
                        <div class="backlot-dash-empty">No page views recorded in the last 30 days.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    private function dashboard_init_js() {
        return <<<'JS'
(function () {
    var d = window.BACKLOT_DASH;
    if (!d || typeof Chart === 'undefined') {
        return;
    }

    Chart.defaults.font.family = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif";
    Chart.defaults.color = '#646970';

    var ink = '#181826';
    var accent = '#6c5ce7';
    var teal = '#00b894';
    var palette = ['#181826', '#6c5ce7', '#00b894', '#fdcb6e', '#e17055', '#0984e3', '#e84393', '#b2bec3'];

    var trendEl = document.getElementById('backlotTrendChart');
    if (trendEl) {
        new Chart(trendEl, {
            type: 'line',
            data: {
                labels: d.labels,
                datasets: [
                    {
                        label: 'Page Views',
                        data: d.pageviews,
                        borderColor: ink,
                        backgroundColor: 'rgba(24,24,38,0.08)',
                        fill: true,
                        tension: 0.35,
                        pointRadius: 0,
                        borderWidth: 2
                    },
                    {
                        label: 'Unique Visits',
                        data: d.uniqueVisits,
                        borderColor: teal,
                        backgroundColor: 'rgba(0,184,148,0.10)',
                        fill: true,
                        tension: 0.35,
                        pointRadius: 0,
                        borderWidth: 2
                    },
                    {
                        label: 'Clicks',
                        data: d.clicks,
                        borderColor: accent,
                        backgroundColor: 'rgba(108,92,231,0.10)',
                        fill: true,
                        tension: 0.35,
                        pointRadius: 0,
                        borderWidth: 2
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'top', labels: { usePointStyle: true, boxWidth: 8 } } },
                scales: {
                    y: { beginAtZero: true, grid: { color: '#f0f0f1' }, ticks: { precision: 0 } },
                    x: { grid: { display: false }, ticks: { maxTicksLimit: 12 } }
                }
            }
        });
    }

    var catEl = document.getElementById('backlotCategoryChart');
    if (catEl && d.categories.values.length) {
        new Chart(catEl, {
            type: 'doughnut',
            data: {
                labels: d.categories.labels,
                datasets: [{ data: d.categories.values, backgroundColor: palette, borderWidth: 0 }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } }
            }
        });
    }

    var linksEl = document.getElementById('backlotLinksChart');
    if (linksEl && d.topLinks.values.length) {
        new Chart(linksEl, {
            type: 'bar',
            data: {
                labels: d.topLinks.labels,
                datasets: [{ label: 'Clicks', data: d.topLinks.values, backgroundColor: accent, borderRadius: 6 }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, grid: { color: '#f0f0f1' }, ticks: { precision: 0 } },
                    y: { grid: { display: false } }
                }
            }
        });
    }
})();
JS;
    }

    public function admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        global $wpdb;

        list($date_from, $date_to) = $this->get_date_filters();

        $page_search = isset($_GET['page_search']) ? sanitize_text_field(wp_unslash($_GET['page_search'])) : '';
        $link_search = isset($_GET['link_search']) ? sanitize_text_field(wp_unslash($_GET['link_search'])) : '';
        $category_search = isset($_GET['category_search']) ? sanitize_text_field(wp_unslash($_GET['category_search'])) : '';

        $where = "WHERE viewed_at BETWEEN %s AND %s";
        $params = array($date_from . ' 00:00:00', $date_to . ' 23:59:59');

        if ($page_search !== '') {
            $where .= " AND page_title LIKE %s";
            $params[] = '%' . $wpdb->esc_like($page_search) . '%';
        }

        if ($link_search !== '') {
            $where .= " AND link_url LIKE %s";
            $params[] = '%' . $wpdb->esc_like($link_search) . '%';
        }

        if ($category_search !== '') {
            $where .= " AND link_category LIKE %s";
            $params[] = '%' . $wpdb->esc_like($category_search) . '%';
        }

        $total_pageviews = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_name} {$where} AND event_type = 'pageview'", $params));
        $unique_pageviews = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT session_id) FROM {$this->table_name} {$where} AND event_type = 'pageview'", $params));
        $total_clicks = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_name} {$where} AND event_type = 'click'", $params));
        $unique_clicks = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT session_id) FROM {$this->table_name} {$where} AND event_type = 'click'", $params));
        $ctr = $this->calculate_ctr($total_clicks, $total_pageviews);

        $top_pages = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    page_id,
                    page_url,
                    MAX(page_title) AS page_title,
                    SUM(CASE WHEN event_type = 'pageview' THEN 1 ELSE 0 END) AS pageviews,
                    COUNT(DISTINCT CASE WHEN event_type = 'pageview' THEN session_id END) AS unique_pageviews,
                    SUM(CASE WHEN event_type = 'click' THEN 1 ELSE 0 END) AS clicks,
                    COUNT(DISTINCT CASE WHEN event_type = 'click' THEN session_id END) AS unique_clicks
                 FROM {$this->table_name}
                 {$where}
                 GROUP BY page_id, page_url
                 ORDER BY pageviews DESC, clicks DESC
                 LIMIT 100",
                $params
            )
        );

        $top_links = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    link_url,
                    link_text,
                    link_category,
                    page_title,
                    page_url,
                    COUNT(*) AS click_count,
                    COUNT(DISTINCT session_id) AS unique_clicks
                 FROM {$this->table_name}
                 {$where}
                 AND event_type = 'click'
                 GROUP BY link_url, page_id
                 ORDER BY click_count DESC
                 LIMIT 100",
                $params
            )
        );

        $category_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    link_category,
                    COUNT(*) AS click_count,
                    COUNT(DISTINCT session_id) AS unique_clicks
                 FROM {$this->table_name}
                 {$where}
                 AND event_type = 'click'
                 GROUP BY link_category
                 ORDER BY click_count DESC",
                $params
            )
        );

        $referrer_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    CASE
                        WHEN referrer IS NULL OR referrer = '' THEN '(direct / none)'
                        ELSE SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(referrer, '://', -1), '/', 1), '?', 1)
                    END AS referrer_host,
                    CASE
                        WHEN utm_campaign IS NULL OR utm_campaign = '' THEN '(none)'
                        ELSE utm_campaign
                    END AS campaign,
                    SUM(CASE WHEN event_type = 'pageview' THEN 1 ELSE 0 END) AS pageviews,
                    COUNT(DISTINCT CASE WHEN event_type = 'pageview' THEN session_id END) AS unique_pageviews,
                    SUM(CASE WHEN event_type = 'click' THEN 1 ELSE 0 END) AS clicks,
                    COUNT(DISTINCT session_id) AS unique_visitors
                 FROM {$this->table_name}
                 {$where}
                 GROUP BY referrer_host, campaign
                 ORDER BY pageviews DESC, clicks DESC
                 LIMIT 100",
                $params
            )
        );

        // ---- Internal links: section summary + per-path drill-down ----
        $site_host = wp_parse_url(home_url(), PHP_URL_HOST);
        $site_host = strtolower(preg_replace('/^www\./i', '', (string) $site_host));

        // SQL fragments below reference the link_url column only (no user input).
        $after_scheme = "SUBSTRING_INDEX(link_url, '://', -1)";
        $path_with_q  = "CASE WHEN LOCATE('/', {$after_scheme}) = 0 THEN '' ELSE SUBSTRING({$after_scheme}, LOCATE('/', {$after_scheme})) END";
        $path_expr    = "SUBSTRING_INDEX(SUBSTRING_INDEX({$path_with_q}, '?', 1), '#', 1)";
        $section_expr = "CASE WHEN TRIM(LEADING '/' FROM {$path_expr}) = '' THEN '(home)' ELSE SUBSTRING_INDEX(TRIM(LEADING '/' FROM {$path_expr}), '/', 1) END";
        $host_expr    = "TRIM(LEADING 'www.' FROM LOWER(SUBSTRING_INDEX({$after_scheme}, '/', 1)))";

        $internal_clause = " AND event_type = 'click' AND {$host_expr} = %s AND (link_category IS NULL OR link_category <> 'Navigation')";
        $internal_params = array_merge($params, array($site_host));

        $internal_sections = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    {$section_expr} AS section,
                    COUNT(*) AS clicks,
                    COUNT(DISTINCT session_id) AS unique_clicks,
                    COUNT(DISTINCT link_url) AS distinct_links
                 FROM {$this->table_name}
                 {$where}{$internal_clause}
                 GROUP BY section
                 ORDER BY clicks DESC
                 LIMIT 100",
                $internal_params
            )
        );

        $internal_paths = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    {$section_expr} AS section,
                    {$path_expr} AS path,
                    MAX(link_text) AS link_text,
                    MAX(link_url) AS link_url,
                    COUNT(*) AS clicks,
                    COUNT(DISTINCT session_id) AS unique_clicks
                 FROM {$this->table_name}
                 {$where}{$internal_clause}
                 GROUP BY section, path
                 ORDER BY clicks DESC
                 LIMIT 500",
                $internal_params
            )
        );

        $nav_items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    MAX(link_text) AS link_text,
                    link_url,
                    COUNT(*) AS clicks,
                    COUNT(DISTINCT session_id) AS unique_clicks
                 FROM {$this->table_name}
                 {$where}
                 AND event_type = 'click'
                 AND link_category = 'Navigation'
                 GROUP BY link_url
                 ORDER BY clicks DESC
                 LIMIT 100",
                $params
            )
        );

        $paths_by_section = array();
        foreach ($internal_paths as $p) {
            $paths_by_section[$p->section][] = $p;
        }

        $tour_dates = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    event_location,
                    CASE
                        WHEN link_url LIKE '%%bandsintown.com/t/%%' THEN 'Tickets'
                        WHEN link_url LIKE '%%bandsintown.com/e/%%' THEN 'Event Page'
                        WHEN link_url LIKE '%%artist-rsvp%%' AND link_text LIKE '%%otify%%' THEN 'Notify Me'
                        WHEN link_url LIKE '%%artist-rsvp%%' AND link_text LIKE '%%emind%%' THEN 'Remind Me'
                        WHEN link_url LIKE '%%artist-rsvp%%' THEN 'RSVP'
                        ELSE 'Other'
                    END AS action,
                    COUNT(*) AS clicks,
                    COUNT(DISTINCT session_id) AS unique_clicks
                 FROM {$this->table_name}
                 {$where}
                 AND event_type = 'click'
                 AND event_location IS NOT NULL
                 AND event_location <> ''
                 GROUP BY event_location, action
                 ORDER BY clicks DESC
                 LIMIT 400",
                $params
            )
        );

        $report_payload = array(
            'site'      => get_bloginfo('name'),
            'generated' => date_i18n('M j, Y \a\t g:i a'),
            'date_from' => $date_from,
            'date_to'   => $date_to,
            'filters'   => array(
                'page'     => $page_search,
                'link'     => $link_search,
                'category' => $category_search,
            ),
            'kpis' => array(
                'pageviews'        => (int) $total_pageviews,
                'unique_pageviews' => (int) $unique_pageviews,
                'clicks'           => (int) $total_clicks,
                'unique_clicks'    => (int) $unique_clicks,
                'ctr'              => $ctr,
            ),
            'top_pages' => array_map(function ($row) {
                return array(
                    'title'            => $row->page_title ?: 'Untitled',
                    'pageviews'        => (int) $row->pageviews,
                    'unique_pageviews' => (int) $row->unique_pageviews,
                    'clicks'           => (int) $row->clicks,
                    'unique_clicks'    => (int) $row->unique_clicks,
                    'ctr'              => $this->calculate_ctr((int) $row->clicks, (int) $row->pageviews),
                    'url'              => $row->page_url,
                );
            }, $top_pages),
            'top_links' => array_map(function ($row) {
                return array(
                    'clicks'   => (int) $row->click_count,
                    'unique'   => (int) $row->unique_clicks,
                    'category' => $row->link_category ?: 'Uncategorized',
                    'text'     => $row->link_text ?: 'No text captured',
                    'url'      => $row->link_url,
                    'from'     => $row->page_title ?: $row->page_url,
                );
            }, $top_links),
            'categories' => array_map(function ($row) {
                return array(
                    'category' => $row->link_category ?: 'Uncategorized',
                    'clicks'   => (int) $row->click_count,
                    'unique'   => (int) $row->unique_clicks,
                );
            }, $category_rows),
            'referrers' => array_map(function ($row) {
                return array(
                    'host'             => $row->referrer_host,
                    'campaign'         => $row->campaign,
                    'pageviews'        => (int) $row->pageviews,
                    'unique_pageviews' => (int) $row->unique_pageviews,
                    'clicks'           => (int) $row->clicks,
                    'visitors'         => (int) $row->unique_visitors,
                );
            }, $referrer_rows),
            'internal_sections' => array_map(function ($row) {
                return array(
                    'section'        => $row->section,
                    'clicks'         => (int) $row->clicks,
                    'unique_clicks'  => (int) $row->unique_clicks,
                    'distinct_links' => (int) $row->distinct_links,
                );
            }, $internal_sections),
            'internal_paths' => array_map(function ($row) {
                return array(
                    'section' => $row->section,
                    'path'    => $row->path ?: '/',
                    'text'    => $row->link_text ?: '',
                    'clicks'  => (int) $row->clicks,
                    'unique'  => (int) $row->unique_clicks,
                );
            }, $internal_paths),
            'nav_items' => array_map(function ($row) {
                return array(
                    'text'   => $row->link_text ?: '',
                    'url'    => $row->link_url,
                    'clicks' => (int) $row->clicks,
                    'unique' => (int) $row->unique_clicks,
                );
            }, $nav_items),
            'tour_dates' => array_map(function ($row) {
                return array(
                    'event'  => $row->event_location,
                    'action' => $row->action,
                    'clicks' => (int) $row->clicks,
                    'unique' => (int) $row->unique_clicks,
                );
            }, $tour_dates),
        );

        ?>

        <div class="wrap backlot-admin-report-wrap">
            <h1>794 Analytics</h1>

            <?php if (isset($_GET['backlot_backfilled'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>Backfilled <?php echo esc_html(number_format_i18n((int) $_GET['backlot_backfilled'])); ?> historical click<?php echo ((int) $_GET['backlot_backfilled'] === 1) ? '' : 's'; ?> with tour locations.</p>
                </div>
            <?php endif; ?>

            <form method="get" class="backlot-filter-panel">
                <input type="hidden" name="page" value="backlot-click-tracker">

                <label>
                    From
                    <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>">
                </label>

                <label>
                    To
                    <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>">
                </label>

                <label>
                    Page / Release
                    <input type="text" name="page_search" value="<?php echo esc_attr($page_search); ?>" placeholder="Release title">
                </label>

                <label>
                    Link URL
                    <input type="text" name="link_search" value="<?php echo esc_attr($link_search); ?>" placeholder="spotify, youtube, ffm.to">
                </label>

                <label>
                    Category
                    <input type="text" name="category_search" value="<?php echo esc_attr($category_search); ?>" placeholder="Spotify, Apple Music">
                </label>

                <button class="button button-primary" type="submit">Filter Report</button>

                <a class="button" href="<?php echo esc_url(admin_url('admin-post.php?action=backlot_export_clicks_csv&date_from=' . urlencode($date_from) . '&date_to=' . urlencode($date_to) . '&page_search=' . urlencode($page_search) . '&link_search=' . urlencode($link_search) . '&category_search=' . urlencode($category_search))); ?>">
                    Export CSV
                </a>

                <button type="button" class="button" id="backlot-export-pdf" onclick="backlotExportPDF(this)">
                    Download PDF
                </button>
            </form>

            <div class="backlot-stat-grid">
                <div class="backlot-stat-card">
                    <div class="label">Page Views</div>
                    <div class="value"><?php echo esc_html(number_format_i18n($total_pageviews)); ?></div>
                </div>

                <div class="backlot-stat-card">
                    <div class="label">Unique Views</div>
                    <div class="value"><?php echo esc_html(number_format_i18n($unique_pageviews)); ?></div>
                </div>

                <div class="backlot-stat-card">
                    <div class="label">Clicks</div>
                    <div class="value"><?php echo esc_html(number_format_i18n($total_clicks)); ?></div>
                </div>

                <div class="backlot-stat-card">
                    <div class="label">Unique Clicks</div>
                    <div class="value"><?php echo esc_html(number_format_i18n($unique_clicks)); ?></div>
                </div>

                <div class="backlot-stat-card">
                    <div class="label">CTR</div>
                    <div class="value"><?php echo esc_html($ctr); ?></div>
                </div>
            </div>

            <div class="backlot-report-section">
                <h2>Top Pages / Releases</h2>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Page / Release</th>
                            <th>Page Views</th>
                            <th>Unique Views</th>
                            <th>Clicks</th>
                            <th>Unique Clicks</th>
                            <th>CTR</th>
                            <th>URL</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($top_pages)) : ?>
                            <?php foreach ($top_pages as $row) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html($row->page_title ?: 'Untitled'); ?></strong></td>
                                    <td><?php echo esc_html(number_format_i18n($row->pageviews)); ?></td>
                                    <td><?php echo esc_html(number_format_i18n($row->unique_pageviews)); ?></td>
                                    <td><?php echo esc_html(number_format_i18n($row->clicks)); ?></td>
                                    <td><?php echo esc_html(number_format_i18n($row->unique_clicks)); ?></td>
                                    <td><strong><?php echo esc_html($this->calculate_ctr((int) $row->clicks, (int) $row->pageviews)); ?></strong></td>
                                    <td class="backlot-link-url">
                                        <a href="<?php echo esc_url($row->page_url); ?>" target="_blank">
                                            <?php echo esc_html($row->page_url); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr><td colspan="7">No data found yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="backlot-report-section">
                <h2>Clicked Links</h2>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Clicks</th>
                            <th>Unique</th>
                            <th>Category</th>
                            <th>Link Text</th>
                            <th>Clicked Link URL</th>
                            <th>Clicked From Page</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($top_links)) : ?>
                            <?php foreach ($top_links as $row) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html(number_format_i18n($row->click_count)); ?></strong></td>
                                    <td><?php echo esc_html(number_format_i18n($row->unique_clicks)); ?></td>
                                    <td><span class="backlot-pill"><?php echo esc_html($row->link_category ?: 'Uncategorized'); ?></span></td>
                                    <td><?php echo esc_html($row->link_text ?: 'No text captured'); ?></td>
                                    <td class="backlot-link-url">
                                        <a href="<?php echo esc_url($row->link_url); ?>" target="_blank">
                                            <?php echo esc_html($row->link_url); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <a href="<?php echo esc_url($row->page_url); ?>" target="_blank">
                                            <?php echo esc_html($row->page_title ?: $row->page_url); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr><td colspan="6">No clicked links found yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="backlot-report-section">
                <h2>Clicks By Category</h2>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Total Clicks</th>
                            <th>Unique Clicks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($category_rows)) : ?>
                            <?php foreach ($category_rows as $row) : ?>
                                <tr>
                                    <td><span class="backlot-pill"><?php echo esc_html($row->link_category ?: 'Uncategorized'); ?></span></td>
                                    <td><strong><?php echo esc_html(number_format_i18n($row->click_count)); ?></strong></td>
                                    <td><?php echo esc_html(number_format_i18n($row->unique_clicks)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr><td colspan="3">No click categories found yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="backlot-report-section">
                <h2>Navigation Menu</h2>
                <p style="margin:4px 0 0;color:#646970;">Clicks on links inside your site navigation and menus, kept separate from in-content links.</p>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Menu Item</th>
                            <th>Destination</th>
                            <th>Clicks</th>
                            <th>Unique Clicks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($nav_items)) : ?>
                            <?php foreach ($nav_items as $n) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html($n->link_text ?: '—'); ?></strong></td>
                                    <td class="backlot-link-url">
                                        <a href="<?php echo esc_url($n->link_url); ?>" target="_blank"><?php echo esc_html($n->link_url); ?></a>
                                    </td>
                                    <td><strong><?php echo esc_html(number_format_i18n($n->clicks)); ?></strong></td>
                                    <td><?php echo esc_html(number_format_i18n($n->unique_clicks)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr><td colspan="4">No navigation clicks recorded yet. Navigation is tracked as its own category starting in v4.3.0, so this fills in with new traffic.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="backlot-report-section">
                <h2>Internal Links</h2>
                <p style="margin:4px 0 0;color:#646970;">In-content same-site link clicks grouped by destination section (navigation menu clicks appear in their own table above). Expand a section to drill into individual destinations.</p>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Section</th>
                            <th>Clicks</th>
                            <th>Unique Clicks</th>
                            <th>Distinct Links</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($internal_sections)) : ?>
                            <?php foreach ($internal_sections as $s) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html($s->section); ?></strong></td>
                                    <td><strong><?php echo esc_html(number_format_i18n($s->clicks)); ?></strong></td>
                                    <td><?php echo esc_html(number_format_i18n($s->unique_clicks)); ?></td>
                                    <td><?php echo esc_html(number_format_i18n($s->distinct_links)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr><td colspan="4">No internal link clicks found yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php if (!empty($internal_sections)) : ?>
                    <h3 style="margin:20px 0 8px;">Drill-down by destination</h3>
                    <?php foreach ($internal_sections as $s) : ?>
                        <?php if (empty($paths_by_section[$s->section])) { continue; } ?>
                        <details class="backlot-drill">
                            <summary>
                                <?php echo esc_html($s->section); ?>
                                <span class="backlot-drill-meta">
                                    &mdash; <?php echo esc_html(number_format_i18n($s->clicks)); ?> clicks across <?php echo esc_html(number_format_i18n($s->distinct_links)); ?> link<?php echo ((int) $s->distinct_links === 1) ? '' : 's'; ?>
                                </span>
                            </summary>
                            <table class="widefat striped">
                                <thead>
                                    <tr>
                                        <th>Destination Path</th>
                                        <th>Top Anchor Text</th>
                                        <th>Clicks</th>
                                        <th>Unique</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($paths_by_section[$s->section] as $p) : ?>
                                        <tr>
                                            <td class="backlot-link-url">
                                                <a href="<?php echo esc_url($p->link_url); ?>" target="_blank">
                                                    <?php echo esc_html($p->path ?: '/'); ?>
                                                </a>
                                            </td>
                                            <td><?php echo esc_html($p->link_text ?: '—'); ?></td>
                                            <td><strong><?php echo esc_html(number_format_i18n($p->clicks)); ?></strong></td>
                                            <td><?php echo esc_html(number_format_i18n($p->unique_clicks)); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </details>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="backlot-report-section">
                <h2>Tour Dates</h2>
                <p style="margin:4px 0 0;color:#646970;">Tour / event link clicks resolved to the show by location and venue, split by action (Tickets, Notify Me, Remind Me). Populates for clicks captured from v3.6.0 onward.</p>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Event</th>
                            <th>Clicks</th>
                            <th>Unique Clicks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($tour_dates)) : ?>
                            <?php foreach ($tour_dates as $row) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html($row->event_location); ?></strong> <span style="color:#646970;">(<?php echo esc_html($row->action); ?>)</span></td>
                                    <td><strong><?php echo esc_html(number_format_i18n($row->clicks)); ?></strong></td>
                                    <td><?php echo esc_html(number_format_i18n($row->unique_clicks)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr><td colspan="3">No tour-link clicks captured yet. New clicks on event links will appear here.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <p style="margin-top:12px;">
                    <button type="button" class="button" onclick="backlotExportTourDatesPDF(this)">Download Tour Dates (PDF)</button>
                </p>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:14px;">
                    <input type="hidden" name="action" value="backlot_backfill_locations">
                    <?php wp_nonce_field('backlot_backfill_locations'); ?>
                    <button type="submit" class="button">Backfill locations from captured clicks</button>
                    <span style="color:#646970;margin-left:8px;">Click each ticket link once (Upcoming &amp; Past tabs), then run this to fill matching historical clicks by URL. Note: shared RSVP / Notify / Remind links can't be backfilled to a city and are skipped.</span>
                </form>
            </div>

            <div class="backlot-report-section">
                <h2>Top Referrers</h2>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Referrer</th>
                            <th>Campaign</th>
                            <th>Page Views</th>
                            <th>Unique Views</th>
                            <th>Clicks</th>
                            <th>Visitors</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($referrer_rows)) : ?>
                            <?php foreach ($referrer_rows as $row) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html($row->referrer_host); ?></strong></td>
                                    <td>
                                        <?php if ($row->campaign === '(none)') : ?>
                                            <span style="color:#a7aaad;">&mdash;</span>
                                        <?php else : ?>
                                            <span class="backlot-pill"><?php echo esc_html($row->campaign); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html(number_format_i18n($row->pageviews)); ?></td>
                                    <td><?php echo esc_html(number_format_i18n($row->unique_pageviews)); ?></td>
                                    <td><?php echo esc_html(number_format_i18n($row->clicks)); ?></td>
                                    <td><?php echo esc_html(number_format_i18n($row->unique_visitors)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr><td colspan="6">No referrer data found yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <script>
                window.BACKLOT_REPORT = <?php echo wp_json_encode($report_payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            </script>
        </div>

        <?php
    }

    public function render_analytics_below_slug($post) {
        if (!in_array($post->post_type, array('release', 'page', 'post'), true)) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        if (!$post->ID) {
            return;
        }

        global $wpdb;

        $date_from = date('Y-m-d H:i:s', strtotime('-30 days'));
        $post_id = absint($post->ID);

        $pageviews = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$this->table_name}
                 WHERE event_type = 'pageview'
                 AND page_id = %d
                 AND viewed_at >= %s",
                $post_id,
                $date_from
            )
        );

        $unique_pageviews = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT session_id)
                 FROM {$this->table_name}
                 WHERE event_type = 'pageview'
                 AND page_id = %d
                 AND viewed_at >= %s",
                $post_id,
                $date_from
            )
        );

        $clicks = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$this->table_name}
                 WHERE event_type = 'click'
                 AND page_id = %d
                 AND viewed_at >= %s",
                $post_id,
                $date_from
            )
        );

        $unique_clicks = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT session_id)
                 FROM {$this->table_name}
                 WHERE event_type = 'click'
                 AND page_id = %d
                 AND viewed_at >= %s",
                $post_id,
                $date_from
            )
        );

        $top_links = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    link_url,
                    link_text,
                    link_category,
                    COUNT(*) AS click_count,
                    COUNT(DISTINCT session_id) AS unique_clicks
                 FROM {$this->table_name}
                 WHERE event_type = 'click'
                 AND page_id = %d
                 AND viewed_at >= %s
                 GROUP BY link_url
                 ORDER BY click_count DESC
                 LIMIT 20",
                $post_id,
                $date_from
            )
        );

        ?>

        <div class="backlot-below-slug-panel">
            <div class="backlot-section-header">
                <div>
                    <h2>794 Analytics</h2>
                    <p>Last 30 days for this page/release.</p>
                </div>
            </div>

            <div class="backlot-mini-grid">
                <div class="backlot-mini-card">
                    <span>Page Views</span>
                    <strong><?php echo esc_html(number_format_i18n($pageviews)); ?></strong>
                </div>

                <div class="backlot-mini-card">
                    <span>Unique Views</span>
                    <strong><?php echo esc_html(number_format_i18n($unique_pageviews)); ?></strong>
                </div>

                <div class="backlot-mini-card">
                    <span>Clicks</span>
                    <strong><?php echo esc_html(number_format_i18n($clicks)); ?></strong>
                </div>

                <div class="backlot-mini-card">
                    <span>Unique Clicks</span>
                    <strong><?php echo esc_html(number_format_i18n($unique_clicks)); ?></strong>
                </div>

                <div class="backlot-mini-card">
                    <span>CTR</span>
                    <strong><?php echo esc_html($this->calculate_ctr($clicks, $pageviews)); ?></strong>
                </div>
            </div>

            <h3>Clicked Links On This Page</h3>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Clicks</th>
                        <th>Unique</th>
                        <th>Category</th>
                        <th>Link Text</th>
                        <th>Clicked Link URL</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($top_links)) : ?>
                        <?php foreach ($top_links as $row) : ?>
                            <tr>
                                <td><strong><?php echo esc_html(number_format_i18n($row->click_count)); ?></strong></td>
                                <td><?php echo esc_html(number_format_i18n($row->unique_clicks)); ?></td>
                                <td><span class="backlot-pill"><?php echo esc_html($row->link_category ?: 'Uncategorized'); ?></span></td>
                                <td><?php echo esc_html($row->link_text ?: 'No text captured'); ?></td>
                                <td class="backlot-link-url">
                                    <a href="<?php echo esc_url($row->link_url); ?>" target="_blank">
                                        <?php echo esc_html($row->link_url); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="5">No clicks tracked yet for this page.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($pageviews === 0 && $clicks === 0) : ?>
                <p style="margin-top:18px;color:#646970;">
                    Open the public page in a private window, click one link, then refresh this edit screen.
                </p>
            <?php endif; ?>
        </div>

        <?php
    }

    public function export_csv() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        global $wpdb;

        list($date_from, $date_to) = $this->get_date_filters();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    viewed_at,
                    clicked_at,
                    event_type,
                    page_id,
                    page_title,
                    page_url,
                    link_category,
                    link_text,
                    link_url,
                    link_target,
                    event_location,
                    country,
                    session_id,
                    utm_source,
                    utm_medium,
                    utm_campaign,
                    utm_content,
                    utm_term,
                    referrer
                 FROM {$this->table_name}
                 WHERE viewed_at BETWEEN %s AND %s
                 ORDER BY viewed_at DESC",
                $date_from . ' 00:00:00',
                $date_to . ' 23:59:59'
            ),
            ARRAY_A
        );

        $filename = 'backlot-analytics-report-' . date('Y-m-d') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $output = fopen('php://output', 'w');

        fputcsv($output, array(
            'Viewed At',
            'Clicked At',
            'Event Type',
            'Page ID',
            'Page Title',
            'Page URL',
            'Category',
            'Link Text',
            'Link URL',
            'Target',
            'Event Location',
            'Country',
            'Session ID',
            'UTM Source',
            'UTM Medium',
            'UTM Campaign',
            'UTM Content',
            'UTM Term',
            'Referrer',
        ));

        foreach ($rows as $row) {
            fputcsv($output, $row);
        }

        fclose($output);
        exit;
    }

    /**
     * Backfill event_location onto historical clicks by matching the link URL
     * (ignoring query string) against clicks that already have a captured
     * location. Lets a single fresh click on each tour link resolve every
     * earlier click of that same link. Idempotent; only fills empty rows.
     *
     * SAFETY: only URLs that map to exactly ONE distinct captured location are
     * used. This deliberately excludes shared, non-event-specific links such as
     * the artist-level RSVP / "Notify Me" / "Remind Me" URL (which is identical
     * across every show), so historical reminder clicks are never mis-assigned
     * to the wrong city.
     */
    public function backfill_locations() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('backlot_backfill_locations');

        global $wpdb;

        // Map: link URL without query string -> its single captured location.
        // Two guards keep backfill from ever mis-assigning a city:
        //   1. link_url NOT LIKE '%artist-rsvp%' hard-excludes the artist-level
        //      RSVP / Notify Me / Remind Me link, which is shared across every
        //      show, so it can never seed or receive a location even if only one
        //      show has been clicked so far.
        //   2. HAVING COUNT(DISTINCT event_location) = 1 keeps only unambiguous,
        //      per-event links (e.g. ticket URLs) for any other shared link.
        $map = $wpdb->get_results(
            "SELECT SUBSTRING_INDEX(link_url, '?', 1) AS link_key, MAX(event_location) AS loc
             FROM {$this->table_name}
             WHERE event_location IS NOT NULL AND event_location <> ''
               AND link_url NOT LIKE '%artist-rsvp%'
             GROUP BY link_key
             HAVING COUNT(DISTINCT event_location) = 1"
        );

        $updated = 0;

        foreach ($map as $row) {
            if ($row->link_key === '' || $row->loc === null || $row->loc === '') {
                continue;
            }

            $count = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$this->table_name}
                     SET event_location = %s
                     WHERE event_type = 'click'
                       AND (event_location IS NULL OR event_location = '')
                       AND SUBSTRING_INDEX(link_url, '?', 1) = %s",
                    $row->loc,
                    $row->link_key
                )
            );

            if ($count) {
                $updated += (int) $count;
            }
        }

        wp_safe_redirect(
            add_query_arg(
                'backlot_backfilled',
                $updated,
                admin_url('admin.php?page=backlot-click-tracker')
            )
        );
        exit;
    }

    private function report_pdf_js() {
        return <<<'JS'
(function () {
    function fmt(n) {
        var v = (typeof n === 'number') ? n : (parseInt(n, 10) || 0);
        return v.toLocaleString();
    }

    function emptyRow(n) {
        var r = ['No data found'];
        for (var i = 1; i < n; i++) { r.push(''); }
        return r;
    }

    window.backlotExportPDF = function (btn) {
        var R = window.BACKLOT_REPORT;
        if (!R) {
            window.alert('Report data is not ready yet. Please reload the page and try again.');
            return;
        }
        if (!window.jspdf || !window.jspdf.jsPDF) {
            window.alert('The PDF library failed to load. Check your connection and reload the page.');
            return;
        }

        var original = '';
        if (btn) { original = btn.textContent; btn.disabled = true; btn.textContent = 'Building PDF…'; }

        try {
            var jsPDF = window.jspdf.jsPDF;
            var doc = new jsPDF({ orientation: 'p', unit: 'pt', format: 'a4' });
            var pageW = doc.internal.pageSize.getWidth();
            var pageH = doc.internal.pageSize.getHeight();
            var margin = 40;
            var ink = [24, 24, 38];

            doc.setFont('helvetica', 'bold');
            doc.setFontSize(20);
            doc.setTextColor(ink[0], ink[1], ink[2]);
            doc.text('794 Analytics', margin, 54);

            doc.setFont('helvetica', 'normal');
            doc.setFontSize(10);
            doc.setTextColor(110, 110, 120);
            var sub = (R.site ? R.site + '  \u00b7  ' : '') + R.date_from + ' \u2013 ' + R.date_to;
            doc.text(sub, margin, 72);
            doc.text('Generated ' + R.generated, margin, 86);

            var startY = 104;
            var filterBits = [];
            if (R.filters) {
                if (R.filters.page) { filterBits.push('Page: ' + R.filters.page); }
                if (R.filters.link) { filterBits.push('Link: ' + R.filters.link); }
                if (R.filters.category) { filterBits.push('Category: ' + R.filters.category); }
            }
            if (filterBits.length) {
                doc.text('Filters \u2014 ' + filterBits.join('    '), margin, 100);
                startY = 116;
            }

            var k = R.kpis || {};
            doc.autoTable({
                startY: startY,
                head: [['Page Views', 'Unique Views', 'Clicks', 'Unique Clicks', 'CTR']],
                body: [[fmt(k.pageviews), fmt(k.unique_pageviews), fmt(k.clicks), fmt(k.unique_clicks), String(k.ctr)]],
                theme: 'grid',
                headStyles: { fillColor: ink, halign: 'center' },
                bodyStyles: { halign: 'center', fontStyle: 'bold', fontSize: 12 },
                margin: { left: margin, right: margin }
            });

            function section(title, head, rows, colStyles) {
                var y = doc.lastAutoTable ? doc.lastAutoTable.finalY + 26 : startY;
                if (y > pageH - 90) { doc.addPage(); y = margin + 10; }
                doc.setFont('helvetica', 'bold');
                doc.setFontSize(12);
                doc.setTextColor(ink[0], ink[1], ink[2]);
                doc.text(title, margin, y);
                doc.autoTable({
                    startY: y + 8,
                    head: [head],
                    body: (rows && rows.length) ? rows : [emptyRow(head.length)],
                    theme: 'striped',
                    headStyles: { fillColor: ink },
                    styles: { fontSize: 8, cellPadding: 4, overflow: 'linebreak' },
                    columnStyles: colStyles || {},
                    margin: { left: margin, right: margin }
                });
            }

            section('Top Pages / Releases',
                ['Page / Release', 'Views', 'Uniq', 'Clicks', 'Uniq', 'CTR', 'URL'],
                (R.top_pages || []).map(function (p) {
                    return [p.title, fmt(p.pageviews), fmt(p.unique_pageviews), fmt(p.clicks), fmt(p.unique_clicks), p.ctr, p.url];
                }),
                { 0: { cellWidth: 120 }, 6: { cellWidth: 150 } }
            );

            section('Top Referrers',
                ['Referrer', 'Campaign', 'Views', 'Uniq Views', 'Clicks', 'Visitors'],
                (R.referrers || []).map(function (r) {
                    return [r.host, (r.campaign === '(none)' ? '\u2014' : r.campaign), fmt(r.pageviews), fmt(r.unique_pageviews), fmt(r.clicks), fmt(r.visitors)];
                }),
                { 0: { cellWidth: 110 }, 1: { cellWidth: 110 } }
            );

            section('Clicked Links',
                ['Clicks', 'Uniq', 'Category', 'Link Text', 'Link URL', 'From Page'],
                (R.top_links || []).map(function (l) {
                    return [fmt(l.clicks), fmt(l.unique), l.category, l.text, l.url, l.from];
                }),
                { 3: { cellWidth: 90 }, 4: { cellWidth: 120 }, 5: { cellWidth: 90 } }
            );

            section('Clicks by Category',
                ['Category', 'Total Clicks', 'Unique Clicks'],
                (R.categories || []).map(function (c) {
                    return [c.category, fmt(c.clicks), fmt(c.unique)];
                })
            );

            section('Navigation Menu',
                ['Menu Item', 'Destination', 'Clicks', 'Uniq'],
                (R.nav_items || []).map(function (n) {
                    return [n.text, n.url, fmt(n.clicks), fmt(n.unique)];
                }),
                { 1: { cellWidth: 160 } }
            );

            section('Internal Links by Section',
                ['Section', 'Clicks', 'Uniq', 'Distinct Links'],
                (R.internal_sections || []).map(function (s) {
                    return [s.section, fmt(s.clicks), fmt(s.unique_clicks), fmt(s.distinct_links)];
                })
            );

            section('Internal Links by Destination',
                ['Section', 'Destination Path', 'Anchor Text', 'Clicks', 'Uniq'],
                (R.internal_paths || []).map(function (p) {
                    return [p.section, p.path, p.text, fmt(p.clicks), fmt(p.unique)];
                }),
                { 1: { cellWidth: 150 }, 2: { cellWidth: 110 } }
            );

            section('Tour Dates',
                ['Event (Location \u2014 Venue \u2014 Date)', 'Action', 'Clicks', 'Uniq'],
                (R.tour_dates || []).map(function (t) {
                    return [t.event, t.action || '', fmt(t.clicks), fmt(t.unique)];
                }),
                { 0: { cellWidth: 260 } }
            );

            var pages = doc.internal.getNumberOfPages();
            doc.setFont('helvetica', 'normal');
            doc.setFontSize(8);
            doc.setTextColor(150, 150, 155);
            for (var i = 1; i <= pages; i++) {
                doc.setPage(i);
                doc.text('794 Analytics', margin, pageH - 20);
                doc.text('Page ' + i + ' of ' + pages, pageW - margin, pageH - 20, { align: 'right' });
            }

            doc.save('backlot-analytics-' + R.date_from + '_to_' + R.date_to + '.pdf');
        } catch (e) {
            window.alert('Could not build the PDF: ' + (e && e.message ? e.message : e));
        } finally {
            if (btn) { btn.disabled = false; btn.textContent = original || 'Download PDF'; }
        }
    };

    window.backlotExportTourDatesPDF = function (btn) {
        var R = window.BACKLOT_REPORT;
        if (!R) {
            window.alert('Report data is not ready yet. Please reload the page and try again.');
            return;
        }
        if (!window.jspdf || !window.jspdf.jsPDF) {
            window.alert('The PDF library failed to load. Check your connection and reload the page.');
            return;
        }

        var original = '';
        if (btn) { original = btn.textContent; btn.disabled = true; btn.textContent = 'Building PDF…'; }

        try {
            var jsPDF = window.jspdf.jsPDF;
            var doc = new jsPDF({ orientation: 'p', unit: 'pt', format: 'a4' });
            var pageW = doc.internal.pageSize.getWidth();
            var pageH = doc.internal.pageSize.getHeight();
            var margin = 40;
            var ink = [24, 24, 38];

            doc.setFont('helvetica', 'bold');
            doc.setFontSize(20);
            doc.setTextColor(ink[0], ink[1], ink[2]);
            doc.text('Tour Dates', margin, 54);

            doc.setFont('helvetica', 'normal');
            doc.setFontSize(10);
            doc.setTextColor(110, 110, 120);
            var sub = (R.site ? R.site + '  \u00b7  ' : '') + R.date_from + ' \u2013 ' + R.date_to;
            doc.text(sub, margin, 72);
            doc.text('Generated ' + R.generated, margin, 86);

            var td = R.tour_dates || [];
            var rows = td.map(function (t) {
                return [t.event, t.action || '', fmt(t.clicks), fmt(t.unique)];
            });

            var totalClicks = 0, totalUniq = 0;
            td.forEach(function (t) {
                totalClicks += (parseInt(t.clicks, 10) || 0);
                totalUniq += (parseInt(t.unique, 10) || 0);
            });

            doc.autoTable({
                startY: 104,
                head: [['Event (Location \u2014 Venue \u2014 Date)', 'Action', 'Clicks', 'Uniq']],
                body: rows.length ? rows : [emptyRow(4)],
                foot: rows.length ? [['Total', '', fmt(totalClicks), fmt(totalUniq)]] : null,
                theme: 'striped',
                headStyles: { fillColor: ink },
                footStyles: { fillColor: [240, 240, 242], textColor: ink, fontStyle: 'bold' },
                styles: { fontSize: 8, cellPadding: 4, overflow: 'linebreak' },
                columnStyles: { 0: { cellWidth: 300 }, 2: { halign: 'right' }, 3: { halign: 'right' } },
                margin: { left: margin, right: margin }
            });

            var pages = doc.internal.getNumberOfPages();
            doc.setFont('helvetica', 'normal');
            doc.setFontSize(8);
            doc.setTextColor(150, 150, 155);
            for (var i = 1; i <= pages; i++) {
                doc.setPage(i);
                doc.text('794 Analytics \u2014 Tour Dates', margin, pageH - 20);
                doc.text('Page ' + i + ' of ' + pages, pageW - margin, pageH - 20, { align: 'right' });
            }

            doc.save('backlot-tour-dates-' + R.date_from + '_to_' + R.date_to + '.pdf');
        } catch (e) {
            window.alert('Could not build the PDF: ' + (e && e.message ? e.message : e));
        } finally {
            if (btn) { btn.disabled = false; btn.textContent = original || 'Download Tour Dates (PDF)'; }
        }
    };
})();
JS;
    }
}

/**
 * Minimal, dependency-free updater that lets WordPress pull new versions of this
 * plugin straight from GitHub releases. Tag a release (e.g. "v4.0.1") whose
 * version is higher than the installed one and every site sees the update in the
 * Plugins screen, with one-click update and optional auto-update support.
 *
 * Repo layout requirement: the main plugin PHP file must sit at the repository
 * ROOT (i.e. /backlot-click-tracker.php in the repo, not inside a subfolder).
 * The updater renames GitHub's source archive back to the plugin's folder slug
 * on install so the plugin keeps its identity (and its tracking data).
 */
class Backlot_GitHub_Updater {

    private $file;
    private $plugin;     // e.g. backlot-click-tracker/backlot-click-tracker.php
    private $slug;       // e.g. backlot-click-tracker
    private $owner;
    private $repo;
    private $token;
    private $cache_key;

    public function __construct($file, $owner, $repo, $token = '') {
        $this->file      = $file;
        $this->owner     = $owner;
        $this->repo      = $repo;
        $this->token     = $token;
        $this->plugin    = plugin_basename($file);
        $this->slug      = dirname($this->plugin);
        $this->cache_key = 'backlot_gh_release_' . md5($this->plugin);

        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api', array($this, 'plugins_api'), 10, 3);
        add_filter('upgrader_source_selection', array($this, 'fix_source_dir'), 10, 4);
        add_filter('http_request_args', array($this, 'maybe_authorize_download'), 10, 2);
        add_action('upgrader_process_complete', array($this, 'clear_cache'), 10, 2);
    }

    private function current_version() {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $data = get_plugin_data($this->file, false, false);

        return isset($data['Version']) ? $data['Version'] : '0.0.0';
    }

    private function normalize_version($tag) {
        return ltrim((string) $tag, 'vV');
    }

    /**
     * Fetch the latest GitHub release, cached for 6 hours so we never hammer the
     * API (which is rate-limited to 60 unauthenticated requests/hour per IP).
     */
    private function get_remote_release() {
        $cached = get_transient($this->cache_key);
        if ($cached !== false) {
            return ($cached === 'none') ? null : $cached;
        }

        $url = "https://api.github.com/repos/{$this->owner}/{$this->repo}/releases/latest";

        $args = array(
            'timeout' => 15,
            'headers' => array(
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'WordPress-794-Analytics-Updater',
            ),
        );

        if ($this->token) {
            $args['headers']['Authorization'] = 'Bearer ' . $this->token;
        }

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            set_transient($this->cache_key, 'none', 6 * HOUR_IN_SECONDS);
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response));

        if (empty($body) || empty($body->tag_name)) {
            set_transient($this->cache_key, 'none', 6 * HOUR_IN_SECONDS);
            return null;
        }

        set_transient($this->cache_key, $body, 6 * HOUR_IN_SECONDS);

        return $body;
    }

    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $release = $this->get_remote_release();
        if (!$release) {
            return $transient;
        }

        $remote_version = $this->normalize_version($release->tag_name);
        $current_version = isset($transient->checked[$this->plugin])
            ? $transient->checked[$this->plugin]
            : $this->current_version();

        $info = array(
            'slug'    => $this->slug,
            'plugin'  => $this->plugin,
            'url'     => "https://github.com/{$this->owner}/{$this->repo}",
            'package' => isset($release->zipball_url) ? $release->zipball_url : '',
        );

        if (version_compare($remote_version, $current_version, '>')) {
            $info['new_version'] = $remote_version;
            $transient->response[$this->plugin] = (object) $info;
        } else {
            $info['new_version'] = $current_version;
            unset($transient->response[$this->plugin]);
            $transient->no_update[$this->plugin] = (object) $info;
        }

        return $transient;
    }

    public function plugins_api($result, $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== $this->slug) {
            return $result;
        }

        $release = $this->get_remote_release();
        if (!$release) {
            return $result;
        }

        return (object) array(
            'name'          => '794 Analytics',
            'slug'          => $this->slug,
            'version'       => $this->normalize_version($release->tag_name),
            'author'        => '<a href="https://github.com/' . esc_attr($this->owner) . '">Porter Media</a>',
            'homepage'      => "https://github.com/{$this->owner}/{$this->repo}",
            'download_link' => isset($release->zipball_url) ? $release->zipball_url : '',
            'sections'      => array(
                'changelog' => !empty($release->body)
                    ? wpautop(wp_kses_post($release->body))
                    : 'See the GitHub releases page for details.',
            ),
        );
    }

    /**
     * GitHub's zipball extracts to a folder like "owner-repo-<sha>". Rename it
     * back to the plugin's real slug so WordPress overwrites the existing
     * install instead of creating a duplicate.
     */
    public function fix_source_dir($source, $remote_source, $upgrader, $hook_extra = null) {
        global $wp_filesystem;

        if (!is_array($hook_extra) || !isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin) {
            return $source;
        }

        if (!$wp_filesystem) {
            return $source;
        }

        $desired = trailingslashit($remote_source) . $this->slug . '/';

        if (untrailingslashit($source) === untrailingslashit($desired)) {
            return $source;
        }

        if ($wp_filesystem->move(untrailingslashit($source), untrailingslashit($desired), true)) {
            return $desired;
        }

        return $source;
    }

    /**
     * For PRIVATE repos, attach the token when WordPress downloads the zipball
     * from the GitHub API. Harmless for public repos (no token set).
     */
    public function maybe_authorize_download($args, $url) {
        if ($this->token && strpos($url, "api.github.com/repos/{$this->owner}/{$this->repo}") !== false) {
            $args['headers']['Authorization'] = 'Bearer ' . $this->token;
        }

        return $args;
    }

    public function clear_cache($upgrader, $options) {
        if (isset($options['action'], $options['type'])
            && $options['action'] === 'update'
            && $options['type'] === 'plugin') {
            delete_transient($this->cache_key);
        }
    }
}

Backlot_Click_Tracker::instance();