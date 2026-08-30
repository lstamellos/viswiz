<?php
use VisWiz\Database\DatasetRepository;
use VisWiz\Domain\RowWriteGuard;

function viswiz_minimum_assert( bool $condition, string $message ): void {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
}

viswiz_minimum_assert( version_compare( get_bloginfo( 'version' ), '6.5', '>=' ), 'WordPress is older than the declared minimum.' );
viswiz_minimum_assert( (int) get_option( 'viswiz_db_schema_version' ) === VISWIZ_DB_VERSION, 'DB migration did not complete on the minimum WordPress version.' );
viswiz_minimum_assert( '2.0.24' === VISWIZ_VERSION, 'Unexpected plugin version in minimum-version smoke test.' );

$invalid = RowWriteGuard::normalize_payload( 'geo', array( 'rows' => array( array( 'label' => 'Missing coordinates' ) ) ) );
viswiz_minimum_assert( is_wp_error( $invalid ) && 'viswiz_row_payload_validation' === $invalid->get_error_code(), 'Canonical row guard did not reject an invalid geo payload.' );

$valid = RowWriteGuard::normalize_payload(
    'geo',
    array(
        'rows' => array(
            array(
                'uuid'      => '11111111-1111-4111-8111-111111111111',
                'row_key'   => 'athens',
                'label'     => 'Athens',
                'latitude'  => 37.9838,
                'longitude' => 23.7275,
            ),
        ),
    )
);
viswiz_minimum_assert( ! is_wp_error( $valid ), 'Canonical row guard rejected a valid geo payload.' );

$repo = new DatasetRepository();
$id = $repo->create( array( 'name' => 'WP 6.5 smoke', 'schema_type' => 'geo' ) );
viswiz_minimum_assert( ! is_wp_error( $id ), 'Could not create a dataset on WordPress 6.5.' );
$result = $repo->replace_payload( (int) $id, $valid, 1, 'WP 6.5 minimum smoke' );
viswiz_minimum_assert( ! is_wp_error( $result ) && 2 === (int) $result['revision'], 'Could not persist a validated payload on WordPress 6.5.' );
$repo->delete_with_usage_cleanup( (int) $id );

do_action( 'rest_api_init' );
$routes = rest_get_server()->get_routes();
viswiz_minimum_assert( isset( $routes['/viswiz/v2/datasets/(?P<id>\d+)/editor/rows/batch'] ), 'Spreadsheet batch REST route is missing on WordPress 6.5.' );
viswiz_minimum_assert( isset( $routes['/viswiz/v2/datasets/(?P<id>\d+)/rows'] ), 'Legacy row REST route is missing on WordPress 6.5.' );

echo "VisWiz WordPress 6.5 minimum smoke tests passed.\n";
