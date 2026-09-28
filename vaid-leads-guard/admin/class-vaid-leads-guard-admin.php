<?php
/**
 * Minimal admin UI: settings screen + shadow-observation report.
 * No frontend assets are enqueued — this class only ever runs in wp-admin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VAID_Leads_Guard_Admin {

	const SETTINGS_NONCE = 'vaid_leads_guard_settings_nonce';
	const EXPORT_NONCE   = 'vaid_leads_guard_export_nonce';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_vaid_leads_guard_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_vaid_leads_guard_export_csv', array( $this, 'handle_export_csv' ) );
	}

	public function register_menu() {
		add_menu_page(
			'VAID Leads Guard',
			'VAID Leads Guard',
			'manage_options',
			'vaid-leads-guard',
			array( $this, 'render_report_page' ),
			'dashicons-shield',
			58
		);

		add_submenu_page(
			'vaid-leads-guard',
			'Shadow Report',
			'Shadow Report',
			'manage_options',
			'vaid-leads-guard',
			array( $this, 'render_report_page' )
		);

		add_submenu_page(
			'vaid-leads-guard',
			'Settings',
			'Settings',
			'manage_options',
			'vaid-leads-guard-settings',
			array( $this, 'render_settings_page' )
		);
	}

	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'vaid-leads-guard' ) );
		}

		check_admin_referer( self::SETTINGS_NONCE );

		VAID_Leads_Guard_Settings::update(
			array(
				'master_enabled' => isset( $_POST['master_enabled'] ),
				'form_9_enabled' => isset( $_POST['form_9_enabled'] ),
				'form_1_enabled' => isset( $_POST['form_1_enabled'] ),
			)
		);

		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'admin.php?page=vaid-leads-guard-settings' ) ) );
		exit;
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = VAID_Leads_Guard_Settings::get();
		$map      = VAID_Leads_Guard_Form_Map::get_map();
		?>
		<div class="wrap">
			<h1>VAID Leads Guard &mdash; Settings</h1>

			<div class="notice notice-info">
				<p><strong>Shadow mode &mdash; no submissions are blocked.</strong>
				v0.1.0 only observes and logs likely duplicate Fluent Forms submissions for reporting.
				It never blocks, merges, deletes, or alters a real lead entry.</p>
			</div>

			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="vaid_leads_guard_save_settings" />
				<?php wp_nonce_field( self::SETTINGS_NONCE ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Master monitoring</th>
						<td>
							<label>
								<input type="checkbox" name="master_enabled" <?php checked( $settings['master_enabled'] ); ?> />
								Enable shadow observation site-wide
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">Form 9 &mdash; <?php echo esc_html( $map[9]['label'] ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="form_9_enabled" <?php checked( $settings['form_9_enabled'] ); ?> />
								Monitor (<?php echo esc_html( $map[9]['url'] ); ?>)
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">Form 1 &mdash; <?php echo esc_html( $map[1]['label'] ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="form_1_enabled" <?php checked( $settings['form_1_enabled'] ); ?> />
								Monitor (<?php echo esc_html( $map[1]['url'] ); ?>)
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">Classification thresholds</th>
						<td>
							<p>Locked in v0.1.0 (not editable yet):</p>
							<ul style="list-style: disc; margin-left: 1.5em;">
								<li>&le; 2 minutes &rarr; strong_mechanical_repeat</li>
								<li>2&ndash;10 minutes &rarr; short_repeat</li>
								<li>10 minutes&ndash;24 hours &rarr; repeat_same_day</li>
								<li>&gt; 24 hours &rarr; returning_enquiry</li>
								<li>match in the other supported form &rarr; cross_form_repeat</li>
							</ul>
						</td>
					</tr>
					<tr>
						<th scope="row">Blocking</th>
						<td>
							<label style="opacity:0.6;">
								<input type="checkbox" disabled />
								Block duplicate submissions
							</label>
							<p class="description">Not implemented in v0.1.0. Shadow mode only &mdash; no submission is ever blocked by this version.</p>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Save Settings' ); ?>
			</form>
		</div>
		<?php
	}

	public function render_report_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$summary = VAID_Leads_Guard_DB::get_summary();
		$recent  = VAID_Leads_Guard_DB::get_recent( 50 );
		$map     = VAID_Leads_Guard_Form_Map::get_map();
		?>
		<div class="wrap">
			<h1>VAID Leads Guard &mdash; Shadow Report</h1>

			<div class="notice notice-info">
				<p><strong>Shadow mode &mdash; no submissions are blocked.</strong>
				This screen reports likely-duplicate patterns observed since activation. It cannot see
				submissions made before this plugin was installed.</p>
			</div>

			<h2>Totals</h2>
			<table class="widefat striped" style="max-width: 480px;">
				<tbody>
					<tr><td>Total shadow observations</td><td><strong><?php echo esc_html( $summary['total'] ); ?></strong></td></tr>
					<?php foreach ( $map as $form_id => $config ) : ?>
						<tr>
							<td><?php echo esc_html( $config['label'] . ' (Form ' . $form_id . ')' ); ?></td>
							<td><?php echo esc_html( isset( $summary['by_form'][ $form_id ] ) ? $summary['by_form'][ $form_id ] : 0 ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2>By classification</h2>
			<table class="widefat striped" style="max-width: 480px;">
				<tbody>
					<?php foreach ( VAID_Leads_Guard_Classifier::all_labels() as $label ) : ?>
						<tr>
							<td><?php echo esc_html( $label ); ?></td>
							<td><?php echo esc_html( isset( $summary['by_classification'][ $label ] ) ? $summary['by_classification'][ $label ] : 0 ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2>Recent observations (masked)</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom: 1em;">
				<input type="hidden" name="action" value="vaid_leads_guard_export_csv" />
				<?php wp_nonce_field( self::EXPORT_NONCE ); ?>
				<?php submit_button( 'Export safe audit CSV', 'secondary', 'submit', false ); ?>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th>Entry ID</th>
						<th>Form</th>
						<th>Prior Entry ID</th>
						<th>Phone FP</th>
						<th>Email FP</th>
						<th>Match Type</th>
						<th>Delta (s)</th>
						<th>Classification</th>
						<th>Cross-form</th>
						<th>Created</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $recent as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row->entry_id ); ?></td>
							<td><?php echo esc_html( $row->form_id ); ?></td>
							<td><?php echo esc_html( $row->prior_entry_id ?: '—' ); ?></td>
							<td><?php echo esc_html( $row->phone_fingerprint ? VAID_Leads_Guard_Fingerprint::short( $row->phone_fingerprint ) : '—' ); ?></td>
							<td><?php echo esc_html( $row->email_fingerprint ? VAID_Leads_Guard_Fingerprint::short( $row->email_fingerprint ) : '—' ); ?></td>
							<td><?php echo esc_html( $row->match_type ?: '—' ); ?></td>
							<td><?php echo esc_html( null !== $row->time_delta_seconds ? $row->time_delta_seconds : '—' ); ?></td>
							<td><?php echo esc_html( $row->classification ); ?></td>
							<td><?php echo esc_html( $row->is_cross_form ? 'yes' : 'no' ); ?></td>
							<td><?php echo esc_html( $row->created_at ); ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( empty( $recent ) ) : ?>
						<tr><td colspan="10">No shadow observations yet.</td></tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public function handle_export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'vaid-leads-guard' ) );
		}

		check_admin_referer( self::EXPORT_NONCE );

		$rows = VAID_Leads_Guard_DB::get_recent( 5000 );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="vaid-leads-guard-shadow-audit.csv"' );

		$out = fopen( 'php://output', 'w' );

		fputcsv(
			$out,
			array(
				'entry_id',
				'form_id',
				'prior_entry_id',
				'prior_form_id',
				'phone_fingerprint_short',
				'email_fingerprint_short',
				'match_type',
				'time_delta_seconds',
				'classification',
				'is_cross_form',
				'action',
				'created_at',
			)
		);

		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array(
					$row->entry_id,
					$row->form_id,
					$row->prior_entry_id,
					$row->prior_form_id,
					$row->phone_fingerprint ? VAID_Leads_Guard_Fingerprint::short( $row->phone_fingerprint ) : '',
					$row->email_fingerprint ? VAID_Leads_Guard_Fingerprint::short( $row->email_fingerprint ) : '',
					$row->match_type,
					$row->time_delta_seconds,
					$row->classification,
					$row->is_cross_form,
					$row->action,
					$row->created_at,
				)
			);
		}

		fclose( $out );
		exit;
	}
}

new VAID_Leads_Guard_Admin();
