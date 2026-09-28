<?php
require_once __DIR__ . '/bootstrap.php';

// --- deterministic: same input + same secret => same fingerprint ---
$fp1 = VAID_Leads_Guard_Fingerprint::make( '9876543210', 'secret-a' );
$fp2 = VAID_Leads_Guard_Fingerprint::make( '9876543210', 'secret-a' );
VAID_Test_Runner::assert_equals( $fp1, $fp2, 'fingerprint: identical input+secret must be deterministic' );

// --- different secret => different fingerprint (no plaintext leakage via constant hash) ---
$fp3 = VAID_Leads_Guard_Fingerprint::make( '9876543210', 'secret-b' );
VAID_Test_Runner::assert_true( $fp1 !== $fp3, 'fingerprint: different secret must produce a different fingerprint' );

// --- different normalized value => different fingerprint ---
$fp4 = VAID_Leads_Guard_Fingerprint::make( '9876543211', 'secret-a' );
VAID_Test_Runner::assert_true( $fp1 !== $fp4, 'fingerprint: different phone must produce a different fingerprint' );

// --- fingerprint never contains the raw input verbatim ---
VAID_Test_Runner::assert_true(
	false === strpos( $fp1, '9876543210' ),
	'fingerprint: output must not contain the raw normalized value'
);
VAID_Test_Runner::assert_equals( 64, strlen( $fp1 ), 'fingerprint: SHA-256 HMAC hex digest is 64 chars' );

// --- pair fingerprint is deterministic and distinct from either individual fingerprint ---
$pair1 = VAID_Leads_Guard_Fingerprint::make_pair( '9876543210', 'someone@example.com', 'secret-a' );
$pair2 = VAID_Leads_Guard_Fingerprint::make_pair( '9876543210', 'someone@example.com', 'secret-a' );
VAID_Test_Runner::assert_equals( $pair1, $pair2, 'pair fingerprint: deterministic for same phone+email+secret' );
VAID_Test_Runner::assert_true( $pair1 !== $fp1, 'pair fingerprint: must differ from the phone-only fingerprint' );

// --- short() truncation for display ---
VAID_Test_Runner::assert_equals( 10, strlen( VAID_Leads_Guard_Fingerprint::short( $fp1 ) ), 'fingerprint: short() truncates to 10 chars' );
VAID_Test_Runner::assert_equals( substr( $fp1, 0, 10 ), VAID_Leads_Guard_Fingerprint::short( $fp1 ), 'fingerprint: short() is a prefix of the full fingerprint' );

exit( VAID_Test_Runner::summary() ? 0 : 1 );
