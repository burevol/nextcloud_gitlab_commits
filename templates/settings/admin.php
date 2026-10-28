<?php

/**
 * SPDX-FileCopyrightText: 2026 GitLab commits contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @var \OCP\IL10N $l
 * @var array<string, mixed> $_
 */

?>
<div class="section" id="gitlab-commits">
	<h2><?php p($l->t('GitLab connection')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('The dashboard widget reads the latest commits of a single GitLab project with a personal access token. The token is stored in the Nextcloud database and is never sent to the browser or written to the log.')); ?>
	</p>

	<?php if ($_['success_message'] !== '') { ?>
		<p class="gitlab-commits-message gitlab-commits-message--success" role="status">
			<?php p($_['success_message']); ?>
		</p>
	<?php } ?>

	<?php if ($_['error_message'] !== '') { ?>
		<p class="gitlab-commits-message gitlab-commits-message--error" role="alert">
			<?php p($_['error_message']); ?>
		</p>
	<?php } ?>

	<form method="post" action="<?php p($_['action']); ?>" class="gitlab-commits-settings">
		<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">

		<p>
			<label for="gitlab-commits-url"><?php p($l->t('GitLab base URL')); ?></label>
			<input type="url"
				id="gitlab-commits-url"
				name="gitlab_url"
				value="<?php p($_['gitlab_url']); ?>"
				placeholder="https://gitlab.example.com"
				autocomplete="off">
			<em><?php p($l->t('Without /api/v4, for example https://gitlab.example.com')); ?></em>
		</p>

		<p>
			<label for="gitlab-commits-token"><?php p($l->t('Personal access token')); ?></label>
			<input type="password"
				id="gitlab-commits-token"
				name="gitlab_token"
				value=""
				autocomplete="new-password"
				placeholder="<?php p($_['token_placeholder']); ?>">
			<em>
				<?php p($l->t('Create a token in GitLab with the read_api scope: Profile → Preferences → Access tokens.')); ?>
			</em>
		</p>

		<?php if ($_['token_is_set']) { ?>
			<p class="gitlab-commits-checkbox">
				<input type="checkbox"
					id="gitlab-commits-token-remove"
					name="gitlab_token_remove"
					value="1">
				<label for="gitlab-commits-token-remove"><?php p($l->t('Remove the stored token')); ?></label>
			</p>
		<?php } ?>

		<p>
			<label for="gitlab-commits-project"><?php p($l->t('Project id or path')); ?></label>
			<input type="text"
				id="gitlab-commits-project"
				name="gitlab_project"
				value="<?php p($_['gitlab_project']); ?>"
				placeholder="23 or group/subgroup/project"
				autocomplete="off">
			<em><?php p($l->t('The numeric project id and the project path are both supported.')); ?></em>
		</p>

		<p>
			<label for="gitlab-commits-branch"><?php p($l->t('Branch')); ?></label>
			<input type="text"
				id="gitlab-commits-branch"
				name="gitlab_branch"
				value="<?php p($_['gitlab_branch']); ?>"
				placeholder="main"
				autocomplete="off">
			<em><?php p($l->t('Commits of this branch are shown, main by default.')); ?></em>
		</p>

		<p>
			<label for="gitlab-commits-ttl"><?php p($l->t('Cache lifetime in seconds')); ?></label>
			<input type="number"
				id="gitlab-commits-ttl"
				name="cache_ttl"
				value="<?php p((string)$_['cache_ttl']); ?>"
				min="<?php p((string)$_['min_cache_ttl']); ?>"
				max="<?php p((string)$_['max_cache_ttl']); ?>"
				step="1">
			<em>
				<?php p($l->t('A successful GitLab response is cached for this long, so the dashboard does not query GitLab on every reload.')); ?>
			</em>
		</p>

		<p>
			<label for="gitlab-commits-limit"><?php p($l->t('Number of commits')); ?></label>
			<input type="number"
				id="gitlab-commits-limit"
				name="commits_limit"
				value="<?php p((string)$_['commits_limit']); ?>"
				min="<?php p((string)$_['min_commits_limit']); ?>"
				max="<?php p((string)$_['max_commits_limit']); ?>"
				step="1">
			<em><?php p($l->t('Maximum number of commits shown in the widget, 7 by default.')); ?></em>
		</p>

		<p>
			<button type="submit" class="button primary"><?php p($l->t('Save')); ?></button>
		</p>
	</form>
</div>
