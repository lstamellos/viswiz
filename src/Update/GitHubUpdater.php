<?php
namespace VisWiz\Update;

final class GitHubUpdater {
    private const REPOSITORY = 'https://github.com/lstamellos/viswiz';
    private const API        = 'https://api.github.com/repos/lstamellos/viswiz';
    private const CACHE_KEY  = 'viswiz_github_latest_release_v2';

    public static function register(): void {
        add_filter( 'pre_set_site_transient_update_plugins', array( self::class, 'check' ) );
        add_filter( 'plugins_api', array( self::class, 'information' ), 20, 3 );
        add_filter( 'upgrader_source_selection', array( self::class, 'normalize_source' ), 10, 4 );
        add_action( 'upgrader_process_complete', array( self::class, 'clear_cache' ), 10, 2 );
    }

    public static function plugin_basename(): string {
        return plugin_basename( VISWIZ_FILE );
    }

    public static function latest( bool $force = false ) {
        if ( ! $force ) {
            $cached = get_site_transient( self::CACHE_KEY );
            if ( is_array( $cached ) && ! empty( $cached['version'] ) && ! empty( $cached['package'] ) ) {
                return $cached;
            }
        }
        $response = wp_remote_get(
            self::API . '/releases/latest',
            array(
                'timeout'     => 10,
                'redirection' => 3,
                'headers'     => array(
                    'Accept'               => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                    'User-Agent'           => 'VisWiz/' . VISWIZ_VERSION,
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
        $package = self::asset( $release, $version );
        if ( ! $version || ! $package ) {
            return false;
        }
        $data = array(
            'version'      => $version,
            'package'      => $package,
            'url'          => esc_url_raw( (string) ( $release['html_url'] ?? self::REPOSITORY ) ),
            'published_at' => sanitize_text_field( (string) ( $release['published_at'] ?? '' ) ),
            'body'         => (string) ( $release['body'] ?? '' ),
        );
        set_site_transient( self::CACHE_KEY, $data, 6 * HOUR_IN_SECONDS );
        return $data;
    }

    public static function check( $transient ) {
        if ( ! is_object( $transient ) ) {
            return $transient;
        }
        $release = self::latest();
        if ( ! $release ) {
            return $transient;
        }
        $plugin = self::plugin_basename();
        $update = self::update_object( $release );
        if ( version_compare( $release['version'], VISWIZ_VERSION, '>' ) ) {
            $transient->response = is_array( $transient->response ?? null ) ? $transient->response : array();
            $transient->response[ $plugin ] = $update;
            if ( isset( $transient->no_update[ $plugin ] ) ) {
                unset( $transient->no_update[ $plugin ] );
            }
        } else {
            $transient->no_update = is_array( $transient->no_update ?? null ) ? $transient->no_update : array();
            $transient->no_update[ $plugin ] = $update;
        }
        return $transient;
    }

    public static function information( $result, string $action, $args ) {
        if ( 'plugin_information' !== $action || empty( $args->slug ) || 'viswiz' !== $args->slug ) {
            return $result;
        }
        $release = self::latest();
        if ( ! $release ) {
            return $result;
        }
        $info                = new \stdClass();
        $info->name          = 'VisWiz WooCommerce Visualizer';
        $info->slug          = 'viswiz';
        $info->version       = $release['version'];
        $info->author        = 'cremedia.studio';
        $info->homepage      = self::REPOSITORY;
        $info->download_link = $release['package'];
        $info->external      = true;
        $info->last_updated  = $release['published_at'];
        $info->sections      = array(
            'description' => '<p>Dataset-first charts, WooCommerce visualizations and investigative node graphs.</p>',
            'changelog'   => '' !== trim( $release['body'] ) ? wpautop( esc_html( $release['body'] ) ) : '<p>See the GitHub release.</p>',
        );
        return $info;
    }

    public static function normalize_source( $source, $remote_source, $upgrader, $hook_extra ) {
        if ( empty( $hook_extra['plugin'] ) || self::plugin_basename() !== $hook_extra['plugin'] ) {
            return $source;
        }
        global $wp_filesystem;
        if ( ! $wp_filesystem || ! is_string( $source ) ) {
            return $source;
        }
        $expected = trailingslashit( $remote_source ) . 'viswiz/';
        if ( trailingslashit( $source ) === $expected ) {
            return $source;
        }
        if ( $wp_filesystem->is_dir( $expected ) ) {
            return $expected;
        }
        return $source;
    }

    public static function clear_cache(): void {
        delete_site_transient( self::CACHE_KEY );
    }

    private static function asset( array $release, string $version ): string {
        $expected = 'viswiz-' . $version . '.zip';
        foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
            if ( $expected === ( $asset['name'] ?? '' ) && ! empty( $asset['browser_download_url'] ) ) {
                return esc_url_raw( $asset['browser_download_url'] );
            }
        }
        return '';
    }

    private static function update_object( array $release ): \stdClass {
        $update              = new \stdClass();
        $update->id          = self::REPOSITORY;
        $update->slug        = 'viswiz';
        $update->plugin      = self::plugin_basename();
        $update->new_version = $release['version'];
        $update->url         = $release['url'];
        $update->package     = $release['package'];

        /*
         * Do not set autoupdate/disable_autoupdate and do not hook auto_update_plugin.
         * WordPress core owns the per-plugin decision through its Plugins list-table
         * toggle and the auto_update_plugins site option.
         */
        return $update;
    }
}
