<?php
/**
 * Dompdf Manual Installation Wrapper and Service
 *
 * @package    WordPressTheme
 * @author     Digitally Disruptive - Donald Raymundo
 * @author URI https://digitallydisruptive.co.uk/
 */

if ( ! class_exists( 'Dompdf_Service' ) ) {

    /**
     * Singleton service to manage Dompdf instantiation via manual Git installation.
     */
    class Dompdf_Service {

        /**
         * The single instance of the class.
         *
         * @var Dompdf_Service|null
         */
        private static $instance = null;

        /**
         * Retrieve the main service instance.
         *
         * @return Dompdf_Service
         */
        public static function get_instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        /**
         * Constructor. Hooks the autoloader into the WordPress lifecycle early.
         */
        private function __construct() {
            add_action( 'after_setup_theme', [ $this, 'load_native_dependencies' ] );
        }

        /**
         * Requires the native Dompdf autoloader designed for manual Git installations.
         *
         * Maps to autoload.inc.php instead of vendor/autoload.php to utilize 
         * the manually cloned dependencies within the dompdf/lib/ directory.
         *
         * @return void
         */
        public function load_native_dependencies() {
            // Adjust the base path if dompdf is located inside a subdirectory like /inc/ or /assets/
            $dompdf_autoloader = get_template_directory() . '/dompdf/autoload.inc.php';

            if ( file_exists( $dompdf_autoloader ) ) {
                require_once $dompdf_autoloader;
            } else {
                error_log( 'Dompdf native autoloader missing at: ' . $dompdf_autoloader );
            }
        }

        /**
         * Factory method to generate a configured Dompdf instance.
         *
         * Centralizes the configuration so the engine behaves consistently 
         * across all custom endpoints or template renders.
         *
         * @return \Dompdf\Dompdf|null Returns the engine, or null if loading failed.
         */
        public function get_engine() {
            if ( ! class_exists( '\Dompdf\Dompdf' ) ) {
                return null;
            }

            $options = new \Dompdf\Options();
            
            // Required for rendering modern HTML structures safely
            $options->set( 'isHtml5ParserEnabled', true );
            
            // Required to load external CSS stylesheets and image assets
            $options->set( 'isRemoteEnabled', true );

            return new \Dompdf\Dompdf( $options );
        }
    }
}

// Initialize the singleton to register the setup hooks.
Dompdf_Service::get_instance();