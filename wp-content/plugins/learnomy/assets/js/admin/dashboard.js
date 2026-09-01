/**
 * Dashboard analytics charts powered by ApexCharts.
 *
 * Reads learnomyDashboard (localized from PHP) which contains 90 days of
 * enrollment and revenue data, completion breakdown, and top courses.
 * The period selector filters the pre-loaded data client-side.
 *
 * @package Learnomy
 */

( function () {
	'use strict';

	var __ = wp.i18n.__;

	if ( typeof learnomyDashboard === 'undefined' || typeof ApexCharts === 'undefined' ) {
		return;
	}

	var data = learnomyDashboard;

	/* ── Helpers ─────────────────────────────────────────────────────────── */

	/**
	 * Read a CSS custom property from the document root.
	 *
	 * @param {string} name Token name including the -- prefix.
	 * @return {string} The computed value or fallback empty string.
	 */
	function token( name ) {
		return getComputedStyle( document.documentElement ).getPropertyValue( name ).trim();
	}

	/**
	 * Filter a date-keyed series to the last N days.
	 *
	 * @param {Array}  series Array of { x: 'YYYY-MM-DD', y: number }.
	 * @param {number} days   Number of trailing days to keep.
	 * @return {Array} Filtered series.
	 */
	function filterByDays( series, days ) {
		if ( ! series || ! series.length ) {
			return [];
		}

		var cutoff = new Date();
		cutoff.setDate( cutoff.getDate() - days );
		cutoff.setHours( 0, 0, 0, 0 );

		return series.filter( function ( point ) {
			return new Date( point.x + 'T00:00:00' ) >= cutoff;
		} );
	}

	/**
	 * Sum the y values of a series.
	 *
	 * @param {Array} series Array of { x, y }.
	 * @return {number}
	 */
	function sumSeries( series ) {
		var total = 0;
		for ( var i = 0; i < series.length; i++ ) {
			total += series[ i ].y;
		}
		return total;
	}

	/**
	 * Format a number as currency using the configured currency code.
	 *
	 * @param {number} amount
	 * @return {string}
	 */
	function formatCurrency( amount ) {
		try {
			return new Intl.NumberFormat( undefined, {
				style: 'currency',
				currency: data.currency || 'USD',
				minimumFractionDigits: 0,
				maximumFractionDigits: 2,
			} ).format( amount );
		} catch ( e ) {
			return '$' + amount.toFixed( 2 );
		}
	}

	/**
	 * Create a DOM element with optional class name and text content.
	 *
	 * @param {string}  tag       HTML tag name.
	 * @param {string}  className CSS class(es) or empty string.
	 * @param {string=} text      Optional text content.
	 * @return {HTMLElement}
	 */
	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( typeof text !== 'undefined' ) {
			node.textContent = text;
		}
		return node;
	}

	/* ── Chart color palette from CSS tokens ────────────────────────────── */

	var colors = {
		accent:    token( '--lrn-accent' )    || '#2383e2',
		success:   token( '--lrn-success' )   || '#2ea44f',
		warn:      token( '--lrn-warn' )      || '#d97706',
		danger:    token( '--lrn-danger' )     || '#e03e3e',
		text:      token( '--lrn-text' )       || '#37352f',
		textSec:   token( '--lrn-text-secondary' ) || '#6b6b6b',
		border:    token( '--lrn-border' )     || 'rgba(55,53,47,0.09)',
		bg:        token( '--lrn-bg' )         || '#ffffff',
		accentBg:  token( '--lrn-accent-bg' )  || 'rgba(35,131,226,0.08)',
	};

	/* ── Shared chart defaults ──────────────────────────────────────────── */

	var fontFamily = token( '--lrn-font' ) || '-apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif';

	var baseChartOptions = {
		chart: {
			fontFamily: fontFamily,
			toolbar: { show: false },
			zoom: { enabled: false },
		},
		grid: {
			borderColor: colors.border,
			strokeDashArray: 3,
			xaxis: { lines: { show: false } },
		},
		xaxis: {
			type: 'datetime',
			labels: {
				style: { colors: colors.textSec, fontSize: '11px' },
			},
			axisBorder: { show: false },
			axisTicks: { show: false },
		},
		yaxis: {
			labels: {
				style: { colors: colors.textSec, fontSize: '11px' },
			},
		},
		tooltip: {
			theme: 'light',
			style: { fontSize: '12px' },
		},
		dataLabels: { enabled: false },
	};

	/* ── Enrollment Trend (line chart) ──────────────────────────────────── */

	var enrollmentEl = document.getElementById( 'lrn-chart-enrollments' );
	var enrollmentChart;

	function renderEnrollmentChart( days ) {
		var series = filterByDays( data.enrollments, days );
		var config = Object.assign( {}, baseChartOptions, {
			chart: Object.assign( {}, baseChartOptions.chart, {
				type: 'line',
				height: 260,
			} ),
			series: [ {
				name: __( 'Enrollments', 'learnomy' ),
				data: series.map( function ( p ) {
					return { x: new Date( p.x + 'T00:00:00' ).getTime(), y: p.y };
				} ),
			} ],
			stroke: { width: 2.5, curve: 'smooth' },
			colors: [ colors.accent ],
			markers: { size: 0, hover: { size: 4 } },
			yaxis: Object.assign( {}, baseChartOptions.yaxis, {
				min: 0,
				forceNiceScale: true,
			} ),
		} );

		if ( enrollmentChart ) {
			enrollmentChart.updateOptions( config, true, true );
		} else if ( enrollmentEl ) {
			enrollmentChart = new ApexCharts( enrollmentEl, config );
			enrollmentChart.render();
		}
	}

	/* ── Revenue Overview (area chart) ──────────────────────────────────── */

	var revenueEl      = document.getElementById( 'lrn-chart-revenue' );
	var revenueTotalEl = document.getElementById( 'lrn-revenue-total' );
	var revenueChart;

	function renderRevenueChart( days ) {
		var series      = filterByDays( data.revenue, days );
		var periodTotal = sumSeries( series );

		if ( revenueTotalEl ) {
			revenueTotalEl.textContent = formatCurrency( periodTotal );
		}

		var config = Object.assign( {}, baseChartOptions, {
			chart: Object.assign( {}, baseChartOptions.chart, {
				type: 'area',
				height: 260,
			} ),
			series: [ {
				name: __( 'Revenue', 'learnomy' ),
				data: series.map( function ( p ) {
					return { x: new Date( p.x + 'T00:00:00' ).getTime(), y: p.y };
				} ),
			} ],
			stroke: { width: 2, curve: 'smooth' },
			colors: [ colors.success ],
			fill: {
				type: 'gradient',
				gradient: {
					shadeIntensity: 1,
					opacityFrom: 0.35,
					opacityTo: 0.05,
					stops: [ 0, 100 ],
				},
			},
			yaxis: Object.assign( {}, baseChartOptions.yaxis, {
				min: 0,
				forceNiceScale: true,
				labels: {
					style: { colors: colors.textSec, fontSize: '11px' },
					formatter: function ( val ) {
						return formatCurrency( val );
					},
				},
			} ),
			tooltip: {
				theme: 'light',
				y: {
					formatter: function ( val ) {
						return formatCurrency( val );
					},
				},
			},
		} );

		if ( revenueChart ) {
			revenueChart.updateOptions( config, true, true );
		} else if ( revenueEl ) {
			revenueChart = new ApexCharts( revenueEl, config );
			revenueChart.render();
		}
	}

	/* ── Course Completion (donut chart) ─────────────────────────────────── */

	var completionEl = document.getElementById( 'lrn-chart-completion' );

	function renderCompletionChart() {
		if ( ! completionEl ) {
			return;
		}

		var comp   = data.completion || {};
		var values = [
			comp.completed   || 0,
			comp.in_progress || 0,
			comp.not_started || 0,
		];
		var total = values[ 0 ] + values[ 1 ] + values[ 2 ];

		var config = {
			chart: {
				type: 'donut',
				height: 280,
				fontFamily: fontFamily,
			},
			series: values,
			labels: [ __( 'Completed', 'learnomy' ), __( 'In Progress', 'learnomy' ), __( 'Not Started', 'learnomy' ) ],
			colors: [ colors.success, colors.accent, colors.warn ],
			legend: {
				position: 'bottom',
				fontSize: '12px',
				labels: { colors: colors.text },
				markers: { radius: 2 },
			},
			plotOptions: {
				pie: {
					donut: {
						size: '60%',
						labels: {
							show: true,
							name: { fontSize: '13px', color: colors.text },
							value: {
								fontSize: '20px',
								fontWeight: 600,
								color: colors.text,
							},
							total: {
								show: true,
								label: __( 'Total', 'learnomy' ),
								fontSize: '12px',
								color: colors.textSec,
								formatter: function () {
									return total;
								},
							},
						},
					},
				},
			},
			dataLabels: { enabled: false },
			stroke: { width: 2, colors: [ colors.bg ] },
			tooltip: {
				y: {
					formatter: function ( val ) {
						if ( total === 0 ) {
							return val + ' (0%)';
						}
						return val + ' (' + Math.round( val / total * 100 ) + '%)';
					},
				},
			},
		};

		var chart = new ApexCharts( completionEl, config );
		chart.render();
	}

	/* ── Top Courses Table + Sparklines ──────────────────────────────────── */

	function renderTopCourses() {
		var tbody = document.getElementById( 'lrn-top-courses-body' );
		if ( ! tbody ) {
			return;
		}

		var courses = data.topCourses || [];

		// Clear existing content safely.
		while ( tbody.firstChild ) {
			tbody.removeChild( tbody.firstChild );
		}

		if ( ! courses.length ) {
			var emptyRow = el( 'tr', 'lrn-empty-row' );
			var emptyCell = el( 'td', '', __( 'No course data yet.', 'learnomy' ) );
			emptyCell.setAttribute( 'colspan', '5' );
			emptyRow.appendChild( emptyCell );
			tbody.appendChild( emptyRow );
			return;
		}

		for ( var i = 0; i < courses.length; i++ ) {
			var c   = courses[ i ];
			var row = el( 'tr' );

			var titleCell = el( 'td', 'lrn-course-title', c.title );
			titleCell.setAttribute( 'title', c.title );
			row.appendChild( titleCell );

			row.appendChild( el( 'td', '', String( c.students ) ) );
			row.appendChild( el( 'td', '', formatCurrency( c.revenue ) ) );

			var compCell  = el( 'td' );
			var badge     = el( 'span', 'lrn-completion-badge', c.completion_rate + '%' );
			compCell.appendChild( badge );
			row.appendChild( compCell );

			var sparkCell = el( 'td', 'lrn-sparkline-cell' );
			var sparkDiv  = el( 'div' );
			sparkDiv.id   = 'lrn-sparkline-' + i;
			sparkCell.appendChild( sparkDiv );
			row.appendChild( sparkCell );

			tbody.appendChild( row );
		}

		// Render sparklines after rows are in the DOM.
		for ( var j = 0; j < courses.length; j++ ) {
			renderSparkline( j, courses[ j ].sparkline || [] );
		}
	}

	/**
	 * Render a tiny sparkline chart inside a table cell.
	 *
	 * @param {number} index  Row index used for the container ID.
	 * @param {Array}  values Array of numeric values.
	 */
	function renderSparkline( index, values ) {
		var sparkEl = document.getElementById( 'lrn-sparkline-' + index );
		if ( ! sparkEl || ! values.length ) {
			return;
		}

		var config = {
			chart: {
				type: 'line',
				width: 80,
				height: 30,
				sparkline: { enabled: true },
			},
			series: [ { data: values } ],
			stroke: { width: 1.5, curve: 'smooth' },
			colors: [ colors.accent ],
			tooltip: { enabled: false },
		};

		var chart = new ApexCharts( sparkEl, config );
		chart.render();
	}

	/* ── Period selector wiring ──────────────────────────────────────────── */

	function initPeriodSelectors() {
		var selectors = document.querySelectorAll( '.lrn-period-selector' );

		for ( var i = 0; i < selectors.length; i++ ) {
			( function ( selector ) {
				var chartName = selector.getAttribute( 'data-chart' );
				var buttons   = selector.querySelectorAll( '.lrn-period-btn' );

				for ( var j = 0; j < buttons.length; j++ ) {
					buttons[ j ].addEventListener( 'click', function () {
						// Update active state.
						for ( var k = 0; k < buttons.length; k++ ) {
							buttons[ k ].classList.remove( 'lrn-period-btn--active' );
						}
						this.classList.add( 'lrn-period-btn--active' );

						var days = parseInt( this.getAttribute( 'data-period' ), 10 );

						if ( chartName === 'enrollments' ) {
							renderEnrollmentChart( days );
						} else if ( chartName === 'revenue' ) {
							renderRevenueChart( days );
						}
					} );
				}
			} )( selectors[ i ] );
		}
	}

	/* ── Bootstrap ───────────────────────────────────────────────────────── */

	document.addEventListener( 'DOMContentLoaded', function () {
		renderEnrollmentChart( 30 );
		renderRevenueChart( 30 );
		renderCompletionChart();
		renderTopCourses();
		initPeriodSelectors();
	} );

} )();
