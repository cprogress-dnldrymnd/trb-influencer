<?php
/**
 * Plugin Name: PMPro AJAX Signup Form
 * Plugin URI:  https://digitallydisruptive.co.uk/
 * Description: Converts the PMPro Signup form into an AJAX-driven form, preventing page reloads on validation errors while retaining native file upload support.
 * Version:     1.0.0
 * Author:      Digitally Disruptive - Donald Raymundo
 * Author URI:  https://digitallydisruptive.co.uk/
 * License:     GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

if ( ! class_exists( 'DD_PMPro_Ajax_Signup' ) ) {

    /**
     * Core plugin class for handling PMPro AJAX Signup integrations.
     *
     * This class encapsulates the hooks and methods required to extend
     * the Paid Memberships Pro signup form with AJAX validation and
     * custom profile fields (like avatars), utilizing an object-oriented
     * architecture.
     */
    class DD_PMPro_Ajax_Signup {

        /**
         * Plugin version identifier.
         *
         * @var string
         */
        private $version = '1.0.0';

        /**
         * Initializes the class and registers WordPress hooks.
         *
         * The constructor binds the required actions to their respective
         * WordPress lifecycle hooks to enqueue scripts and register
         * PMPro user fields upon instantiation.
         *
         * @return void
         */
        public function __construct() {
            add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_ajax_script' ) );
            add_action( 'init', array( $this, 'add_avatar_field' ) );
        }

        /**
         * Enqueues the necessary JavaScript for AJAX form validation.
         *
         * Hooked to 'wp_enqueue_scripts', this method ensures the AJAX handler 
         * is loaded only when Paid Memberships Pro is active on the site.
         *
         * @return void
         */
        public function enqueue_ajax_script() {
            // Verify PMPro is active before enqueuing script payloads.
            if ( function_exists( 'pmpro_url' ) ) {
                wp_enqueue_script(
                    'dd-pmpro-ajax-signup',
                    plugin_dir_url( __FILE__ ) . 'assets/js/pmpro-ajax-signup.js',
                    array(), // Vanilla JS, no jQuery dependency required
                    $this->version,
                    true
                );
            }
        }

        /**
         * Adds a custom user avatar field to the PMPro signup form.
         *
         * Utilizing the PMPro Field API, this method registers a 'user_avatar' 
         * file input within the 'profile' field group, allowing users to upload 
         * an image during registration.
         *
         * @return void
         */
        public function add_avatar_field() {
            // Check if PMPro is active
            if ( ! function_exists( 'pmpro_add_user_field' ) ) {
                return;
            }

            // Define the avatar field
            $field = new PMPro_Field(
                'user_avatar', // Meta key used by some avatar plugins
                'file',        // Field type
                array(
                    'label'        => 'Profile Picture',
                    'profile'      => true,      // Show on frontend profile
                    'preview'      => true,      // Show image preview
                    'allow_delete' => true,      // Allow deletion
                    'hint'         => 'Recommended size: 200x200 pixels.'
                )
            );

            // Add to the 'profile' group
            pmpro_add_user_field( 'profile', $field );
        }
    }

    // Instantiate the class to boot up the plugin functionality.
    new DD_PMPro_Ajax_Signup();
}