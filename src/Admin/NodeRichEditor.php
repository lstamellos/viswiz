<?php
namespace VisWiz\Admin;

use VisWiz\Database\DatasetRepository;

final class NodeRichEditor {
    public static function register(): void {
        add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ), 70 );
    }

    public static function assets(): void {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        $dataset_id = isset( $_GET['dataset_id'] ) ? absint( $_GET['dataset_id'] ) : 0;
        if ( 'viswiz-datasets' !== $page || ! $dataset_id ) {
            return;
        }

        $dataset = ( new DatasetRepository() )->get( $dataset_id );
        if ( ! $dataset || 'graph' !== (string) $dataset['schema_type'] ) {
            return;
        }

        // Load the WordPress classic editor API for editors instantiated after page load.
        wp_enqueue_editor();
        wp_enqueue_script(
            'viswiz-node-rich-editor',
            VISWIZ_URL . 'assets/viswiz-node-rich-editor.js',
            array( 'editor', 'viswiz-dataset-editor-v2' ),
            VISWIZ_VERSION,
            true
        );
        wp_add_inline_style(
            'viswiz-admin-v2',
            '.viswiz-rich-editor-field{display:block;margin:0 0 14px}.viswiz-rich-editor-label{display:block;margin:0 0 6px;font-weight:600}.viswiz-rich-editor-field .wp-editor-wrap{width:100%}.viswiz-rich-editor-field .wp-editor-container{border-color:#8c8f94}.viswiz-rich-editor-field .wp-editor-area{width:100%;box-sizing:border-box}.viswiz-rich-editor-field.is-fallback textarea{min-height:150px}'
        );
    }
}
