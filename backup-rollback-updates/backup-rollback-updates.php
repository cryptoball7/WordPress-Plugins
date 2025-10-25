<?php
/**
 * Plugin Name: Backup & Rollback on Update
 * Description: Automatically snapshot plugins/themes (files + optional DB dump) before updates and allow rollback from admin UI.
 * Version: 1.0.0
 * Author: Cryptoball cryptoball7@gmail.com
 *
 * Notes:
 * - Requires PHP ZipArchive.
 * - Snapshots are stored in wp-content/backup-rollback-updates/snapshots/
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BRU_Backup_Rollback {

    private $snapshots_dir;
    private $base_dir;
    private $option_key = 'bru_settings';

    public function __construct() {
        $this->base_dir = WP_CONTENT_DIR . '/backup-rollback-updates';
        $this->snapshots_dir = $this->base_dir . '/snapshots';

        register_activation_hook( __FILE__, array( $this, 'activate' ) );

        // admin UI
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_post_bru_restore', array( $this, 'handle_restore' ) );
        add_action( 'admin_post_bru_delete_snapshot', array( $this, 'handle_delete' ) );

        // Automatically run before package install (themes or plugins)
        add_action( 'upgrader_pre_install', array( $this, 'maybe_create_snapshot' ), 10, 2 );

        // show notice if no ZipArchive
        add_action( 'admin_notices', array( $this, 'zip_check_notice' ) );
    }

    public function activate() {
        if ( ! file_exists( $this->snapshots_dir ) ) {
            wp_mkdir_p( $this->snapshots_dir );
        }
        // default settings
        $defaults = array(
            'include_db' => false,
            'max_snapshots' => 20,
        );
        if ( false === get_option( $this->option_key ) ) {
            add_option( $this->option_key, $defaults );
        }
    }

    public function zip_check_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! class_exists( 'ZipArchive' ) ) {
            echo '<div class="notice notice-warning"><p><strong>Backup & Rollback on Update:</strong> PHP ZipArchive is not available. File snapshots will fail. Contact your host to enable ZipArchive.</p></div>';
        }
    }

    /**
     * Called before upgrader installs a new package.
     * $hook_extra often contains 'plugin' => 'plugin-folder/plugin-file.php' for plugin upgrades
     * and 'theme' => 'themename' for themes.
     */
    public function maybe_create_snapshot( $true, $hook_extra ) {
        // $hook_extra is an array. Check for plugin or theme keys.
        if ( empty( $hook_extra ) || ! is_array( $hook_extra ) ) {
            return $true;
        }

        $settings = $this->get_settings();

        // Determine if plugin update or theme update
        if ( ! empty( $hook_extra['plugin'] ) ) {
            // plugin upgrade
            $plugin_rel_path = $hook_extra['plugin']; // e.g. akismet/akismet.php
            $parts = explode( '/', $plugin_rel_path );
            $slug = $parts[0];
            $type = 'plugin';
            $target_path = WP_PLUGIN_DIR . '/' . $slug;
            // fallback: if a single-file plugin (no slash) use file path parent
            if ( count( $parts ) === 1 ) {
                // plugin file in plugins root; slug remove .php
                $slug = basename( $plugin_rel_path, '.php' );
                $target_path = WP_PLUGIN_DIR . '/' . $slug;
                // if folder doesn't exist, fallback to plugin file path location
                if ( ! file_exists( $target_path ) ) {
                    $target_path = WP_PLUGIN_DIR . '/' . $plugin_rel_path;
                }
            }
        } elseif ( ! empty( $hook_extra['theme'] ) ) {
            $slug = $hook_extra['theme'];
            $type = 'theme';
            $target_path = get_theme_root() . '/' . $slug;
        } else {
            // Unknown/other package (core, translations...). We ignore core updates.
            return $true;
        }

        // If target not found, still create snapshot if possible by reading the current path.
        if ( ! file_exists( $target_path ) ) {
            // nothing to snapshot
            return $true;
        }

        // Create snapshot
        $this->create_snapshot( $type, $slug, $target_path, $settings['include_db'] );

        // Enforce max snapshots (simple FIFO)
        $this->prune_snapshots( $settings['max_snapshots'] );

        return $true;
    }

    private function timestamp() {
        return gmdate( 'Ymd-His' );
    }

    private function create_snapshot( $type, $slug, $target_path, $include_db = false ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            // fallback: copy directory to snapshot folder (no zip) if possible
            $fallback_dir = "{$this->snapshots_dir}/{$type}-{$slug}-" . $this->timestamp();
            if ( ! wp_mkdir_p( $fallback_dir ) ) {
                error_log( 'BRU: failed to create fallback snapshot dir' );
                return false;
            }
            // copy
            require_once ABSPATH . 'wp-admin/includes/file.php';
            copy_dir( $target_path, $fallback_dir );
            // optionally dump DB
            if ( $include_db ) {
                $this->write_db_dump( $fallback_dir . '/db-dump.sql' );
            }
            return true;
        }

        $zipname = "{$this->snapshots_dir}/{$type}-{$slug}-" . $this->timestamp() . '.zip';
        $zip = new ZipArchive();
        if ( true !== $zip->open( $zipname, ZipArchive::CREATE ) ) {
            error_log( 'BRU: failed to open zip for writing: ' . $zipname );
            return false;
        }

        // add files recursively
        $this->zip_add_folder( $zip, $target_path, dirname( $target_path ) );

        // optionally include database dump inside zip
        if ( $include_db ) {
            $tmpfile = wp_tempnam( 'bru_db_dump_' );
            $this->write_db_dump( $tmpfile );
            if ( file_exists( $tmpfile ) ) {
                $zip->addFile( $tmpfile, 'db-dump.sql' );
                @unlink( $tmpfile );
            }
        }

        $zip->close();

        // write a meta file with timestamp info
        $meta = array(
            'created' => gmdate( 'c' ),
            'type'    => $type,
            'slug'    => $slug,
            'source'  => $target_path,
            'file'    => basename( $zipname ),
        );
        file_put_contents( $zipname . '.meta.json', wp_json_encode( $meta ) );

        return true;
    }

    /**
     * Recursively add folder contents to ZipArchive.
     *
     * $zip: ZipArchive handle
     * $folder: absolute path to folder or file
     * $base: base path to remove when storing names (so zips don't include full server paths)
     */
    private function zip_add_folder( $zip, $folder, $base ) {
        $folder = untrailingslashit( $folder );
        if ( is_dir( $folder ) ) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $folder, RecursiveDirectoryIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ( $files as $file ) {
                $filePath = $file->getPathname();
                // compute local name
                $localname = ltrim( str_replace( $base, '', $filePath ), '/\\' );
                if ( is_dir( $filePath ) ) {
                    $zip->addEmptyDir( $localname );
                } else {
                    $zip->addFile( $filePath, $localname );
                }
            }
        } elseif ( is_file( $folder ) ) {
            $localname = ltrim( str_replace( $base, '', $folder ), '/\\' );
            $zip->addFile( $folder, $localname );
        }
    }

    private function write_db_dump( $filepath ) {
        global $wpdb;
        // We will produce a simple SQL dump (create table + inserts).
        // This is not a full mysqldump replacement but useful for many rollbacks.
        try {
            $fh = @fopen( $filepath, 'w' );
            if ( ! $fh ) {
                return false;
            }
            fwrite( $fh, "-- BRU DB dump\n-- Generated: " . gmdate( 'c' ) . "\n\n" );

            $tables = $wpdb->get_col( 'SHOW TABLES' );

            foreach ( $tables as $table ) {
                // CREATE TABLE
                $create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
                if ( ! empty( $create ) && isset( $create[1] ) ) {
                    fwrite( $fh, "-- Table structure for `{$table}`\n\n" );
                    fwrite( $fh, "DROP TABLE IF EXISTS `{$table}`;\n" );
                    fwrite( $fh, $create[1] . ";\n\n" );
                }

                // INSERTS in batches
                $rows = $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A );
                if ( empty( $rows ) ) {
                    continue;
                }
                fwrite( $fh, "-- Dumping data for `{$table}`\n" );
                foreach ( $rows as $row ) {
                    $cols = array();
                    foreach ( $row as $col => $val ) {
                        if ( is_null( $val ) ) {
                            $cols[] = 'NULL';
                        } else {
                            $cols[] = "'" . esc_sql( $val ) . "'";
                        }
                    }
                    $line = "INSERT INTO `{$table}` (`" . implode( '`,`', array_keys( $row ) ) . "`) VALUES (" . implode( ',', $cols ) . ");\n";
                    fwrite( $fh, $line );
                }
                fwrite( $fh, "\n" );
            }

            fclose( $fh );
            return true;
        } catch ( Exception $e ) {
            return false;
        }
    }

    private function prune_snapshots( $max ) {
        $files = glob( $this->snapshots_dir . '/*.{zip,meta.json}', GLOB_BRACE );
        if ( ! $files ) {
            return;
        }
        // group by base (zip)
        $zips = array();
        foreach ( $files as $f ) {
            if ( substr( $f, -9 ) === '.meta.json' ) {
                continue;
            }
            $zips[] = $f;
        }
        // sort by file mtime ascending
        usort( $zips, function ( $a, $b ) {
            return filemtime( $a ) - filemtime( $b );
        } );
        if ( count( $zips ) <= $max ) {
            return;
        }
        $to_delete = array_slice( $zips, 0, count( $zips ) - $max );
        foreach ( $to_delete as $z ) {
            @unlink( $z );
            @unlink( $z . '.meta.json' );
        }
    }

    /* ---------------------- Admin UI ---------------------- */

    public function admin_menu() {
        add_management_page(
            'Backup & Rollback',
            'Backup & Rollback',
            'manage_options',
            'bru-backups',
            array( $this, 'admin_page' )
        );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    public function register_settings() {
        register_setting( 'bru_settings_group', $this->option_key );
    }

    private function get_settings() {
        $defaults = array(
            'include_db' => false,
            'max_snapshots' => 20,
        );
        $opts = get_option( $this->option_key, array() );
        return wp_parse_args( $opts, $defaults );
    }

    public function admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions' );
        }
        $settings = $this->get_settings();
        // list snapshots
        $snapshots = $this->gather_snapshots();
        ?>
        <div class="wrap">
            <h1>Backup & Rollback</h1>

            <h2>Settings</h2>
            <form method="post" action="options.php">
                <?php settings_fields( 'bru_settings_group' ); ?>
                <?php $opt = $this->option_key; $s = $settings; ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Include DB dump in snapshot</th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[include_db]" value="1" <?php checked( $s['include_db'], true ); ?> /> Yes (may increase snapshot size)</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Max snapshots to keep</th>
                        <td>
                            <input type="number" name="<?php echo esc_attr( $opt ); ?>[max_snapshots]" value="<?php echo esc_attr( intval( $s['max_snapshots'] ) ); ?>" min="1" />
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2>Available Snapshots</h2>
            <?php if ( empty( $snapshots ) ) : ?>
                <p>No snapshots found.</p>
            <?php else : ?>
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Snapshot</th>
                            <th>Type</th>
                            <th>Slug</th>
                            <th>Created (UTC)</th>
                            <th>Size</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $snapshots as $s ) : ?>
                            <tr>
                                <td><?php echo esc_html( basename( $s['file'] ) ); ?></td>
                                <td><?php echo esc_html( $s['meta']['type'] ); ?></td>
                                <td><?php echo esc_html( $s['meta']['slug'] ); ?></td>
                                <td><?php echo esc_html( $s['meta']['created'] ); ?></td>
                                <td><?php echo size_format( filesize( $s['path'] ) ); ?></td>
                                <td>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Restore this snapshot? This will overwrite files. (DB restore optional).');" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                        <?php wp_nonce_field( 'bru_restore_' . basename( $s['path'] ) ); ?>
                                        <input type="hidden" name="action" value="bru_restore" />
                                        <input type="hidden" name="snapshot" value="<?php echo esc_attr( basename( $s['path'] ) ); ?>" />
                                        <label style="margin-right:8px;"><input type="checkbox" name="restore_db" value="1" /> Restore DB (if included)</label>
                                        <?php submit_button( 'Restore', 'secondary small', '', false ); ?>
                                    </form>

                                    <form method="post" style="display:inline;" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                        <?php wp_nonce_field( 'bru_delete_' . basename( $s['path'] ) ); ?>
                                        <input type="hidden" name="action" value="bru_delete_snapshot" />
                                        <input type="hidden" name="snapshot" value="<?php echo esc_attr( basename( $s['path'] ) ); ?>" />
                                        <?php submit_button( 'Delete', 'delete small', '', false ); ?>
                                    </form>

                                    <a href="<?php echo esc_url( $this->snapshot_download_url( $s['path'] ) ); ?>" style="margin-left:8px;">Download</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2>Manual Snapshot</h2>
            <p>If you want to take a manual snapshot of a plugin or theme, use WP-CLI or copy the plugin/theme folder into <code><?php echo esc_html( $this->snapshots_dir ); ?></code> and create a .meta.json file with the fields <code>type</code>, <code>slug</code>, <code>created</code>.</p>

        </div>
        <?php
    }

    private function gather_snapshots() {
        $items = array();
        if ( ! is_dir( $this->snapshots_dir ) ) {
            return $items;
        }
        $files = glob( $this->snapshots_dir . '/*.zip' );
        if ( $files ) {
            foreach ( $files as $f ) {
                $metaf = $f . '.meta.json';
                $meta = array();
                if ( file_exists( $metaf ) ) {
                    $meta = json_decode( file_get_contents( $metaf ), true );
                } else {
                    $meta = array( 'created' => date( 'c', filemtime( $f ) ), 'type' => 'unknown', 'slug' => 'unknown' );
                }
                $items[] = array(
                    'path' => $f,
                    'file' => basename( $f ),
                    'meta' => $meta,
                );
            }
            // sort desc by time
            usort( $items, function ( $a, $b ) {
                return filemtime( $b['path'] ) - filemtime( $a['path'] );
            } );
        }
        return $items;
    }

    private function snapshot_download_url( $path ) {
        // Simple download proxy to avoid exposing path
        return add_query_arg( array( 'bru_download' => basename( $path ) ), admin_url( 'admin-ajax.php' ) );
    }

    // handle restore action
    public function handle_restore() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions' );
        }
        if ( empty( $_POST['snapshot'] ) ) {
            wp_safe_redirect( admin_url( 'tools.php?page=bru-backups' ) );
            exit;
        }
        $snapshot = sanitize_file_name( wp_unslash( $_POST['snapshot'] ) );
        $path = $this->snapshots_dir . '/' . $snapshot;

        if ( ! file_exists( $path ) ) {
            wp_die( 'Snapshot not found.' );
        }
        if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'bru_restore_' . $snapshot ) ) {
            wp_die( 'Invalid nonce' );
        }

        $restore_db = ! empty( $_POST['restore_db'] ) ? true : false;

        // unzip snapshot to temp dir, inspect meta to determine target
        $tmp = wp_tempnam( 'bru_restore_' );
        // we'll unzip into a temp folder
        $temp_dir = $tmp . '_dir';
        wp_mkdir_p( $temp_dir );

        $zip = new ZipArchive();
        if ( true !== $zip->open( $path ) ) {
            wp_die( 'Could not open snapshot.' );
        }
        $zip->extractTo( $temp_dir );
        $zip->close();

        // load meta if exists inside zip (db-dump.sql present or not)
        $meta = array();
        // check for top-level meta: there may be a .meta.json next to zip on disk
        $disk_meta = $path . '.meta.json';
        if ( file_exists( $disk_meta ) ) {
            $meta = json_decode( file_get_contents( $disk_meta ), true );
        }

        // if meta type is plugin or theme we know where to restore
        $restored = false;
        if ( ! empty( $meta['type'] ) && ! empty( $meta['slug'] ) ) {
            if ( 'plugin' === $meta['type'] ) {
                // target plugin dir
                $target = WP_PLUGIN_DIR . '/' . $meta['slug'];
                $this->safe_overwrite( $temp_dir, $target, $meta['slug'] );
                $restored = true;
            } elseif ( 'theme' === $meta['type'] ) {
                $target = get_theme_root() . '/' . $meta['slug'];
                $this->safe_overwrite( $temp_dir, $target, $meta['slug'] );
                $restored = true;
            }
        }

        // As fallback, try to detect a plugin or theme folder inside archive root and restore into plugins or themes.
        if ( ! $restored ) {
            // search first-level directories in $temp_dir
            $first_level = scandir( $temp_dir );
            foreach ( $first_level as $entry ) {
                if ( in_array( $entry, array( '.', '..' ), true ) ) {
                    continue;
                }
                $full = $temp_dir . '/' . $entry;
                if ( is_dir( $full ) ) {
                    // attempt plugin restore
                    if ( file_exists( $full . '/index.php' ) || preg_match( '/\.php$/', $entry ) ) {
                        // copy to plugins
                        $this->safe_overwrite( $full, WP_PLUGIN_DIR . '/' . $entry, $entry );
                        $restored = true;
                        break;
                    }
                    // attempt theme restore (style.css present?)
                    if ( file_exists( $full . '/style.css' ) ) {
                        $this->safe_overwrite( $full, get_theme_root() . '/' . $entry, $entry );
                        $restored = true;
                        break;
                    }
                }
            }
        }

        // If DB restore requested and db-dump.sql exists in extracted folder, import it.
        if ( $restore_db ) {
            $db_sql_path = $temp_dir . '/db-dump.sql';
            if ( ! file_exists( $db_sql_path ) ) {
                // maybe it was at a nested position; search
                $found = $this->find_file_recursive( $temp_dir, 'db-dump.sql' );
                if ( $found ) {
                    $db_sql_path = $found;
                }
            }
            if ( file_exists( $db_sql_path ) ) {
                $import_ok = $this->import_sql_file( $db_sql_path );
                if ( ! $import_ok ) {
                    // cleanup temp
                    $this->rrmdir( $temp_dir );
                    wp_die( 'DB import failed. Please check the SQL file manually.' );
                }
            } else {
                $this->rrmdir( $temp_dir );
                wp_die( 'DB dump not found inside snapshot.' );
            }
        }

        // cleanup temp
        $this->rrmdir( $temp_dir );

        wp_safe_redirect( admin_url( 'tools.php?page=bru-backups&restored=1' ) );
        exit;
    }

    private function find_file_recursive( $dir, $filename ) {
        $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ) );
        foreach ( $it as $f ) {
            if ( $f->getFilename() === $filename ) {
                return $f->getPathname();
            }
        }
        return false;
    }

    private function import_sql_file( $file ) {
        global $wpdb;
        // Read file, split by semicolon - naive approach; acceptable for simple dumps.
        $content = file_get_contents( $file );
        if ( false === $content ) {
            return false;
        }
        // Remove comments and lines starting with -- or # (simple)
        $lines = explode( "\n", $content );
        $sql = '';
        foreach ( $lines as $line ) {
            $trim = trim( $line );
            if ( '' === $trim || strpos( $trim, '--' ) === 0 || strpos( $trim, '#' ) === 0 ) {
                continue;
            }
            $sql .= $line . "\n";
        }

        // split statements by ; followed by newline (very naive)
        $statements = preg_split( '/;\s*(\r?\n)/', $sql );
        foreach ( $statements as $stmt ) {
            $stmt = trim( $stmt );
            if ( empty( $stmt ) ) {
                continue;
            }
            // run statement
            $res = $wpdb->query( $stmt );
            if ( false === $res ) {
                // abort
                return false;
            }
        }
        return true;
    }

    // Copy extracted_dir contents into $target (overwrite). Use recursive copy and remove existing first.
    private function safe_overwrite( $extracted_dir, $target, $slug ) {
        // If target exists, move it to a temp backup first (very simple)
        if ( file_exists( $target ) ) {
            $backup_target = $target . '.backup-' . $this->timestamp();
            @rename( $target, $backup_target );
        }

        // create target parent if needed
        wp_mkdir_p( dirname( $target ) );

        // move/copy contents
        // if extracted_dir contains a top-level folder matching slug, move that; otherwise move whole extracted_dir
        $candidate = trailingslashit( $extracted_dir ) . $slug;
        if ( is_dir( $candidate ) ) {
            // copy candidate into target location
            require_once ABSPATH . 'wp-admin/includes/file.php';
            copy_dir( $candidate, $target );
        } else {
            // copy everything from extracted_dir to target (merge)
            $entries = scandir( $extracted_dir );
            foreach ( $entries as $entry ) {
                if ( in_array( $entry, array( '.', '..' ), true ) ) {
                    continue;
                }
                $src = $extracted_dir . '/' . $entry;
                $dst = $target . '/' . $entry;
                if ( is_dir( $src ) ) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                    copy_dir( $src, $dst );
                } else {
                    wp_mkdir_p( dirname( $dst ) );
                    copy( $src, $dst );
                }
            }
        }
    }

    // delete snapshot
    public function handle_delete() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions' );
        }
        if ( empty( $_POST['snapshot'] ) ) {
            wp_safe_redirect( admin_url( 'tools.php?page=bru-backups' ) );
            exit;
        }
        $snapshot = sanitize_file_name( wp_unslash( $_POST['snapshot'] ) );
        $path = $this->snapshots_dir . '/' . $snapshot;
        if ( ! file_exists( $path ) ) {
            wp_safe_redirect( admin_url( 'tools.php?page=bru-backups' ) );
            exit;
        }
        if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'bru_delete_' . $snapshot ) ) {
            wp_die( 'Invalid nonce' );
        }
        @unlink( $path );
        @unlink( $path . '.meta.json' );
        wp_safe_redirect( admin_url( 'tools.php?page=bru-backups&deleted=1' ) );
        exit;
    }

    private function rrmdir( $dir ) {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
        foreach ( $it as $file ) {
            if ( $file->isDir() ) {
                @rmdir( $file->getPathname() );
            } else {
                @unlink( $file->getPathname() );
            }
        }
        @rmdir( $dir );
    }
}

new BRU_Backup_Rollback();

/* Admin-ajax download handler (public)
   We output file content to logged-in admins only.
*/
add_action( 'admin_init', function() {
    if ( ! empty( $_GET['bru_download'] ) ) {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        $file = sanitize_file_name( wp_unslash( $_GET['bru_download'] ) );
        $base = WP_CONTENT_DIR . '/backup-rollback-updates/snapshots';
        $path = $base . '/' . $file;
        if ( ! file_exists( $path ) ) {
            wp_die( 'File not found.' );
        }
        header( 'Content-Description: File Transfer' );
        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename=' . basename( $path ) );
        header( 'Content-Transfer-Encoding: binary' );
        header( 'Content-Length: ' . filesize( $path ) );
        readfile( $path );
        exit;
    }
} );
