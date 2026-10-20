<?php
/**
 * Rules screen integration tests.
 *
 * @package PressQueue\Tests\Integration
 */

declare(strict_types=1);

namespace PressQueue\Tests\Integration;

use PressQueue\Admin\RuleEditor;
use PressQueue\Admin\RuleFormException;
use PressQueue\Admin\RulesController;
use PressQueue\Admin\RuleSummary;
use PressQueue\Admin\RuleTestView;
use PressQueue\Security\Capabilities;
use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\Database;
use PressQueue\Storage\Record\ChannelRecord;
use PressQueue\Storage\Record\RuleRecord;
use PressQueue\Storage\Repository\ChannelRepository;
use PressQueue\Storage\Repository\ConnectionRepository;
use PressQueue\Storage\Repository\RuleRepository;
use PressQueue\Storage\Repository\TemplateRepository;
use PressQueue\Storage\Schema;
use PressQueue\Storage\SchemaMigrator;
use PressQueue\Storage\SettingsRepository;
use PressQueue\Storage\StaleRecordException;
use PressQueue\Storage\ValidationException;
use PressQueue\Tests\Support\PressQueueIntegrationTestCase as WP_UnitTestCase;

/**
 * @covers \PressQueue\Admin\RuleEditor
 * @covers \PressQueue\Admin\RulesController
 * @covers \PressQueue\Admin\RuleSummary
 * @covers \PressQueue\Admin\RuleTestView
 * @covers \PressQueue\Admin\MessageComposer
 * @covers \PressQueue\Admin\TemplateController
 * @covers \PressQueue\Admin\ConditionBuilderController
 * @covers \PressQueue\Storage\Repository\RuleRepository
 */
final class RulesAdminIntegrationTest extends WP_UnitTestCase {
	/**
	 * Channel sequence for unique remote IDs.
	 *
	 * @var int
	 */
	private int $sequence = 0;

	protected function setUp(): void {
		parent::setUp();

		$_GET  = array();
		$_POST = array();
		$this->resetStorage();
		( new SchemaMigrator( Database::from_globals() ) )->migrate();
		$this->adminUser();
	}

	protected function tearDown(): void {
		$_GET  = array();
		$_POST = array();
		wp_set_current_user( 0 );
		$this->resetStorage();

		parent::tearDown();
	}

	public function test_untouched_editor_submission_keeps_rule_and_stored_triggers(): void {
		$channel = $this->channel( 'linkedin' );
		$rules   = new RuleRepository();
		$rule    = $rules->create(
			array(
				'name'                   => 'Campaign posts',
				'effect'                 => 'include',
				'priority'               => 40,
				'triggers'               => array( 'first_publish' ),
				'condition_tree'         => array(
					'schema_version' => 1,
					'type'           => 'group',
					'mode'           => 'ALL',
					'children'       => array(
						array(
							'type'      => 'condition',
							'condition' => 'post_type',
							'operator'  => 'in',
							'value'     => array( 'post' ),
						),
						array(
							'type'      => 'condition',
							'condition' => 'metadata',
							'operator'  => 'equals',
							'value'     => array(
								'key'   => 'campaign',
								'value' => array(
									'type'  => 'number',
									'value' => 5,
								),
							),
						),
					),
				),
				'destination_set'        => array(
					'schema_version' => 1,
					'destinations'   => array( array( 'channel_public_id' => $channel->public_id ) ),
				),
				'template_set'           => array(
					'schema_version'   => 1,
					'default_message'  => 'New: {{ title }} 50%25 off',
					'service_messages' => array( 'linkedin' => '{{ title }} {{ meta:campaign }}' ),
					'metadata'         => array( 'tone' => 'friendly' ),
				),
				'custom_field_allowlist' => array( 'campaign' ),
				'schedule_policy'        => array(
					'schema_version' => 1,
					'mode'           => 'queue_next',
				),
				'media_policy'           => array(
					'schema_version' => 1,
					'mode'           => 'none',
				),
			)
		);
		$rule    = $rules->mark_version_previewed( $rule->public_id, $rule->lock_version, $rule->version );
		$rule    = $rules->enable_after_preview( $rule->public_id, $rule->lock_version );
		$before  = $this->storedRow( $rule->public_id );

		$_POST   = $this->editorPost( $rule );
		$input   = RuleEditor::input_from_request( $rule );
		$updated = $rules->update( $rule->public_id, $rule->lock_version, RuleEditor::changes_for_update( $rule, $input ) );

		self::assertSame( array( 'first_publish' ), $input['triggers'] );
		self::assertTrue( $updated->enabled );
		self::assertSame( $rule->version, $updated->version );
		self::assertSame( $before, $this->storedRow( $rule->public_id ) );
	}

	public function test_when_choices_map_to_triggers_without_silent_changes(): void {
		$channel = $this->channel( 'linkedin' );

		$_POST = $this->newRulePost( $channel, array( 'when' => array( 'publish', 'bulk' ) ) );
		self::assertSame( array( 'first_publish', 'scheduled_publication', 'bulk' ), RuleEditor::input_from_request()['triggers'] );

		$rules     = new RuleRepository();
		$scheduled = $rules->create( array_merge( $this->baseInput( $channel ), array( 'triggers' => array( 'scheduled_publication' ) ) ) );

		$_POST = $this->editorPost( $scheduled );
		self::assertSame( array( 'scheduled_publication' ), RuleEditor::input_from_request( $scheduled )['triggers'] );

		$_POST['when_add_immediate'] = '1';
		self::assertSame( array( 'scheduled_publication', 'first_publish' ), RuleEditor::input_from_request( $scheduled )['triggers'] );

		$_POST         = $this->editorPost( $scheduled );
		$_POST['when'] = array( 'update' );
		self::assertSame( array( 'update' ), RuleEditor::input_from_request( $scheduled )['triggers'] );

		unset( $_POST['when'] );
		try {
			RuleEditor::input_from_request( $scheduled );
			self::fail( 'A rule without any trigger must be refused.' );
		} catch ( RuleFormException $exception ) {
			self::assertSame( 'when', $exception->section );
		}
	}

	public function test_untouched_legacy_rule_without_template_snapshot_is_not_backfilled(): void {
		$channel = $this->channel( 'linkedin' );
		$rules   = new RuleRepository();
		$input   = $this->baseInput( $channel );
		unset( $input['template_set'] );
		$input['inline_template'] = array(
			'schema_version'         => 1,
			'message'                => '{{ title }}',
			'custom_field_allowlist' => array(),
		);
		$rule                     = $rules->create( $input );
		$rules->update( $rule->public_id, $rule->lock_version, array( 'name' => 'Legacy' ) );
		Database::from_globals()->query( 'UPDATE `' . Database::from_globals()->table( Schema::RULES ) . '` SET template_set_json = NULL WHERE public_id = %s', array( $rule->public_id ) );
		$rule = $rules->find_by_public_id( $rule->public_id );
		self::assertInstanceOf( RuleRecord::class, $rule );
		self::assertFalse( $rule->has_template_snapshot );

		$_POST   = $this->editorPost( $rule );
		$updated = $rules->update( $rule->public_id, $rule->lock_version, RuleEditor::changes_for_update( $rule, RuleEditor::input_from_request( $rule ) ) );

		self::assertSame( $rule->version, $updated->version );
		self::assertFalse( $updated->has_template_snapshot );
	}

	public function test_message_json_edit_wins_and_adopted_template_keeps_provenance(): void {
		$channel   = $this->channel( 'linkedin' );
		$templates = new TemplateRepository();
		$template  = $templates->create(
			'Title and excerpt',
			array(
				'schema_version'  => 1,
				'default_message' => '{{ title }} {{ excerpt | truncate:120 }}',
			)
		);
		$template  = $templates->set_enabled( $template->public_id, $template->lock_version, true );

		$_POST = $this->newRulePost(
			$channel,
			array(
				'message_default'            => '{{ title }} {{ excerpt | truncate:120 }}',
				'adopted_template_public_id' => $template->public_id,
			)
		);
		$input = RuleEditor::input_from_request();
		self::assertSame( $template->public_id, $input['source_template_public_id'] );
		self::assertSame( $template->version, $input['source_template_version'] );

		$_POST['message_default'] = 'Changed {{ title }}';
		self::assertArrayNotHasKey( 'source_template_public_id', RuleEditor::input_from_request() );

		$_POST['template_set_json'] = wp_slash(
			(string) wp_json_encode(
				array(
					'schema_version'  => 1,
					'default_message' => 'From JSON {{ title }}',
				)
			)
		);
		self::assertSame( 'From JSON {{ title }}', RuleEditor::input_from_request()['template_set']['default_message'] );
	}

	public function test_never_share_editor_keeps_hidden_documents(): void {
		$rules = new RuleRepository();
		$term  = self::factory()->category->create( array( 'name' => 'Kept private' ) );
		$rule  = $rules->create(
			array(
				'name'                   => 'Keep private',
				'effect'                 => 'exclude',
				'priority'               => 10,
				'triggers'               => array( 'first_publish', 'update' ),
				'condition_tree'         => $this->categoryTree( (int) $term ),
				'destination_set'        => array(
					'schema_version' => 1,
					'scope'          => 'all',
				),
				'template_set'           => array(
					'schema_version'  => 1,
					'default_message' => 'Hidden {{ title }}',
				),
				'custom_field_allowlist' => array(),
				'schedule_policy'        => array(
					'schema_version' => 1,
					'mode'           => 'draft',
				),
				'media_policy'           => array(
					'schema_version' => 1,
					'mode'           => 'none',
				),
			)
		);

		$_POST = wp_slash(
			array(
				'rule_form'             => '2',
				'effect'                => 'exclude',
				'name'                  => 'Keep private',
				'priority'              => '10',
				'condition_tree_json'   => (string) wp_json_encode( $rule->condition_tree ),
				'custom_fields_present' => '1',
				'custom_fields'         => '',
			)
		);
		$input = RuleEditor::input_from_request( $rule );

		self::assertSame( array( 'first_publish', 'update' ), $input['triggers'] );
		self::assertSame( 'draft', $input['schedule_policy']['mode'] );
		self::assertSame( 'Hidden {{ title }}', $input['template_set']['default_message'] );
		self::assertSame( 'all', $input['destination_set']['scope'] );
		self::assertSame( $rule->version, $rules->update( $rule->public_id, $rule->lock_version, RuleEditor::changes_for_update( $rule, $input ) )->version );
	}

	public function test_rule_summary_describes_rules_in_words(): void {
		$channel  = $this->channel( 'linkedin', 'Larkfield Bakery' );
		$recipes  = self::factory()->category->create( array( 'name' => 'Recipes' ) );
		$author   = self::factory()->user->create( array( 'display_name' => 'Mara Lindqvist' ) );
		$rules    = new RuleRepository();
		$summary  = new RuleSummary( ( new ChannelRepository() )->find_all() );
		$input    = $this->baseInput( $channel );
		$tree     = $this->categoryTree( (int) $recipes );
		$tree['children'][] = array(
			'type'      => 'condition',
			'condition' => 'post_type',
			'operator'  => 'equals',
			'value'     => 'post',
		);
		$tree['children'][] = array(
			'type'      => 'condition',
			'condition' => 'author',
			'operator'  => 'equals',
			'value'     => $author,
		);
		$input['condition_tree'] = $tree;
		$input['triggers']       = array( 'first_publish', 'bulk' );
		$sentence                = wp_strip_all_tags( $summary->sentence( $rules->create( $input ) ) );

		self::assertStringContainsString( 'When a Post in Recipes or any of its subcategories and by Mara Lindqvist is published or picked in Share old posts', $sentence );
		self::assertStringContainsString( 'send it to inLarkfield Bakery (LinkedIn)', $sentence );
		self::assertStringContainsString( 'end of the Buffer queue', $sentence );
		self::assertStringContainsString( 'featured image when there is one', $sentence );

		$nested                            = $this->categoryTree( (int) $recipes );
		$nested['children'][]              = array(
			'type'     => 'group',
			'mode'     => 'ANY',
			'children' => array( $tree['children'][1] ),
		);
		$input['condition_tree']           = $nested;
		self::assertStringContainsString( 'a post matching advanced conditions', wp_strip_all_tags( $summary->sentence( $rules->create( $input ) ) ) );

		$exclusion = $rules->create(
			array_merge(
				$input,
				array(
					'effect'          => 'exclude',
					'condition_tree'  => $this->categoryTree( (int) $recipes ),
					'destination_set' => array(
						'schema_version' => 1,
						'scope'          => 'all',
					),
				)
			)
		);
		self::assertSame( 'Never share a post in Recipes or any of its subcategories. This beats every sharing rule.', wp_strip_all_tags( $summary->sentence( $exclusion ) ) );
	}

	public function test_rule_test_marks_version_previewed_and_explains_each_post(): void {
		$channel   = $this->channel( 'linkedin', 'Larkfield Bakery' );
		$recipes   = (int) self::factory()->category->create( array( 'name' => 'Sourdough' ) );
		$private   = (int) self::factory()->category->create( array( 'name' => 'Private notes' ) );
		$news      = (int) self::factory()->category->create( array( 'name' => 'Shop news' ) );
		$shared    = self::factory()->post->create( array( 'post_category' => array( $recipes ) ) );
		$blocked   = self::factory()->post->create( array( 'post_category' => array( $recipes, $private ) ) );
		$unmatched = self::factory()->post->create( array( 'post_category' => array( $news ) ) );
		$rules     = new RuleRepository();
		$exclusion = $rules->create(
			array_merge(
				$this->baseInput( $channel ),
				array(
					'name'            => 'Keep the journal private',
					'effect'          => 'exclude',
					'condition_tree'  => $this->categoryTree( $private ),
					'destination_set' => array(
						'schema_version' => 1,
						'scope'          => 'all',
					),
				)
			)
		);
		$exclusion = $rules->mark_version_previewed( $exclusion->public_id, $exclusion->lock_version, $exclusion->version );
		$exclusion = $rules->enable_after_preview( $exclusion->public_id, $exclusion->lock_version );
		$rule = $rules->create( array_merge( $this->baseInput( $channel ), array( 'condition_tree' => $this->categoryTree( $recipes ) ) ) );

		$result   = RuleTestView::run( $rule, array( $shared, $blocked, $unmatched ), 'first_publish', 3, false );
		$outcomes = array_column( $result['rows'], 'outcome', 'post_id' );
		$reasons  = array_column( $result['rows'], 'reason', 'post_id' );

		self::assertSame( 'share', $outcomes[ $shared ] );
		self::assertSame( 'blocked', $outcomes[ $blocked ] );
		self::assertStringContainsString( 'Keep the journal private', $reasons[ $blocked ] );
		self::assertSame( 'skip', $outcomes[ $unmatched ] );
		self::assertSame( 'It is not in Sourdough.', $reasons[ $unmatched ] );

		$rule = $rules->find_by_public_id( $rule->public_id );
		self::assertInstanceOf( RuleRecord::class, $rule );
		self::assertSame( $rule->version, $rule->last_previewed_version );
		self::assertTrue( $rules->enable_after_preview( $rule->public_id, $rule->lock_version )->enabled );

		$exclusion_result = RuleTestView::run( $exclusion, array( $blocked, $shared ), 'first_publish', 2, false );
		self::assertSame( array( 'blocked_by_rule', 'not_affected' ), array_column( $exclusion_result['rows'], 'outcome' ) );
	}

	public function test_rule_test_treats_older_posts_as_published_today(): void {
		$channel = $this->channel( 'linkedin', 'Larkfield Bakery' );
		$older   = self::factory()->post->create(
			array(
				'post_date'     => '2020-10-02 09:00:00',
				'post_date_gmt' => '2020-10-02 09:00:00',
				'post_status'   => 'publish',
				'post_title'    => 'Older loaf notes',
			)
		);
		$draft   = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_title'  => 'Draft loaf notes',
			)
		);
		$rule    = ( new RuleRepository() )->create( $this->baseInput( $channel ) );

		$result = RuleTestView::run( $rule, array( $older, $draft ), 'first_publish', 2, false );
		$rows   = array_column( $result['rows'], null, 'post_id' );

		self::assertSame( 'share', $rows[ $older ]['outcome'] );
		self::assertSame( '2020-10-02 09:00:00', $rows[ $older ]['published_at'] );
		self::assertSame( 'share', $rows[ $draft ]['outcome'] );
		self::assertSame( '', $rows[ $draft ]['published_at'] );

		$_GET = array(
			'page' => RulesController::SLUG,
			'view' => 'test',
			'rule' => $rule->public_id,
		);
		$html = $this->render();
		self::assertStringContainsString( '2 of 2 posts would be shared.', $html );
		self::assertStringContainsString( 'Published on Oct 2, 2020. Tested as if it were published today.', $html );
		self::assertStringContainsString( 'tick &quot;I pick old posts in Share old posts&quot; when you edit it', $html );
		self::assertStringNotContainsString( 'more than a day in the past', $html );

		$rule = ( new RuleRepository() )->find_by_public_id( $rule->public_id );
		self::assertInstanceOf( RuleRecord::class, $rule );
		$update = RuleTestView::run( $rule, array( $older ), 'update', 1, false );
		self::assertSame( '', $update['rows'][0]['published_at'] );
	}

	public function test_only_disabled_rules_without_waiting_deliveries_can_be_deleted(): void {
		$channel = $this->channel( 'linkedin' );
		$rules   = new RuleRepository();
		$rule    = $rules->create( $this->baseInput( $channel ) );
		$rule    = $rules->mark_version_previewed( $rule->public_id, $rule->lock_version, $rule->version );
		$rule    = $rules->enable_after_preview( $rule->public_id, $rule->lock_version );

		try {
			$rules->delete_disabled( $rule->public_id, $rule->lock_version );
			self::fail( 'An enabled rule must not be deleted.' );
		} catch ( ValidationException $exception ) {
			self::assertSame( 'Rule must be disabled before it can be deleted.', $exception->getMessage() );
		}

		$rule = $rules->update( $rule->public_id, $rule->lock_version, array( 'enabled' => false ) );
		$this->insertDelivery( $rule, $channel, 'held' );

		try {
			$rules->delete_disabled( $rule->public_id, $rule->lock_version );
			self::fail( 'A rule with waiting deliveries must not be deleted.' );
		} catch ( ValidationException $exception ) {
			self::assertSame( 'Rule has deliveries waiting to be sent.', $exception->getMessage() );
		}

		Database::from_globals()->query( 'UPDATE `' . Database::from_globals()->table( Schema::DELIVERIES ) . "` SET state = 'cancelled' WHERE rule_public_id = %s", array( $rule->public_id ) );

		try {
			$rules->delete_disabled( $rule->public_id, $rule->lock_version + 1 );
			self::fail( 'A stale lock version must not delete the rule.' );
		} catch ( StaleRecordException $exception ) {
			self::assertSame( 'Rule lock version is stale.', $exception->getMessage() );
		}

		$rules->delete_disabled( $rule->public_id, $rule->lock_version );
		self::assertNull( $rules->find_by_public_id( $rule->public_id ) );
		self::assertSame( '1', Database::from_globals()->get_var( 'SELECT COUNT(*) FROM `' . Database::from_globals()->table( Schema::DELIVERIES ) . '` WHERE rule_public_id = %s', array( $rule->public_id ) ) );
	}

	public function test_quick_start_creates_a_switched_off_rule_for_enabled_channels(): void {
		self::assertNull( RulesController::create_quick_start_rule() );

		$first  = $this->channel( 'linkedin' );
		$second = $this->channel( 'twitter' );
		$this->channel( 'bluesky', 'Off channel', false );

		$rule = RulesController::create_quick_start_rule();

		self::assertInstanceOf( RuleRecord::class, $rule );
		self::assertFalse( $rule->enabled );
		self::assertNull( $rule->last_previewed_version );
		self::assertSame( array( 'bulk', 'first_publish', 'scheduled_publication' ), $rule->triggers['triggers'] );
		$expected = array( $first->public_id, $second->public_id );
		sort( $expected );
		self::assertSame( $expected, array_column( $rule->destination_set['destinations'], 'channel_public_id' ) );
		self::assertSame( 'queue_end', $rule->schedule_policy );
		self::assertSame( 'featured_image_optional', $rule->media_policy['mode'] );
		self::assertSame( '{{ title }}', $rule->template_set['default_message'] ?? null );
		self::assertSame( 'post', $rule->condition_tree['children'][0]['value'] );
	}

	public function test_rules_page_renders_cards_escapes_names_and_hides_json(): void {
		$_GET['page'] = RulesController::SLUG;
		$html         = $this->render();
		self::assertStringContainsString( 'No rules yet', $html );
		self::assertStringContainsString( 'Go to Channels', $html );

		$channel = $this->channel( 'linkedin', 'Larkfield Bakery' );
		$html    = $this->render();
		self::assertStringContainsString( 'Share every new post to all my channels', $html );
		self::assertStringContainsString( 'pressqueue_quick_start_rule', $html );

		$rule = ( new RuleRepository() )->create( array_merge( $this->baseInput( $channel ), array( 'name' => '<script>alert(1)</script> & friends' ) ) );
		$html = $this->render();
		self::assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt; &amp; friends', $html );
		self::assertStringNotContainsString( '<script>alert(1)</script>', $html );
		self::assertStringContainsString( 'Sharing rules', $html );
		self::assertStringContainsString( 'Never share', $html );
		self::assertStringContainsString( 'Off. Test it, then switch it on.', $html );
		self::assertStringNotContainsString( 'Condition Tree JSON', $html );
		self::assertStringNotContainsString( $rule->public_id . '</code>', $html );

		$_GET['view'] = 'edit';
		$_GET['rule'] = $rule->public_id;
		$html         = $this->render();
		self::assertStringContainsString( 'Which posts', $html );
		self::assertStringContainsString( 'data-pq-builder=', $html );
		self::assertStringContainsString( 'name="condition_tree_json"', $html );
		self::assertStringContainsString( 'Save and test', $html );
		self::assertStringContainsString( 'Delete rule', $html );

		$_GET['view'] = 'test';
		$html         = $this->render();
		self::assertStringContainsString( 'Run the test', $html );
		self::assertStringNotContainsString( 'Turn on this rule', $html );

		RuleTestView::run( $rule, array( self::factory()->post->create() ), 'first_publish', 1, true );
		$html = $this->render();
		self::assertStringContainsString( 'Turn on this rule', $html );

		$_GET = array(
			'page' => RulesController::SLUG,
			'tab'  => 'templates',
		);
		self::assertStringContainsString( 'No message templates yet', $this->render() );
	}

	public function test_switching_a_rule_on_in_test_mode_says_nothing_reaches_buffer_and_offers_go_live(): void {
		$channel = $this->channel( 'linkedin', 'Larkfield Bakery' );
		$rules   = new RuleRepository();
		$rule    = $rules->create( $this->baseInput( $channel ) );
		$rule    = $rules->mark_version_previewed( $rule->public_id, $rule->lock_version, $rule->version );
		$rule    = $rules->enable_after_preview( $rule->public_id, $rule->lock_version );
		$hint    = 'PressQueue is still in test mode, so nothing goes to Buffer yet.';
		self::assertSame( 'test', ( new SettingsRepository() )->sharing_mode() );

		$_GET = array(
			'page'               => RulesController::SLUG,
			'pressqueue_message' => 'rule_enabled',
			'rule'               => $rule->public_id,
		);
		$html = $this->render();
		self::assertStringContainsString( 'The rule is on.', $html );
		self::assertStringContainsString( $hint, $html );
		self::assertStringContainsString( 'name="sharing_mode" value="live"', $html );
		self::assertStringContainsString( 'name="redirect_page" value="pressqueue-rules"', $html );
		self::assertStringContainsString( '>Go live</button>', $html );

		$_GET['pressqueue_message'] = 'rule_saved_on';
		self::assertStringContainsString( $hint, $this->render() );

		// The test view shows the hint next to the switch, once.
		$_GET['view']               = 'test';
		$_GET['pressqueue_message'] = 'rule_enabled';
		$html                       = $this->render();
		self::assertStringContainsString( 'This rule is on', $html );
		self::assertSame( 1, substr_count( $html, $hint ) );
		self::assertStringContainsString( '>Go live</button>', $html );

		// "Never share" rules send nothing in any mode.
		$never = $rules->create(
			array_merge(
				$this->baseInput( $channel ),
				array(
					'name'            => 'Keep drafts private',
					'effect'          => 'exclude',
					'destination_set' => array(
						'schema_version' => 1,
						'scope'          => 'all',
					),
				)
			)
		);
		$_GET  = array(
			'page'               => RulesController::SLUG,
			'pressqueue_message' => 'rule_enabled',
			'rule'               => $never->public_id,
		);
		$html  = $this->render();
		self::assertStringContainsString( 'The rule is on.', $html );
		self::assertStringNotContainsString( $hint, $html );

		// Once live, the notice is the plain one and Go live comes back with its own notice.
		( new SettingsRepository() )->set_sharing_mode( 'live' );
		$_GET = array(
			'page'                => RulesController::SLUG,
			'pressqueue_message'  => 'rule_enabled',
			'rule'                => $rule->public_id,
			'pressqueue_settings' => 'mode_live',
		);
		$html = $this->render();
		self::assertStringContainsString( 'The rule is on.', $html );
		self::assertStringNotContainsString( $hint, $html );
		self::assertStringContainsString( 'You are live. Posts that match a rule are now sent to Buffer.', $html );
	}

	public function test_turning_a_rule_on_from_the_list_keeps_the_rule_in_the_notice_link(): void {
		$channel = $this->channel( 'linkedin', 'Larkfield Bakery' );
		$rules   = new RuleRepository();
		$rule    = $rules->create( $this->baseInput( $channel ) );
		$rule    = $rules->mark_version_previewed( $rule->public_id, $rule->lock_version, $rule->version );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'_wpnonce'       => wp_create_nonce( 'pressqueue_enable_rule' ),
			'rule_public_id' => $rule->public_id,
			'lock_version'   => (string) $rule->lock_version,
			'return_view'    => 'list',
		);
		$_REQUEST                  = $_POST;
		$locations                 = array();

		// The handler redirects from inside its own try block, so keep the first redirect.
		remove_all_filters( 'wp_redirect' );
		add_filter(
			'wp_redirect',
			static function ( $url ) use ( &$locations ): void {
				$locations[] = (string) $url;
				throw new \RuntimeException( 'pressqueue-test-redirect' );
			}
		);

		try {
			RulesController::handle_enable_rule();
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'pressqueue-test-redirect', $exception->getMessage() );
		} finally {
			remove_all_filters( 'wp_redirect' );
			$_REQUEST                  = array();
			$_SERVER['REQUEST_METHOD'] = 'GET';
		}

		$location = (string) ( $locations[0] ?? '' );
		self::assertStringContainsString( 'pressqueue_message=rule_enabled', $location );
		self::assertStringContainsString( 'rule=' . $rule->public_id, $location );
		self::assertStringNotContainsString( 'view=', $location );
		self::assertTrue( $rules->find_by_public_id( $rule->public_id )->enabled );
	}

	/**
	 * Render the Rules page.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		RulesController::render_page();

		return (string) ob_get_clean();
	}

	/**
	 * Build the POST an untouched editor sends for a rule.
	 *
	 * @param RuleRecord $rule Rule.
	 * @return array<string,mixed>
	 */
	private function editorPost( RuleRecord $rule ): array {
		$triggers = $rule->triggers['triggers'];
		$when     = array();
		if ( in_array( 'first_publish', $triggers, true ) || in_array( 'scheduled_publication', $triggers, true ) ) {
			$when[] = 'publish';
		}
		foreach ( array( 'update', 'bulk', 'repost' ) as $choice ) {
			if ( in_array( $choice, $triggers, true ) ) {
				$when[] = $choice;
			}
		}

		$set  = (array) $rule->template_set;
		$json = (string) wp_json_encode( $set, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return wp_slash(
			array(
				'rule_form'                    => '2',
				'effect'                       => $rule->effect,
				'name'                         => $rule->name,
				'priority'                     => (string) $rule->priority,
				'when_controls_present'        => '1',
				'when'                         => $when,
				'condition_tree_json'          => (string) wp_json_encode( $rule->condition_tree, JSON_PRETTY_PRINT ),
				'custom_fields_present'        => '1',
				'custom_fields'                => implode( ', ', $rule->custom_field_allowlist ),
				'destination_controls_present' => '1',
				'destination_channel_ids'      => array_column( $rule->destination_set['destinations'] ?? array(), 'channel_public_id' ),
				'message_controls_present'     => '1',
				'message_default'              => (string) ( $set['default_message'] ?? '' ),
				'message_service'              => array_merge( array( 'linkedin' => '' ), $set['service_messages'] ?? array() ),
				'template_set_json'            => $json,
				'template_set_json_original'   => $json,
				'schedule_policy_mode'         => (string) $rule->schedule_policy_document['mode'],
				'media_policy_mode'            => (string) $rule->media_policy['mode'],
			)
		);
	}

	/**
	 * Build the POST for a new sharing rule.
	 *
	 * @param ChannelRecord       $channel Channel.
	 * @param array<string,mixed> $overrides Field overrides.
	 * @return array<string,mixed>
	 */
	private function newRulePost( ChannelRecord $channel, array $overrides = array() ): array {
		$template = (string) wp_json_encode(
			array(
				'default_message' => '{{ title }}',
				'schema_version'  => 1,
			)
		);

		return wp_slash(
			array_merge(
				array(
					'rule_form'                    => '2',
					'effect'                       => 'include',
					'name'                         => 'New rule',
					'priority'                     => '100',
					'when_controls_present'        => '1',
					'when'                         => array( 'publish' ),
					'condition_tree_json'          => (string) wp_json_encode( RuleEditor::default_condition_tree( 'include' ) ),
					'custom_fields_present'        => '1',
					'custom_fields'                => '',
					'destination_controls_present' => '1',
					'destination_channel_ids'      => array( $channel->public_id ),
					'message_controls_present'     => '1',
					'message_default'              => '{{ title }}',
					'template_set_json'            => $template,
					'template_set_json_original'   => $template,
					'schedule_policy_mode'         => 'queue_end',
					'media_policy_mode'            => 'featured_image_optional',
				),
				$overrides
			)
		);
	}

	/**
	 * Return a basic include rule input.
	 *
	 * @param ChannelRecord $channel Channel.
	 * @return array<string,mixed>
	 */
	private function baseInput( ChannelRecord $channel ): array {
		return array(
			'name'                   => 'Share posts',
			'effect'                 => 'include',
			'priority'               => 100,
			'triggers'               => array( 'first_publish', 'scheduled_publication' ),
			'condition_tree'         => RuleEditor::default_condition_tree( 'include' ),
			'destination_set'        => array(
				'schema_version' => 1,
				'destinations'   => array( array( 'channel_public_id' => $channel->public_id ) ),
			),
			'template_set'           => array(
				'schema_version'  => 1,
				'default_message' => '{{ title }}',
			),
			'custom_field_allowlist' => array(),
			'schedule_policy'        => array(
				'schema_version' => 1,
				'mode'           => 'queue_end',
			),
			'media_policy'           => array(
				'schema_version' => 1,
				'mode'           => 'featured_image_optional',
			),
		);
	}

	/**
	 * Return a one-category condition tree.
	 *
	 * @param int $term_id Category ID.
	 * @return array<string,mixed>
	 */
	private function categoryTree( int $term_id ): array {
		return array(
			'schema_version' => 1,
			'type'           => 'group',
			'mode'           => 'ALL',
			'children'       => array(
				array(
					'type'      => 'condition',
					'condition' => 'taxonomy',
					'operator'  => 'has_any',
					'value'     => array(
						'taxonomy'          => 'category',
						'terms'             => array( $term_id ),
						'include_ancestors' => true,
					),
				),
			),
		);
	}

	/**
	 * Return the stored behavior documents of a rule.
	 *
	 * @param string $public_id Rule public ID.
	 * @return array<string,mixed>
	 */
	private function storedRow( string $public_id ): array {
		$database = Database::from_globals();
		$row      = $database->get_results_array( 'SELECT * FROM `' . $database->table( Schema::RULES ) . '` WHERE public_id = %s', array( $public_id ) );

		return $row[0];
	}

	/**
	 * Insert a minimal delivery row for a rule.
	 *
	 * @param RuleRecord    $rule Rule.
	 * @param ChannelRecord $channel Channel.
	 * @param string        $state Delivery state.
	 */
	private function insertDelivery( RuleRecord $rule, ChannelRecord $channel, string $state ): void {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert(
			Database::from_globals()->table( Schema::DELIVERIES ),
			array(
				'public_id'            => '01J0000000000000000000DLV1',
				'decision_id'          => 1,
				'decision_public_id'   => '01J0000000000000000000DEC1',
				'event_key'            => str_repeat( 'a', 64 ),
				'delivery_key'         => str_repeat( 'b', 64 ),
				'delivery_mode'        => 'live',
				'provider'             => 'buffer',
				'connection_id'        => $channel->connection_id,
				'connection_public_id' => $channel->connection_public_id,
				'channel_id'           => $channel->id,
				'channel_public_id'    => $channel->public_id,
				'remote_channel_id'    => $channel->remote_channel_id,
				'rule_public_id'       => $rule->public_id,
				'rule_version'         => $rule->version,
				'state'                => $state,
				'payload_preview_json' => '{}',
				'payload_hash'         => str_repeat( 'c', 64 ),
				'created_at'           => $now,
				'updated_at'           => $now,
			)
		);
		self::assertSame( '', $wpdb->last_error );
	}

	/**
	 * Create a verified connection with one channel.
	 *
	 * @param string $service Service.
	 * @param string $name Display name.
	 * @param bool   $enabled Whether the channel is switched on.
	 * @return ChannelRecord
	 */
	private function channel( string $service, string $name = 'Example', bool $enabled = true ): ChannelRecord {
		++$this->sequence;
		$connections = new ConnectionRepository();
		$channels    = new ChannelRepository();
		$connection  = null;

		foreach ( $connections->find_all() as $existing ) {
			$connection = $existing;
		}

		if ( null === $connection ) {
			$envelope   = CanonicalJson::encode_object( array( 'fixture' => 'encrypted' ) );
			$account    = array(
				'display_label'     => 'Owner',
				'organizations'     => array(
					array(
						'channel_count'          => 3,
						'display_label'          => 'Editorial',
						'provider'               => 'buffer',
						'remote_organization_id' => 'org_1',
					),
				),
				'provider'          => 'buffer',
				'remote_account_id' => 'acct_1',
				'schema_version'    => 1,
			);
			$connection = $connections->create_with_credential( 'buffer', 'Local account', $envelope, hash( 'sha256', $envelope ) );
			$connection = $connections->store_verified_account( $connection->public_id, 'acct_1', $account );
			$connection = $connections->select_organization_unverified( $connection->public_id, 'org_1' );
			$lease      = $connections->acquire_sync_lease( $connection->public_id );
			self::assertNotNull( $lease );
			$connection = $connections->complete_sync_success( $connection->public_id, $lease, 'acct_1', 'org_1', $account );
			$connection = $connections->enable( $connection->public_id );
		}

		$capabilities = array(
			'catalog_version' => '2026-08-25.1',
			'delivery'        => array(
				'image'               => array(
					'alt_text_required' => false,
					'max_bytes'         => 5 * 1024 * 1024,
					'max_height'        => 4096,
					'max_items'         => 1,
					'max_width'         => 4096,
					'mime_types'        => array( 'image/gif', 'image/jpeg', 'image/png' ),
					'min_height'        => 1,
					'min_width'         => 1,
				),
				'max_text_characters' => 280,
				'schedule_slots'      => array( 'immediate', 'queue_next', 'queue_end', 'explicit_time', 'draft' ),
				'text'                => true,
			),
			'schema_version'  => 2,
			'service'         => $service,
			'supported'       => true,
		);
		$channel      = $channels->create( $connection->public_id, 'buffer', 'remote-channel-' . $this->sequence, $service, array( 'name' => $name ), $capabilities, false, '2026-08-24 12:00:00', 'org_1', 'available' );

		if ( $enabled ) {
			$channel = $channels->set_enabled_if_current( $channel->public_id, true, $channel->updated_at->format( 'Y-m-d H:i:s' ) );
		}

		return $channel;
	}

	/**
	 * Log in an administrator with every PressQueue capability.
	 */
	private function adminUser(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = get_userdata( $user_id );
		self::assertInstanceOf( \WP_User::class, $user );
		foreach ( Capabilities::names() as $capability ) {
			$user->add_cap( $capability );
		}
		wp_set_current_user( 0 );
		wp_set_current_user( $user_id );
	}

	/**
	 * Reset custom storage between tests.
	 */
	private function resetStorage(): void {
		global $wpdb;

		$database = Database::from_globals();

		foreach ( array_reverse( Schema::table_suffixes() ) as $suffix ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . $database->table( $suffix ) . '`' );
		}

		foreach ( array( Schema::VERSION_OPTION, Schema::MIGRATION_LOCK_OPTION, Schema::MIGRATION_FAILED_OPTION, Capabilities::VERSION_OPTION, SettingsRepository::KILL_SWITCH_OPTION, SettingsRepository::DRY_RUN_OPTION ) as $option ) {
			delete_option( $option );
		}

		delete_transient( 'pressqueue_rule_test_' . get_current_user_id() );
	}
}
