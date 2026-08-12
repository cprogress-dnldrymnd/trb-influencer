<?php

/**
 * Plugin Name: Onboarding
 * Description: Welcome popup + step-by-step guided tour for new members, plus a "Getting
 *              started" checklist. Admin-authored (Influencer Theme → Onboarding); all copy
 *              is editable under Influencer Theme → Messages.
 * Author: Digitally Disruptive
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Welcome popup + guided tour + "Getting started" checklist.
 *
 * Progress is tracked per-user in `_dd_onboarding_state` user meta — the same underscore-
 * prefixed, update_user_meta()-backed convention every other theme-written flag uses (see
 * `_dd_company_trial_restricted` in pmpro-company-trial.php). The whole feature can be
 * switched off without a code change via `dd_onboarding_enabled` (Functionality tab), the
 * same on/off posture as `dd_gate_use_popup` in page-gate.php.
 */
class DD_Onboarding
{
    const STATE_META_KEY = '_dd_onboarding_state';
    const STEPS_OPTION    = 'dd_onboarding_steps';
    const NONCE_ACTION    = 'dd_onboarding_nonce';

    public function __construct()
    {
        add_action('admin_init', [$this, 'register_settings']);
        add_filter('dd_theme_settings_tabs', [$this, 'register_tab']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        add_action('wp_ajax_dd_onboarding_state', [$this, 'handle_ajax_state']);

        add_action('init', [$this, 'register_shortcode']);

        // Seed state on new/changed membership so the welcome popup still fires for a user
        // who reaches the dashboard by a route other than the post-signup redirect (e.g.
        // logging in later). Idempotent — never overwrites an existing state. The two PMPro
        // hooks disagree on argument order (pmpro_after_checkout passes $user_id first;
        // pmpro_after_change_membership_level passes $level_id first — see
        // dd_company_trial_after_checkout()/dd_company_trial_after_level_change() in
        // pmpro-company-trial.php for the same distinction), so each gets its own callback.
        add_action('pmpro_after_checkout', [$this, 'seed_state_on_checkout'], 20, 1);
        add_action('pmpro_after_change_membership_level', [$this, 'seed_state_on_level_change'], 20, 2);

        add_action('admin_init', [$this, 'maybe_backfill_existing_users']);
    }

    // -----------------------------------------------------------------
    // State helpers
    // -----------------------------------------------------------------

    /**
     * @param int|null $user_id
     * @return array{welcome_seen:bool, tour_step:int, tour_completed:bool, dismissed_at:int}
     */
    public static function get_state($user_id = null)
    {
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        $defaults = [
            'welcome_seen'   => false,
            'tour_step'      => 0,
            'tour_completed' => false,
            'dismissed_at'   => 0,
        ];

        if (! $user_id) {
            return $defaults;
        }

        $state = get_user_meta($user_id, self::STATE_META_KEY, true);
        return is_array($state) ? array_merge($defaults, $state) : $defaults;
    }

    /**
     * @param int   $user_id
     * @param array $patch
     * @return array The merged state that was saved.
     */
    public static function update_state($user_id, $patch)
    {
        $user_id = (int) $user_id;
        if (! $user_id) {
            return self::get_state();
        }

        $state = array_merge(self::get_state($user_id), $patch);
        update_user_meta($user_id, self::STATE_META_KEY, $state);
        return $state;
    }

    /**
     * Seeds a fresh state only when one doesn't already exist — never re-triggers the
     * welcome popup for an account that has already seen/dismissed it.
     *
     * @param int $user_id
     * @return void
     */
    private function seed_state($user_id)
    {
        $user_id = (int) $user_id;
        if (! $user_id) {
            return;
        }

        $existing = get_user_meta($user_id, self::STATE_META_KEY, true);
        if (is_array($existing)) {
            return;
        }

        update_user_meta($user_id, self::STATE_META_KEY, [
            'welcome_seen'   => false,
            'tour_step'      => 0,
            'tour_completed' => false,
            'dismissed_at'   => 0,
        ]);
    }

    /**
     * pmpro_after_checkout($user_id, $morder) — $user_id is the first argument.
     *
     * @param int $user_id
     * @return void
     */
    public function seed_state_on_checkout($user_id)
    {
        $this->seed_state($user_id);
    }

    /**
     * pmpro_after_change_membership_level($level_id, $user_id, $cancel_level = null) —
     * $user_id is the SECOND argument here, unlike pmpro_after_checkout above.
     *
     * @param int $level_id
     * @param int $user_id
     * @return void
     */
    public function seed_state_on_level_change($level_id, $user_id)
    {
        $this->seed_state($user_id);
    }

    /**
     * One-time backfill so existing members aren't interrupted by the welcome popup the
     * first time this feature is switched on — mirrors dd_company_trial_backfill()
     * (modules/membership-extensions/pmpro-company-trial.php), gated by an option flag.
     *
     * @return void
     */
    public function maybe_backfill_existing_users()
    {
        if (get_option('dd_onboarding_backfill_done')) {
            return;
        }

        $user_ids = get_users(['fields' => 'ID']);
        foreach ($user_ids as $user_id) {
            $existing = get_user_meta($user_id, self::STATE_META_KEY, true);
            if (is_array($existing)) {
                continue;
            }
            update_user_meta($user_id, self::STATE_META_KEY, [
                'welcome_seen'   => true,
                'tour_step'      => 0,
                'tour_completed' => true,
                'dismissed_at'   => time(),
            ]);
        }

        update_option('dd_onboarding_backfill_done', 1);
    }

    // -----------------------------------------------------------------
    // Steps registry
    // -----------------------------------------------------------------

    /**
     * @return array<int, array{id:string,title:string,body:string,target:string,placement:string,page:string,cta_label:string,cta_url:string}>
     */
    public static function get_steps()
    {
        $raw = get_option(self::STEPS_OPTION, '');
        if (empty($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Which step "page" bucket the current request is on, so the tour only shows steps
     * relevant to where the visitor actually is.
     *
     * @return string dashboard|search|results|any
     */
    public static function current_page_key()
    {
        if (function_exists('dd_get_page_id')) {
            if (is_page(dd_get_page_id('dd_dashboard_page_id', 1565))) {
                return 'dashboard';
            }
            if (is_page(dd_get_page_id('dd_search_page_id', 2149))) {
                return 'search';
            }
            if (is_page(dd_get_page_id('dd_search_results_page_id', 1949))) {
                return 'results';
            }
        }
        return 'any';
    }

    // -----------------------------------------------------------------
    // Front-end payload
    // -----------------------------------------------------------------

    /**
     * Localized onto the dd-onboarding JS handle. Copy strings are read separately via the
     * existing `dd_messages` global (dd_msg_ob_*, includes/core/messages-settings.php) so
     * onboarding wording stays in the one place every other editable string lives.
     *
     * @return array
     */
    public static function client_map()
    {
        if (! is_user_logged_in() || ! self::is_enabled()) {
            return ['enabled' => false];
        }

        $current_page = self::current_page_key();
        $steps = array_values(array_filter(self::get_steps(), function ($step) use ($current_page) {
            $page = isset($step['page']) ? $step['page'] : 'any';
            return $page === 'any' || $page === $current_page;
        }));

        $search_page_url = function_exists('dd_get_page_id')
            ? get_permalink(dd_get_page_id('dd_search_page_id', 2149))
            : home_url('/');

        return [
            'enabled'          => true,
            'ajax_url'         => admin_url('admin-ajax.php'),
            'nonce'            => wp_create_nonce(self::NONCE_ACTION),
            'welcome_url'      => get_option('dd_onboarding_welcome_url', '') ?: $search_page_url,
            'show_tour_button' => (bool) get_option('dd_onboarding_show_tour_button', true) && ! empty($steps),
            'steps'            => $steps,
            'state'            => self::get_state(),
            'current_page'     => $current_page,
        ];
    }

    public static function is_enabled()
    {
        return (bool) get_option('dd_onboarding_enabled', true);
    }

    // -----------------------------------------------------------------
    // AJAX
    // -----------------------------------------------------------------

    public function handle_ajax_state()
    {
        check_ajax_referer(self::NONCE_ACTION, 'security');

        $user_id = get_current_user_id();
        if (! $user_id) {
            wp_send_json_error(['message' => 'Not logged in.']);
        }

        $event = isset($_POST['event']) ? sanitize_key($_POST['event']) : '';
        $patch = [];

        switch ($event) {
            case 'welcome_seen':
                $patch = ['welcome_seen' => true];
                break;
            case 'tour_progress':
                $patch = ['welcome_seen' => true, 'tour_step' => isset($_POST['step']) ? absint($_POST['step']) : 0];
                break;
            case 'tour_completed':
                $patch = ['welcome_seen' => true, 'tour_completed' => true];
                break;
            case 'dismiss':
                $patch = ['welcome_seen' => true, 'tour_completed' => true, 'dismissed_at' => time()];
                break;
            default:
                wp_send_json_error(['message' => 'Unknown event.']);
        }

        $state = self::update_state($user_id, $patch);
        wp_send_json_success(['state' => $state]);
    }

    // -----------------------------------------------------------------
    // "Getting started" checklist shortcode
    // -----------------------------------------------------------------

    public function register_shortcode()
    {
        add_shortcode('onboarding_checklist', [$this, 'render_checklist_shortcode']);
    }

    /**
     * [onboarding_checklist]
     *
     * A persistent "what have I done / what's left" list, self-ticking from data that
     * already exists (no new tracking) — answers "where am I in the process", which a
     * one-shot welcome popup can't.
     */
    public function render_checklist_shortcode($atts)
    {
        if (! is_user_logged_in()) {
            return '';
        }

        $search_count    = function_exists('number_of_searches') ? (int) number_of_searches() : 0;
        $saved_count     = count((array) get_saved_influencer());
        $unlocked_count  = function_exists('get_user_purchased_post_ids') ? count((array) get_user_purchased_post_ids()) : 0;
        $outreach_count  = count((array) get_outreach());

        $search_url = function_exists('dd_get_page_id') ? get_permalink(dd_get_page_id('dd_search_page_id', 2149)) : home_url('/');

        $items = [
            [
                'label' => 'Run your first search',
                'done'  => $search_count > 0,
                'url'   => $search_url,
                'feature' => '',
            ],
            [
                'label' => 'Save a creator to a list',
                'done'  => $saved_count > 0,
                'url'   => $search_url,
                'feature' => 'saved_lists',
            ],
            [
                'label' => 'Unlock a creator profile',
                'done'  => $unlocked_count > 0,
                'url'   => $search_url,
                'feature' => '',
            ],
            [
                'label' => 'Contact a creator',
                'done'  => $outreach_count > 0,
                'url'   => $search_url,
                'feature' => 'outreach',
            ],
        ];

        ob_start();
    ?>
        <ul class="dd-onboarding-checklist">
            <?php foreach ($items as $item) :
                $locked = ! empty($item['feature']) && function_exists('dd_user_can') && ! dd_user_can($item['feature']);
            ?>
                <li class="dd-onboarding-checklist__item<?php echo $item['done'] ? ' is-done' : ''; ?><?php echo $locked ? ' is-locked' : ''; ?>">
                    <span class="dd-onboarding-checklist__check" aria-hidden="true"><?php echo $item['done'] ? '&#10003;' : ''; ?></span>
                    <?php if ($locked) : ?>
                        <a class="dd-onboarding-checklist__label" href="<?php echo esc_url(function_exists('dd_plan_upgrade_url') ? dd_plan_upgrade_url() : $search_url); ?>"><?php echo esc_html($item['label']); ?></a>
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="currentColor" class="bi bi-lock-fill inf-btn-lock-icon" viewBox="0 0 16 16">
                            <path d="M8 1a2 2 0 0 1 2 2v4H6V3a2 2 0 0 1 2-2zm3 6V3a3 3 0 0 0-6 0v4a2 2 0 0 0-2 2v5a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z" />
                        </svg>
                    <?php elseif (! $item['done']) : ?>
                        <a class="dd-onboarding-checklist__label" href="<?php echo esc_url($item['url']); ?>"><?php echo esc_html($item['label']); ?></a>
                    <?php else : ?>
                        <span class="dd-onboarding-checklist__label"><?php echo esc_html($item['label']); ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <style>
            .dd-onboarding-checklist {
                list-style: none;
                margin: 0;
                padding: 0;
                display: flex;
                flex-direction: column;
                gap: 10px;
            }
            .dd-onboarding-checklist__item {
                display: flex;
                align-items: center;
                gap: 10px;
                font-size: 14px;
                color: #4b4b4b;
            }
            .dd-onboarding-checklist__check {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 20px;
                height: 20px;
                flex: 0 0 auto;
                border-radius: 50%;
                border: 1.5px solid #cbd5e1;
                font-size: 12px;
                color: #fff;
            }
            .dd-onboarding-checklist__item.is-done .dd-onboarding-checklist__check {
                background: var(--e-global-color-primary, #034146);
                border-color: var(--e-global-color-primary, #034146);
            }
            .dd-onboarding-checklist__item.is-done .dd-onboarding-checklist__label {
                color: #9ca3af;
                text-decoration: line-through;
            }
            .dd-onboarding-checklist__label {
                color: inherit;
                text-decoration: none;
            }
            a.dd-onboarding-checklist__label:hover {
                text-decoration: underline;
            }
            .dd-onboarding-checklist__item.is-locked {
                opacity: var(--dd-lock-opacity, 0.65);
            }
        </style>
    <?php
        return ob_get_clean();
    }

    // -----------------------------------------------------------------
    // Settings — Onboarding tab
    // -----------------------------------------------------------------

    public function register_tab($tabs)
    {
        $tabs[] = [
            'id'     => 'onboarding',
            'label'  => 'Onboarding',
            'render' => [$this, 'render_tab_panel'],
        ];
        return $tabs;
    }

    public function register_settings()
    {
        register_setting('dd_theme_page_ids', 'dd_onboarding_enabled', [
            'type'              => 'boolean',
            'sanitize_callback' => function ($value) {
                return ! empty($value);
            },
            'default' => true,
        ]);

        add_settings_field('dd_onboarding_enabled', 'Onboarding', function () {
        ?>
            <label>
                <input type="checkbox" name="dd_onboarding_enabled" value="1" <?php checked(DD_Onboarding::is_enabled()); ?>>
                Show the welcome popup and guided tour to new members.
            </label>
<?php
        }, 'dd-theme-settings-functionality', 'dd_functionality_section');

        // The Onboarding tab renders its own self-contained <form>/settings group, per the
        // dd_theme_settings_tabs convention (see includes/core/admin-settings.php).
        register_setting('dd_onboarding_group', 'dd_onboarding_welcome_url', [
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default'           => '',
        ]);
        register_setting('dd_onboarding_group', 'dd_onboarding_show_tour_button', [
            'type'              => 'boolean',
            'sanitize_callback' => function ($value) {
                return ! empty($value);
            },
            'default' => true,
        ]);
        register_setting('dd_onboarding_group', self::STEPS_OPTION, [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitize_steps'],
            'default'           => '[]',
        ]);
    }

    /**
     * @param string $raw JSON-encoded array posted by the hidden #dd-ob-steps-input field.
     * @return string Re-encoded, validated JSON.
     */
    public function sanitize_steps($raw)
    {
        $decoded = json_decode((string) $raw, true);
        if (! is_array($decoded)) {
            return '[]';
        }

        $clean = [];
        foreach ($decoded as $step) {
            if (! is_array($step) || empty($step['title'])) {
                continue;
            }
            $page = isset($step['page']) ? sanitize_key($step['page']) : 'any';
            if (! in_array($page, ['any', 'dashboard', 'search', 'results'], true)) {
                $page = 'any';
            }
            $placement = isset($step['placement']) ? sanitize_key($step['placement']) : 'bottom';
            if (! in_array($placement, ['top', 'bottom', 'left', 'right'], true)) {
                $placement = 'bottom';
            }
            $clean[] = [
                'id'        => isset($step['id']) && $step['id'] !== '' ? sanitize_key($step['id']) : uniqid('step_'),
                'title'     => sanitize_text_field($step['title']),
                'body'      => isset($step['body']) ? sanitize_textarea_field($step['body']) : '',
                'target'    => isset($step['target']) ? sanitize_text_field($step['target']) : '',
                'placement' => $placement,
                'page'      => $page,
                'cta_label' => isset($step['cta_label']) ? sanitize_text_field($step['cta_label']) : '',
                'cta_url'   => isset($step['cta_url']) && $step['cta_url'] !== '' ? esc_url_raw($step['cta_url']) : '',
            ];
        }

        return wp_json_encode(array_values($clean));
    }

    public function enqueue_admin_assets($hook)
    {
        if ('toplevel_page_dd-theme-settings' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'dd-onboarding-admin',
            get_stylesheet_directory_uri() . '/modules/onboarding/assets/onboarding-admin.css',
            [],
            defined('HELLO_ELEMENTOR_CHILD_VERSION') ? HELLO_ELEMENTOR_CHILD_VERSION : false
        );
        wp_enqueue_script(
            'dd-onboarding-admin',
            get_stylesheet_directory_uri() . '/modules/onboarding/assets/onboarding-admin.js',
            ['jquery', 'jquery-ui-sortable'],
            defined('HELLO_ELEMENTOR_CHILD_VERSION') ? HELLO_ELEMENTOR_CHILD_VERSION : false,
            true
        );
        wp_localize_script('dd-onboarding-admin', 'dd_onboarding_admin', [
            'steps' => self::get_steps(),
        ]);
    }

    /**
     * Self-contained "Onboarding" tab panel body — the hub (includes/core/admin-settings.php)
     * provides the surrounding <div class="dd-panel">. Every selector in
     * modules/onboarding/assets/onboarding-admin.js is scoped to #dd-panel-onboarding, per the
     * shared-DOM convention documented in CLAUDE.md (several module tabs now live in one page).
     */
    public function render_tab_panel()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $steps = self::get_steps();
    ?>
        <p class="dd-tab-desc">
            Configure the welcome popup shown to new members on the dashboard, and the
            step-by-step guided tour that follows it. Popup/button copy (titles, body text,
            button labels) is edited under Influencer Theme → Messages → Onboarding.
        </p>
        <form action="options.php" method="post" id="dd-onboarding-form">
            <?php settings_fields('dd_onboarding_group'); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Start Search Button URL</th>
                    <td>
                        <input type="url" name="dd_onboarding_welcome_url" value="<?php echo esc_attr(get_option('dd_onboarding_welcome_url', '')); ?>" class="regular-text" placeholder="<?php echo esc_attr(function_exists('dd_get_page_id') ? get_permalink(dd_get_page_id('dd_search_page_id', 2149)) : ''); ?>">
                        <p class="description">Leave blank to use the configured Search Form Page.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Guided Tour</th>
                    <td>
                        <label>
                            <input type="checkbox" name="dd_onboarding_show_tour_button" value="1" <?php checked((bool) get_option('dd_onboarding_show_tour_button', true)); ?>>
                            Show a "<?php echo esc_html(function_exists('dd_get_message') ? dd_get_message('dd_msg_ob_tour_cta') : 'Show me around first'); ?>" button on the welcome popup.
                        </label>
                    </td>
                </tr>
            </table>

            <h3>Tour Steps</h3>
            <p class="description">Each step highlights one element on the page. "Target" is a CSS selector (e.g. <code>#search-header</code>) — a step is skipped automatically if its target isn't found on the page, so it's safe to author steps for elements a template edit might later remove.</p>

            <div id="dd-ob-steps-list"></div>
            <p><button type="button" class="button" id="dd-ob-add-step">Add Step</button></p>

            <input type="hidden" name="<?php echo esc_attr(self::STEPS_OPTION); ?>" id="dd-ob-steps-input" value="<?php echo esc_attr(wp_json_encode($steps)); ?>">

            <?php submit_button('Save Onboarding Settings'); ?>
        </form>
<?php
    }
}

new DD_Onboarding();
