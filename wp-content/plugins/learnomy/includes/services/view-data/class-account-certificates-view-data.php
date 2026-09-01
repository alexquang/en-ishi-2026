<?php
/**
 * View-data resolver for the account certificates section (/account/certificates/).
 *
 * Lists the learner's earned certificates — the dedicated home for what used to
 * be the dashboard "Your Certificates" widget. Template stays presentation-only.
 *
 * @package Learnomy
 */

namespace Learnomy\Services\View_Data;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Certificate;
use Learnomy\Services\Certificate_Service;

class Account_Certificates_View_Data {

	/**
	 * @param array $data Route data (unused).
	 * @return array Pre-fetched view data.
	 */
	public static function resolve( array $data ): array {
		unset( $data );

		$user_id = get_current_user_id();
		// Active only — a revoked/expired certificate must not show on the
		// student's own account as earned (Basecamp 10109147988).
		$certificates = $user_id ? Certificate::get_active_for_user( $user_id ) : array();

		return array(
			'certificates'       => $certificates,
			// What each credential says it is for, snapshot first — a retired
			// course must not blank the label on the learner's own certificate
			// (Basecamp 10148480574). Replaces a per-certificate Course::find()
			// loop that existed only to print a title the row now carries.
			'certificate_titles' => Certificate_Service::display_titles( $certificates ),
		);
	}
}
