<?php
/**
 * GitHub Releases updater for VisWiz.
 *
 * Integrates the plugin with WordPress' native plugin update UI and automatic
 * update runner. Release assets must be named viswiz-{version}.zip and contain
 * a top-level viswiz/ directory.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/viswiz-commerce-builder.php';

add_filter( 'pre_set_site_transient_update_plugins', 'viswiz_updater_check_for_update' );
add_filter( 'plugins_api', 'viswiz_updater_plugin_information', 20, 3 );
add_filter( 'auto_update_plugin', 'viswiz_updater_enable_automatic_updates', 10, 2 );
add_filter( 'upgrader_source_selection', 'viswiz_updater_normalize_source_directory', 10, 4 );
add_action( 'upgrader_process_complete', 'viswiz_updater_clear_cache_after_upgrade', 10, 2 );
add_action( 'admin_init', 'viswiz_updater_maybe_clear_cache_on_force_check' );
add_action( 'admin_menu', 'viswiz_updater_register_admin_page', 99 );
add_action( 'network_admin_menu', 'viswiz_updater_register_network_admin_page', 99 );
add_action( 'admin_post_viswiz_save_auto_update_setting', 'viswiz_updater_save_auto_update_setting' );
add_filter( 'plugin_action_links_' . viswiz_updater_plugin_basename(), 'viswiz_updater_plugin_action_links' );
add_filter( 'network_admin_plugin_action_links_' . viswiz_updater_plugin_basename(), 'viswiz_updater_network_plugin_action_links' );

function viswiz_updater_plugin_file() {
    return dirname( __DIR__ ) . '/viswiz.php';
}

function viswiz_updater_plugin_basename() {
    return plugin_basename( viswiz_updater_plugin_file() );
}

function viswiz_updater_repository_url() {
    return 'https://github.com/lstamellos/viswiz';
}

function viswiz_updater_api_url() {
    return 'https://api.github.com/repos/lstamellos/viswiz';
}

function viswiz_updater_cache_key() {
    return 'viswiz_github_latest_release';
}

function viswiz_updater_get_latest_release( $force = false ) {
    if ( ! $force ) {
        $cached = get_site_transient( viswiz_updater_cache_key() );
        if ( is_array( $cached ) && ! empty( $cached['version'] ) && ! empty( $cached['package'] ) ) {
            return $cached;
        }
    }

    $response = wp_remote_get(
        viswiz_updater_api_url() . '/releases/latest',
        array(
            'timeout'     => 10,
            'redirection' => 3,
            'headers'     => array(
                'Accept'               => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent'           => 'VisWiz/' . VISWIZ_VERSION . '; ' . home_url( '/' ),
            ),
        )
    );

    if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
        return false;
    }

    $release = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! is_array( $release ) || empty( $release['tag_name'] ) || ! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) ) {
        return false;
    }

    $version = ltrim( (string) $release['tag_name'], 'vV' );
    if ( '' === $version ) {
        return false;
    }

    $package = viswiz_updater_find_release_asset( $release, $version );
    if ( '' === $package ) {
        return false;
    }

    $data = array(
        'version'      => $version,
        'package'      => $package,
        'url'          => ! empty( $release['html_url'] ) ? esc_url_raw( $release['html_url'] ) : viswiz_updater_repository_url(),
        'published_at' => ! empty( $release['published_at'] ) ? sanitize_text_field( $release['published_at'] ) : '',
        'body'         => isset( $release['body'] ) ? (string) $release['body'] : '',
    );

    set_site_transient( viswiz_updater_cache_key(), $data, 6 * HOUR_IN_SECONDS );
    return $data;
}

function viswiz_updater_find_release_asset( $release, $version ) {
    if ( empty( $release['assets'] ) || ! is_array( $release['assets'] ) ) {
        return '';
    }

    $expected = 'viswiz-' . $version . '.zip';
    $fallback = '';

    foreach ( $release['assets'] as $asset ) {
        if ( empty( $asset['name'] ) || empty( $asset['browser_download_url'] ) ) {
            continue;
        }

        $name = (string) $asset['name'];
        $url  = esc_url_raw( $asset['browser_download_url'] );

        if ( $expected === $name ) {
            return $url;
        }

        if ( '' === $fallback && preg_match( '/^viswiz(?:[-_.].*)?\.zip$/i', $name ) ) {
            $fallback = $url;
        }
    }

    return $fallback;
}

function viswiz_updater_build_update_object( $release ) {
    $update              = new stdClass();
    $update->id          = viswiz_updater_repository_url();
    $update->slug        = 'viswiz';
    $update->plugin      = viswiz_updater_plugin_basename();
    $update->new_version = $release['version'];
    $update->url         = $release['url'];
    $update->package     = $release['package'];
    return $update;
}

function viswiz_updater_check_for_update( $transient ) {
    if ( ! is_object( $transient ) ) {
        return $transient;
    }

    $release = viswiz_updater_get_latest_release();
    if ( ! $release ) {
        return $transient;
    }

    $plugin = viswiz_updater_plugin_basename();
    $update = viswiz_updater_build_update_object( $release );

    if ( version_compare( $release['version'], VISWIZ_VERSION, '>' ) ) {
        if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
            $transient->response = array();
        }
        $transient->response[ $plugin ] = $update;
        if ( isset( $transient->no_update[ $plugin ] ) ) {
            unset( $transient->no_update[ $plugin ] );
        }
    } else {
        if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
            $transient->no_update = array();
        }
        $transient->no_update[ $plugin ] = $update;
    }

    return $transient;
}

function viswiz_updater_plugin_information( $result, $action, $args ) {
    if ( 'plugin_information' !== $action || empty( $args->slug ) || 'viswiz' !== $args->slug ) {
        return $result;
    }

    $release = viswiz_updater_get_latest_release();
    if ( ! $release ) {
        return $result;
    }

    $info                = new stdClass();
    $info->name          = 'VisWiz WooCommerce Visualizer';
    $info->slug          = 'viswiz';
    $info->version       = $release['version'];
    $info->author        = 'cremedia.studio';
    $info->homepage      = viswiz_updater_repository_url();
    $info->download_link = $release['package'];
    $info->external      = true;
    $info->last_updated  = $release['published_at'];
    $info->sections      = array(
        'description' => '<p>Real-time WooCommerce dashboards and reusable data visualizations.</p>',
        'changelog'   => '' !== trim( $release['body'] ) ? wpautop( esc_html( $release['body'] ) ) : '<p>See the GitHub release for details.</p>',
    );

    return $info;
}

/**
 * Respect WordPress' native per-plugin auto-update setting.
 *
 * Earlier VisWiz releases forced automatic updates on unless a wp-config.php
 * constant disabled them. That prevented the normal WordPress Plugins screen
 * from being authoritative. From 1.8.0 onward the incoming WordPress decision
 * is preserved. VISWIZ_AUTO_UPDATES remains available as an explicit hard
 * override for managed installations.
 */
function viswiz_updater_enable_automatic_updates( $update, $item ) {
    $plugin = '';
    if ( is_object( $item ) && isset( $item->plugin ) ) {
        $plugin = (string) $item->plugin;
    } elseif ( is_array( $item ) && isset( $item['plugin'] ) ) {
        $plugin = (string) $item['plugin'];
    }

    if ( viswiz_updater_plugin_basename() !== $plugin ) {
        return $update;
    }

    if ( defined( 'VISWIZ_AUTO_UPDATES' ) ) {
        return (bool) apply_filters( 'viswiz_auto_update_enabled', (bool) VISWIZ_AUTO_UPDATES, $item );
    }

    return (bool) apply_filters( 'viswiz_auto_update_enabled', (bool) $update, $item );
}

function viswiz_updater_get_native_auto_update_enabled() {
    $plugins = get_site_option( 'auto_update_plugins', array() );
    if ( ! is_array( $plugins ) ) {
        $plugins = array();
    }

    return in_array( viswiz_updater_plugin_basename(), $plugins, true );
}

function viswiz_updater_set_native_auto_update_enabled( $enabled ) {
    $plugin  = viswiz_updater_plugin_basename();
    $plugins = get_site_option( 'auto_update_plugins', array() );

    if ( ! is_array( $plugins ) ) {
        $plugins = array();
    }

    $plugins = array_values( array_unique( array_filter( array_map( 'strval', $plugins ) ) ) );
    $index   = array_search( $plugin, $plugins, true );

    if ( $enabled && false === $index ) {
        $plugins[] = $plugin;
    } elseif ( ! $enabled && false !== $index ) {
        unset( $plugins[ $index ] );
        $plugins = array_values( $plugins );
    }

    update_site_option( 'auto_update_plugins', $plugins );
}

function viswiz_updater_register_admin_page() {
    if ( is_network_admin() || ! current_user_can( 'update_plugins' ) ) {
        return;
    }

    add_submenu_page(
        'viswiz',
        __( 'VisWiz Updates', 'viswiz' ),
        __( 'Updates', 'viswiz' ),
        'update_plugins',
        'viswiz-updates',
        'viswiz_updater_render_settings_page'
    );
}

function viswiz_updater_register_network_admin_page() {
    if ( ! is_multisite() || ! current_user_can( 'update_plugins' ) ) {
        return;
    }

    add_submenu_page(
        'settings.php',
        __( 'VisWiz Updates', 'viswiz' ),
        __( 'VisWiz Updates', 'viswiz' ),
        'update_plugins',
        'viswiz-updates',
        'viswiz_updater_render_settings_page'
    );
}

function viswiz_updater_settings_url( $network = null ) {
    if ( null === $network ) {
        $network = is_network_admin();
    }

    if ( $network && is_multisite() ) {
        return network_admin_url( 'settings.php?page=viswiz-updates' );
    }

    return admin_url( 'admin.php?page=viswiz-updates' );
}

function viswiz_updater_plugin_action_links( $links ) {
    if ( ! current_user_can( 'update_plugins' ) ) {
        return $links;
    }

    $settings = sprintf(
        '<a href="%s">%s</a>',
        esc_url( viswiz_updater_settings_url( false ) ),
        esc_html__( 'Auto-updates', 'viswiz' )
    );
    array_unshift( $links, $settings );
    return $links;
}

function viswiz_updater_network_plugin_action_links( $links ) {
    if ( ! current_user_can( 'update_plugins' ) ) {
        return $links;
    }

    $settings = sprintf(
        '<a href="%s">%s</a>',
        esc_url( viswiz_updater_settings_url( true ) ),
        esc_html__( 'Auto-updates', 'viswiz' )
    );
    array_unshift( $links, $settings );
    return $links;
}

function viswiz_updater_render_settings_page() {
    if ( ! current_user_can( 'update_plugins' ) ) {
        wp_die( esc_html__( 'You are not allowed to manage plugin updates.', 'viswiz' ) );
    }

    $locked         = defined( 'VISWIZ_AUTO_UPDATES' );
    $native_enabled = viswiz_updater_get_native_auto_update_enabled();
    $effective      = $locked ? (bool) VISWIZ_AUTO_UPDATES : $native_enabled;
    $global_enabled = ! function_exists( 'wp_is_auto_update_enabled_for_type' ) || wp_is_auto_update_enabled_for_type( 'plugin' );
    $network        = is_network_admin();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'VisWiz Updates', 'viswiz' ); ?></h1>
        <?php if ( ! empty( $_GET['updated'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'VisWiz automatic-update preference saved.', 'viswiz' ); ?></p></div>
        <?php endif; ?>
        <p><?php esc_html_e( 'VisWiz discovers releases from GitHub and installs only the release ZIP asset. Automatic installation is controlled by WordPress rather than being forced by the plugin.', 'viswiz' ); ?></p>

        <table class="form-table" role="presentation">
            <tbody>
            <tr>
                <th scope="row"><?php esc_html_e( 'Installed version', 'viswiz' ); ?></th>
                <td><code><?php echo esc_html( defined( 'VISWIZ_VERSION' ) ? VISWIZ_VERSION : '' ); ?></code></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Automatic updates', 'viswiz' ); ?></th>
                <td><strong><?php echo $effective ? esc_html__( 'Enabled', 'viswiz' ) : esc_html__( 'Disabled', 'viswiz' ); ?></strong></td>
            </tr>
            </tbody>
        </table>

        <?php if ( $locked ) : ?>
            <div class="notice notice-info inline">
                <p>
                    <?php
                    printf(
                        esc_html__( 'This setting is locked by VISWIZ_AUTO_UPDATES in wp-config.php and cannot be changed here. Its current value is %s.', 'viswiz' ),
                        VISWIZ_AUTO_UPDATES ? 'true' : 'false'
                    );
                    ?>
                </p>
            </div>
        <?php else : ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="viswiz_save_auto_update_setting" />
                <input type="hidden" name="viswiz_update_context" value="<?php echo $network ? 'network' : 'site'; ?>" />
                <?php wp_nonce_field( 'viswiz_save_auto_update_setting' ); ?>
                <p>
                    <label>
                        <input type="hidden" name="viswiz_auto_updates" value="0" />
                        <input type="checkbox" name="viswiz_auto_updates" value="1" <?php checked( $native_enabled ); ?> />
                        <strong><?php esc_html_e( 'Automatically install new VisWiz releases', 'viswiz' ); ?></strong>
                    </label>
                </p>
                <p class="description">
                    <?php esc_html_e( 'This is synchronized with WordPress’ native per-plugin auto-update preference shown on the Plugins screen.', 'viswiz' ); ?>
                </p>
                <?php submit_button( __( 'Save update preference', 'viswiz' ) ); ?>
            </form>
        <?php endif; ?>

        <?php if ( ! $global_enabled ) : ?>
            <div class="notice notice-warning inline"><p><?php esc_html_e( 'WordPress automatic plugin updates are disabled globally on this installation, so enabling VisWiz here will not make automatic installation run until the global restriction is removed.', 'viswiz' ); ?></p></div>
        <?php endif; ?>

        <hr />
        <p><a href="<?php echo esc_url( viswiz_updater_repository_url() . '/releases' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View VisWiz releases on GitHub', 'viswiz' ); ?></a></p>
    </div>
    <?php
}

function viswiz_updater_save_auto_update_setting() {
    if ( ! current_user_can( 'update_plugins' ) ) {
        wp_die( esc_html__( 'You are not allowed to manage plugin updates.', 'viswiz' ) );
    }

    check_admin_referer( 'viswiz_save_auto_update_setting' );

    $network = is_multisite() && isset( $_POST['viswiz_update_context'] ) && 'network' === sanitize_key( wp_unslash( $_POST['viswiz_update_context'] ) );

    if ( ! defined( 'VISWIZ_AUTO_UPDATES' ) ) {
        $enabled = ! empty( $_POST['viswiz_auto_updates'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['viswiz_auto_updates'] ) );
        viswiz_updater_set_native_auto_update_enabled( $enabled );
    }

    wp_safe_redirect( add_query_arg( 'updated', '1', viswiz_updater_settings_url( $network ) ) );
    exit;
}

function viswiz_updater_normalize_source_directory( $source, $remote_source, $upgrader, $hook_extra ) {
    if ( empty( $hook_extra['plugin'] ) || viswiz_updater_plugin_basename() !== $hook_extra['plugin'] ) {
        return $source;
    }

    global $wp_filesystem;
    if ( ! $wp_filesystem ) {
        return $source;
    }

    $current_dir = basename( dirname( viswiz_updater_plugin_file() ) );
    $source_dir  = basename( untrailingslashit( $source ) );
    if ( $current_dir === $source_dir ) {
        return $source;
    }

    $desired = trailingslashit( $remote_source ) . $current_dir;
    if ( $wp_filesystem->exists( $desired ) ) {
        return $source;
    }

    if ( $wp_filesystem->move( $source, $desired, true ) ) {
        return trailingslashit( $desired );
    }

    return $source;
}

function viswiz_updater_clear_cache_after_upgrade( $upgrader, $options ) {
    if ( empty( $options['action'] ) || 'update' !== $options['action'] || empty( $options['type'] ) || 'plugin' !== $options['type'] ) {
        return;
    }

    $plugins = array();
    if ( ! empty( $options['plugins'] ) && is_array( $options['plugins'] ) ) {
        $plugins = $options['plugins'];
    } elseif ( ! empty( $options['plugin'] ) ) {
        $plugins = array( $options['plugin'] );
    }

    if ( in_array( viswiz_updater_plugin_basename(), $plugins, true ) ) {
        delete_site_transient( viswiz_updater_cache_key() );
    }
}

function viswiz_updater_maybe_clear_cache_on_force_check() {
    if ( ! is_admin() || ! current_user_can( 'update_plugins' ) || empty( $_GET['force-check'] ) ) {
        return;
    }

    if ( '1' === sanitize_text_field( wp_unslash( $_GET['force-check'] ) ) ) {
        delete_site_transient( viswiz_updater_cache_key() );
    }
}
