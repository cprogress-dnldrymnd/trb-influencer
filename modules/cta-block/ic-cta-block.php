<?php

/**
 * Plugin Name: IC CTA Block
 * Description: Global in-article call-to-action block, configured from its own admin page and dropped into any article via [ic_cta].
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 * Version: 1.0.0
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Class DD_IC_CTA_Block
 *
 * Registers a top-level admin page for a single global CTA card, and renders
 * it anywhere via the [ic_cta] shortcode. Deliberately not part of the
 * Settings → Influencer Theme screen — this is editorial/marketing content,
 * not influencer-app configuration.
 */
class DD_IC_CTA_Block
{
    const OPTION = 'dd_ic_cta';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_shortcode('ic_cta', [$this, 'render_shortcode']);
    }

    /**
     * Registers the top-level "IC CTA Block" admin page.
     * @return void
     */
    public function register_admin_menu()
    {
        add_menu_page(
            'IC CTA Block',
            'IC CTA Block',
            'manage_options',
            'dd-ic-cta',
            [$this, 'render_admin_page'],
            'dashicons-megaphone',
            58
        );
    }

    /**
     * Registers the dd_ic_cta option with the Settings API.
     * @return void
     */
    public function register_settings()
    {
        register_setting('dd_ic_cta_group', self::OPTION, [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
            'default'           => $this->defaults(),
        ]);
    }

    /**
     * Default field values (also the screenshot's placeholder copy).
     * @return array
     */
    private function defaults()
    {
        return [
            'heading'        => 'Lorem ipsum <em>dolor sit amet</em> consectetur adipisicing elit.',
            'body'           => 'Lorem ipsum dolor sit amet consectetur adipisicing elit. Optio, consectetur voluptates odio quos voluptatem ipsa in animi fuga. Culpa quod maxime quae amet dolores. Adipisci odio harum rerum facilis error.',
            'button_label'   => 'Click Here',
            'button_url'     => '',
            'button_new_tab' => '0',
        ];
    }

    /**
     * Reads the saved settings merged over defaults.
     * @return array
     */
    private function get_settings()
    {
        $saved = get_option(self::OPTION, []);
        if (! is_array($saved)) {
            $saved = [];
        }
        return wp_parse_args($saved, $this->defaults());
    }

    /**
     * Settings API sanitize callback for self::OPTION.
     * @param mixed $input Raw posted value.
     * @return array Sanitized settings array.
     */
    public function sanitize($input)
    {
        if (! is_array($input)) {
            $input = [];
        }

        $allowed_heading = [
            'em'     => [],
            'i'      => [],
            'strong' => [],
            'b'      => [],
            'br'     => [],
        ];
        $allowed_body = $allowed_heading + [
            'p' => [],
            'a' => [
                'href'   => true,
                'target' => true,
                'rel'    => true,
            ],
        ];

        return [
            'heading'        => isset($input['heading']) ? wp_kses(trim($input['heading']), $allowed_heading) : '',
            'body'           => isset($input['body']) ? wp_kses(trim($input['body']), $allowed_body) : '',
            'button_label'   => isset($input['button_label']) ? sanitize_text_field($input['button_label']) : '',
            'button_url'     => isset($input['button_url']) ? esc_url_raw($input['button_url']) : '',
            'button_new_tab' => ! empty($input['button_new_tab']) ? '1' : '0',
        ];
    }

    /**
     * Renders the admin settings page: form + live preview.
     * @return void
     */
    public function render_admin_page()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $settings = $this->get_settings();
?>
        <div class="wrap">
            <h1><?php esc_html_e('IC CTA Block', 'hello-elementor-child'); ?></h1>
            <p>
                <?php esc_html_e('Drop this block into any article with the shortcode:', 'hello-elementor-child'); ?>
                <code>[ic_cta]</code>
            </p>

            <form method="post" action="options.php">
                <?php settings_fields('dd_ic_cta_group'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="dd_ic_cta_heading"><?php esc_html_e('Heading', 'hello-elementor-child'); ?></label></th>
                        <td>
                            <textarea id="dd_ic_cta_heading" name="<?php echo esc_attr(self::OPTION); ?>[heading]" rows="3" class="large-text"><?php echo esc_textarea($settings['heading']); ?></textarea>
                            <p class="description"><?php esc_html_e('Wrap any part in <em>…</em> to italicise it, exactly like the design.', 'hello-elementor-child'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="dd_ic_cta_body"><?php esc_html_e('Body text', 'hello-elementor-child'); ?></label></th>
                        <td>
                            <textarea id="dd_ic_cta_body" name="<?php echo esc_attr(self::OPTION); ?>[body]" rows="4" class="large-text"><?php echo esc_textarea($settings['body']); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="dd_ic_cta_button_label"><?php esc_html_e('Button label', 'hello-elementor-child'); ?></label></th>
                        <td>
                            <input type="text" id="dd_ic_cta_button_label" name="<?php echo esc_attr(self::OPTION); ?>[button_label]" value="<?php echo esc_attr($settings['button_label']); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="dd_ic_cta_button_url"><?php esc_html_e('Button URL', 'hello-elementor-child'); ?></label></th>
                        <td>
                            <input type="url" id="dd_ic_cta_button_url" name="<?php echo esc_attr(self::OPTION); ?>[button_url]" value="<?php echo esc_attr($settings['button_url']); ?>" class="regular-text" placeholder="https://" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Open in new tab', 'hello-elementor-child'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[button_new_tab]" value="1" <?php checked($settings['button_new_tab'], '1'); ?> />
                                <?php esc_html_e('Open the button link in a new tab', 'hello-elementor-child'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save CTA Block'); ?>
            </form>

            <h2><?php esc_html_e('Preview', 'hello-elementor-child'); ?></h2>
            <div style="max-width: 800px; background: #f0f0f1; padding: 24px; border: 1px solid #dcdcde;">
                <?php echo $this->render_block($settings); ?>
            </div>
        </div>
<?php
    }

    /**
     * Shortcode callback for [ic_cta].
     * @return string
     */
    public function render_shortcode()
    {
        return $this->render_block($this->get_settings());
    }

    /**
     * Renders the CTA card markup and (once per request) its scoped CSS.
     * @param array $settings
     * @return string
     */
    private function render_block($settings)
    {
        $heading = $settings['heading'];
        $body    = $settings['body'];

        if (trim(wp_strip_all_tags($heading)) === '' && trim(wp_strip_all_tags($body)) === '') {
            return '';
        }

        $show_button = ! empty($settings['button_label']) && ! empty($settings['button_url']);
        $new_tab     = ! empty($settings['button_new_tab']) && $settings['button_new_tab'] === '1';

        ob_start();
        $this->render_styles();
?>
        <div class="ic-cta">
            <?php if ($heading !== '') : ?>
                <p class="ic-cta__heading"><?php echo wp_kses_post($heading); ?></p>
            <?php endif; ?>
            <?php if ($body !== '') : ?>
                <p class="ic-cta__body"><?php echo wp_kses_post(do_shortcode($body)); ?></p>
            <?php endif; ?>
            <?php if ($show_button) : ?>
                <a class="ic-cta__btn" href="<?php echo esc_url($settings['button_url']); ?>" <?php echo $new_tab ? 'target="_blank" rel="noopener"' : ''; ?>>
                    <?php echo esc_html($settings['button_label']); ?>
                </a>
            <?php endif; ?>
        </div>
<?php
        return ob_get_clean();
    }

    /**
     * Prints the block's scoped CSS once per request.
     * @return void
     */
    private function render_styles()
    {
        static $printed = false;
        if ($printed) {
            return;
        }
        $printed = true;
?>
        <style>
            .ic-cta {
                background-color: var(--e-global-color-primary, #034146);
                border-radius: 12px;
                padding: clamp(28px, 4vw, 56px);
                margin: 40px 0;
            }

            .ic-cta__heading {
                font-family: "Playfair Display", Georgia, serif;
                font-size: clamp(28px, 3.4vw, 48px);
                line-height: 1.2;
                font-weight: 500;
                color: var(--e-global-color-3868d1d, #FFDBD1);
                margin: 0 0 20px;
            }

            .ic-cta__heading em {
                font-style: italic;
            }

            .ic-cta__body {
                font-family: "Work Sans", Inter, sans-serif;
                font-size: clamp(15px, 1.1vw, 18px);
                line-height: 1.6;
                color: var(--e-global-color-3868d1d, #FFDBD1);
                max-width: 62ch;
                margin: 0 0 28px;
            }

            .ic-cta__btn.ic-cta__btn {
                display: inline-block;
                background-color: var(--e-global-color-accent, #F77D67);
                color: var(--e-global-color-2ba2932, #FFFFFF) !important;
                font-family: Inter, sans-serif;
                font-size: 16px;
                font-weight: 600;
                letter-spacing: 0.4px;
                text-decoration: none !important;
                text-align: center;
                padding: 14px 28px;
                border-radius: 5px;
                transition: background-color 400ms;
            }

            .ic-cta__btn:hover,
            .ic-cta__btn:focus-visible {
                background-color: var(--e-global-color-secondary, #3B1527);
                color: var(--e-global-color-2ba2932, #FFFFFF) !important;
            }

            .ic-cta__btn:focus-visible {
                outline: 2px solid var(--e-global-color-3868d1d, #FFDBD1);
                outline-offset: 2px;
            }

            @media (max-width: 767px) {
                .ic-cta {
                    padding: 32px 24px;
                }

                .ic-cta__heading {
                    margin-bottom: 16px;
                }

                .ic-cta__body {
                    margin-bottom: 20px;
                }
            }
        </style>
<?php
    }
}

new DD_IC_CTA_Block();
