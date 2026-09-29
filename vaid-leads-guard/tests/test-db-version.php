<?php
require_once __DIR__ . '/bootstrap.php';

// --- activation/migration idempotency gate (pure logic) ---
VAID_Test_Runner::assert_true(
	VAID_Leads_Guard_DB::needs_upgrade( '0', '1.1.0' ),
	'db-version: a fresh install (stored "0") needs the v1.1.0 schema'
);
VAID_Test_Runner::assert_true(
	VAID_Leads_Guard_DB::needs_upgrade( '1.0.0', '1.1.0' ),
	'db-version: a site still on v0.1.0 schema (1.0.0) needs upgrading to 1.1.0'
);
VAID_Test_Runner::assert_true(
	! VAID_Leads_Guard_DB::needs_upgrade( '1.1.0', '1.1.0' ),
	'db-version: a site already on 1.1.0 must NOT be told it needs upgrading (idempotency — install() would otherwise run on every single page load)'
);
VAID_Test_Runner::assert_true(
	! VAID_Leads_Guard_DB::needs_upgrade( '1.2.0', '1.1.0' ),
	'db-version: a newer stored version must never be "downgraded" by an older plugin build'
);

exit( VAID_Test_Runner::summary() ? 0 : 1 );
