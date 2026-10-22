<?php
use PHPUnit\Framework\TestCase;

final class ObjectPermissionsIntegrationTest extends TestCase {
    private function source( string $path ): string {
        return file_get_contents( dirname( __DIR__ ) . '/' . $path );
    }

    public function test_dataset_ownership_and_explicit_editors_are_checked_centrally(): void {
        $source = $this->source( 'src/Access/Permissions.php' );
        self::assertStringContainsString( "dataset['created_by']", $source );
        self::assertStringContainsString( 'dataset_editors( $id )', $source );
        self::assertStringContainsString( "user_can( \$user_id, 'edit_posts' )", $source );
        self::assertStringContainsString( "user_can( \$user_id, 'edit_viswiz_datasets' )", $source );
    }

    public function test_visualization_ownership_and_wordpress_meta_cap_mapping(): void {
        $source = $this->source( 'src/Access/Permissions.php' );
        self::assertStringContainsString( "'map_meta_cap'", $source );
        self::assertStringContainsString( "post_author", $source );
        self::assertStringContainsString( 'visualization_editors( $post_id )', $source );
        self::assertStringContainsString( "array( 'do_not_allow' )", $source );
    }

    public function test_dataset_mutation_endpoints_use_object_access(): void {
        foreach ( array( 'src/Rest/Api.php', 'src/Rest/DatasetEditorApi.php', 'src/Rest/SpreadsheetEditorApi.php', 'src/Rest/ImportApi.php' ) as $path ) {
            self::assertStringContainsString( 'Permissions::can_edit_dataset', $this->source( $path ), $path );
        }
    }

    public function test_permission_edits_require_nonce_and_cannot_grant_manager_role(): void {
        $ui = $this->source( 'src/Access/EditorUi.php' );
        $permissions = $this->source( 'src/Access/Permissions.php' );
        self::assertStringContainsString( 'check_admin_referer', $ui );
        self::assertStringContainsString( 'wp_verify_nonce', $ui );
        self::assertStringContainsString( 'Permissions::save_dataset', $ui );
        self::assertStringContainsString( 'Permissions::save_visualization', $ui );
        self::assertStringContainsString( '! self::manager( get_current_user_id() )', $permissions );
    }
}
