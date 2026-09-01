<?php
/**
 * CSV formatting helper shared by CLI and REST endpoints.
 *
 * @package Learnomy
 */

namespace Learnomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Response;

class Csv_Formatter {

	/**
	 * Serialise rows to a CSV string.
	 *
	 * @param string[] $headers Column names in output order.
	 * @param array    $rows    Associative row arrays; missing columns render empty.
	 */
	public static function to_string( array $headers, array $rows ): string {
		// php://temp is an in-memory stream — no disk IO, so WP_Filesystem does not apply.
		$output = fopen( 'php://temp', 'r+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		fputcsv( $output, $headers );

		foreach ( $rows as $row ) {
			$line = [];
			foreach ( $headers as $col ) {
				$line[] = $row[ $col ] ?? '';
			}
			fputcsv( $output, $line );
		}

		rewind( $output );
		$csv = (string) stream_get_contents( $output );
		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the in-memory stream above.

		return $csv;
	}

	/**
	 * Build a downloadable CSV REST response.
	 *
	 * @param string[] $headers  Column names in output order.
	 * @param array    $rows     Associative row arrays.
	 * @param string   $filename Filename without extension (e.g. "transactions").
	 */
	public static function respond( array $headers, array $rows, string $filename ): WP_REST_Response {
		$csv = self::to_string( $headers, $rows );

		$response = new WP_REST_Response( $csv, 200 );
		$response->header( 'Content-Type', 'text/csv; charset=utf-8' );
		$response->header(
			'Content-Disposition',
			sprintf( 'attachment; filename="%s-%s.csv"', sanitize_file_name( $filename ), gmdate( 'Ymd-His' ) )
		);
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate' );

		return $response;
	}
}
