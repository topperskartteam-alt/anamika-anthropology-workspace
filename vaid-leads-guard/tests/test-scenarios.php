<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/helpers/fake-observation-store.php';

/**
 * End-to-end scenario tests that exercise the same decision path the
 * observer uses (normalize -> fingerprint -> look up prior -> classify),
 * against an in-memory fake of the observations table, so no WordPress
 * or database is required. See tests/helpers/fake-observation-store.php
 * for the shared fake (also used by test-concurrency.php).
 */

function simulate_submission( Fake_Observation_Store $store, $secret, $form_id, $entry_id, $phone_raw, $timestamp ) {
	$phone = VAID_Leads_Guard_Normalizer::normalize_phone( $phone_raw );
	if ( ! $phone['valid'] ) {
		return array( 'classification' => 'invalid_phone' );
	}

	$fp    = VAID_Leads_Guard_Fingerprint::make( $phone['normalized'], $secret );
	$prior = $store->find_prior( $fp, $timestamp );

	$delta         = null;
	$is_cross_form = false;
	if ( $prior ) {
		$delta         = $timestamp - $prior['timestamp'];
		$is_cross_form = ( $prior['form_id'] !== $form_id );
	}

	$classification = VAID_Leads_Guard_Classifier::classify( $delta, $is_cross_form );
	$store->insert( $fp, $form_id, $entry_id, $timestamp );

	return array(
		'classification' => $classification,
		'delta'           => $delta,
		'prior_entry_id'  => $prior ? $prior['entry_id'] : null,
	);
}

$secret = 'test-secret';
$store  = new Fake_Observation_Store();
$t0     = 1_700_000_000; // arbitrary fixed base timestamp

// 1) brand-new unique identity
$r = simulate_submission( $store, $secret, 9, 'e1', '9876500001', $t0 );
VAID_Test_Runner::assert_equals( VAID_Leads_Guard_Classifier::NEW_IDENTITY, $r['classification'], 'scenario: first-ever submission is new_identity' );

// 2) same phone, same form, 90 seconds later -> strong_mechanical_repeat
$r = simulate_submission( $store, $secret, 9, 'e2', '9876500001', $t0 + 90 );
VAID_Test_Runner::assert_equals( VAID_Leads_Guard_Classifier::STRONG_MECHANICAL_REPEAT, $r['classification'], 'scenario: same phone/form within 2m is strong_mechanical_repeat' );
VAID_Test_Runner::assert_equals( 'e1', $r['prior_entry_id'], 'scenario: prior entry correctly identified' );

// 3) same phone, same form, 5 minutes after THAT -> short_repeat
$r = simulate_submission( $store, $secret, 9, 'e3', '9876500001', $t0 + 90 + 300 );
VAID_Test_Runner::assert_equals( VAID_Leads_Guard_Classifier::SHORT_REPEAT, $r['classification'], 'scenario: same phone/form 2-10m gap is short_repeat' );

// 4) different phone entirely, same form, 12 hours later -> new_identity
$r = simulate_submission( $store, $secret, 9, 'e4', '9876500002', $t0 + 43200 );
VAID_Test_Runner::assert_equals( VAID_Leads_Guard_Classifier::NEW_IDENTITY, $r['classification'], 'scenario: distinct phone is new_identity, unaffected by other identities repeating' );

// 5) first phone again, same form, 20 hours after entry e3 -> repeat_same_day
$r = simulate_submission( $store, $secret, 9, 'e5', '9876500001', $t0 + 90 + 300 + 72000 );
VAID_Test_Runner::assert_equals( VAID_Leads_Guard_Classifier::REPEAT_SAME_DAY, $r['classification'], 'scenario: same phone/form <=24h gap is repeat_same_day' );

// 6) first phone again, same form, 10 days later -> returning_enquiry
$r = simulate_submission( $store, $secret, 9, 'e6', '9876500001', $t0 + 90 + 300 + 72000 + ( 10 * 86400 ) );
VAID_Test_Runner::assert_equals( VAID_Leads_Guard_Classifier::RETURNING_ENQUIRY, $r['classification'], 'scenario: same phone/form >24h gap is returning_enquiry' );

// 7) same phone submits to the OTHER form -> cross_form_repeat, regardless of gap size
$cross_form_time = $t0 + 90 + 300 + 72000 + ( 10 * 86400 ) + 30; // 30s after entry e6, but different form
$r = simulate_submission( $store, $secret, 1, 'e7', '9876500001', $cross_form_time );
VAID_Test_Runner::assert_equals( VAID_Leads_Guard_Classifier::CROSS_FORM_REPEAT, $r['classification'], 'scenario: same phone on the other supported form is cross_form_repeat' );

exit( VAID_Test_Runner::summary() ? 0 : 1 );
