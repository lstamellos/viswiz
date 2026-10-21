<?php
namespace VisWiz;

final class Diagnostics {
    private const OPTION = 'viswiz_diagnostics_v2';
    private const MAX_EVENTS = 20;

    /**
     * Store only a bounded error category and machine code.
     * Never retain visualization IDs, user identifiers, queries or import records.
     */
    public static function record( string $area, string $code ): void {
        if ( ! in_array( $area, array( 'public_load', 'import_preview', 'import_commit', 'migration' ), true ) ) {
            return;
        }
        $code = sanitize_key( $code );
        if ( '' === $code ) {
            $code = 'unknown_error';
        }
        // One write per category/code per minute, including anonymous public failures.
        $throttle = 'viswiz_diag_' . md5( $area . ':' . $code );
        if ( get_transient( $throttle ) ) {
            return;
        }
        set_transient( $throttle, 1, MINUTE_IN_SECONDS );

        $events = get_option( self::OPTION, array() );
        $events = is_array( $events ) ? $events : array();
        array_unshift( $events, array( 'time' => gmdate( 'c' ), 'area' => $area, 'code' => $code ) );
        update_option( self::OPTION, array_slice( $events, 0, self::MAX_EVENTS ), false );
    }

    public static function events(): array {
        if ( ! current_user_can( 'manage_viswiz_settings' ) ) {
            return array();
        }
        $events = get_option( self::OPTION, array() );
        return is_array( $events ) ? array_slice( $events, 0, self::MAX_EVENTS ) : array();
    }
}
