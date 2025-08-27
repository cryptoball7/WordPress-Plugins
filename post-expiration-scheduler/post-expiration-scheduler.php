<?php
/*
Plugin Name: Post Expiration Scheduler
Description: Adds an "expire date" to posts and automatically hides/unpublishes them when the date passes. Uses post meta, WP-Cron and conditional queries.
Version: 1.0
Author: Cryptoball cryptoball7@gmail.ocm
Text Domain: post-expiration-scheduler
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PES_Post_Expiration_Scheduler {

    const META_KEY = '_pes_expire';
    const CRON_HOOK = 'pes_check_expired_hook';
    const CRON_INTERVAL = 'hourly'; // can change to 'twicedaily' or register custom intervals

    public function __construct() {
        // Admin UI
        add_action( 'add_meta_boxes', array( $this, 'add_expire_meta_box' ) );
        add_action( 'save_post', array( $this, 'save_expire_meta' ), 10, 2 );

        // Front-end filtering
        add_action( 'pre_get_posts', array( $this, 'exclude_expired_in_query' ) );

        // Cron
        add_action( 'init', array( $this, 'maybe_schedule_cron' ) );
        add_action( self::CRON_HOOK, array( $this, 'cron_handle_expired' ) );

        // Activation / Deactivation hooks
        register_activation_hook( __FILE__, array( __CLASS__, 'on_activation' ) );
        register_deactivation_hook( __FILE__, array( __CLASS__, 'on_deactivation' ) );
    }

    /* ------------------ Meta box + save ------------------ */

    public function add_expire_meta_box() {
        add_meta_box(
            'pes_expire_date',
            __( 'Expire Date', 'post-expiration-scheduler' ),
            array( $this, 'render_meta_box' ),
            null, // post types: use null to show on all public post types; change to array('post') if you want only posts
            'side',
            'high'
        );
    }

    public function render_meta_box( $post ) {
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            return;
        }

        wp_nonce_field( 'pes_save_expire', 'pes_save_expire_nonce' );

        $gmt_ts = get_post_meta( $post->ID, self::META_KEY, true );
        $value = '';

        if ( $gmt_ts ) {
            // convert stored GMT timestamp to site-local formatted value for datetime-local input
            // get_date_from_gmt expects a GMT formatted string and returns local time formatted
            $gmt_mysql = gmdate( 'Y-m-d H:i:s', intval( $gmt_ts ) );
            $local_formatted = get_date_from_gmt( $gmt_mysql, 'Y-m-d\TH:i' ); // HTML5 datetime-local format
            $value = esc_attr( $local_formatted );
        }

        echo '<label for="pes_expire_field">' . esc_html__( 'Set a date/time when this post should expire (local time):', 'post-expiration-scheduler' ) . '</label><br/>';
        echo '<input type="datetime-local" id="pes_expire_field" name="pes_expire_field" value="' . $value . '" style="width:100%"/>';
        echo '<p style="font-size: 0.9em; color: #555; margin-top:6px;">' . esc_html__( 'Leave empty to disable expiration for this post.', 'post-expiration-scheduler' ) . '</p>';
    }

    public function save_expire_meta( $post_id, $post ) {
        // check autosave, permissions, nonce
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! isset( $_POST['pes_save_expire_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pes_save_expire_nonce'] ) ), 'pes_save_expire' ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( isset( $_POST['pes_expire_field'] ) && $_POST['pes_expire_field'] !== '' ) {
            $raw = sanitize_text_field( wp_unslash( $_POST['pes_expire_field'] ) ); // expects "YYYY-MM-DDTHH:MM"
            // Convert local datetime to GMT timestamp robustly using site's timezone setting if possible:
            $local_str = str_replace( 'T', ' ', $raw ); // "YYYY-MM-DD HH:MM"
            $tz_string = get_option( 'timezone_string' );
            if ( $tz_string ) {
                try {
                    $dt = new DateTime( $local_str, new DateTimeZone( $tz_string ) );
                } catch ( Exception $e ) {
                    $dt = new DateTime( $local_str ); // fallback
                }
            } else {
                // timezone_string may be empty if site uses offset; construct from gmt_offset
                $offset = get_option( 'gmt_offset', 0 );
                $hours = (int) $offset;
                $minutes = ( $offset - $hours ) * 60;
                $sign = $offset >= 0 ? '+' : '-';
                $tz = sprintf( '%s%02d:%02d', $sign, abs( $hours ), abs( $minutes ) );
                try {
                    $dt = new DateTime( $local_str, new DateTimeZone( $tz ) );
                } catch ( Exception $e ) {
                    $dt = new DateTime( $local_str );
                }
            }
            // convert to GMT
            $dt->setTimezone( new DateTimeZone( 'GMT' ) );
            $gmt_ts = $dt->getTimestamp();

            update_post_meta( $post_id, self::META_KEY, intval( $gmt_ts ) );
        } else {
            // empty -> remove meta
            delete_post_meta( $post_id, self::META_KEY );
        }
    }

    /* ------------------ Query filtering ------------------ */

    public function exclude_expired_in_query( $query ) {
        if ( is_admin() || ! $query->is_main_query() ) {
            return;
        }

        // Only apply to public queries (home, archives, single public posts), but not REST or XMLRPC.
        // We'll apply broadly on frontend so expired posts don't appear anywhere on front-end.
        $now = time(); // current server time -> it's fine because we store GMT timestamps
        // meta_query: allow posts that either have no expire meta OR meta > now
        $meta_query = array(
            'relation' => 'OR',
            array(
                'key'     => self::META_KEY,
                'compare' => 'NOT EXISTS',
            ),
            array(
                'key'     => self::META_KEY,
                'value'   => $now,
                'type'    => 'NUMERIC',
                'compare' => '>',
            ),
        );

        // Merge with existing meta_query if any
        $existing = $query->get( 'meta_query' );
        if ( ! $existing ) {
            $query->set( 'meta_query', $meta_query );
        } else {
            // Nest the existing queries and the expiration check under an AND so we preserve other filters
            $combined = array( 'relation' => 'AND', $existing, $meta_query );
            $query->set( 'meta_query', $combined );
        }
    }

    /* ------------------ Cron scheduling & handler ------------------ */

    public function maybe_schedule_cron() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), self::CRON_INTERVAL, self::CRON_HOOK );
        }
    }

    public function cron_handle_expired() {
        // get current GMT timestamp
        $now = time();

        // Query for published posts with expire meta <= now
        $args = array(
            'post_type'      => 'any',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'     => self::META_KEY,
                    'value'   => $now,
                    'compare' => '<=',
                    'type'    => 'NUMERIC',
                ),
            ),
            'no_found_rows'  => true,
        );

        $expired_ids = get_posts( $args );

        if ( ! empty( $expired_ids ) ) {
            foreach ( $expired_ids as $pid ) {
                /**
                 * Default behavior: set to 'draft'.
                 * If you want to make this configurable, change the status or provide a filter.
                 */
                $new_status = apply_filters( 'pes_expired_post_status', 'draft', $pid );

                // gracefully update post_status; preserve post_type and other fields
                $updated = wp_update_post( array(
                    'ID'          => $pid,
                    'post_status' => $new_status,
                ), true );

                if ( is_wp_error( $updated ) ) {
                    // Log error for debugging (if WP_DEBUG_LOG is enabled)
                    error_log( 'PES: Failed to update post ' . $pid . ': ' . $updated->get_error_message() );
                } else {
                    /**
                     * Optional action after a post expires. Good for notifications / logging.
                     * do_action( 'pes_post_expired', $pid, $new_status );
                     */
                }
            }
        }
    }

    /* ------------------ Activation / Deactivation ------------------ */

    public static function on_activation() {
        // schedule if not scheduled
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), self::CRON_INTERVAL, self::CRON_HOOK );
        }
    }

    public static function on_deactivation() {
        // clear scheduled event
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }

}

new PES_Post_Expiration_Scheduler();
