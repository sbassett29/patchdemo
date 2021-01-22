<?php
require_once "includes.php";

if ( $useOAuth && !$user ) {
	echo oauth_signin_prompt();
} else {
	$branches = get_branches( 'mediawiki/core' );

	$branches = array_filter( $branches, function ( $branch ) {
		return preg_match( '/^origin\/(master|wmf|REL)/', $branch );
	} );
	natcasesort( $branches );

	// Put newest branches first
	$branches = array_reverse( array_values( $branches ) );

	// Move master to the top
	array_unshift( $branches, array_pop( $branches ) );

	$branchesOptions = array_map( function ( $branch ) {
		return [
			'label' => preg_replace( '/^origin\//', '', $branch ),
			'data' => $branch,
		];
	}, $branches );

	$repoBranches = [];
	$repoOptions = [];
	$repoData = get_repo_data();
	ksort( $repoData );
	foreach ( $repoData as $repo => $path ) {
		$repoBranches[$repo] = get_branches( $repo );
		$repo = htmlspecialchars( $repo );
		$repoOptions[] = [
			'data' => $repo,
			'label' => $repo,
			'disabled' => ( $repo === 'mediawiki/core' ),
		];
	}
	$repoBranches = htmlspecialchars( json_encode( $repoBranches ), ENT_NOQUOTES );
	echo "<script>window.repoBranches = $repoBranches;</script>\n";

	$presets = get_repo_presets();
	$reposValid = array_keys( $repoData );
	foreach ( $presets as $name => $repos ) {
		$presets[$name] = array_values( array_intersect( $repos, $reposValid ) );
	}
	$presets = htmlspecialchars( json_encode( $presets ), ENT_NOQUOTES );
	echo "<script>window.presets = $presets;</script>\n";

	include_once 'DetailsFieldLayout.php';

	echo new OOUI\FormLayout( [
		'infusable' => true,
		'method' => 'POST',
		'action' => 'new.php',
		'id' => 'new-form',
		'items' => [
			new OOUI\FieldsetLayout( [
				'label' => null,
				'items' => array_filter( [
					new OOUI\FieldLayout(
						new OOUI\DropdownInputWidget( [
							'classes' => [ 'form-branch' ],
							'name' => 'branch',
							'options' => $branchesOptions,
						] ),
						[
							'label' => 'Start with version:',
							'align' => 'left',
						]
					),
					new OOUI\FieldLayout(
						new OOUI\MultilineTextInputWidget( [
							'classes' => [ 'form-patches' ],
							'name' => 'patches',
							'rows' => 4,
							'placeholder' => "e.g. 456123",
						] ),
						[
							'label' => 'Then, apply patches:',
							'help' => 'Gerrit changeset number or Change-Id, one per line',
							'helpInline' => true,
							'align' => 'left',
						]
					),
					$config['conduitApiKey'] ?
						new OOUI\FieldLayout(
							new OOUI\CheckboxInputWidget( [
								'name' => 'announce',
								'value' => 1,
								'selected' => true
							] ),
							[
								'label' => 'Announce wiki on Phabricator:',
								'help' => 'Any tasks linked to from patches applied will get a comment announcing this wiki.',
								'helpInline' => true,
								'align' => 'left',
							]
						) :
						null,
					new OOUI\FieldLayout(
						new OOUI\RadioSelectInputWidget( [
							'classes' => [ 'form-preset' ],
							'name' => 'preset',
							'options' => [
								[
									'data' => 'all',
									'label' => 'All',
								],
								[
									'data' => 'wikimedia',
									'label' => new OOUI\HtmlSnippet( '<abbr title="Most skins and extensions installed on most Wikimedia wikis, based on MediaWiki.org">Wikimedia</abbr>' ),
								],
								[
									'data' => 'tarball',
									'label' => new OOUI\HtmlSnippet( '<abbr title="Skins and extensions included in the official MediaWiki release">Tarball</abbr>' ),
								],
								[
									'data' => 'minimal',
									'label' => new OOUI\HtmlSnippet( '<abbr title="Only MediaWiki and default skin">Minimal</abbr>' ),
								],
								[
									'data' => 'custom',
									'label' => 'Custom',
								],
							],
							'value' => 'wikimedia',
						] ),
						[
							'label' => 'Choose configuration preset:',
							'align' => 'left',
						]
					),
					new DetailsFieldLayout(
						new OOUI\CheckboxMultiselectInputWidget( [
							'classes' => [ 'form-repos' ],
							'name' => 'repos[]',
							'options' => $repoOptions,
							'value' => get_repo_presets()[ 'wikimedia' ],
						] ),
						[
							'label' => 'Choose included repos:',
							'helpInline' => true,
							'align' => 'left',
							'classes' => [ 'form-repos-field' ],
						]
					),
					new OOUI\FieldLayout(
						new OOUI\ButtonInputWidget( [
							'classes' => [ 'form-submit' ],
							'label' => 'Create demo',
							'type' => 'submit',
							// 'disabled' => true,
							'flags' => [ 'progressive', 'primary' ]
						] ),
						[
							'label' => ' ',
							'align' => 'left',
						]
					),
				] )
			] ),
		]
	] );

	$banner = banner_html();
	if ( $banner ) {
		echo "<p class='banner'>$banner</p>";
	}
}
?>
<br/>
<h3>Previously generated wikis</h3>
<?php
if ( $user ) {
	echo new OOUI\FieldLayout(
		new OOUI\CheckboxInputWidget( [
			'infusable' => true,
			'classes' => [ 'myWikis' ]
		] ),
		[
			'align' => 'inline',
			'label' => 'Show only my wikis',
		]
	);
	echo new OOUI\FieldLayout(
		new OOUI\CheckboxInputWidget( [
			'infusable' => true,
			'classes' => [ 'closedWikis' ]
		] ),
		[
			'align' => 'inline',
			'label' => 'Show only wikis where all patches are merged or abandoned',
		]
	);
}
?>
<p><em>✓=Merged ✗=Abandoned</em></p>
<?php

function all_closed( $statuses ) {
	foreach ( $statuses as $status ) {
		if ( $status !== 'MERGED' && $status !== 'ABANDONED' ) {
			return false;
		}
	}
	return true;
}

$rows = '';
$anyCanDelete = false;
$closedWikis = 0;

$results = $mysqli->query( 'SELECT wiki FROM wikis ORDER BY created DESC' );
if ( !$results ) {
	die( $mysqli->error );
}
while ( $data = $results->fetch_assoc() ) {
	$wiki = $data['wiki'];
	$wikiData = get_wiki_data( $wiki );
	$statuses = [];
	$patches = '?';
	$linkedTasks = '';

	$patches = implode( '<br>', array_map( function ( $patchData ) use ( &$statuses, &$linkedTaskList ) {
		global $config;
		$statuses[] = $patchData['status'];
		$title = $patchData['patch'] . ': ' . $patchData[ 'subject' ];

		return '<a href="' . $config['gerritUrl'] . '/r/c/' . $patchData['r'] . '/' . $patchData['p'] . '" title="' . htmlspecialchars( $title ) . '" class="status-' . $patchData['status'] . '">' .
			htmlspecialchars( $title ) .
		'</a>';
	}, $wikiData['patchList'] ) );

	$taskDescs = [];
	foreach ( $wikiData['linkedTaskList'] as $task => $taskData ) {
		$taskTitle = $taskData['id'] . ': ' . htmlspecialchars( $taskData['title'] );
		$taskDescs[] = '<a href="' . $config['phabricatorUrl'] . '/' . $taskData['id'] . '" title="' . $taskTitle . '">' . $taskTitle . '</a>';
	}
	$linkedTasks = implode( '<br>', $taskDescs );

	$creator = $wikiData[ 'creator' ] ?? '';
	$username = $user ? $user->username : null;
	$canDelete = can_delete( $creator );
	$canAdmin = can_admin();
	$anyCanDelete = $anyCanDelete || $canDelete;
	$closed = all_closed( $statuses );

	$classes = [];
	if ( $creator !== $username ) {
		$classes[] = 'other';
	}
	if ( !$closed ) {
		$classes[] = 'open';
	}

	$rows .= '<tr class="' . implode( ' ', $classes ) . '">' .
		'<td data-label="Wiki" class="wiki"><a href="wikis/' . $wiki . '/w" title="' . $wiki . '">' . $wiki . '</a></td>' .
		'<td data-label="Patches" class="patches">' . ( $patches ?: '<em>No patches</em>' ) . '</td>' .
		'<td data-label="Linked tasks" class="linkedTasks">' . ( $linkedTasks ?: '<em>No tasks</em>' ) . '</td>' .
		'<td data-label="Time" class="date">' . date( 'Y-m-d H:i:s', $wikiData[ 'created' ] ) . '</td>' .
		( $useOAuth ? '<td data-label="Creator">' . ( $creator ? user_link( $creator ) : '?' ) . '</td>' : '' ) .
		( $canAdmin ? '<td data-label="Time to create">' . ( $wikiData['timeToCreate'] ? $wikiData['timeToCreate'] . 's' : '' ) . '</td>' : '' ) .
		( $canDelete ?
			'<td data-label="Actions"><a href="delete.php?wiki=' . $wiki . '">Delete</a></td>' :
			( $anyCanDelete ? '<td></td>' : '' )
		) .
	'</tr>';

	if ( $username && $username === $creator && $closed ) {
		$closedWikis++;
	}
}

if ( $closedWikis ) {
	echo new OOUI\MessageWidget( [
		'type' => 'warning',
		'label' => new OOUI\HtmlSnippet(
			new OOUI\ButtonWidget( [
				'infusable' => true,
				'label' => 'Show',
				'classes' => [ 'showClosed' ],
			] ) .
			'You have created ' . $closedWikis . ' ' . ( $closedWikis > 1 ? 'wikis' : 'wiki' ) . ' where all the patches ' .
			'have been merged or abandoned and therefore can be deleted.'
		)
	] );
}

echo '<table class="wikis">' .
	'<tr>' .
		'<th>Wiki</th>' .
		'<th>Patches</th>' .
		'<th>Linked tasks</th>' .
		'<th>Time</th>' .
		( $useOAuth ? '<th>Creator</th>' : '' ) .
		( $canAdmin ? '<th><abbr title="Time to create">TTC</th>' : '' ) .
		( $anyCanDelete ? '<th>Actions</th>' : '' ) .
	'</tr>' .
	$rows .
'</table>';

?>
<script src="DetailsFieldLayout.js"></script>
<script src="index.js"></script>
<?php
include "footer.html";
