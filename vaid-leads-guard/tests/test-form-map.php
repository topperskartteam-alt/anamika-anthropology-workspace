<?php
require_once __DIR__ . '/bootstrap.php';

$map = VAID_Leads_Guard_Form_Map::get_map();

VAID_Test_Runner::assert_true( VAID_Leads_Guard_Form_Map::is_supported( 9 ), 'form-map: Form 9 (Course Page) must be supported' );
VAID_Test_Runner::assert_true( VAID_Leads_Guard_Form_Map::is_supported( 1 ), 'form-map: Form 1 (Reserve My Free Seat) must be supported' );
VAID_Test_Runner::assert_true( ! VAID_Leads_Guard_Form_Map::is_supported( 5 ), 'form-map: Form 5 (not a target landing form per audit) must NOT be supported' );
VAID_Test_Runner::assert_true( ! VAID_Leads_Guard_Form_Map::is_supported( 999 ), 'form-map: unknown form ID must NOT be supported' );

VAID_Test_Runner::assert_equals( 8621, $map[9]['page_id'], 'form-map: Form 9 page ID is locked to 8621' );
VAID_Test_Runner::assert_equals( '/anthropology/optional-coaching/', $map[9]['url'], 'form-map: Form 9 URL is locked' );
VAID_Test_Runner::assert_equals( 7489, $map[1]['page_id'], 'form-map: Form 1 page ID is locked to 7489' );
VAID_Test_Runner::assert_equals( '/anthropology/workshop/', $map[1]['url'], 'form-map: Form 1 URL is locked' );

exit( VAID_Test_Runner::summary() ? 0 : 1 );
