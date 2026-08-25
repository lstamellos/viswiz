<?php
namespace VisWiz\Update;

final class GitHubUpdater {
    private const REPOSITORY = 'https://github.com/lstamellos/viswiz';
    private const API        = 'https://api.github.com/repos/lstamellos/viswiz';
    private const CACHE_KEY  = 'viswiz_github_latest_release_v2';

    public static function register(): void {
        add_filter( 'pre_set_site_transient_update_plugins', array( self::class, 'check' ) );
        add_filter( 'plugins_api', array( self::class, 'information' ), 20, 3 );
        add_filter( 'auto_update_plugin', array( self::class, 'auto_update' ), 10, 2 );
        add_filter( 'upgrader_source_selection', array( self::class, 'normalize_source' ), 10, 4 );
        add_action( 'upgrader_process_complete', array( self::class, 'clear_cache' ), 10, 2 );
        add_action( 'admin_menu', array( self::class, 'menu' ), 99 );
        add_action( 'network_admin_menu', array( self::class, 'network_menu' ), 99 );
        add_action( 'admin_post_viswiz_save_auto_update_setting', array( self::class, 'save_setting' ) );
        add_filter( 'plugin_action_links_' . self::plugin_basename(), array( self::class, 'action_links' ) );
        add_filter( 'network_admin_plugin_action_links_' . self::plugin_basename(), array( self::class, 'action_links' ) );
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

    public static function auto_updates_enabled(): bool {
        return in_array( self::plugin_basename(), (array) get_site_option( 'auto_update_plugins', array() ), true );
    }

    public static function auto_update( $decision, $item ) {
        $plugin = is_object( $item ) ? (string) ( $item->plugin ?? '' ) : (string) ( $item['plugin'] ?? '' );
        if ( self::plugin_basename() !== $plugin ) {
            return $decision;
        }
        return self::auto_updates_enabled();
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

    public static function menu(): void {
        if ( is_network_admin() || ! current_user_can( 'manage_viswiz_updates' ) ) {
            return;
        }
        add_submenu_page( 'viswiz', __( 'VisWiz updates', 'viswiz' ), __( 'Updates', 'viswiz' ), 'manage_viswiz_updates', 'viswiz-updates', array( self::class, 'page' ) );
    }

    public static function network_menu(): void {
        if ( ! is_multisite() || ! current_user_can( 'update_plugins' ) ) {
            return;
        }
        add_submenu_page( 'settings.php', __( 'VisWiz updates', 'viswiz' ), __( 'VisWiz updates', 'viswiz' ), 'update_plugins', 'viswiz-updates', array( self::class, 'page' ) );
    }

    public static function action_links( array $links ): array {
        if ( ! current_user_can( 'manage_viswiz_updates' ) && ! current_user_can( 'update_plugins' ) ) {
            return $links;
        }
        $url = is_network_admin() && is_multisite()
            ? network_admin_url( 'settings.php?page=viswiz-updates' )
            : admin_url( 'admin.php?page=viswiz-updates' );
        array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Update settings', 'viswiz' ) . '</a>' );
        return $links;
    }

    public static function page(): void {
        if ( ! current_user_can( 'manage_viswiz_updates' ) && ! current_user_can( 'update_plugins' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'viswiz' ) );
        }
        $release = self::latest( ! empty( $_GET['force-check'] ) );
        $enabled = self::auto_updates_enabled();
        ?>
        <div class="wrap viswiz-admin-wrap">
            <h1><?php esc_html_e( 'VisWiz updates', 'viswiz' ); ?></h1>
            <?php if ( ! empty( $_GET['updated'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Automatic update setting saved.', 'viswiz' ); ?></p></div><?php endif; ?>
            <?php if ( defined( 'VISWIZ_AUTO_UPDATES' ) ) : ?><div class="notice notice-info"><p><?php esc_html_e( 'VISWIZ_AUTO_UPDATES is deprecated and no longer locks this setting. Automatic updates are managed from WordPress.', 'viswiz' ); ?></p></div><?php endif; ?>
            <table class="widefat striped" style="max-width:760px"><tbody>
                <tr><th><?php esc_html_e( 'Installed', 'viswiz' ); ?></th><td><code><?php echo esc_html( VISWIZ_VERSION ); ?></code></td></tr>
                <tr><th><?php esc_html_e( 'Latest release', 'viswiz' ); ?></th><td><code><?php echo esc_html( is_array( $release ) ? $release['version'] : __( 'Unavailable', 'viswiz' ) ); ?></code></td></tr>
                <tr><th><?php esc_html_e( 'Automatic updates', 'viswiz' ); ?></th><td><strong><?php echo esc_html( $enabled ? __( 'Enabled', 'viswiz' ) : __( 'Disabled', 'viswiz' ) ); ?></strong></td></tr>
            </tbody></table>
            <p><a class="button" href="<?php echo esc_url( add_query_arg( 'force-check', '1' ) ); ?>"><?php esc_html_e( 'Check GitHub now', 'viswiz' ); ?></a></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="viswiz_save_auto_update_setting">
                <input type="hidden" name="network_context" value="<?php echo is_network_admin() ? '1' : '0'; ?>">
                <?php wp_nonce_field( 'viswiz_save_auto_update_setting' ); ?>
                <label><input type="checkbox" name="enabled" value="1" <?php checked( $enabled ); ?>> <?php esc_html_e( 'Automatically install new VisWiz releases', 'viswiz' ); ?></label>
                <p class="description"><?php esc_html_e( 'This uses the native WordPress automatic-update setting for this plugin and stays in sync with the Plugins screen.', 'viswiz' ); ?></p>
                <p><button class="button button-primary"><?php esc_html_e( 'Save', 'viswiz' ); ?></button></p>
            </form>
        </div>
        <?php
    }

    public static function save_setting(): void {
        if ( ! current_user_can( 'manage_viswiz_updates' ) && ! current_user_can( 'update_plugins' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'viswiz' ) );
        }
        check_admin_referer( 'viswiz_save_auto_update_setting' );

        $plugin  = self::plugin_basename();
        $plugins = array_values( array_unique( array_map( 'strval', (array) get_site_option( 'auto_update_plugins', array() ) ) ) );
        $enabled = ! empty( $_POST['enabled'] );
        $index   = array_search( $plugin, $plugins, true );
        if ( $enabled && false === $index ) {
            $plugins[] = $plugin;
        } elseif ( ! $enabled && false !== $index ) {
            unset( $plugins[ $index ] );
        }
        update_site_option( 'auto_update_plugins', array_values( $plugins ) );
        self::clear_cache();
        delete_site_transient( 'update_plugins' );
        wp_clean_plugins_cache( true );

        $network_context = ! empty( $_POST['network_context'] ) && is_multisite();
        $redirect = $network_context
            ? network_admin_url( 'settings.php?page=viswiz-updates&updated=1' )
            : admin_url( 'admin.php?page=viswiz-updates&updated=1' );
        wp_safe_redirect( $redirect );
        exit;
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
        return $update;
    }
}
