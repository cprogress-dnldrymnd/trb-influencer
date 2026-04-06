<?php
/**
 * Plugin Name: DD Global Email Template Manager
 * Plugin URI: https://digitallydisruptive.co.uk/
 * Description: Provides global HTML header and footer wrappers for transactional emails. Seamlessly intercepts and integrates with myCred, Paid Memberships Pro, and Elementor Pro Forms while explicitly isolating DD Outreach Manager payloads.
 * Version: 1.0.0
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly to prevent direct file execution.
}

/**
 * Class DD_Global_Email_Manager
 * Manages the backend settings interface, live preview AJAX architecture, 
 * and strict stack-trace evaluation to wrap supported emails globally.
 */
class DD_Global_Email_Manager
{
    /**
     * Initializes the class, registers hooks, settings, and AJAX endpoints.
     * 
     * @return void
     */
    public function __construct()
    {
        // Backend Admin Menu & Settings
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);

        // Backend AJAX Handler for Live Email Preview
        add_action('wp_ajax_dd_preview_global_email', [$this, 'ajax_preview_global_email']);

        // wp_mail interception for target plugin wrappers
        add_filter('wp_mail', [$this, 'apply_global_email_template'], 9999);
    }

    /**
     * Registers the plugin settings submenu page under options.
     * Utilizes a logically partitioned tabbed interface.
     *
     * @return void
     */
    public function register_admin_menu()
    {
        add_options_page(
            'Global Email Templates',
            'Global Email Templates',
            'manage_options',
            'dd-global-email-settings',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Registers the structural settings and database options for the builder.
     *
     * @return void
     */
    public function register_settings()
    {
        register_setting('dd_global_email_group', 'dd_global_header');
        register_setting('dd_global_email_group', 'dd_global_footer');
    }

    /**
     * Helper to return default header boilerplate HTML.
     * 
     * @return string
     */
    private function get_default_header()
    {
        return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{site_name}</title>
</head>
<body style="margin:0;padding:20px;background-color:#F5F5F5;color:#333;font-family:sans-serif;">
    <div style="background:#fff; max-width:600px; margin:0 auto; padding:30px; border-radius:8px; border-top: 4px solid #034146;">
        <h1 style="margin-top:0;">{site_name}</h1>
        <hr style="border:0; border-bottom:1px solid #eee; margin-bottom: 20px;">
        <!-- Email Body Content Begins Here -->';
    }

    /**
     * Helper to return default footer boilerplate HTML.
     * 
     * @return string
     */
    private function get_default_footer()
    {
        return '
        <!-- Email Body Content Ends Here -->
        <br><br>
        <hr style="border:0; border-bottom:1px solid #eee; margin-bottom: 20px;">
        <p style="font-size:12px; color:#888;">&copy; ' . date('Y') . ' {site_name}. All rights reserved.<br>
        <a href="{site_url}" style="color:#034146;">Visit our website</a></p>
    </div>
</body>
</html>';
    }

    /**
     * Enqueues administration scripts and styles strictly for the settings page.
     * Handles live HTML preview rendering, tab switching, and merge tag injection.
     *
     * @param string $hook The current admin page hook.
     * @return void
     */
    public function enqueue_admin_scripts($hook)
    {
        if (strpos($hook, 'dd-global-email-settings') === false) {
            return;
        }

        wp_enqueue_script('jquery');

        $custom_js = "
        jQuery(document).ready(function($) {
            
            // --- 1. Tab Switcher Logic ---
            $('.nav-tab').on('click', function(e) {
                e.preventDefault();
                $('.nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                $('.dd-tab-content').hide();
                var target = $(this).attr('href');
                $(target).show();
            });

            // --- 2. Merge Tag Injection & Editor Tracking ---
            var lastFocusedElement = null;

            $('#dd_header_editor, #dd_footer_editor').on('focus', function() {
                lastFocusedElement = this;
            });

            $('.dd-merge-tag').on('click', function(e) {
                e.preventDefault();
                var tag = $(this).data('tag');
                
                if (lastFocusedElement) {
                    var txtarea = $(lastFocusedElement);
                    var val = txtarea.val();
                    var start = lastFocusedElement.selectionStart;
                    var end = lastFocusedElement.selectionEnd;
                    
                    txtarea.val(val.substring(0, start) + tag + val.substring(end));
                    triggerPreviewUpdate();
                    
                    txtarea.focus();
                    lastFocusedElement.selectionEnd = start + tag.length;
                } else {
                    alert('Please click inside the header or footer field to insert a merge tag.');
                }
            });

            // --- 3. Live Preview AJAX Logic ---
            var previewTimer;
            function triggerPreviewUpdate() {
                var headerContent = $('#dd_header_editor').val();
                var footerContent = $('#dd_footer_editor').val();

                $.post(ajaxurl, {
                    action: 'dd_preview_global_email',
                    security: ddGlobalAdmin.nonce,
                    header: headerContent,
                    footer: footerContent
                }, function(response) {
                    if (response.success) {
                        var iframe = document.getElementById('dd-global-preview-iframe');
                        var doc = iframe.contentWindow.document;
                        doc.open();
                        doc.write(response.data);
                        doc.close();
                    }
                });
            }

            // Update preview as the user types
            $('#dd_header_editor, #dd_footer_editor').on('keyup change', function() {
                clearTimeout(previewTimer);
                previewTimer = setTimeout(triggerPreviewUpdate, 500);
            });

            // Render on page load
            setTimeout(triggerPreviewUpdate, 300);

        });
        ";

        wp_add_inline_script('jquery-core', $custom_js);
        wp_localize_script('jquery-core', 'ddGlobalAdmin', [
            'nonce' => wp_create_nonce('dd_global_admin_nonce')
        ]);
    }

    /**
     * AJAX endpoint to render the live HTML preview of the global template.
     * Parses the header and footer, injects dummy content, and evaluates merge tags.
     *
     * @return void
     */
    public function ajax_preview_global_email()
    {
        check_ajax_referer('dd_global_admin_nonce', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $header = isset($_POST['header']) ? wp_unslash($_POST['header']) : '';
        $footer = isset($_POST['footer']) ? wp_unslash($_POST['footer']) : '';
        
        $dummy_body = '<div style="padding: 20px 0;">
            <h2>Preview Notification</h2>
            <p>This is a simulated email body representing the localized payload generated by myCred, PMPro, or Elementor Forms.</p>
            <p>The layout and typography boundaries are structurally inherited from your global wrapper definitions.</p>
        </div>';

        $full_html = $header . "\n" . $dummy_body . "\n" . $footer;

        $dictionary = [
            '{site_name}' => get_bloginfo('name'),
            '{site_url}'  => get_bloginfo('url'),
        ];

        $final_html = str_replace(array_keys($dictionary), array_values($dictionary), $full_html);

        wp_send_json_success($final_html);
    }

    /**
     * Intercepts wp_mail arguments at execution. 
     * Scrutinizes the execution stack trace to inject wrappers exclusively for 
     * Elementor Pro Forms, myCred, and Paid Memberships Pro.
     * 
     * @param array $args The native wp_mail arguments array.
     * @return array Modified wp_mail arguments structurally wrapped in global UI.
     */
    public function apply_global_email_template($args)
    {
        // 1. Analyze execution stack to dynamically identify the origin of the wp_mail call.
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $should_wrap = false;
        $is_outreach = false;

        foreach ($backtrace as $trace) {
            $class = $trace['class'] ?? '';
            $function = $trace['function'] ?? '';
            $file = $trace['file'] ?? '';

            // Strict Exclusion Constraint: Abort immediately if dispatched by DD Outreach Manager.
            if ($class === 'DD_Outreach_Manager') {
                $is_outreach = true;
                break;
            }

            // Target Validation: Elementor Pro Forms
            if (strpos($class, 'ElementorPro\Modules\Forms') !== false || strpos($class, 'ElementorPro\Modules\Forms\Classes\Action_Base') !== false) {
                $should_wrap = true;
            }

            // Target Validation: myCred
            if (strpos($class, 'myCRED_Email') !== false || strpos($function, 'mycred_') === 0 || strpos($file, 'mycred') !== false) {
                $should_wrap = true;
            }

            // Target Validation: Paid Memberships Pro (PMPro)
            if (strpos($class, 'PMProEmail') !== false || strpos($function, 'pmpro_') === 0 || strpos($file, 'paid-memberships-pro') !== false) {
                $should_wrap = true;
            }
        }

        if ($is_outreach) {
            return $args; // Escape modification context.
        }

        // If a supported integration is verified, assemble and wrap the payload.
        if ($should_wrap) {
            $message = is_array($args['message']) ? implode("\n", $args['message']) : $args['message'];
            
            $header = get_option('dd_global_header', $this->get_default_header());
            $footer = get_option('dd_global_footer', $this->get_default_footer());
            
            $full_html = $header . "\n" . $message . "\n" . $footer;
            
            $dictionary = [
                '{site_name}' => get_bloginfo('name'),
                '{site_url}'  => get_bloginfo('url'),
            ];
            
            $args['message'] = str_replace(array_keys($dictionary), array_values($dictionary), $full_html);
            $args['headers'] = $this->enforce_html_headers($args['headers'] ?? []);
        }

        return $args;
    }

    /**
     * Forces standard HTML content type headers if not explicitly assigned by the upstream plugin.
     * 
     * @param array|string $headers Original headers object array/string.
     * @return array Normalized headers array ensuring text/html specification.
     */
    private function enforce_html_headers($headers) 
    {
        if (empty($headers)) {
            return ['Content-Type: text/html; charset=UTF-8'];
        }

        if (is_string($headers)) {
            $headers = explode("\n", str_replace("\r\n", "\n", $headers));
        }

        $has_content_type = false;
        foreach ($headers as $header) {
            if (stripos($header, 'Content-Type') !== false) {
                $has_content_type = true;
                break;
            }
        }

        if (!$has_content_type) {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
        }

        return $headers;
    }

    /**
     * Renders the Backend Settings Page HTML via a logical tabbed component view.
     *
     * @return void
     */
    public function render_settings_page()
    {
        $header_html = get_option('dd_global_header', $this->get_default_header());
        $footer_html = get_option('dd_global_footer', $this->get_default_footer());
?>
        <div class="wrap">
            <h1>Global Email Templates</h1>

            <h2 class="nav-tab-wrapper">
                <a href="#tab-email-builder" class="nav-tab nav-tab-active">Email Builder</a>
                <a href="#tab-settings" class="nav-tab">Integration Context</a>
            </h2>

            <div id="tab-email-builder" class="dd-tab-content" style="margin-top:20px;">
                <form method="post" action="options.php">
                    <?php settings_fields('dd_global_email_group'); ?>

                    <div style="display: flex; gap: 30px; align-items: flex-start; flex-wrap: wrap;">
                        <div style="flex: 1.5; min-width: 500px;">
                            <h3>Wrapper Design Structure</h3>
                            
                            <div style="margin-bottom: 15px; background: #fff; padding: 10px; border: 1px solid #ccc; border-radius: 4px;">
                                <strong>Supported Merge Tags (Click field to focus, then click tag to insert):</strong><br>
                                <button type="button" class="button button-small dd-merge-tag" data-tag="{site_name}">{site_name}</button>
                                <button type="button" class="button button-small dd-merge-tag" data-tag="{site_url}">{site_url}</button>
                            </div>

                            <div style="margin-bottom: 20px;">
                                <label style="display:block; font-weight:600; margin-bottom:5px;">Header HTML Sequence</label>
                                <textarea id="dd_header_editor" name="dd_global_header" style="width: 100%; height: 250px; font-family: monospace; font-size: 13px; line-height: 1.5; padding: 15px; border-radius: 4px; border: 1px solid #8c8f94;" dir="ltr"><?php echo esc_textarea($header_html); ?></textarea>
                            </div>

                            <div style="margin-bottom: 20px;">
                                <label style="display:block; font-weight:600; margin-bottom:5px;">Footer HTML Sequence</label>
                                <textarea id="dd_footer_editor" name="dd_global_footer" style="width: 100%; height: 200px; font-family: monospace; font-size: 13px; line-height: 1.5; padding: 15px; border-radius: 4px; border: 1px solid #8c8f94;" dir="ltr"><?php echo esc_textarea($footer_html); ?></textarea>
                            </div>

                            <hr style="margin: 20px 0;">
                            <?php submit_button('Save Template Settings', 'primary', 'submit', false); ?>
                        </div>

                        <div style="flex: 1; min-width: 400px; position: sticky; top: 40px;">
                            <h3>Live Framework Preview</h3>
                            <p>Reflects the architectural merge state against dummy payload simulation.</p>
                            <div style="border: 1px solid #ccc; border-radius: 8px; overflow: hidden; background: #EBEBEB; padding: 0; box-shadow: 0 4px 10px rgba(0,0,0,0.1);">
                                <iframe id="dd-global-preview-iframe" style="width: 100%; height: 600px; border: none;" src="about:blank"></iframe>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <div id="tab-settings" class="dd-tab-content" style="display:none; margin-top:20px;">
                <div style="background: #fff; padding: 20px; border: 1px solid #ccc; border-radius: 4px; max-width: 800px;">
                    <h3>System Scope & Integrity</h3>
                    <p>This extension dynamically monitors the WordPress <code>wp_mail</code> stack trace at execution. To enforce layout isolation and prevent dependency collisions, wrappers are <strong>only compiled</strong> for targeted modules:</p>
                    <ul style="list-style-type: square; padding-left: 20px;">
                        <li><strong>Paid Memberships Pro</strong> [PMProEmail]</li>
                        <li><strong>myCred System Operations</strong> [myCRED_Email]</li>
                        <li><strong>Elementor Pro Form Routines</strong> [ElementorPro\Modules\Forms]</li>
                    </ul>
                    <hr>
                    <p style="color: #d63638;"><strong>Constraint Acknowledgement:</strong> Notifications specifically initiated by the standalone <em>DD Outreach Manager</em> plugin are programmatically excluded from these global settings ensuring isolated framework compliance.</p>
                </div>
            </div>
        </div>
    <?php
    }
}

new DD_Global_Email_Manager();