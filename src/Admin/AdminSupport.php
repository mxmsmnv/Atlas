<?php namespace ProcessWire;

/** Internal ProcessAtlas concern; composed by the ProcessWire module entry point. */
trait ProcessAtlasAdminSupport {
	protected function emptyState(string $icon, string $title, string $body, string $url = '', string $action = ''): string {
		$button = $url !== '' ? '<a class="uk-button uk-button-primary" href="' . $url . '">' . $action . '</a>' : '';
		return '<div class="AtlasEmpty pw-empty-state"><span class="AtlasEmpty-icon">' . wireIconMarkup($icon) . '</span><h3 class="uk-h4">' . $title . '</h3><p>' . $body . '</p>' . $button . '</div>';
	}

	protected function hasAtlasError($atlas): bool {
		return method_exists($atlas, 'lastError') && $atlas->lastError() !== '';
	}

	protected function isReservedCollection(string $collection): bool {
		return strpos($collection, AtlasStore::STAGING_PREFIX) === 0;
	}

	protected function dataError($atlas, string $title = ''): string {
		$title = $title !== '' ? $title : $this->_('Knowledge data is temporarily unavailable');
		$detail = $this->wire('sanitizer')->entities(method_exists($atlas, 'lastError') ? $atlas->lastError() : '');
		return '<div class="AtlasSearchError AtlasDataError uk-alert uk-alert-danger" role="alert"><span class="AtlasSearchError-icon">' . wireIconMarkup('exclamation-triangle') . '</span><div><h3>' . $title . '</h3><p>' . $this->_('Atlas could not load this data. Check the database connection, then reload the page.') . '</p><details><summary>' . $this->_('Technical details') . '</summary><code>' . $detail . '</code></details></div></div>';
	}

	protected function actionReturnUrl($input, $san): string {
		$section = $san->pageName((string) $input->post('return_section'));
		if ($section === 'search') {
			$args = ['section' => 'search', 'collection_q' => $san->text((string)$input->post('return_collection')), 'q' => trim((string)$input->post('return_q')), 'keyword' => trim((string)$input->post('return_keyword')), 'keyword_require' => !empty($input->post('return_keyword_require')) ? 1 : 0, 'mmr' => !empty($input->post('return_mmr')) ? 1 : 0, 'topk' => max(1, min(50, (int)$input->post('return_topk') ?: 5))];
			return './?' . http_build_query($args);
		}
		$view = $san->text((string) $input->post('view'));
		return $view !== '' ? './?section=collections&view=' . urlencode($view) : './?section=collections';
	}

	/** Readiness banner + totals. */

	protected function renderStatus($atlas): string {
		$cols   = $atlas->collections();
		if ($this->hasAtlasError($atlas)) return "<div class='AtlasStatus needs-setup' title='" . $this->_('Atlas could not read its database table.') . "'>" . wireIconMarkup('exclamation-triangle') . '<span><strong>' . $this->_('Storage unavailable') . '</strong></span></div>';
		$total  = array_sum(array_column($cols, 'count'));
		$ready  = $atlas->isReady();
		$readinessFailed = !$ready && method_exists($atlas, 'readinessError') && $atlas->readinessError() !== '';
		$icon   = $ready ? wireIconMarkup('check-circle') : wireIconMarkup('exclamation-triangle');
		$label = $ready ? $this->_('Ready') : ($readinessFailed ? $this->_('Provider unavailable') : $this->_('Setup needed'));
		$title = $ready ? $this->_('Embeddings available via Squad.') : ($readinessFailed ? $this->_('Check Squad and the ProcessWire logs.') : $this->_('Configure an embedding provider key in Squad to search or index.'));
		$title = $this->wire('sanitizer')->entities($title);
		$cls    = $ready ? ' is-ready' : ' needs-setup';
		$countLabel = sprintf($this->_n('%1$d entry', '%1$d entries', $total), $total);
		return "<div class='AtlasStatus{$cls}' title='{$title}'>{$icon}<span><strong>{$label}</strong>"
			. '<small>' . $countLabel . '</small></span></div>';
	}


	protected function renderRef(string $ref, $san): string {
		$value = $san->entities($ref);
		$copy = $san->entities($this->_('Copy ref'));
		$copied = $san->entities($this->_('Copied'));
		return '<span class="AtlasRef"><code>' . $value . '</code><button type="button" class="AtlasCopy" data-copy="' . $value . '" data-copy-label="' . $copy . '" data-copied-label="' . $copied . '" aria-label="' . $copy . '" title="' . $copy . '">' . wireIconMarkup('copy') . '</button></span>';
	}

	protected function renderMeta(array $meta, $san): string {
		if (!$meta) return '';
		$json = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if (!is_string($json)) return '';
		return '<details class="AtlasMeta"><summary>' . $this->_('Metadata') . '</summary><pre>' . $san->entities($json) . '</pre></details>';
	}

	/** Allow local links and http(s) links only; metadata can originate outside the admin. */

	protected function safeUrl(string $url): string {
		$url = trim($url);
		if ($url === '' || strpos($url, '\\') !== false || preg_match('/[\x00-\x1F\x7F]/', $url)) return '';
		if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) return $url;
		if (filter_var($url, FILTER_VALIDATE_URL) === false) return '';
		$parts = parse_url($url);
		if (!is_array($parts)) return '';
		$scheme = strtolower((string)($parts['scheme'] ?? ''));
		$host = (string)($parts['host'] ?? '');
		if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) return '';
		return $url;
	}

}
