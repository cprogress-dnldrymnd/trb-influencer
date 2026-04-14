<?php
/**
 * Plugin Name: Custom myCRED Frontend Log
 * Description: An object-oriented approach to rendering a highly customizable myCRED points log via shortcode.
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 * Version: 1.0.0
 */

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Custom_MyCred_Frontend_Log
 *
 * Handles the registration and rendering of the custom myCRED history shortcode
 * using a modular, object-oriented architecture.
 */
class Custom_MyCred_Frontend_Log {

    /**
     * Initializes the class and hooks the shortcode registration into WordPress.
     *
     * @return void
     */
    public function __construct() {
        add_action( 'init', array( $this, 'register_shortcode' ) );
    }

    /**
     * Registers the custom shortcode with the WordPress API.
     *
     * @return void
     */
    public function register_shortcode() {
        add_shortcode( 'custom_mycred_log', array( $this, 'render_shortcode' ) );
    }

    /**
     * Callback function for the shortcode to process attributes and trigger the renderer.
     *
     * @param array $atts User-defined shortcode attributes.
     * @return string     The buffered HTML output of the points log.
     */
    public function render_shortcode( $atts ) {
        // Parse shortcode attributes with safe default fallbacks
        $args = shortcode_atts( array(
            'user_id' => get_current_user_id(),
            'limit'   => 10,
            'ctype'   => 'mycred_default',
        ), $atts, 'custom_mycred_log' );

        return $this->get_log_html( $args['user_id'], $args['limit'], $args['ctype'] );
    }

    /**
     * Retrieves, parses, and formats the myCRED points history table.
     *
     * @param int    $user_id The ID of the user to query.
     * @param int    $limit   The maximum number of log entries to retrieve.
     * @param string $ctype   The specific point type key to query.
     * @return string         HTML markup containing the custom points log table.
     */
    private function get_log_html( $user_id, $limit, $ctype ) {
        
        // Verify myCRED is active to prevent fatal errors
        if ( ! function_exists( 'mycred' ) ) {
            return '<p class="error">myCRED core functions are not available.</p>';
        }

        $user_id = absint( $user_id );
        
        // Halt execution if the user is unauthenticated or invalid
        if ( ! $user_id ) {
            return '<p class="auth-required">Authentication required to view points history.</p>';
        }

        // Initialize the main myCRED object for the specific point type
        $mycred = mycred( $ctype );

        // Define the query arguments for the log execution
        $query_args = array(
            'user_id' => $user_id,
            'number'  => absint( $limit ),
            'ctype'   => sanitize_key( $ctype )
        );

        // Instantiate the myCRED log query class to fetch entries
        $log = new myCRED_Query_Log( $query_args );

        // Handle empty states gracefully
        if ( ! $log->have_entries() ) {
            return '<div class="mycred-empty-log">No points history found for this account.</div>';
        }

        // Buffer the output for clean shortcode returning
        ob_start();
        ?>
        
        <table class="mycred-custom-log-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Transaction Details</th>
                    <th>Points Impact</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                // Iterate through the retrieved log objects
                foreach ( $log->results as $entry ) : 
                ?>
                    <tr>
                        <td>
                            <?php 
                            // Format the Unix timestamp into the site's localized date format
                            echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $entry->time ) ); 
                            ?>
                        </td>
                        <td>
                            <?php 
                            // Parse any dynamic template tags (like %user_profile_link%) inside the log entry text
                            echo wp_kses_post( $mycred->parse_template_tags( $entry->entry, $entry ) ); 
                            ?>
                        </td>
                        <td class="<?php echo ( $entry->creds > 0 ) ? 'positive-points' : 'negative-points'; ?>">
                            <?php 
                            // Format the numerical value according to myCRED point prefix/suffix settings
                            echo esc_html( $mycred->format_creds( $entry->creds ) ); 
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        return ob_get_clean();
    }
}

// Instantiate the class to initialize the shortcode environment
new Custom_MyCred_Frontend_Log();