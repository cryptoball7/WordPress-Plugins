<?php
/*
Plugin Name: Content Expiry & Scheduler
Plugin URI:  https://example.com/
Description: Auto-expires outdated posts or schedules updates with reminders. Add expiry date, choose expiry action (Draft/Expired/Trash), and set reminder emails.
Version:     1.0.0
Author:      Cryptoball cryptoball7@gmail.com
Text Domain: content-expiry-scheduler
Domain Path: /languages
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CES_Content_Expiry_Scheduler {

    const VERSION = '1.0.0';
    const OPTION_KEY = 'ces_settings';
    const META_EXPIRY = '_ces_expiry_timestamp';
    const META_EXPIRY_ACTION = '_ces_expiry_action';
    const META_REMINDER_DAYS = '_ces_reminder_days';
    const META_REMINDER_SENT = '_ces_reminder_sent';
    const META_UPDATE_TIMESTAMP = '_ces_update_timestamp';

    public function __construct() {
        // Init
        add_action( 'init', array( $this, 'register_expired_status' ) );
        add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
        add_action( 'save_post', array( $this, 'save_post_meta' ), 10, 2 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'ces_do_expire_post', array( $this, 'do_expire_post' ), 10, 1 );
        add_action( 'ces_send_reminder', array( $this, 'send_reminder' ), 10, 1 );
        add_action( 'ces_do_scheduled_update', array( $this, 'do_scheduled_update' ), 10, 1 );
        register_activation_hook( __FILE__, array( $this, 'on_activation' ) );
        register_deactivation_hook( __FILE__, array( $this, 'on_deactivation' ) );
        add_filter( 'post_row_actions', array( $this, 'post_row_actions' ), 10, 2 );
        add_filter( 'display_post_states', array( $this, 'display_expired_state' ), 10, 2 );
        add_action( 'admin_notices', array( $this, 'admin_notices' ) );
        load_plugin_textdomain( 'content-expiry-scheduler', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

    /* ---------- Post status ---------- */
    public function register_expired_status() {
        register_post_status( 'expired', array(
            'label'                     => _x( 'Expired', 'post' , 'content-expiry-scheduler' ),
            'public'                    => false,
            'internal'                  => false,
            'exclude_from_search'       => true,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Expired <span class="count">(%s)</span>', 'Expired <span class="count">(%s)</span>' ),
        ) );
    }

    /* ---------- Admin meta box ---------- */
    public function register_meta_box() {
        $screens = array( 'post', 'page' );
        foreach ( $screens as $screen ) {
            add_meta_box(
                'ces_expiry_scheduler',
                __( 'Content Expiry & Scheduler', 'content-expiry-scheduler' ),
                array( $this, 'render_meta_box' ),
                $screen,
                'side',
                'default'
            );
        }
    }

    public function render_meta_box( $post ) {
        wp_nonce_field( 'ces_save_meta', 'ces_meta_nonce' );

        $expiry_ts = get_post_meta( $post->ID, self::META_EXPIRY, true );
        $action = get_post_meta( $post->ID, self::META_EXPIRY_ACTION, true );
        $reminder_days = get_post_meta( $post->ID, self::META_REMINDER_DAYS, true );
        $update_ts = get_post_meta( $post->ID, self::META_UPDATE_TIMESTAMP, true );

        $expiry_val = $expiry_ts ? date( 'Y-m-d\TH:i', (int) $expiry_ts ) : '';
        $update_val = $update_ts ? date( 'Y-m-d\TH:i', (int) $update_ts ) : '';

        $settings = $this->get_settings();
        $default_action = isset( $settings['default_action'] ) ? $settings['default_action'] : 'draft';
        $default_reminder = isset( $settings['default_reminder_days'] ) ? intval( $settings['default_reminder_days'] ) : 3;

        if ( $action === '' ) {
            $action = $default_action;
        }
        if ( $reminder_days === '' ) {
            $reminder_days = $default_reminder;
        }

        ?>
        <p>
            <label for="ces_expiry"><?php _e( 'Expiry date & time', 'content-expiry-scheduler' ); ?></label><br/>
            <input type="datetime-local" id="ces_expiry" name="ces_expiry" value="<?php echo esc_attr( $expiry_val ); ?>" />
        </p>

        <p>
            <label for="ces_action"><?php _e( 'On expiry', 'content-expiry-scheduler' ); ?></label><br/>
            <select id="ces_action" name="ces_action">
                <option value="draft" <?php selected( $action, 'draft' ); ?>><?php _e( 'Change to Draft', 'content-expiry-scheduler' ); ?></option>
                <option value="expired" <?php selected( $action, 'expired' ); ?>><?php _e( 'Mark as Expired (custom status)', 'content-expiry-scheduler' ); ?></option>
                <option value="trash" <?php selected( $action, 'trash' ); ?>><?php _e( 'Move to Trash', 'content-expiry-scheduler' ); ?></option>
            </select>
        </p>

        <p>
            <label for="ces_reminder"><?php _e( 'Reminder (days before expiry)', 'content-expiry-scheduler' ); ?></label><br/>
            <input type="number" id="ces_reminder" name="ces_reminder" value="<?php echo esc_attr( $reminder_days ); ?>" min="0" style="width:80px" />
            <br/><small><?php _e( 'Set 0 to disable reminders for this post.', 'content-expiry-scheduler' ); ?></small>
        </p>

        <hr/>

        <p>
            <label for="ces_update"><?php _e( 'Schedule update/reminder date & time', 'content-expiry-scheduler' ); ?></label><br/>
            <input type="datetime-local" id="ces_update" name="ces_update" value="<?php echo esc_attr( $update_val ); ?>" />
            <br/><small><?php _e( 'Will send a reminder email to the post author on this date/time.', 'content-expiry-scheduler' ); ?></small>
        </p>

        <?php
    }

    /* ---------- Save meta, schedule events ---------- */
    public function save_post_meta( $post_id, $post ) {
        // Autosave, permission, nonce checks
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( wp_is_post_revision( $post_id ) ) return;
        if ( ! isset( $_POST['ces_meta_nonce'] ) || ! wp_verify_nonce( $_POST['ces_meta_nonce'], 'ces_save_meta' ) ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        // Expiry
        $expiry_raw = isset( $_POST['ces_expiry'] ) ? sanitize_text_field( wp_unslash( $_POST['ces_expiry'] ) ) : '';
        $expiry_ts = $expiry_raw ? strtotime( $expiry_raw ) : 0;

        if ( $expiry_ts ) {
            update_post_meta( $post_id, self::META_EXPIRY, $expiry_ts );
        } else {
            delete_post_meta( $post_id, self::META_EXPIRY );
            delete_post_meta( $post_id, self::META_EXPIRY_ACTION );
            delete_post_meta( $post_id, self::META_REMINDER_DAYS );
            delete_post_meta( $post_id, self::META_REMINDER_SENT );
        }

        // Action
        $action = isset( $_POST['ces_action'] ) ? sanitize_text_field( wp_unslash( $_POST['ces_action'] ) ) : '';
        if ( $action ) {
            update_post_meta( $post_id, self::META_EXPIRY_ACTION, $action );
        }

        // Reminder days
        $reminder = isset( $_POST['ces_reminder'] ) ? intval( $_POST['ces_reminder'] ) : 0;
        if ( $reminder > 0 ) {
            update_post_meta( $post_id, self::META_REMINDER_DAYS, $reminder );
        } else {
            update_post_meta( $post_id, self::META_REMINDER_DAYS, 0 );
        }

        // Scheduled update/reminder date
        $update_raw = isset( $_POST['ces_update'] ) ? sanitize_text_field( wp_unslash( $_POST['ces_update'] ) ) : '';
        $update_ts = $update_raw ? strtotime( $update_raw ) : 0;
        if ( $update_ts ) {
            update_post_meta( $post_id, self::META_UPDATE_TIMESTAMP, $update_ts );
        } else {
            delete_post_meta( $post_id, self::META_UPDATE_TIMESTAMP );
        }

        // Now schedule/unschedule events:
        $this->schedule_post_events( $post_id );
    }

    protected function schedule_post_events( $post_id ) {
        // Clear any existing scheduled hooks for this post ID by checking timestamp meta stored in hook args
        // For reliability we unschedule single events by searching for existing events with matching hook and args.
        // Expiry
        $expiry_ts = get_post_meta( $post_id, self::META_EXPIRY, true );
        if ( $expiry_ts && $expiry_ts > time() ) {
            // schedule expiry
            if ( ! wp_next_scheduled( 'ces_do_expire_post', array( $post_id ) ) ) {
                wp_schedule_single_event( (int) $expiry_ts, 'ces_do_expire_post', array( $post_id ) );
            } else {
                // reschedule if existing is at different time — easiest path is to clear and reschedule
                $this->reschedule_single_event( 'ces_do_expire_post', array( $post_id ), (int) $expiry_ts );
            }
            // schedule reminder before expiry (if configured)
            $reminder_days = intval( get_post_meta( $post_id, self::META_REMINDER_DAYS, true ) );
            if ( $reminder_days > 0 ) {
                $reminder_ts = (int) $expiry_ts - ( $reminder_days * DAY_IN_SECONDS );
                if ( $reminder_ts > time() ) {
                    if ( ! wp_next_scheduled( 'ces_send_reminder', array( $post_id ) ) ) {
                        wp_schedule_single_event( (int) $reminder_ts, 'ces_send_reminder', array( $post_id ) );
                        delete_post_meta( $post_id, self::META_REMINDER_SENT );
                    } else {
                        $this->reschedule_single_event( 'ces_send_reminder', array( $post_id ), (int) $reminder_ts );
                    }
                }
            }
        } else {
            // No expiry => remove scheduled events if any
            $this->unschedule_event_for_post( 'ces_do_expire_post', $post_id );
            $this->unschedule_event_for_post( 'ces_send_reminder', $post_id );
            delete_post_meta( $post_id, self::META_REMINDER_SENT );
        }

        // Scheduled update/reminder
        $update_ts = get_post_meta( $post_id, self::META_UPDATE_TIMESTAMP, true );
        if ( $update_ts && $update_ts > time() ) {
            if ( ! wp_next_scheduled( 'ces_do_scheduled_update', array( $post_id ) ) ) {
                wp_schedule_single_event( (int) $update_ts, 'ces_do_scheduled_update', array( $post_id ) );
            } else {
                $this->reschedule_single_event( 'ces_do_scheduled_update', array( $post_id ), (int) $update_ts );
            }
        } else {
            $this->unschedule_event_for_post( 'ces_do_scheduled_update', $post_id );
        }
    }

    protected function unschedule_event_for_post( $hook, $post_id ) {
        $timestamp = wp_next_scheduled( $hook, array( $post_id ) );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, $hook, array( $post_id ) );
        }
    }

    protected function reschedule_single_event( $hook, $args, $new_timestamp ) {
        $next = wp_next_scheduled( $hook, $args );
        if ( $next && $next !== (int) $new_timestamp ) {
            wp_unschedule_event( $next, $hook, $args );
            wp_schedule_single_event( (int) $new_timestamp, $hook, $args );
        }
    }

    /* ---------- Cron callbacks ---------- */

    // Expire a post: change to chosen action
    public function do_expire_post( $post_id ) {
        $post_id = intval( $post_id );
        $post = get_post( $post_id );
        if ( ! $post ) return;

        $expiry_ts = (int) get_post_meta( $post_id, self::META_EXPIRY, true );
        if ( ! $expiry_ts || $expiry_ts > time() ) {
            return; // expiry removed or changed
        }

        $action = get_post_meta( $post_id, self::META_EXPIRY_ACTION, true );
        if ( ! $action ) $action = $this->get_settings_default_action();

        // Only proceed if not already in target state
        if ( $action === 'draft' ) {
            if ( $post->post_status !== 'draft' ) {
                wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
            }
        } elseif ( $action === 'expired' ) {
            if ( $post->post_status !== 'expired' ) {
                wp_update_post( array( 'ID' => $post_id, 'post_status' => 'expired' ) );
            }
        } elseif ( $action === 'trash' ) {
            if ( $post->post_status !== 'trash' ) {
                wp_trash_post( $post_id );
            }
        }

        // cleanup scheduled reminder if any
        $this->unschedule_event_for_post( 'ces_send_reminder', $post_id );
    }

    // Send reminder email about upcoming expiry (or scheduled update)
    public function send_reminder( $post_id ) {
        $post_id = intval( $post_id );
        $post = get_post( $post_id );
        if ( ! $post ) return;

        // don't re-send if already sent
        $sent = get_post_meta( $post_id, self::META_REMINDER_SENT, true );
        if ( $sent ) return;

        $author = get_userdata( $post->post_author );
        if ( ! $author || ! $author->user_email ) return;

        $settings = $this->get_settings();
        $subject = isset( $settings['reminder_subject'] ) ? $settings['reminder_subject'] : __( 'Content expiry reminder', 'content-expiry-scheduler' );
        $template = isset( $settings['reminder_body'] ) ? $settings['reminder_body'] : __( "Hi {author_name},\n\nThe post \"{post_title}\" is due to expire on {expiry_date}.\n\nEdit it here: {edit_link}\n\nRegards,\nSite", 'content-expiry-scheduler' );

        $expiry_ts = get_post_meta( $post_id, self::META_EXPIRY, true );
        $expiry_date = $expiry_ts ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $expiry_ts ) : '';

        $placeholders = array(
            '{author_name}' => $author->display_name,
            '{post_title}'  => $post->post_title,
            '{expiry_date}' => $expiry_date,
            '{edit_link}'   => get_edit_post_link( $post_id, '' ),
            '{site_name}'   => get_bloginfo( 'name' ),
        );

        $body = strtr( $template, $placeholders );

        wp_mail( $author->user_email, $subject, $body );

        update_post_meta( $post_id, self::META_REMINDER_SENT, time() );
    }

    // Scheduled update callback: send reminder to author (different template possible)
    public function do_scheduled_update( $post_id ) {
        $post_id = intval( $post_id );
        $post = get_post( $post_id );
        if ( ! $post ) return;

        $author = get_userdata( $post->post_author );
        if ( ! $author || ! $author->user_email ) return;

        $settings = $this->get_settings();

        $subject = isset( $settings['update_subject'] ) ? $settings['update_subject'] : __( 'Scheduled update reminder', 'content-expiry-scheduler' );
        $template = isset( $settings['update_body'] ) ? $settings['update_body'] : __( "Hi {author_name},\n\nThis is a reminder to review/update the post \"{post_title}\" scheduled for {update_date}.\n\nEdit: {edit_link}\n\nThanks.", 'content-expiry-scheduler' );

        $update_ts = get_post_meta( $post_id, self::META_UPDATE_TIMESTAMP, true );
        $update_date = $update_ts ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $update_ts ) : '';

        $placeholders = array(
            '{author_name}' => $author->display_name,
            '{post_title}'  => $post->post_title,
            '{update_date}' => $update_date,
            '{edit_link}'   => get_edit_post_link( $post_id, '' ),
            '{site_name}'   => get_bloginfo( 'name' ),
        );

        $body = strtr( $template, $placeholders );

        wp_mail( $author->user_email, $subject, $body );
    }

    /* ---------- Settings page ---------- */

    public function add_settings_page() {
        add_options_page(
            __( 'Content Expiry & Scheduler', 'content-expiry-scheduler' ),
            __( 'Content Expiry', 'content-expiry-scheduler' ),
            'manage_options',
            'ces-settings',
            array( $this, 'render_settings_page' )
        );
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( isset( $_POST['ces_settings_nonce'] ) ) {
            if ( ! wp_verify_nonce( $_POST['ces_settings_nonce'], 'ces_save_settings' ) ) {
                echo '<div class="error"><p>Nonce verification failed.</p></div>';
            } else {
                $this->save_settings_from_post();
                echo '<div class="updated"><p>' . esc_html__( 'Settings updated.', 'content-expiry-scheduler' ) . '</p></div>';
            }
        }

        $settings = $this->get_settings();
        $default_action = isset( $settings['default_action'] ) ? $settings['default_action'] : 'draft';
        $default_reminder_days = isset( $settings['default_reminder_days'] ) ? intval( $settings['default_reminder_days'] ) : 3;
        $reminder_subject = isset( $settings['reminder_subject'] ) ? $settings['reminder_subject'] : __( 'Content expiry reminder', 'content-expiry-scheduler' );
        $reminder_body = isset( $settings['reminder_body'] ) ? $settings['reminder_body'] : __( "Hi {author_name},\n\nThe post \"{post_title}\" is due to expire on {expiry_date}.\n\nEdit it here: {edit_link}\n\nRegards,\n{site_name}", 'content-expiry-scheduler' );

        $update_subject = isset( $settings['update_subject'] ) ? $settings['update_subject'] : __( 'Scheduled update reminder', 'content-expiry-scheduler' );
        $update_body = isset( $settings['update_body'] ) ? $settings['update_body'] : __( "Hi {author_name},\n\nThis is a reminder to review/update the post \"{post_title}\" scheduled for {update_date}.\n\nEdit: {edit_link}\n\nThanks,\n{site_name}", 'content-expiry-scheduler' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Content Expiry & Scheduler Settings', 'content-expiry-scheduler' ); ?></h1>

            <form method="post" action="">
                <?php wp_nonce_field( 'ces_save_settings', 'ces_settings_nonce' ); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Default action on expiry', 'content-expiry-scheduler' ); ?></th>
                        <td>
                            <select name="default_action">
                                <option value="draft" <?php selected( $default_action, 'draft' ); ?>><?php esc_html_e( 'Change to Draft', 'content-expiry-scheduler' ); ?></option>
                                <option value="expired" <?php selected( $default_action, 'expired' ); ?>><?php esc_html_e( 'Mark as Expired', 'content-expiry-scheduler' ); ?></option>
                                <option value="trash" <?php selected( $default_action, 'trash' ); ?>><?php esc_html_e( 'Move to Trash', 'content-expiry-scheduler' ); ?></option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e( 'Default reminder days', 'content-expiry-scheduler' ); ?></th>
                        <td>
                            <input type="number" name="default_reminder_days" value="<?php echo esc_attr( $default_reminder_days ); ?>" min="0" />
                            <p class="description"><?php esc_html_e( 'Default number of days before expiry to send reminders.', 'content-expiry-scheduler' ); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e( 'Reminder email subject', 'content-expiry-scheduler' ); ?></th>
                        <td>
                            <input type="text" name="reminder_subject" value="<?php echo esc_attr( $reminder_subject ); ?>" style="width:60%" />
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e( 'Reminder email body', 'content-expiry-scheduler' ); ?></th>
                        <td>
                            <textarea name="reminder_body" rows="6" style="width:80%"><?php echo esc_textarea( $reminder_body ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Use placeholders: {author_name}, {post_title}, {expiry_date}, {edit_link}, {site_name}', 'content-expiry-scheduler' ); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e( 'Update reminder subject', 'content-expiry-scheduler' ); ?></th>
                        <td>
                            <input type="text" name="update_subject" value="<?php echo esc_attr( $update_subject ); ?>" style="width:60%" />
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e( 'Update reminder body', 'content-expiry-scheduler' ); ?></th>
                        <td>
                            <textarea name="update_body" rows="6" style="width:80%"><?php echo esc_textarea( $update_body ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Placeholders: {author_name}, {post_title}, {update_date}, {edit_link}, {site_name}', 'content-expiry-scheduler' ); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    protected function save_settings_from_post() {
        $data = array();
        $data['default_action'] = isset( $_POST['default_action'] ) ? sanitize_text_field( wp_unslash( $_POST['default_action'] ) ) : 'draft';
        $data['default_reminder_days'] = isset( $_POST['default_reminder_days'] ) ? intval( $_POST['default_reminder_days'] ) : 0;
        $data['reminder_subject'] = isset( $_POST['reminder_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['reminder_subject'] ) ) : '';
        $data['reminder_body'] = isset( $_POST['reminder_body'] ) ? wp_kses_post( wp_unslash( $_POST['reminder_body'] ) ) : '';
        $data['update_subject'] = isset( $_POST['update_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['update_subject'] ) ) : '';
        $data['update_body'] = isset( $_POST['update_body'] ) ? wp_kses_post( wp_unslash( $_POST['update_body'] ) ) : '';
        update_option( self::OPTION_KEY, $data );
    }

    public function get_settings() {
        $defaults = array(
            'default_action' => 'draft',
            'default_reminder_days' => 3,
            'reminder_subject' => __( 'Content expiry reminder', 'content-expiry-scheduler' ),
            'reminder_body' => __( "Hi {author_name},\n\nThe post \"{post_title}\" is due to expire on {expiry_date}.\n\nEdit it here: {edit_link}\n\nRegards,\n{site_name}", 'content-expiry-scheduler' ),
            'update_subject' => __( 'Scheduled update reminder', 'content-expiry-scheduler' ),
            'update_body' => __( "Hi {author_name},\n\nThis is a reminder to review/update the post \"{post_title}\" scheduled for {update_date}.\n\nEdit: {edit_link}\n\nThanks,\n{site_name}", 'content-expiry-scheduler' ),
        );
        $stored = get_option( self::OPTION_KEY, array() );
        return wp_parse_args( $stored, $defaults );
    }

    protected function get_settings_default_action() {
        $s = $this->get_settings();
        return isset( $s['default_action'] ) ? $s['default_action'] : 'draft';
    }

    /* ---------- Activation / Deactivation ---------- */

    public function on_activation() {
        // Ensure scheduled events for existing posts are set
        $this->register_expired_status();

        // Rescan all posts with expiry meta (in case plugin activated after adding meta)
        $args = array(
            'post_type' => array( 'post', 'page' ),
            'post_status' => array( 'publish', 'future', 'private' ),
            'meta_query' => array(
                array(
                    'key' => self::META_EXPIRY,
                    'compare' => 'EXISTS',
                )
            ),
            'posts_per_page' => -1,
            'fields' => 'ids',
        );

        $query = new WP_Query( $args );
        if ( $query->have_posts() ) {
            foreach ( $query->posts as $post_id ) {
                $this->schedule_post_events( $post_id );
            }
        }
        wp_reset_postdata();
    }

    public function on_deactivation() {
        // Unschedule all ces hooks
        $this->clear_all_scheduled_ces_events();
    }

    protected function clear_all_scheduled_ces_events() {
        // We can't easily query scheduled events by hook name directly, so we'll attempt to unschedule per-post by scanning meta:
        $args = array(
            'post_type' => array( 'post', 'page' ),
            'post_status' => 'any',
            'meta_query' => array(
                array(
                    'key' => self::META_EXPIRY,
                    'compare' => 'EXISTS',
                ),
            ),
            'posts_per_page' => -1,
            'fields' => 'ids',
        );

        $query = new WP_Query( $args );
        if ( $query->have_posts() ) {
            foreach ( $query->posts as $post_id ) {
                $this->unschedule_event_for_post( 'ces_do_expire_post', $post_id );
                $this->unschedule_event_for_post( 'ces_send_reminder', $post_id );
                $this->unschedule_event_for_post( 'ces_do_scheduled_update', $post_id );
            }
        }
        wp_reset_postdata();
    }

    /* ---------- Admin UX ---------- */

    public function enqueue_admin_assets( $hook ) {
        if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
            // small inline script for UI toggles — no external libraries required
            $script = "
                (function(){
                    var expiry = document.getElementById('ces_expiry');
                    var reminder = document.getElementById('ces_reminder');
                    function toggleReminderNote(){
                        if(!expiry || !reminder) return;
                        if(!expiry.value) {
                            reminder.setAttribute('disabled','disabled');
                        } else {
                            reminder.removeAttribute('disabled');
                        }
                    }
                    document.addEventListener('DOMContentLoaded', toggleReminderNote);
                    if(expiry) expiry.addEventListener('change', toggleReminderNote);
                })();
            ";
            wp_add_inline_script( 'jquery', $script );
        }
        // Settings page styles could be added if needed
    }

    /* ---------- Admin helper UI ---------- */

    public function post_row_actions( $actions, $post ) {
        $expiry_ts = get_post_meta( $post->ID, self::META_EXPIRY, true );
        if ( $expiry_ts ) {
            $actions['ces_expiry'] = '<span title="' . esc_attr__( 'Has expiry set', 'content-expiry-scheduler' ) . '">⏰</span>';
        }
        return $actions;
    }

    public function display_expired_state( $states, $post ) {
        if ( 'expired' === $post->post_status ) {
            $states['expired'] = __( 'Expired', 'content-expiry-scheduler' );
        }
        return $states;
    }

    public function admin_notices() {
        // Simple notice if cron is disabled or others could be added; but keep minimal.
        // No intrusive notices by default.
    }

}

new CES_Content_Expiry_Scheduler();
