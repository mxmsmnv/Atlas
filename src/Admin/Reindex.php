<?php namespace ProcessWire;

/** Internal ProcessAtlas concern; composed by the ProcessWire module entry point. */
trait ProcessAtlasReindex {
	/** Guard nested Repeater/Matrix extraction against cyclic page structures. */
	protected $pageFieldExtractionDepth = 0;

	/** One-click "build a knowledge base from this whole site". */
	protected function renderReindex($atlas): string {
		if ($this->hasAtlasError($atlas)) return '<section class="AtlasSection"><div class="AtlasSection-head"><div><p>' . $this->_('Build a searchable knowledge base from site pages.') . '</p></div></div>' . $this->dataError($atlas) . '</section>';
		$job = $this->wire('session')->get('AtlasReindexJob');
		if (is_array($job) && ($job['status'] ?? '') === 'running') {
			$this->headline($this->_('Reindex in progress'));
			if (empty($job['token'])) {
				$job['token'] = bin2hex(random_bytes(16));
				$this->wire('session')->set('AtlasReindexJob', $job);
			}
			return $this->renderReindexJob($job);
		}
		if (!$atlas->isReady()) return '<section class="AtlasSection"><div class="AtlasSection-head"><div><p>' . $this->_('Build a searchable knowledge base from site pages.') . '</p></div></div>'
			. $this->emptyState('plug', $this->_('Embedding provider required'), $this->_('Configure an embedding provider in Squad before starting a reindex.')) . '</section>';
		$matchingPageCount = $this->wire('pages')->count(self::SITE_SELECTOR);
		$pageCount = min(1000, $matchingPageCount);

		$form = $this->wire('modules')->get('InputfieldForm');
		$form->attr('method', 'post');
		$form->attr('action', './');
		$form->addClass('AtlasReindexForm');
		$form->attr('data-confirm', $this->_('Start reindexing? This will call the configured embedding API.'));

		$set = $this->wire('modules')->get('InputfieldFieldset');
		$set->label = $this->_('Build a knowledge base from this site');
		$set->icon = 'database';
		$countMessage = $matchingPageCount > 1000
			? sprintf($this->_('The first %1$d of %2$d matching pages will be embedded'), $pageCount, $matchingPageCount)
			: sprintf($this->_n('About %d page will be embedded', 'About %d pages will be embedded', $pageCount), $pageCount);
		$set->description = $this->_('Index every public page (title + text), split into chunks, into one collection — ready for search and RAG.') . ' ' . $countMessage . '; ' . $this->_('this calls the embedding API.');

		$hidden = $this->wire('modules')->get('InputfieldHidden');
		$hidden->attr('name', 'action'); $hidden->attr('value', 'reindex');
		$set->add($hidden);

		$f = $this->wire('modules')->get('InputfieldText');
		$f->attr('name', 'collection'); $f->attr('value', 'site');
		$f->attr('maxlength', 128);
		$f->label = $this->_('Collection'); $f->columnWidth = 40;
		$f->notes = $this->_('New or existing. Use “olivia_site” to feed Olivia’s Operate audit.');
		$set->add($f);

		$f = $this->wire('modules')->get('InputfieldText');
		$f->attr('name', 'selector'); $f->attr('value', self::SITE_SELECTOR);
		$f->attr('maxlength', 1000);
		$f->label = $this->_('Pages (advanced)'); $f->columnWidth = 60; $f->collapsed = Inputfield::collapsedYes;
		$f->notes = $this->_('ProcessWire selector — which pages to include (max 1000).');
		$set->add($f);

		$s = $this->wire('modules')->get('InputfieldSubmit');
		$s->attr('value', $this->_('Build knowledge base')); $s->icon = 'database';
		$set->add($s);

		$form->add($set);
		return '<section class="AtlasSection"><div class="AtlasSection-head"><div><p>' . $this->_('Build a searchable knowledge base from site pages.') . '</p></div></div>'
			. '<div class="AtlasCallout uk-alert uk-alert-primary">' . wireIconMarkup('info-circle') . '<div><strong>' . $countMessage . '</strong><span>'
			. $this->_('This calls the configured embedding API in small batches. The current collection remains available until every batch succeeds.') . '</span></div></div>'
			. '<div class="AtlasPanel pw-wrap">' . $form->render() . '</div></section>';
	}

	protected function renderReindexJob(array $job): string {
		$total = max(0, count((array)($job['page_ids'] ?? [])));
		$unchanged = max(0, (int)($job['unchanged'] ?? 0));
		$current = max(0, min($total, (int)($job['current'] ?? 0)));
		$percent = $total > 0 ? (int) floor($current / $total * 100) : 100;
		$csrf = $this->wire('session')->CSRF->renderInput();
		$collection = $this->wire('sanitizer')->entities((string)($job['collection'] ?? ''));
		$jobToken = $this->wire('sanitizer')->entities((string)($job['token'] ?? ''));
		$batch = '<form method="post" action="./?section=reindex" class="AtlasBatchForm">' . $csrf . '<input type="hidden" name="action" value="reindex_batch"><input type="hidden" name="job_token" value="' . $jobToken . '"></form>';
		$cancel = '<form method="post" action="./?section=reindex" class="AtlasInlineForm" data-confirm="' . $this->_('Cancel this reindex? The previous collection will remain unchanged.') . '">' . $csrf
			. '<input type="hidden" name="action" value="reindex_cancel"><input type="hidden" name="job_token" value="' . $jobToken . '"><button type="submit" class="uk-button uk-button-default">' . wireIconMarkup('times') . ' ' . $this->_('Cancel') . '</button></form>';
		$retry = $this->wire('sanitizer')->entities($this->_('Connection interrupted — retrying…'));
		$networkError = $this->wire('sanitizer')->entities($this->_('Connection failed after several attempts. The reindex job is still safe to resume.'));
		$tryAgain = $this->wire('sanitizer')->entities($this->_('Try again'));
		$title = $total > 0
			? sprintf($this->_('Processing page %1$d of %2$d'), min($current + 1, $total), $total)
			: sprintf($this->_n('Reusing %d unchanged page', 'Reusing %d unchanged pages', $unchanged), $unchanged);
		$note = $unchanged > 0
			? sprintf($this->_n('%d unchanged page was reused; Atlas is embedding only changed content.', '%d unchanged pages were reused; Atlas is embedding only changed content.', $unchanged), $unchanged)
			: $this->_('You can keep this page open while Atlas embeds each batch.');
		return '<section class="AtlasSection"><div class="AtlasSection-head"><div><p>' . sprintf($this->_('Building collection “%s” in safe batches.'), $collection) . '</p></div></div>'
			. '<div class="AtlasPanel AtlasJob pw-wrap" data-atlas-job data-retry-label="' . $retry . '" data-network-error-label="' . $networkError . '" data-try-again-label="' . $tryAgain . '" role="status" aria-live="polite"><div class="AtlasJob-head"><span class="AtlasJob-icon">' . wireIconMarkup('refresh') . '</span><div><strong data-atlas-job-title>'
			. $title . '</strong><small>' . $note . '</small></div></div>'
			. '<progress class="uk-progress" max="100" value="' . $percent . '" data-atlas-progress aria-label="' . $this->_('Reindex progress') . '"></progress><div class="AtlasJob-foot"><span data-atlas-progress-label>' . $percent . '%</span>' . $cancel . '</div>' . $batch . '</div></section>';
	}


	/** Prepare a resumable browser-driven reindex job without changing live data. */
	protected function startReindex($atlas, $input, $san): void {
		if (!$atlas->isReady()) { $this->error($this->_('Reindex needs an embedding provider configured in Squad.')); return; }
		$coll     = $san->text((string) $input->post('collection')) ?: 'site';
		if (mb_strlen($coll) > 128) { $this->error($this->_('Collection names are limited to 128 characters.')); return; }
		if ($this->isReservedCollection($coll)) { $this->error($this->_('Collection names beginning with “__atlas_stage_” are reserved for internal reindexing.')); return; }
		$selector = trim((string) $input->post('selector'));
		if (mb_strlen($selector) > 1000) { $this->error($this->_('Page selectors are limited to 1,000 characters.')); return; }
		if ($selector === '') $selector = self::SITE_SELECTOR;
		try { $pages = $this->wire('pages')->find($selector . ', limit=1000'); }
		catch (\Throwable $e) { $this->error($this->_('Invalid page selector.')); return; }
		$ids = [];
		foreach ($pages as $page) $ids[] = (int)$page->id;
		if (!$ids) { $this->error($this->_('No pages matched this selector. The current collection was not changed.')); return; }
		$atlas->cleanupStaging();
		if ($this->hasAtlasError($atlas)) { $this->error($this->_('Atlas could not prepare staging storage. The current reindex job was not changed.')); return; }
		$storedRefs = method_exists($atlas, 'baseRefCounts') ? $atlas->baseRefCounts($coll) : [];
		if ($this->hasAtlasError($atlas)) { $this->error($this->_('Atlas could not inspect the current collection. The current reindex job was not changed.')); return; }
		$identity = method_exists($atlas, 'embeddingIdentity') ? (string)$atlas->embeddingIdentity() : '';
		$changedIds = [];
		$unchangedRefs = [];
		$pageStates = [];
		foreach ($pages as $page) {
			$id = (int)$page->id;
			$document = $this->buildPageDocument($page, $coll);
			$text = !empty($document['skip']) ? '' : trim((string)($document['text'] ?? ''));
			$documentMeta = is_array($document['meta'] ?? null) ? $document['meta'] : [];
			$hash = hash('sha256', $text);
			$documentHash = $this->pageDocumentHash($text, $documentMeta);
			$chunks = $text === '' ? 0 : count($atlas->chunkText($text));
			$previous = $this->pageIndexState($page, $coll);
			$baseRef = 'page-' . $id;
			$storageMatches = $chunks > 0 ? (int)($storedRefs[$baseRef] ?? -1) === $chunks : !array_key_exists($baseRef, $storedRefs);
			$isCurrent = $identity !== ''
				&& hash_equals((string)($previous['document_hash'] ?? ''), $documentHash)
				&& hash_equals((string)($previous['identity'] ?? ''), $identity)
				&& (int)($previous['chunks'] ?? -1) === $chunks
				&& $storageMatches;
			if ($isCurrent) {
				if ($chunks > 0) $unchangedRefs[] = $baseRef;
				$pageStates[(string)$id] = ['content_hash' => $hash, 'document_hash' => $documentHash, 'chunks' => $chunks];
			} else {
				$changedIds[] = $id;
			}
		}
		$currentIds = array_fill_keys(array_map('strval', $ids), true);
		$removedIds = [];
		foreach (array_keys($storedRefs) as $baseRef) {
			if (preg_match('/^page-(\d+)$/', (string)$baseRef, $match) && !isset($currentIds[$match[1]])) $removedIds[] = (int)$match[1];
		}
		$this->cancelReindex($atlas);
		$staging = AtlasStore::STAGING_PREFIX . bin2hex(random_bytes(16));
		$copied = 0;
		if ($unchangedRefs) {
			$copied = $atlas->copyRefs($coll, $staging, $unchangedRefs);
			$expected = array_sum(array_intersect_key($storedRefs, array_fill_keys($unchangedRefs, true)));
			if ($copied !== $expected) {
				$atlas->clearCollection($staging);
				if ($this->hasAtlasError($atlas)) { $this->error($this->_('Atlas could not prepare the incremental index. The current collection was not changed.')); return; }
				$changedIds = $ids;
				$unchangedRefs = [];
				$pageStates = [];
				$copied = 0;
			}
		}
		$this->wire('session')->set('AtlasReindexJob', [
			'status' => 'running',
			'token' => bin2hex(random_bytes(16)),
			'collection' => $coll,
			'staging' => $staging,
			'page_ids' => $changedIds,
			'all_page_ids' => $ids,
			'page_states' => $pageStates,
			'removed_page_ids' => $removedIds,
			'identity' => $identity,
			'current' => 0,
			'pages' => count($pageStates),
			'chunks' => $copied,
			'unchanged' => count($pageStates),
		]);
	}

	/** Process a few pages and return progress for the AJAX runner. */
	protected function processReindexBatch($atlas, string $token = ''): array {
		$session = $this->wire('session');
		$job = $session->get('AtlasReindexJob');
		if (!is_array($job) || ($job['status'] ?? '') !== 'running') return ['done' => true, 'error' => $this->_('No active reindex job.')];
		$activeToken = (string)($job['token'] ?? '');
		if (!hash_equals($activeToken, $token)) return ['done' => true, 'error' => $this->_('This reindex job changed in another tab. Reload to continue the active job.')];
		$ids = (array)($job['page_ids'] ?? []);
		$total = count($ids);
		try {
			$batchItems = []; $batchPages = 0;
			foreach (array_slice($ids, (int)$job['current'], 5) as $id) {
				$page = $this->wire('pages')->get((int)$id);
				if ($page->id) {
					$document = $this->buildPageDocument($page, (string)$job['collection']);
					$text = !empty($document['skip']) ? '' : trim((string)($document['text'] ?? ''));
					$documentMeta = is_array($document['meta'] ?? null) ? $document['meta'] : [];
					$chunks = $text === '' ? [] : $atlas->chunkText($text);
					$job['page_states'][(string)$page->id] = [
						'content_hash' => hash('sha256', $text),
						'document_hash' => $this->pageDocumentHash($text, $documentMeta),
						'chunks' => count($chunks),
					];
					if ($text !== '') {
						$base = 'page-' . $page->id;
						foreach ($chunks as $i => $chunk) {
							$batchItems[] = ['ref' => $base . '#' . $i, 'text' => $chunk, 'meta' => array_merge($documentMeta, ['parent' => $base, 'id' => $page->id, 'title' => (string)$page->title, 'url' => $page->url, 'chunk' => $i])];
						}
						$batchPages++;
					}
				}
				$job['current']++;
			}
			foreach (array_chunk($batchItems, 64) as $batch) {
				$stored = $atlas->addMany((string)$job['staging'], $batch);
				if ($stored !== count($batch)) {
					$detail = method_exists($atlas, 'lastError') ? $atlas->lastError() : '';
					throw new \RuntimeException($detail !== '' ? $detail : 'Embedding batch failed');
				}
				$job['chunks'] += $stored;
			}
			$job['pages'] += $batchPages;

			if ((int)$job['current'] >= $total) {
				$summary = $this->reindexReady($atlas, (string)$job['collection'], (string)$job['staging'], [
					'pages' => (int)$job['pages'],
					'chunks' => (int)$job['chunks'],
					'unchanged' => (int)($job['unchanged'] ?? 0),
				]);
				if ($this->hasAtlasError($atlas)) {
					$detail = method_exists($atlas, 'lastError') ? $atlas->lastError() : '';
					throw new \RuntimeException($detail !== '' ? $detail : 'Reindex integration failed');
				}
				if (is_array($summary)) {
					$job['pages'] = max(0, (int)($summary['pages'] ?? $job['pages']));
					$job['chunks'] = max(0, (int)($summary['chunks'] ?? $job['chunks']));
				}
				if (!$atlas->replaceCollection((string)$job['collection'], (string)$job['staging'])) {
					$detail = method_exists($atlas, 'lastError') ? $atlas->lastError() : '';
					throw new \RuntimeException($detail !== '' ? $detail : 'Collection promotion failed');
				}
				if (method_exists($atlas, 'stats')) {
					$stats = $atlas->stats((string)$job['collection']);
					if (is_array($stats) && trim((string)($stats['models'] ?? '')) !== '') $job['model'] = trim((string)$stats['models']);
				}
				$this->commitPageIndexMeta($job);
				try {
					$this->collectionReindexed((string)$job['collection'], [
						'pages' => (int)$job['pages'],
						'chunks' => (int)$job['chunks'],
						'unchanged' => (int)($job['unchanged'] ?? 0),
						'model' => (string)($job['model'] ?? ''),
					]);
				} catch (\Throwable $e) {
					$this->wire('log')->error('atlas collectionReindexed hook: ' . $e->getMessage());
				}
				$session->remove('AtlasReindexJob');
				$this->message(sprintf($this->_('Reindexed %1$d pages → %2$d chunks into “%3$s”.'), $job['pages'], $job['chunks'], $this->wire('sanitizer')->entities((string)$job['collection'])));
				return ['done' => true, 'percent' => 100, 'current' => $total, 'total' => $total, 'label' => sprintf($this->_('Processed %1$d of %2$d pages'), $total, $total)];
			}

			$session->set('AtlasReindexJob', $job);
			$percent = $total > 0 ? (int)floor((int)$job['current'] / $total * 100) : 100;
			return ['done' => false, 'percent' => $percent, 'current' => (int)$job['current'], 'total' => $total, 'label' => sprintf($this->_('Processed %1$d of %2$d pages'), $job['current'], $total)];
		} catch (\Throwable $e) {
			$atlas->clearCollection((string)($job['staging'] ?? ''));
			$session->remove('AtlasReindexJob');
			$this->wire('log')->error('atlas reindex: ' . $e->getMessage());
			$this->error($this->_('Reindex failed; the previous collection was left unchanged.'));
			return ['done' => true, 'error' => $this->_('Reindex failed. The previous collection was left unchanged.'), 'detail' => $e->getMessage()];
		}
	}

	/**
	 * Hookable page-to-document adapter used by admin reindex.
	 * Hooks may replace `text`, extend `meta`, or set `skip` to true.
	 */
	public function ___buildPageDocument(Page $page, string $collection): array {
		return [
			'text' => $this->pageText($page),
			'meta' => $this->pageDocumentMeta($page),
			'skip' => false,
		];
	}

	/**
	 * Hookable extraction for one ProcessWire field.
	 * Return an empty string to exclude the field from the default document.
	 */
	public function ___buildPageFieldText(Page $page, $field): string {
		$name = trim((string)($field->name ?? ''));
		if ($name === '' || $name === 'title' || $name === 'repeater_matrix_type' || $this->isPresentationField($name)) return '';

		$type = $this->pageFieldTypeName($field);
		$supported = [
			'FieldtypeTable', 'FieldtypeCombo', 'FieldtypeRepeater', 'FieldtypeRepeaterMatrix',
			'FieldtypeTextareas', 'FieldtypeMultiplier',
		];
		if (!in_array($type, $supported, true) && !preg_match('/Fieldtype(?:Text(?:area)?(?:Language)?|Integer|Float|Decimal|Date(?:time)?|Options|Page(?:IDs|Table)?|Checkbox|Toggle|URL|Email|Image|File)(?:$|\\b)/', $type)) return '';

		try { $value = $page->get($name); }
		catch (\Throwable $e) { return ''; }
		if ($type === 'FieldtypeCheckbox' || $type === 'FieldtypeToggle') $text = (bool)$value ? 'true' : 'false';
		elseif (($type === 'FieldtypeDate' || $type === 'FieldtypeDatetime') && is_numeric($value) && (int)$value > 0) $text = date('c', (int)$value);
		elseif ($type === 'FieldtypeTable') $text = $this->proFieldsTableText($value, $field);
		elseif ($type === 'FieldtypeCombo') $text = $this->proFieldsComboText($value);
		elseif ($type === 'FieldtypeTextareas') $text = $this->proFieldsTextareasText($value, $field);
		elseif ($type === 'FieldtypeRepeater' || $type === 'FieldtypeRepeaterMatrix') $text = $this->repeaterFieldText($value, $field, $type === 'FieldtypeRepeaterMatrix');
		else $text = $this->pageFieldValueText($value);
		if ($text === '') return '';

		$label = trim((string)($field->label ?? '')) ?: $name;
		$composite = in_array($type, [
			'FieldtypeTable', 'FieldtypeCombo', 'FieldtypeTextareas',
			'FieldtypeRepeater', 'FieldtypeRepeaterMatrix',
		], true);
		return $this->labelledPageValue($label, $text, $composite);
	}

	/**
	 * Hookable final staging step. Integrations may add their own documents to
	 * `$staging` through `$atlas` and return adjusted summary counts.
	 */
	public function ___reindexReady($atlas, string $collection, string $staging, array $summary): array {
		return $summary;
	}

	/** Hookable notification emitted after one page's index metadata is saved. */
	public function ___pageIndexed(Page $page, string $collection, array $state): void {}

	/** Hookable notification emitted after the collection is promoted successfully. */
	public function ___collectionReindexed(string $collection, array $summary): void {}

	/** Stable signature for hook-provided text and metadata. */
	protected function pageDocumentHash(string $text, array $meta): string {
		$normalize = function($value) use (&$normalize) {
			if (!is_array($value)) return $value;
			$isList = $value === [] || array_keys($value) === range(0, count($value) - 1);
			if (!$isList) ksort($value, SORT_STRING);
			foreach ($value as $key => $item) $value[$key] = $normalize($item);
			return $value;
		};
		$json = json_encode($normalize($meta), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
		return hash('sha256', $text . "\0" . (is_string($json) ? $json : ''));
	}

	/** Read Atlas' collection-specific state without exposing other page metadata. */
	protected function pageIndexState(Page $page, string $collection): array {
		try {
			$all = $page->meta(self::PAGE_META_KEY);
			return is_array($all) && is_array($all[$collection] ?? null) ? $all[$collection] : [];
		} catch (\Throwable $e) {
			return [];
		}
	}

	/** Persist page state only after staging has been promoted successfully. */
	protected function commitPageIndexMeta(array $job): void {
		$collection = (string)($job['collection'] ?? '');
		$identity = (string)($job['identity'] ?? '');
		$model = (string)($job['model'] ?? '');
		$states = (array)($job['page_states'] ?? []);
		$indexedAt = time();
		foreach ((array)($job['all_page_ids'] ?? array_keys($states)) as $id) {
			$page = $this->wire('pages')->get((int)$id);
			$state = $states[(string)(int)$id] ?? null;
			if (!$page->id || !is_array($state)) continue;
			try {
				$all = $page->meta(self::PAGE_META_KEY);
				if (!is_array($all)) $all = [];
				$all[$collection] = [
					'content_hash' => (string)($state['content_hash'] ?? ''),
					'document_hash' => (string)($state['document_hash'] ?? ''),
					'identity' => $identity,
					'model' => $model,
					'chunks' => (int)($state['chunks'] ?? 0),
					'indexed_at' => $indexedAt,
				];
				$page->meta(self::PAGE_META_KEY, $all);
				$this->pageIndexed($page, $collection, $all[$collection]);
			} catch (\Throwable $e) {
				$this->wire('log')->error('atlas page meta: ' . $e->getMessage());
			}
		}
		foreach ((array)($job['removed_page_ids'] ?? []) as $id) {
			$page = $this->wire('pages')->get((int)$id);
			if (!$page->id) continue;
			try {
				$all = $page->meta(self::PAGE_META_KEY);
				if (!is_array($all) || !array_key_exists($collection, $all)) continue;
				unset($all[$collection]);
				$page->meta(self::PAGE_META_KEY, $all);
			} catch (\Throwable $e) {
				$this->wire('log')->error('atlas page meta cleanup: ' . $e->getMessage());
			}
		}
	}

	protected function sendReindexBatch($atlas): void {
		$this->sendJson($this->processReindexBatch($atlas, (string)$this->wire('input')->post('job_token')));
	}

	protected function sendJson(array $response, int $status = 200): void {
		http_response_code($status);
		header('Content-Type: application/json; charset=utf-8');
		$json = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
		if (!is_string($json)) {
			http_response_code(500);
			$json = '{"done":true,"error":"Could not encode the server response."}';
		}
		echo $json;
		exit;
	}

	protected function cancelReindex($atlas, ?string $token = null): bool {
		$session = $this->wire('session');
		$job = $session->get('AtlasReindexJob');
		if ($token !== null && is_array($job) && !hash_equals((string)($job['token'] ?? ''), $token)) return false;
		if (is_array($job) && !empty($job['staging'])) $atlas->clearCollection((string)$job['staging']);
		$session->remove('AtlasReindexJob');
		return true;
	}

	/** Default metadata that is useful for filtering across any ProcessWire site. */
	protected function pageDocumentMeta(Page $page): array {
		$meta = [];
		$template = trim((string)($page->template->name ?? ''));
		$name = trim((string)($page->name ?? ''));
		$path = trim((string)($page->path ?? ''));
		$parentId = (int)($page->parent_id ?? 0);
		if ($template !== '') $meta['template'] = $template;
		if ($name !== '') $meta['name'] = $name;
		if ($path !== '') $meta['path'] = $path;
		if ($parentId > 0) $meta['parent_id'] = $parentId;
		return $meta;
	}

	/** Keep presentation-only configuration out of semantic documents. */
	protected function isPresentationField(string $name): bool {
		return preg_match('/(^|_)(icon|icon_class|css|css_class|class|style)(_|$)/i', $name) === 1;
	}

	/** Resolve a fieldtype name without requiring an optional field module class. */
	protected function pageFieldTypeName($field): string {
		try {
			if (isset($field->type) && is_object($field->type) && method_exists($field->type, 'className')) {
				return (string)$field->type->className();
			}
		} catch (\Throwable $e) {}
		$type = (string)($field->type ?? '');
		$separator = strrpos($type, '\\');
		return $separator === false ? $type : substr($type, $separator + 1);
	}

	/** Format nested values as readable labelled blocks while retaining plain text. */
	protected function labelledPageValue(string $label, string $text, bool $block = false): string {
		$label = trim($label);
		$text = trim($text);
		if ($label === '' || $text === '') return '';
		if (!$block && strpos($text, "\n") === false) return $label . ': ' . $text;
		return $label . ":\n  " . str_replace("\n", "\n  ", $text);
	}

	/** ProFields Table: preserve official column labels and row values. */
	protected function proFieldsTableText($value, $field): string {
		if (!is_iterable($value)) return '';
		try { $columns = method_exists($value, 'getColumns') ? $value->getColumns() : []; }
		catch (\Throwable $e) { $columns = []; }
		if (!$columns) return $this->pageFieldValueText($value);

		$rows = [];
		foreach ($value as $row) {
			$cells = [];
			foreach ($columns as $column) {
				$name = trim((string)($column['name'] ?? ''));
				if ($name === '' || $this->isPresentationField($name)) continue;
				try { $cell = is_object($row) && method_exists($row, 'get') ? $row->get($name) : ($row->{$name} ?? null); }
				catch (\Throwable $e) { $cell = null; }
				$text = $this->pageFieldValueText($cell);
				if ($text === '') continue;
				$label = trim((string)($column['label'] ?? '')) ?: $name;
				$cells[] = $this->labelledPageValue($label, $text);
			}
			if ($cells) $rows[] = implode("\n", $cells);
			if (count($rows) >= 50) break;
		}
		return implode("\n\n", $rows);
	}

	/** ProFields Combo: use its public subfield API and retain subfield labels. */
	protected function proFieldsComboText($value): string {
		if (!is_object($value) || !method_exists($value, 'getSubfields') || !method_exists($value, 'get')) return '';
		try { $subfields = $value->getSubfields(); }
		catch (\Throwable $e) { return ''; }
		if (!is_iterable($subfields)) return '';
		$parts = [];
		foreach ($subfields as $name => $subfield) {
			if ($this->isPresentationField((string)$name)) continue;
			try { $item = $value->get($name); }
			catch (\Throwable $e) { continue; }
			if (is_scalar($item)) $item = $this->proFieldsOptionLabel($subfield, (string)$item);
			elseif (is_array($item)) {
				foreach ($item as $key => $option) $item[$key] = $this->proFieldsOptionLabel($subfield, (string)$option);
			}
			$text = $this->pageFieldValueText($item);
			if ($text === '') continue;
			try { $label = method_exists($subfield, 'getLabel') ? (string)$subfield->getLabel() : ''; }
			catch (\Throwable $e) { $label = ''; }
			$parts[] = $this->labelledPageValue($label !== '' ? $label : (string)$name, $text);
			if (count($parts) >= 50) break;
		}
		return implode("\n", $parts);
	}

	/** Resolve Combo select/radio option values using the same public config as Collections. */
	protected function proFieldsOptionLabel($subfield, string $raw): string {
		try { $options = is_object($subfield) && method_exists($subfield, 'get') ? $subfield->get('options') : null; }
		catch (\Throwable $e) { return $raw; }
		if (is_string($options)) {
			foreach (preg_split('/\\R/u', $options) as $line) {
				$line = trim($line);
				if ($line === '') continue;
				[$value, $label] = strpos($line, '=') !== false ? array_map('trim', explode('=', $line, 2)) : [$line, $line];
				if ($value === $raw) return $label;
			}
		} elseif (is_array($options)) {
			foreach ($options as $value => $label) {
				if ((is_int($value) ? (string)$label : (string)$value) === $raw) return (string)$label;
			}
		}
		return $raw;
	}

	/** ProFields Textareas: enumerate configured subfields through the fieldtype API. */
	protected function proFieldsTextareasText($value, $field): string {
		try { $subfields = $field->type->getTextareaFields($field); }
		catch (\Throwable $e) { return ''; }
		$parts = [];
		foreach ((array)$subfields as $subfield) {
			$name = trim((string)($subfield['name'] ?? ''));
			if ($name === '' || $this->isPresentationField($name)) continue;
			try { $item = is_object($value) && method_exists($value, 'get') ? $value->get($name) : null; }
			catch (\Throwable $e) { continue; }
			$text = $this->pageFieldValueText($item);
			if ($text === '') continue;
			$label = trim((string)($subfield['label'] ?? '')) ?: $name;
			$parts[] = $this->labelledPageValue($label, $text);
		}
		return implode("\n", $parts);
	}

	/** Repeater and Repeater Matrix: index nested semantic fields, not item IDs. */
	protected function repeaterFieldText($value, $field, bool $matrix): string {
		if (!is_iterable($value) || $this->pageFieldExtractionDepth >= 3) return '';
		$this->pageFieldExtractionDepth++;
		$items = [];
		try {
			foreach ($value as $item) {
				if (!$item instanceof Page) continue;
				$parts = [];
				if ($matrix) {
					$type = $this->repeaterMatrixTypeLabel($item, $field);
					if ($type !== '') $parts[] = $type;
				}
				foreach ($item->template->fieldgroup as $nestedField) {
					$text = trim($this->buildPageFieldText($item, $nestedField));
					if ($text !== '') $parts[] = $text;
				}
				if ($parts) $items[] = implode("\n", $parts);
				if (count($items) >= 50) break;
			}
		} finally {
			$this->pageFieldExtractionDepth--;
		}
		return implode("\n\n", $items);
	}

	/** Resolve a stable human-readable Repeater Matrix type without raw config coupling. */
	protected function repeaterMatrixTypeLabel(Page $item, $field): string {
		try {
			$type = trim((string)$item->matrix('name'));
			$n = (int)$item->matrix('n');
			if ($n > 0 && method_exists($field, 'get')) {
				$label = trim((string)$field->get("matrix{$n}_label"));
				if ($label !== '') return $label;
			}
			return $type;
		} catch (\Throwable $e) {
			try {
				$n = (int)$item->getUnformatted('repeater_matrix_type');
				if ($n <= 0 || !method_exists($field, 'get')) return '';
				$label = trim((string)$field->get("matrix{$n}_label"));
				return $label !== '' ? $label : trim((string)$field->get("matrix{$n}_name"));
			} catch (\Throwable $ignored) { return ''; }
		}
	}

	/** Convert common scalar, option and page-reference values to useful text. */
	protected function pageFieldValueText($value): string {
		if ($value === null) return '';
		if (is_bool($value)) return $value ? 'true' : 'false';
		if (is_scalar($value)) return $this->cleanPageText((string)$value);
		if ($value instanceof Page) return $this->cleanPageText((string)$value->title);
		$class = is_object($value) ? (new \ReflectionClass($value))->getShortName() : '';
		if (preg_match('/^Page(?:image|file)$/i', $class)) return $this->pageMediaUrl($value);
		if (preg_match('/^Page(?:images|files)$/i', $class)) {
			$urls = [];
			foreach ($value as $media) {
				$url = $this->pageMediaUrl($media);
				if ($url !== '' && !in_array($url, $urls, true)) $urls[] = $url;
				if (count($urls) >= 20) break;
			}
			return implode("\n", $urls);
		}
		if (preg_match('/^SelectableOption$/i', $class)) {
			try { return $this->cleanPageText((string)($value->title ?? $value->value ?? '')); }
			catch (\Throwable $e) { return ''; }
		}

		if (is_array($value)) return $this->pageArrayValueText($value, true);
		if (is_object($value) && preg_match('/(?:SelectableOption|Page)Array$/i', $class) && is_iterable($value)) {
			$parts = [];
			foreach ($value as $item) {
				$text = $this->pageFieldValueText($item);
				if ($text !== '' && !in_array($text, $parts, true)) $parts[] = $text;
				if (count($parts) >= 50) break;
			}
			return implode(', ', $parts);
		}

		if (is_object($value)) {
			foreach (['title', 'value', 'name'] as $property) {
				try { $candidate = $value->{$property} ?? null; }
				catch (\Throwable $e) { $candidate = null; }
				if (is_scalar($candidate)) {
					$text = $this->cleanPageText((string)$candidate);
					if ($text !== '') return $text;
				}
			}
			if (method_exists($value, '__toString')) return $this->cleanPageText((string)$value);
		}

		return '';
	}

	/** Preserve useful associative keys while keeping positional arrays concise. */
	protected function pageArrayValueText(array $values, bool $labels): string {
		$parts = [];
		$associative = $values !== [] && array_keys($values) !== range(0, count($values) - 1);
		foreach ($values as $key => $item) {
			$text = $this->pageFieldValueText($item);
			if ($text === '') continue;
			if ($labels && !is_int($key)) {
				$label = $this->cleanPageText(str_replace(['_', '-'], ' ', (string)$key));
				if ($label !== '') $text = $this->labelledPageValue($label, $text);
			}
			if (!in_array($text, $parts, true)) $parts[] = $text;
			if (count($parts) >= 50) break;
		}
		return implode($associative ? "\n" : ', ', $parts);
	}

	/** Keep a useful absolute media URL without leaking file object internals. */
	protected function pageMediaUrl($media): string {
		try {
			$url = trim((string)($media->httpUrl ?? ''));
			if ($url === '' && method_exists($media, 'httpUrl')) $url = trim((string)$media->httpUrl());
			if ($url === '') $url = trim((string)($media->url ?? ''));
		} catch (\Throwable $e) { return ''; }
		if ($url === '') return '';
		if (preg_match('#^https?://#i', $url)) return $this->cleanPageText($url);

		try {
			$config = $this->wire('config');
			$root = trim((string)($config->urls->httpRoot ?? ''));
			if ($root !== '') return rtrim($root, '/') . '/' . ltrim($url, '/');
			$scheme = !empty($config->https) ? 'https://' : 'http://';
			$host = trim((string)($config->httpHost ?? ''));
			if ($host !== '') return $scheme . $host . '/' . ltrim($url, '/');
		} catch (\Throwable $e) {}
		return $this->cleanPageText($url);
	}

	/** Plain text suitable for embedding, with HTML entities decoded. */
	protected function cleanPageText(string $value): string {
		$value = preg_replace('/<[^>]+>/', ' ', $value);
		$value = html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		return trim((string)preg_replace('/\\s+/u', ' ', $value));
	}

	/** Parent titles add semantic context when a page has no descriptive fields. */
	protected function pageHierarchyText(Page $page): string {
		try { $parents = $page->parents(); }
		catch (\Throwable $e) { return ''; }
		if (!is_iterable($parents)) return '';

		$parts = [];
		foreach ($parents as $parent) {
			if (!$parent instanceof Page || (int)$parent->id <= 1) continue;
			$title = $this->cleanPageText((string)$parent->title);
			if ($title !== '') $parts[] = $title;
		}
		$title = $this->cleanPageText((string)$page->title);
		if ($title !== '') $parts[] = $title;
		return count($parts) > 1 ? implode(' / ', $parts) : '';
	}

	/** Page title + labelled semantic field values, capped. */
	protected function pageText(Page $p): string {
		$parts = [$this->cleanPageText((string)$p->title)];
		foreach ($p->template->fieldgroup as $f) {
			$value = trim($this->buildPageFieldText($p, $f));
			if ($value !== '') $parts[] = $value;
		}
		$currentText = trim(implode("\n", array_filter($parts)));
		if (mb_strlen($currentText) < 80) {
			$hierarchy = $this->pageHierarchyText($p);
			if ($hierarchy !== '') $parts[] = $hierarchy;
		}
		return mb_substr(trim(implode("\n", array_filter($parts))), 0, 20000);
	}
}
