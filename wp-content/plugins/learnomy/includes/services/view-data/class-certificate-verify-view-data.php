<?php
/**
 * View-data resolver for the certificate-verify template.
 *
 * Replaces direct Model + Renderer calls in templates/certificate-verify.php
 * with a pre-resolved data array so the template has zero DB queries
 * and zero rendering side effects.
 *
 * @package Learnomy
 */

namespace Learnomy\Services\View_Data;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Certificate;
use Learnomy\Models\CertificateTemplate;
use Learnomy\Models\Course;
use Learnomy\Services\Certificate_Renderer;
use Learnomy\Services\Certificate_Service;

class Certificate_Verify_View_Data {

	/**
	 * Resolve all data needed by templates/certificate-verify.php.
	 *
	 * @param array $data Route data — must include 'slug' (certificate UUID).
	 * @return array Pre-fetched view data.
	 */
	public static function resolve( array $data ): array {
		$slug        = (string) ( $data['slug'] ?? '' );
		$certificate = $slug ? Certificate::verify( $slug ) : null;
		$is_valid    = $certificate && 'active' === $certificate->status;
		$is_revoked  = $certificate && 'revoked' === $certificate->status;

		// Tamper detection: re-verify the stored Ed25519 signature. An active
		// certificate whose signature no longer matches its uuid/user/course was
		// altered, so it must NOT be reported as valid.
		//
		// `signature_state()` is the authority and returns 'invalid' ONLY when a
		// signature is actually stored and fails — including when it is not
		// decodable base64, which previously reported valid (Basecamp 10134139810).
		// An unsigned or uncheckable certificate is never failed just because it
		// cannot be proved. Same method backs the REST verify endpoint, so the two
		// surfaces cannot drift apart again.
		$is_tampered = false;
		if ( $is_valid && 'invalid' === ( new Certificate_Service() )->signature_state( $certificate ) ) {
			$is_valid    = false;
			$is_tampered = true;
		}

		$out = array(
			'certificate' => $certificate,
			'is_valid'    => $is_valid,
			'is_revoked'  => $is_revoked,
			'is_tampered' => $is_tampered,
		);

		// A UUID that resolves to no certificate at all is a genuine
		// "not found" — send a 404 so search crawlers do not index the
		// empty-state page as a real URL. A revoked certificate is a real
		// record (it exists, it is just no longer valid), so it stays 200.
		if ( null === $certificate && ! headers_sent() && function_exists( 'status_header' ) ) {
			status_header( 404 );
			nocache_headers();
		}

		// A REVOKED certificate is a real record and the page needs to say WHICH
		// credential was revoked and WHEN. It shipped rendering only a generic
		// "Certificate Revoked" card while `revoked_at` sat populated in the
		// database, so a verifier could not tell whether the revocation predated
		// the candidate's claim (Basecamp 10134139845 / 10134139810).
		//
		// Only the three identifying facts — not the rendered certificate, QR or
		// download, which would amount to re-issuing something we just revoked.
		if ( $is_revoked ) {
			$revoked_facts             = Certificate_Service::asserted_facts( $certificate );
			$out['lrn_recipient_name'] = $revoked_facts['recipient_name'];
			$out['lrn_course_title']   = $revoked_facts['course_title'];
		}

		if ( ! $is_valid ) {
			return $out;
		}

		// What this credential asserts, read from its own snapshot in preference
		// to the live rows: a course that has since been renamed or retired must
		// not be able to rewrite or blank a certificate already earned
		// (Basecamp 10148480574).
		$facts = Certificate_Service::asserted_facts( $certificate );

		// $student / $course stay part of the documented template contract (a
		// theme override at theme/learnomy/certificate-verify.php may read them),
		// but nothing the page PRINTS is resolved from them any more. Both are
		// null when the person or the course is gone, which is precisely why they
		// cannot be what the credential asserts.
		$student = get_userdata( (int) $certificate->user_id );
		$course  = Course::find( (int) $certificate->course_id );

		$template = null;
		if ( ! empty( $certificate->template_id ) ) {
			$template = CertificateTemplate::find( (int) $certificate->template_id );
		}

		$renderer         = new Certificate_Renderer();
		$certificate_html = $renderer->render_html( $certificate, $template );

		// Locally generated QR for the verify-page sidebar — no external HTTP.
		$verify_url = \Learnomy\route_url( 'certificate-verify', array( 'slug' => (string) $certificate->uuid ) );
		$qr_src     = $renderer->qr_data_uri( $verify_url );

		$out['student']      = $student;
		$out['student_name'] = '' !== $facts['recipient_name'] ? $facts['recipient_name'] : __( 'Unknown', 'learnomy' );
		$out['course']       = $course;
		// The course line, as the certificate itself prints it. When the title was
		// never recorded AND the course row is gone it is genuinely unrecoverable,
		// so the page says what remains true — this person completed a course of
		// study at this academy — and explains the gap in `course_note` rather
		// than printing "Course no longer available", which reads to an employer
		// like a void notice and tells them nothing.
		$out['course_name']      = Certificate_Service::course_display_title( $facts );
		$out['course_recorded']  = 'unavailable' !== $facts['course_title_source'];
		$out['course_note']      = $out['course_recorded']
			? ''
			: __( 'This course was removed from the catalogue after the certificate was issued, so its name is no longer on record. The credential itself is unchanged and still verifies.', 'learnomy' );
		$out['issuer_name']      = $facts['issuer_name'];
		$out['issued_date']      = date_i18n( get_option( 'date_format' ), strtotime( $certificate->issued_at ) );
		$out['short_id']         = strtoupper( substr( $certificate->uuid, 0, 8 ) );
		$out['template']         = $template;
		$out['certificate_html'] = $certificate_html;
		$out['qr_src']           = $qr_src;
		$out['pdf_available']    = Certificate_Renderer::dompdf_available();
		$out['pdf_url']          = rest_url( 'learnomy/v1/certificates/' . urlencode( $certificate->uuid ) . '/download?format=pdf' );
		$out['html_dl_url']      = rest_url( 'learnomy/v1/certificates/' . urlencode( $certificate->uuid ) . '/download?format=html' );

		return $out;
	}
}
