<?php
namespace VisWiz\Admin;

use VisWiz\Diagnostics;

final class DiagnosticsPage {
    public static function register(): void {
        add_action( 'admin_menu', array( self::class, 'menu' ), 6 );
    }

    public static function menu(): void {
        add_submenu_page(
            'viswiz',
            __( 'Diagnostics', 'viswiz' ),
            __( 'Diagnostics', 'viswiz' ),
            'manage_viswiz_settings',
            'viswiz-diagnostics',
            array( self::class, 'render' )
        );
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_viswiz_settings' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'viswiz' ) );
        }
        $installed = (int) get_option( 'viswiz_db_schema_version', 0 );
        $migrated = (string) get_option( 'viswiz_v2_legacy_migrated', '' );
        $events = Diagnostics::events();
        ?>
        <div class="wrap viswiz-admin-wrap">
            <h1><?php esc_html_e( 'VisWiz diagnostics', 'viswiz' ); ?></h1>
            <p><?php esc_html_e( 'Recent error categories and database maintenance status. No dataset contents, identifiers, query values or import records are stored in this log.', 'viswiz' ); ?></p>
            <h2><?php esc_html_e( 'Database / legacy migration', 'viswiz' ); ?></h2>
            <p><?php echo esc_html( sprintf( __( 'Installed schema: %d; expected: %d', 'viswiz' ), $installed, VISWIZ_DB_VERSION ) ); ?></p>
            <p><?php echo esc_html( $migrated ? __( 'Legacy migration completed.', 'viswiz' ) : __( 'Legacy migration has not been marked complete.', 'viswiz' ) ); ?></p>
            <h2><?php esc_html_e( 'Recent failures', 'viswiz' ); ?></h2>
            <?php if ( ! $events ) : ?>
                <p><?php esc_html_e( 'No recorded failures.', 'viswiz' ); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead><tr>
                        <th><?php esc_html_e( 'UTC time', 'viswiz' ); ?></th>
                        <th><?php esc_html_e( 'Component', 'viswiz' ); ?></th>
                        <th><?php esc_html_e( 'Error code', 'viswiz' ); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $events as $event ) : ?>
                        <tr>
                            <td><?php echo esc_html( (string) ( $event['time'] ?? '' ) ); ?></td>
                            <td><code><?php echo esc_html( (string) ( $event['area'] ?? '' ) ); ?></code></td>
                            <td><code><?php echo esc_html( (string) ( $event['code'] ?? '' ) ); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
