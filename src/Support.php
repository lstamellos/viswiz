<?php
namespace VisWiz;

final class Support {
    public static function is_uuid( string $candidate ): bool {
        return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', strtolower( trim( $candidate ) ) );
    }

    public static function uuid( string $candidate = '' ): string {
        $candidate = strtolower( trim( $candidate ) );
        return self::is_uuid( $candidate ) ? $candidate : wp_generate_uuid4();
    }

    public static function json_decode_array( mixed $value ): array {
        if ( is_array( $value ) ) {
            return $value;
        }
        if ( ! is_string( $value ) || '' === trim( $value ) ) {
            return array();
        }
        $decoded = json_decode( $value, true );
        return is_array( $decoded ) ? $decoded : array();
    }

    public static function json( mixed $value ): string {
        return (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    }

    public static function sanitize_meta( mixed $value ): array {
        $meta = self::json_decode_array( $value );
        return self::sanitize_recursive( $meta );
    }

    public static function sanitize_recursive( mixed $value ): mixed {
        if ( is_array( $value ) ) {
            $out = array();
            foreach ( $value as $key => $item ) {
                $safe_key         = is_string( $key ) ? sanitize_key( $key ) : $key;
                $out[ $safe_key ] = self::sanitize_recursive( $item );
            }
            return $out;
        }
        if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
            return $value;
        }
        return sanitize_textarea_field( (string) $value );
    }

    public static function sanitize_html( mixed $value ): string {
        return wp_kses_post( (string) $value );
    }

    public static function sanitize_color( mixed $value, string $fallback = '' ): string {
        $value = (string) $value;
        $hex   = sanitize_hex_color( $value );
        return $hex ?: $fallback;
    }

    public static function bool( mixed $value ): bool {
        return rest_sanitize_boolean( $value );
    }

    public static function int_list( mixed $value ): array {
        if ( is_string( $value ) ) {
            $value = preg_split( '/[\s,]+/', $value ) ?: array();
        }
        return array_values( array_unique( array_filter( array_map( 'absint', (array) $value ) ) ) );
    }

    public static function table( string $suffix ): string {
        global $wpdb;
        return $wpdb->prefix . 'viswiz_v2_' . $suffix;
    }

    public static function legacy_table( string $suffix ): string {
        global $wpdb;
        return $wpdb->prefix . 'viswiz_' . $suffix;
    }
}
