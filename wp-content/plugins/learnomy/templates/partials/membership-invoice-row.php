<?php
/**
 * Single invoice row for the billing-history table on /membership/manage/.
 *
 * Required variables (set by including template):
 *   $invoice         object Transaction row from Transaction::get_for_subscription().
 *   $currency_symbol string Fallback currency glyph when the row doesn't carry one.
 *
 * Never called standalone — the wrapping <table> is emitted by the manage
 * template, this partial only renders a single <tr>.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

/** @var object $invoice */

// This partial used to carry its own 6-entry currency-symbol map — a second,
// smaller copy of \Learnomy\currency_symbols() that silently fell back to '$'
// for anything outside it, and formatted with a hard '.' decimal. format_price()
// is the one formatter: it resolves the symbol, the per-currency decimals and
// the locale-aware placement together.
$row_currency   = strtoupper( (string) ( $invoice->currency ?? 'USD' ) );
$amount_display = \Learnomy\format_price( (float) ( $invoice->amount ?? 0 ), $row_currency );
$status         = (string) ( $invoice->status ?? 'pending' );
$status_classes = array(
	'completed' => 'lrn-badge lrn-badge--green',
	'pending'   => 'lrn-badge lrn-badge--amber',
	'failed'    => 'lrn-badge lrn-badge--red',
	'refunded'  => 'lrn-badge lrn-badge--blue',
);
$badge_class    = $status_classes[ $status ] ?? 'lrn-badge lrn-badge--blue';

$created_display = ! empty( $invoice->created_at )
	? date_i18n( get_option( 'date_format' ), strtotime( (string) $invoice->created_at ) )
	: '—';

/**
 * Filter the PDF download URL for an invoice row.
 *
 * Default: the existing Free `/account/billing/<txn>/receipt/` endpoint
 * (introduced in PR-A2, exercised by the receipt-pdf-service path). The
 * comment that used to live here claimed Free shipped without a PDF
 * endpoint and left this empty — which surfaced as J-02.8 in
 * journey-walk-02: the same transaction had a working receipt link on
 * /account/billing/ but rendered an em-dash on /membership/manage/.
 *
 * Pro can still override the filter to swap in a signed long-form
 * invoice URL.
 *
 * @param string $url     Download URL.
 * @param object $invoice Full invoice/transaction row.
 */
$default_pdf_url = isset( $invoice->id ) ? \Learnomy\route_url( 'account-billing-receipt', array( 'txn' => (int) $invoice->id ) ) : '';
$pdf_url         = (string) apply_filters( 'learnomy_invoice_pdf_url', $default_pdf_url, $invoice );
?>
<tr>
	<td><?php echo esc_html( $created_display ); ?></td>
	<td class="lrn-invoice-table__amount"><?php echo esc_html( $amount_display ); ?></td>
	<td>
		<span class="<?php echo esc_attr( $badge_class ); ?>">
			<?php echo esc_html( ucfirst( $status ) ); ?>
		</span>
	</td>
	<td class="lrn-invoice-table__download">
		<?php
		if ( '' !== $pdf_url ) :
			$pdf_aria_label = sprintf(
				/* translators: %s: invoice date. */
				__( 'Download invoice PDF for %s', 'learnomy' ),
				$created_display
			);
			?>
			<a
				href="<?php echo esc_url( $pdf_url ); ?>"
				target="_blank"
				rel="noopener"
				aria-label="<?php echo esc_attr( $pdf_aria_label ); ?>"
			>
				<i data-lucide="download" aria-hidden="true"></i>
				<span aria-hidden="true"><?php esc_html_e( 'PDF', 'learnomy' ); ?></span>
			</a>
		<?php else : ?>
			<span aria-hidden="true">—</span>
		<?php endif; ?>
	</td>
</tr>
