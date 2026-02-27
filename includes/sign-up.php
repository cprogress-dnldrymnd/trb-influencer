<?php

/**
 * Plugin Name: PMPro AJAX Signup Form
 * Plugin URI:  https://digitallydisruptive.co.uk/
 * Description: Converts the PMPro Signup form into an AJAX-driven form via inline JS, strictly preventing redirects to the main checkout page on validation errors. Includes custom avatar and required acceptance fields.
 * Version:     1.0.3
 * Author:      Digitally Disruptive - Donald Raymundo
 * Author URI:  https://digitallydisruptive.co.uk/
 * License:     GPL-2.0+
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (! class_exists('DD_PMPro_Ajax_Signup')) {

    /**
     * Core plugin class for handling PMPro AJAX Signup integrations.
     *
     * This class encapsulates the hooks and methods required to extend
     * the Paid Memberships Pro signup form with AJAX validation via inline
     * JavaScript and custom profile fields, utilizing an object-oriented architecture.
     */
    class DD_PMPro_Ajax_Signup
    {

        /**
         * Plugin version identifier.
         *
         * @var string
         */
        private $version = '1.0.3';

        /**
         * Initializes the class and registers WordPress hooks.
         *
         * The constructor binds the required actions to their respective
         * WordPress lifecycle hooks to inject inline scripts into the footer
         * and register PMPro user fields upon instantiation.
         *
         * @return void
         */
        public function __construct()
        {
            add_action('wp_footer', array($this, 'inject_inline_script'));
            add_action('init', array($this, 'add_avatar_field'));
            add_action('init', array($this, 'add_acceptance_field'));
        }

        /**
         * Injects the necessary JavaScript for AJAX form validation directly into the footer.
         *
         * Hooked to 'wp_footer', this method ensures the AJAX handler 
         * is loaded inline only when Paid Memberships Pro is active on the site.
         * It captures form submissions, processes them silently, and intercepts
         * error redirects to keep the user on the signup shortcode page.
         *
         * @return void
         */
        public function inject_inline_script()
        {
            // Verify PMPro is active before injecting script payloads.
            if (! function_exists('pmpro_url')) {
                return;
            }
            if (is_page(4144)) {
?>
                <script type="text/javascript">
                    /**
                     * PMPro AJAX Signup Form Handler
                     * Intercepts the checkout submission, processses the POST request silently,
                     * and dynamically injects validation errors back into the shortcode UI 
                     * without allowing the browser to redirect to the main checkout page.
                     */
                    document.addEventListener('DOMContentLoaded', function() {

                        const form = document.getElementById('pmpro_form');
                        if (!form) return;

                        form.addEventListener('submit', async function(e) {
                            e.preventDefault();

                            const submitBtn = form.querySelector('input[type="submit"], button[type="submit"], #pmpro_btn-submit');
                            if (!submitBtn) {
                                form.submit();
                                return;
                            }

                            const originalText = submitBtn.value || submitBtn.textContent || 'Processing...';

                            if (submitBtn.tagName.toLowerCase() === 'input') {
                                submitBtn.value = 'Processing...';
                            } else {
                                submitBtn.textContent = 'Processing...';
                            }
                            submitBtn.disabled = true;

                            const formData = new FormData(form);

                            if (!formData.has('submit-checkout')) {
                                formData.append('submit-checkout', '1');
                            }

                            // Target the form's native action URL (typically /membership-checkout/)
                            const fetchUrl = form.action || window.location.href;

                            try {
                                // Execute POST request. redirect: 'follow' ensures the Fetch API 
                                // transparently resolves the 302 redirect returning the final HTML.
                                const response = await fetch(fetchUrl, {
                                    method: 'POST',
                                    body: formData,
                                    redirect: 'follow'
                                });

                                // Parse the virtual DOM from the final resolved URL
                                const html = await response.text();
                                const parser = new DOMParser();
                                const doc = parser.parseFromString(html, 'text/html');

                                const pmproMessage = doc.querySelector('#pmpro_message, .pmpro_error');
                                const existingMessage = document.querySelector('#pmpro_message, .pmpro_error');

                                // Evaluate if the resulting page contains a validation error
                                if (pmproMessage && pmproMessage.classList.contains('pmpro_error')) {

                                    // Inject the error message dynamically into the current /sign-up/ page
                                    if (existingMessage) {
                                        existingMessage.replaceWith(pmproMessage);
                                    } else {
                                        form.parentNode.insertBefore(pmproMessage, form);
                                    }

                                    // Restore button state
                                    if (submitBtn.tagName.toLowerCase() === 'input') {
                                        submitBtn.value = originalText;
                                    } else {
                                        submitBtn.textContent = originalText;
                                    }
                                    submitBtn.disabled = false;

                                    pmproMessage.scrollIntoView({
                                        behavior: 'smooth',
                                        block: 'center'
                                    });

                                } else {
                                    // No errors detected. Safely process the success redirect.
                                    if (response.redirected) {
                                        window.location.href = response.url;
                                    } else if (doc.querySelector('.pmpro_confirmation_wrap')) {
                                        const signupWrap = document.querySelector('.pmpro_signup_wrap') || form.parentNode;
                                        signupWrap.replaceWith(doc.querySelector('.pmpro_confirmation_wrap'));
                                    } else {
                                        // Failsafe fallback
                                        window.location.href = fetchUrl;
                                    }
                                }

                            } catch (error) {
                                console.error('PMPro AJAX Validation Error:', error);
                                form.removeEventListener('submit', arguments.callee);
                                form.submit();
                            }
                        });
                    });
                </script>
<?php
            }
        }

        /**
         * Adds a custom user avatar field to the PMPro signup form.
         *
         * Utilizing the PMPro Field API, this method registers a 'user_avatar' 
         * file input within the 'profile' field group.
         *
         * @return void
         */
        public function add_avatar_field()
        {
            if (! function_exists('pmpro_add_user_field')) {
                return;
            }

            $field = new PMPro_Field(
                'user_avatar',
                'file',
                array(
                    'label'        => 'Profile Picture',
                    'profile'      => true,
                    'preview'      => true,
                    'allow_delete' => true,
                    'hint'         => 'Recommended size: 200x200 pixels.'
                )
            );

            pmpro_add_user_field('profile', $field);
        }

        /**
         * Adds a required acceptance checkbox field to the PMPro signup form.
         *
         * Fetches the native WordPress Privacy Policy URL and the specific 
         * Terms of Use page URL (via ID) to generate an HTML string for the checkbox option.
         * Uses the 'text' parameter for single-checkbox inline rendering.
         * Enforces strict frontend and backend validation by setting the field to required.
         *
         * @return void
         */
        public function add_acceptance_field()
        {
            if (! function_exists('pmpro_add_user_field')) {
                return;
            }

            // Retrieve the dynamic URLs for the policy pages
            $privacy_url = get_privacy_policy_url();
            $terms_url   = get_permalink(10501);

            // Construct the HTML label containing the required target="_blank" links
            $label_html = sprintf(
                'I have read and agree to the <a href="%s" target="_blank">Privacy Policy</a> and <a href="%s" target="_blank">Terms of Use</a>.',
                esc_url($privacy_url),
                esc_url($terms_url)
            );

            // Instantiate the PMPro Checkbox Field
            $field = new PMPro_Field(
                'terms_acceptance',
                'checkbox',
                array(
                    'label'    => 'Agreements',
                    'text'     => $label_html, // Use 'text' instead of 'options' array for a single boolean checkbox
                    'required' => true,        // Enforces validation on checkout
                    'profile'  => false,       // Keeps this out of the user profile edit screen
                )
            );

            // Attach to the 'profile' field group on checkout so it renders naturally in the form
            pmpro_add_user_field('profile', $field);
        }
    }

    new DD_PMPro_Ajax_Signup();
}