<?php
require_once __DIR__ . '/bootstrap.php';

// --- same-phone same-form buckets ---
VAID_Test_Runner::assert_equals(
	VAID_Leads_Guard_Classifier::STRONG_MECHANICAL_REPEAT,
	VAID_Leads_Guard_Classifier::classify( 0, false ),
	'classify: 0s gap is strong_mechanical_repeat'
);

VAID_Test_Runner::assert_equals(
	VAID_Leads_Guard_Classifier::STRONG_MECHANICAL_REPEAT,
	VAID_Leads_Guard_Classifier::classify( 120, false ),
	'classify: exactly 2m boundary is still strong_mechanical_repeat'
);

VAID_Test_Runner::assert_equals(
	VAID_Leads_Guard_Classifier::SHORT_REPEAT,
	VAID_Leads_Guard_Classifier::classify( 121, false ),
	'classify: 2m+1s is short_repeat'
);

VAID_Test_Runner::assert_equals(
	VAID_Leads_Guard_Classifier::SHORT_REPEAT,
	VAID_Leads_Guard_Classifier::classify( 600, false ),
	'classify: exactly 10m boundary is still short_repeat'
);

VAID_Test_Runner::assert_equals(
	VAID_Leads_Guard_Classifier::REPEAT_SAME_DAY,
	VAID_Leads_Guard_Classifier::classify( 601, false ),
	'classify: 10m+1s is repeat_same_day'
);

VAID_Test_Runner::assert_equals(
	VAID_Leads_Guard_Classifier::REPEAT_SAME_DAY,
	VAID_Leads_Guard_Classifier::classify( 86400, false ),
	'classify: exactly 24h boundary is still repeat_same_day'
);

VAID_Test_Runner::assert_equals(
	VAID_Leads_Guard_Classifier::RETURNING_ENQUIRY,
	VAID_Leads_Guard_Classifier::classify( 86401, false ),
	'classify: 24h+1s is returning_enquiry'
);

VAID_Test_Runner::assert_equals(
	VAID_Leads_Guard_Classifier::RETURNING_ENQUIRY,
	VAID_Leads_Guard_Classifier::classify( 60 * 60 * 24 * 30, false ),
	'classify: 30 days is returning_enquiry'
);

// --- cross-form repeat overrides the time bucket label ---
VAID_Test_Runner::assert_equals(
	VAID_Leads_Guard_Classifier::CROSS_FORM_REPEAT,
	VAID_Leads_Guard_Classifier::classify( 5, true ),
	'classify: cross-form match at 5s is cross_form_repeat, not strong_mechanical_repeat'
);

VAID_Test_Runner::assert_equals(
	VAID_Leads_Guard_Classifier::CROSS_FORM_REPEAT,
	VAID_Leads_Guard_Classifier::classify( 60 * 60 * 24 * 90, true ),
	'classify: cross-form match at 90 days is still cross_form_repeat'
);

// --- new unique identity (no prior match at all) ---
VAID_Test_Runner::assert_equals(
	VAID_Leads_Guard_Classifier::NEW_IDENTITY,
	VAID_Leads_Guard_Classifier::classify( null, false ),
	'classify: null delta (no prior match) is new_identity'
);

exit( VAID_Test_Runner::summary() ? 0 : 1 );
