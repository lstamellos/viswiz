<?php
namespace VisWiz\Admin;

use VisWiz\Database\DatasetRepository;

final class NodePublicFields {
    public static function register(): void {
        add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ), 75 );
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

        wp_enqueue_script(
            'viswiz-node-public-fields',
            VISWIZ_URL . 'assets/viswiz-node-public-fields.js',
            array( 'viswiz-dataset-editor-v2', 'wp-i18n' ),
            VISWIZ_VERSION,
            true
        );
        wp_set_script_translations( 'viswiz-node-public-fields', 'viswiz', VISWIZ_DIR . 'languages' );
        wp_add_inline_style(
            'viswiz-admin-v2',
            '.viswiz-public-fields{padding:14px;border:1px solid #dcdcde;border-radius:8px;background:#fff}.viswiz-public-fields .viswiz-section-heading{margin-bottom:8px}.viswiz-public-fields .viswiz-section-heading h3{margin:0}.viswiz-public-fields-list{display:grid;gap:10px}.viswiz-public-fields-empty{margin:8px 0;color:#646970;font-style:italic}.viswiz-public-field-row{display:grid;grid-template-columns:minmax(140px,1fr) 130px minmax(180px,2fr) auto;gap:8px;align-items:start;padding:10px;border:1px solid #dcdcde;border-radius:7px;background:#f6f7f7}.viswiz-public-field-row label{display:grid;gap:4px}.viswiz-public-field-row label>span{font-weight:600}.viswiz-public-field-row input,.viswiz-public-field-row select,.viswiz-public-field-row textarea{width:100%;max-width:none}.viswiz-public-field-actions{display:flex;gap:4px;padding-top:24px;white-space:nowrap}.viswiz-node-meta-advanced{margin-top:0}@media(max-width:782px){.viswiz-public-field-row{grid-template-columns:1fr}.viswiz-public-field-actions{padding-top:0}}'
        );
    }
}
