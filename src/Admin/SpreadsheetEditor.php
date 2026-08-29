<?php
namespace VisWiz\Admin;

use VisWiz\Database\DatasetRepository;

final class SpreadsheetEditor {
    public static function register(): void {
        add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ), 70 );
    }

    public static function assets(): void {
        $page       = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        $dataset_id = isset( $_GET['dataset_id'] ) ? absint( $_GET['dataset_id'] ) : 0;
        if ( 'viswiz-datasets' !== $page || ! $dataset_id ) {
            return;
        }

        $dataset = ( new DatasetRepository() )->get( $dataset_id );
        if ( ! $dataset || 'graph' === $dataset['schema_type'] ) {
            return;
        }

        wp_enqueue_style(
            'viswiz-spreadsheet-editor-v2',
            VISWIZ_URL . 'assets/viswiz-spreadsheet-editor.css',
            array( 'viswiz-admin-v2' ),
            VISWIZ_VERSION
        );
        wp_enqueue_script(
            'viswiz-spreadsheet-editor-v2',
            VISWIZ_URL . 'assets/viswiz-spreadsheet-editor.js',
            array( 'viswiz-dataset-editor-v2' ),
            VISWIZ_VERSION,
            true
        );
    }
}
