<?php
namespace VisWiz\Admin;

final class ImportUi {
    public static function register(): void {
        add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ), 120 );
    }

    public static function assets(): void {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        $dataset_id = isset( $_GET['dataset_id'] ) ? absint( $_GET['dataset_id'] ) : 0;
        if ( 'viswiz-datasets' !== $page || ! $dataset_id || ! current_user_can( 'edit_viswiz_datasets' ) ) {
            return;
        }

        wp_enqueue_style(
            'viswiz-import-v2',
            VISWIZ_URL . 'assets/viswiz-import.css',
            array( 'viswiz-admin-v2' ),
            VISWIZ_VERSION
        );
        wp_enqueue_script(
            'viswiz-import-v2',
            VISWIZ_URL . 'assets/viswiz-import.js',
            array('viswiz-admin-v2', 'wp-i18n' ),
            VISWIZ_VERSION,
            true
        );
        wp_set_script_translations( 'viswiz-import-v2', 'viswiz', VISWIZ_DIR . 'languages' );
    }
}
