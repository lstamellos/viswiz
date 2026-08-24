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

add_filter( 'pre_set_site_transient_update_plugins', 'viswiz_updater_check_for_update' );
add_filter( 'plugins_api', 'viswiz_updater_plugin_information', 20, 3 );
add_filter( 'auto_update_plugin', 'viswiz_updater_enable_automatic_updates', 10, 2 );
add_filter( 'upgrader_source_selection', 'viswiz_updater_normalize_source_directory', 10, 4 );
add_action( 'upgrader_process_complete', 'viswiz_updater_clear_cache_after_upgrade', 10, 2 );
add_action( 'admin_init', 'viswiz_updater_maybe_clear_cache_on_force_check' );

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

    $enabled = true;
    if ( defined( 'VISWIZ_AUTO_UPDATES' ) ) {
        $enabled = (bool) VISWIZ_AUTO_UPDATES;
    }

    return (bool) apply_filters( 'viswiz_auto_update_enabled', $enabled, $item );
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
