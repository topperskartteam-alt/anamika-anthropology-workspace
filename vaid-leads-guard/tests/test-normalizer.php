<?php
require_once __DIR__ . '/bootstrap.php';

// --- Phone: +91 / 91 / leading-0 normalization ---
$r = VAID_Leads_Guard_Normalizer::normalize_phone( '+91 98765 43210' );
VAID_Test_Runner::assert_true( $r['valid'], 'phone: +91 with spaces should be valid' );
VAID_Test_Runner::assert_equals( '9876543210', $r['normalized'], 'phone: +91 with spaces normalizes to 10 digits' );

$r = VAID_Leads_Guard_Normalizer::normalize_phone( '919876543210' );
VAID_Test_Runner::assert_true( $r['valid'], 'phone: 91-prefixed 12-digit should be valid' );
VAID_Test_Runner::assert_equals( '9876543210', $r['normalized'], 'phone: 91-prefixed normalizes correctly' );

$r = VAID_Leads_Guard_Normalizer::normalize_phone( '09876543210' );
VAID_Test_Runner::assert_true( $r['valid'], 'phone: leading-0 11-digit should be valid' );
VAID_Test_Runner::assert_equals( '9876543210', $r['normalized'], 'phone: leading-0 normalizes correctly' );

$r = VAID_Leads_Guard_Normalizer::normalize_phone( '(987) 654-3210' );
VAID_Test_Runner::assert_true( $r['valid'], 'phone: punctuation/parentheses/hyphens stripped' );
VAID_Test_Runner::assert_equals( '9876543210', $r['normalized'], 'phone: punctuation-stripped normalizes correctly' );

// --- Phone: malformed 9-digit rejection (never guessed) ---
$r = VAID_Leads_Guard_Normalizer::normalize_phone( '855996586' );
VAID_Test_Runner::assert_true( ! $r['valid'], 'phone: 9-digit number must be rejected, not corrected' );
VAID_Test_Runner::assert_null( $r['normalized'], 'phone: 9-digit number normalized value must be null' );

// --- Phone: text rejection ---
$r = VAID_Leads_Guard_Normalizer::normalize_phone( 'Fjfkh' );
VAID_Test_Runner::assert_true( ! $r['valid'], 'phone: non-numeric text must be rejected' );

$r = VAID_Leads_Guard_Normalizer::normalize_phone( '123456' );
VAID_Test_Runner::assert_true( ! $r['valid'], 'phone: obviously-junk short numeric string must be rejected' );

$r = VAID_Leads_Guard_Normalizer::normalize_phone( '' );
VAID_Test_Runner::assert_true( ! $r['valid'], 'phone: empty string must be rejected' );

$r = VAID_Leads_Guard_Normalizer::normalize_phone( null );
VAID_Test_Runner::assert_true( ! $r['valid'], 'phone: null must be rejected' );

// A landline-shaped 10-digit number not starting 6-9 must not be
// silently accepted as an Indian mobile number.
$r = VAID_Leads_Guard_Normalizer::normalize_phone( '0123456789' );
VAID_Test_Runner::assert_true( ! $r['valid'], 'phone: 10-digit number starting with 0 after strip must be rejected' );

// --- Email normalization ---
$r = VAID_Leads_Guard_Normalizer::normalize_email( '  Someone@Example.COM ' );
VAID_Test_Runner::assert_true( $r['valid'], 'email: valid email with whitespace/mixed case should be valid' );
VAID_Test_Runner::assert_equals( 'someone@example.com', $r['normalized'], 'email: trimmed + lowercased' );

$r = VAID_Leads_Guard_Normalizer::normalize_email( 'a.b+tag@gmail.com' );
VAID_Test_Runner::assert_true( $r['valid'], 'email: gmail dot/plus address should be valid' );
VAID_Test_Runner::assert_equals( 'a.b+tag@gmail.com', $r['normalized'], 'email: dot/plus semantics must NOT be mutated' );

$r = VAID_Leads_Guard_Normalizer::normalize_email( 'not-an-email' );
VAID_Test_Runner::assert_true( ! $r['valid'], 'email: syntactically invalid value must be rejected' );

$r = VAID_Leads_Guard_Normalizer::normalize_email( '' );
VAID_Test_Runner::assert_true( ! $r['valid'], 'email: empty string must be rejected' );

exit( VAID_Test_Runner::summary() ? 0 : 1 );
