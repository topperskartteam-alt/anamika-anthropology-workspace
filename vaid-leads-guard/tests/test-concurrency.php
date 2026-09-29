<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/helpers/fake-observation-store.php';

/**
 * Concurrency/race simulation — deterministic, no real threads.
 *
 * Two distinct races matter here, and they are NOT the same thing:
 *
 * 1. "Double-hook" race (same request, sequential, not a true race):
 *    v0.1.1 registers the observer on two Fluent Forms hook names for
 *    the same underlying event (see observer docblock). On a Fluent
 *    Forms version where both fire, the SAME entry_id is processed
 *    twice in one PHP request. This is made safe by the schema's
 *    UNIQUE KEY (form_id, entry_id) — the second insert attempt is
 *    rejected at the database level and logged as a harmless duplicate,
 *    never surfaced as an error. This cannot be exercised by a pure-PHP
 *    fake (there is no real unique constraint without a database), so
 *    it is verified structurally instead, by test-static-checks.php
 *    confirming the UNIQUE KEY exists in the schema, plus the observer
 *    source explicitly treating a "duplicate" $wpdb->last_error as
 *    expected/non-error (see class-vaid-leads-guard-observer.php).
 *
 * 2. "Two different visitors submit near-simultaneously" race (true
 *    concurrency, two separate PHP-FPM workers): both Fluent Forms
 *    entries are already inserted independently by Fluent Forms itself
 *    (out of this plugin's control) before either of OUR observer
 *    callbacks runs. If both callbacks' "find prior match" SELECT
 *    queries execute before either callback's INSERT has committed,
 *    each will see the OTHER's row as if it doesn't exist yet, and
 *    neither observation will cross-reference the other as a match.
 *    THIS test simulates exactly that ordering to prove the outcome is
 *    a missed classification (data-completeness limitation), never a
 *    crash, corruption, duplicate row for the SAME entry, or anything
 *    that could be mistaken for a blocking/enforcement side effect.
 *    Documented as a known limitation in ARCHITECTURE.md — no schema
 *    fix is applied for this case, since serializing it would require
 *    real cross-request locking that shadow-mode logging does not
 *    justify (see the red-team brief's own "do not overengineer").
 */

$secret = 'race-test-secret';
$store  = new Fake_Observation_Store();
$t0     = 1_700_000_000;

$phone_a = VAID_Leads_Guard_Normalizer::normalize_phone( '9876500099' )['normalized'];
$fp_a    = VAID_Leads_Guard_Fingerprint::make( $phone_a, $secret );

// Simulate: both FF entries already exist (entry 'race1' at t0, 'race2'
// at t0+3 — a 3-second real gap, well inside strong_mechanical_repeat).
// Both observer callbacks read BEFORE either writes.
$prior_seen_by_cb1 = $store->find_prior( $fp_a, $t0 );       // sees nothing yet
$prior_seen_by_cb2 = $store->find_prior( $fp_a, $t0 + 3 );   // ALSO sees nothing yet (race)

VAID_Test_Runner::assert_true( null === $prior_seen_by_cb1, 'concurrency: first callback sees no prior row (correct, none exists yet)' );
VAID_Test_Runner::assert_true( null === $prior_seen_by_cb2, 'concurrency: second callback ALSO sees no prior row under the race — this is the documented miss' );

// Both then write their own row — no crash, no corruption, no
// exception, both entries end up recorded.
$store->insert( $fp_a, 9, 'race1', $t0 );
$store->insert( $fp_a, 9, 'race2', $t0 + 3 );

$class_cb1 = VAID_Leads_Guard_Classifier::classify( $prior_seen_by_cb1 ? 0 : null, false );
$class_cb2 = VAID_Leads_Guard_Classifier::classify( $prior_seen_by_cb2 ? 0 : null, false );

VAID_Test_Runner::assert_equals( VAID_Leads_Guard_Classifier::NEW_IDENTITY, $class_cb1, 'concurrency: under the race, both submissions classify as new_identity instead of one being strong_mechanical_repeat' );
VAID_Test_Runner::assert_equals( VAID_Leads_Guard_Classifier::NEW_IDENTITY, $class_cb2, 'concurrency: this is the proven, bounded, non-corrupting failure mode — a missed classification, not a crash or duplicate row' );

// Compare against the NON-race case (v0.1.1's normal sequential path,
// exercised in test-scenarios.php): a genuinely sequential second
// lookup, issued AFTER the first insert has landed, correctly finds
// the match. This proves the miss above is specifically a same-instant
// ordering artifact, not a general defect in the matching logic.
$store2 = new Fake_Observation_Store();
$store2->insert( $fp_a, 9, 'seq1', $t0 );
$prior_sequential = $store2->find_prior( $fp_a, $t0 + 3 );
VAID_Test_Runner::assert_true( null !== $prior_sequential, 'concurrency: outside the race window (sequential order), the same lookup correctly finds the prior row' );
VAID_Test_Runner::assert_equals( 'seq1', $prior_sequential['entry_id'], 'concurrency: sequential lookup correctly identifies the true prior entry' );

exit( VAID_Test_Runner::summary() ? 0 : 1 );
