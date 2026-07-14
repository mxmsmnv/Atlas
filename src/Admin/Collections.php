<?php namespace ProcessWire;

/** Internal ProcessAtlas concern; composed by the ProcessWire module entry point. */
trait ProcessAtlasCollections {
	/** Embed and store one entry from the admin (creates the collection if new). */
	protected function doAddEntry($atlas, $input, $san): bool {
		$coll = $san->text((string) $input->post('collection'));
		$text = trim($san->textarea((string) $input->post('text')));
		$ref  = $san->text((string) $input->post('ref'));
		$title = mb_substr($san->text((string) $input->post('title')), 0, 255);
		$this->wire('session')->set(self::ADD_DRAFT_KEY, ['collection' => $coll, 'ref' => $ref, 'title' => $title, 'text' => $text]);

		if (!$atlas->isReady()) { $this->error($this->_('Add needs an embedding provider configured in Squad.')); return false; }
		if ($coll === '' || $text === '') { $this->error($this->_('Collection and text are required.')); return false; }
		if (mb_strlen($coll) > 128) { $this->error($this->_('Collection names are limited to 128 characters.')); return false; }
		if ($this->isReservedCollection($coll)) { $this->error($this->_('Collection names beginning with “__atlas_stage_” are reserved for internal reindexing.')); return false; }
		if (mb_strlen($text) > self::MAX_MANUAL_TEXT) { $this->error(sprintf($this->_('Text is limited to %s characters.'), number_format(self::MAX_MANUAL_TEXT))); return false; }
		if ($ref === '') $ref = 'entry-' . substr(md5($text . microtime()), 0, 8);
		if (mb_strlen($ref) > 180) { $this->error($this->_('Refs are limited to 180 characters when entries may be chunked.')); return false; }
		$meta = [];
		if ($title !== '') $meta['title'] = $title;

		// long text is chunked (ref#0, ref#1, …); short text stored as one entry
		$n = mb_strlen($text) > 1500
			? $atlas->addChunked($coll, $ref, $text, $meta)
			: ((int) $atlas->add($coll, $ref, $text, $meta));
		if ($n > 0) {
			$this->wire('session')->remove(self::ADD_DRAFT_KEY);
			$this->message(sprintf($this->_n('Added %1$d entry to “%2$s”.', 'Added %1$d entries to “%2$s”.', $n), $n, $san->entities($coll)));
			return true;
		}
		if (!method_exists($atlas, 'lastError') || $atlas->lastError() === '') $this->error($this->_('Could not add the entry. Check the embedding provider and database connection.'));
		return false;
	}

	protected function renderAddEntry($atlas, string $defaultCollection = '', bool $expanded = false): string {
		if (!$atlas->isReady()) return $this->emptyState('plug', $this->_('Embedding provider required'), $this->_('Configure an embedding provider in Squad before adding entries.'));
		$session = $this->wire('session');
		$draft = $session->get(self::ADD_DRAFT_KEY);
		if (!is_array($draft)) $draft = [];
		else { $expanded = true; $session->remove(self::ADD_DRAFT_KEY); }
		if (!empty($draft['collection'])) $defaultCollection = (string)$draft['collection'];
		$form = $this->wire('modules')->get('InputfieldForm');
		$form->attr('method', 'post'); $form->attr('action', './');

		$set = $this->wire('modules')->get('InputfieldFieldset');
		$set->label = $this->_('Add entry'); $set->icon = 'plus-circle';
		$set->description = $this->_('Embed and store one entry. Type a new collection name to create it. Long text is split into chunks automatically.');
		$set->collapsed = $expanded ? Inputfield::collapsedNo : Inputfield::collapsedYes;

		$hidden = $this->wire('modules')->get('InputfieldHidden');
		$hidden->attr('name', 'action'); $hidden->attr('value', 'add');
		$set->add($hidden);

		$f = $this->wire('modules')->get('InputfieldText');
		$f->attr('name', 'collection'); $f->label = $this->_('Collection'); $f->required = true; $f->columnWidth = 34;
		$f->attr('maxlength', 128);
		if ($defaultCollection !== '') $f->attr('value', $defaultCollection);
		$f->notes = $this->_('Existing name, or a new one to create it.');
		$set->add($f);

		$f = $this->wire('modules')->get('InputfieldText');
		$f->attr('name', 'ref'); $f->label = $this->_('Ref'); $f->columnWidth = 33;
		$f->attr('maxlength', 180);
		if (isset($draft['ref'])) $f->attr('value', (string)$draft['ref']);
		$f->notes = $this->_('Unique id within the collection. Blank = auto.');
		$set->add($f);

		$f = $this->wire('modules')->get('InputfieldText');
		$f->attr('name', 'title'); $f->label = $this->_('Title (meta, optional)'); $f->columnWidth = 33;
		$f->attr('maxlength', 255);
		if (isset($draft['title'])) $f->attr('value', (string)$draft['title']);
		$set->add($f);

		$f = $this->wire('modules')->get('InputfieldTextarea');
		$f->attr('name', 'text'); $f->attr('maxlength', self::MAX_MANUAL_TEXT); $f->label = $this->_('Text'); $f->rows = 5; $f->required = true;
		if (isset($draft['text'])) $f->attr('value', (string)$draft['text']);
		$f->notes = sprintf($this->_('Up to %s characters. Long text is split into chunks automatically.'), number_format(self::MAX_MANUAL_TEXT));
		$set->add($f);

		$s = $this->wire('modules')->get('InputfieldSubmit');
		$s->attr('value', $this->_('Add entry')); $s->icon = 'plus';
		$set->add($s);

		$form->add($set);
		return '<div id="AtlasAddEntry" class="AtlasPanel AtlasAddPanel pw-wrap">' . $form->render() . '</div>';
	}

	/** Browse a single collection's entries: stats panel + rich, paginated table. */
	protected function renderCollectionView($atlas, string $collection, $input, $san): string {
		if ($this->isReservedCollection($collection)) return '<section class="AtlasSection">' . $this->emptyState('folder-open-o', $this->_('Collection not found'), $this->_('Internal Atlas collections are not available for browsing.'), './?section=collections', $this->_('Back to collections')) . '</section>';
		$stats = $atlas->stats($collection);
		if ($this->hasAtlasError($atlas)) return '<section class="AtlasSection">' . $this->dataError($atlas, $this->_('Collection could not be loaded')) . '</section>';
		$total = $stats['total'];
		if ($total === 0) return '<section class="AtlasSection">' . $this->emptyState('folder-open-o', $this->_('Collection not found'), $this->_('It may have been cleared or removed.'), './?section=collections', $this->_('Back to collections')) . '</section>';
		$limit = 50;
		$start = max(0, (int) $input->get('start'));
		if ($start >= $total) $start = max(0, (int)(floor(($total - 1) / $limit) * $limit));
		$rows  = $atlas->entries($collection, $limit, $start);
		if ($this->hasAtlasError($atlas)) return '<section class="AtlasSection">' . $this->dataError($atlas, $this->_('Collection entries could not be loaded')) . '</section>';
		$cn    = $san->entities($collection);
		$enc   = urlencode($collection);
		$csrf  = $this->wire('session')->CSRF->renderInput();
		$mixedModels = strpos($stats['models'], ',') !== false;
		$healthClass = $mixedModels ? ' is-warning' : '';
		$healthIcon = $mixedModels ? 'exclamation-triangle' : 'check-circle';
		$healthLabel = $mixedModels ? $this->_('Reindex recommended') : $this->_('Ready for retrieval');

		$h = '<div class="AtlasCollectionHero uk-card uk-card-default uk-card-small uk-card-body"><div class="AtlasCollectionHero-main"><span class="AtlasCollectionHero-icon">' . wireIconMarkup('database') . '</span><div><span class="AtlasEyebrow uk-text-meta">' . $this->_('Knowledge collection') . '</span><p class="uk-text-muted">' . $this->_('Browse indexed chunks and source metadata.') . '</p><span class="AtlasHealth' . $healthClass . '">' . wireIconMarkup($healthIcon) . ' ' . $healthLabel . '</span></div></div><div class="AtlasActions pw-module-actions">'
			. '<a class="uk-button uk-button-primary" href="./?section=search&collection_q=' . $enc . '">' . wireIconMarkup('search') . ' ' . $this->_('Search this collection') . '</a>'
			. '<a class="uk-button uk-button-default" href="./?section=collections&add=1&collection=' . $enc . '#AtlasAddEntry">' . wireIconMarkup('plus') . ' ' . $this->_('Add entry') . '</a></div></div>';

		// ── collection overview ──
		$updated = $stats['last_modified'] !== '' ? date('Y-m-d H:i', strtotime($stats['last_modified'])) : '—';
		$primaryMetrics = [
			['file-text-o', $this->_('Entries'),      (string) $total],
			['files-o',     $this->_('Source docs'),  (string) $stats['docs']],
			['clock-o',     $this->_('Last updated'), $updated],
		];
		$primaryCards = '';
		foreach ($primaryMetrics as [$icon, $label, $val]) {
			$primaryCards .= "<div class='AtlasStat'><span class='AtlasStat-icon'>" . wireIconMarkup($icon) . "</span><div><span>{$label}</span><strong>{$val}</strong></div></div>";
		}
		$model = $san->entities($stats['models'] !== '' ? $stats['models'] : '—');
		$dims = $stats['dims'] ? (string)$stats['dims'] : '—';
		$avgChars = $stats['avg_chars'] ? number_format($stats['avg_chars']) : '—';
		$h .= "<div class='AtlasCollectionOverview uk-card uk-card-default uk-card-small uk-card-body'><div class='AtlasStats'>{$primaryCards}</div>"
			. '<dl class="AtlasTechnical"><div><dt>' . $this->_('Embedding model') . '</dt><dd>' . $model . '</dd></div><div><dt>' . $this->_('Dimensions') . '</dt><dd>' . $dims . '</dd></div><div><dt>' . $this->_('Avg. chars') . '</dt><dd>' . $avgChars . '</dd></div></dl></div>';
		if ($mixedModels) $h .= '<div class="AtlasWarning uk-alert uk-alert-warning">' . wireIconMarkup('exclamation-triangle') . ' ' . $this->_('This collection contains more than one embedding model. Reindex it to restore consistent retrieval.') . '</div>';

		$range = sprintf($this->_('Showing %1$d–%2$d of %3$d'), $start + 1, min($start + $limit, $total), $total);
		$entryCount = sprintf($this->_n('%1$d entry', '%1$d entries', $total), $total);
		$h .= '<div class="AtlasEntriesHead"><div><h3>' . $this->_('Entries') . '<span>' . $entryCount . '</span></h3><p class="detail AtlasResultCount">' . $range . '</p></div>'
			. '<label class="AtlasFilter AtlasEntryFilter">' . wireIconMarkup('search') . '<span class="AtlasSrOnly">' . $this->_('Filter entries on this page') . '</span><input class="uk-input" type="search" data-atlas-filter="#AtlasEntryList" data-atlas-filter-empty="#AtlasEntryFilterEmpty" placeholder="' . $this->_('Filter this page…') . '" autocomplete="off"></label></div>';

		// ── readable entry list ──
		$entries = '<div id="AtlasEntryList" class="AtlasEntryList">';
		foreach ($rows as $i => $r) {
			$ref   = $san->entities($r['ref']);
			$title = trim((string)($r['meta']['title'] ?? ''));
			$template = trim((string)($r['meta']['template'] ?? ''));
			$url   = $this->safeUrl((string)($r['meta']['url'] ?? ''));
			$titleText = $title !== '' ? $san->entities($title) : $this->_('Untitled entry');
			$titleCell = $url !== '' ? "<a class='AtlasEntry-titleLink' href='" . $san->entities($url) . "'>{$titleText}" . wireIconMarkup('external-link') . '</a>' : $titleText;
			$text = (string) $r['text'];
			$textLength = mb_strlen($text);
			$full = $san->entities($text);
			$upd = $r['modified'] !== '' ? date('Y-m-d H:i', strtotime((string) $r['modified'])) : '';
			$filterValue = $san->entities($r['ref'] . ' ' . $title . ' ' . $template . ' ' . mb_substr($text, 0, 360));
			$del = '<form method="post" action="./" class="AtlasInlineForm" data-confirm="' . $san->entities($this->_('Delete this entry? This cannot be undone.')) . '">' . $csrf
				. '<input type="hidden" name="action" value="delete"><input type="hidden" name="collection" value="' . $cn . '">'
				. '<input type="hidden" name="ref" value="' . $ref . '"><input type="hidden" name="view" value="' . $cn . '">'
				. '<button type="submit" class="AtlasIconButton AtlasDangerButton" title="' . $this->_('Delete entry') . '" aria-label="' . $this->_('Delete entry') . '">' . wireIconMarkup('trash-o') . '</button></form>';
			$charCount = sprintf($this->_('%d chars'), $textLength);
			$kindLabel = $template !== '' ? $san->entities($template) : $this->_('Entry');
			$shortClass = $textLength < 80 ? ' is-short' : '';
			$quality = $textLength < 80 ? '<span class="AtlasEntry-quality">' . $this->_('Short source') . '</span>' : '';
			$entries .= '<article class="AtlasEntry uk-card uk-card-default uk-card-small uk-card-body' . $shortClass . '" data-atlas-filter-item data-filter-value="' . $filterValue . '"><div class="AtlasEntry-main"><div class="AtlasEntry-heading">'
				. '<span class="AtlasEntry-index">' . (string)($start + $i + 1) . '</span><h4>' . $titleCell . '</h4>'
				. ($upd !== '' ? '<time datetime="' . $san->entities((string)$r['modified']) . '">' . $upd . '</time>' : '') . '</div>'
				. '<div class="AtlasEntry-top"><span class="AtlasEntry-kind">' . $kindLabel . '</span>' . $quality . $this->renderRef($r['ref'], $san) . '</div>'
				. '<div class="AtlasEntry-foot"><details class="AtlasDetails"><summary>' . wireIconMarkup('align-left') . ' ' . $this->_('Content') . ' <span>' . $charCount . '</span></summary>'
				. '<pre>' . $full . '</pre></details>' . $this->renderMeta($r['meta'], $san) . '</div></div><div class="AtlasEntry-actions">' . $del . '</div></article>';
		}
		$entries .= '</div><p id="AtlasEntryFilterEmpty" class="AtlasFilterEmpty" hidden>' . $this->_('No entries on this page match this filter.') . '</p>';
		$nav = [];
		if ($start > 0) $nav[] = "<li><a href='./?section=collections&view={$enc}&start=" . max(0, $start - $limit) . "'>" . wireIconMarkup('angle-left') . ' ' . $this->_('Prev') . '</a></li>';
		if ($start + $limit < $total) $nav[] = "<li><a href='./?section=collections&view={$enc}&start=" . ($start + $limit) . "'>" . $this->_('Next') . ' ' . wireIconMarkup('angle-right') . '</a></li>';
		$pagination = $nav ? '<ul class="AtlasPagination uk-pagination" uk-margin>' . implode('', $nav) . '</ul>' : '';
		return '<section class="AtlasSection">' . $h . $entries . $pagination . '</section>';
	}

	protected function renderCollections($atlas): string {
		$cols = $atlas->collections();
		$out  = '<section class="AtlasSection"><div class="AtlasSection-head pw-module-head"><div><p class="uk-text-muted">' . $this->_('Browse and maintain independent knowledge namespaces.') . '</p></div><div class="AtlasActions pw-module-actions"><a class="uk-button uk-button-default" href="./?section=reindex">' . wireIconMarkup('refresh') . ' ' . $this->_('Build from pages') . '</a><a class="uk-button uk-button-primary" href="./?section=collections&add=1#AtlasAddEntry">' . wireIconMarkup('plus') . ' ' . $this->_('Add entry') . '</a></div></div>';
		if ($this->hasAtlasError($atlas)) return $out . $this->dataError($atlas) . '</section>';
		if (!$cols) return $out . $this->emptyState('folder-open-o', $this->_('No collections yet'), $this->_('Build a site knowledge base or add your first entry below.'), './?section=reindex', $this->_('Build knowledge base')) . '</section>';
		$totalEntries = array_sum(array_column($cols, 'count'));
		$collectionCount = count($cols);
		$summary = sprintf($this->_n('%1$d collection', '%1$d collections', $collectionCount), $collectionCount) . ' · '
			. sprintf($this->_n('%1$d entry', '%1$d entries', $totalEntries), $totalEntries);
		$out .= '<div class="AtlasCollectionToolbar"><p class="uk-text-muted">' . $summary . '</p><label class="AtlasFilter">' . wireIconMarkup('search') . '<span class="AtlasSrOnly">' . $this->_('Filter collections') . '</span><input class="uk-input" type="search" data-atlas-filter="#AtlasCollectionGrid" data-atlas-filter-empty="#AtlasCollectionFilterEmpty" placeholder="' . $this->_('Filter collections…') . '" autocomplete="off"></label></div>';

		$san  = $this->wire('sanitizer');
		$csrf = $this->wire('session')->CSRF->renderInput();
		$cards = '<div id="AtlasCollectionGrid" class="AtlasCollectionGrid">';
		foreach ($cols as $c) {
			$name = $san->entities($c['collection']);
			$count = (int)$c['count'];
			$countLabel = sprintf($this->_n('%1$d entry', '%1$d entries', $count), $count);
			$viewUrl = './?section=collections&view=' . urlencode($c['collection']);
			$search = "<a class='uk-button uk-button-default' href='./?section=search&collection_q=" . urlencode($c['collection']) . "'>" . wireIconMarkup('search') . ' ' . $this->_('Search') . '</a>';
			$clear = '<form method="post" action="./" data-confirm="' . $san->entities($this->_('Clear this collection? This cannot be undone.')) . '" class="AtlasInlineForm">'
				. $csrf
				. '<input type="hidden" name="action" value="clear"><input type="hidden" name="collection" value="' . $name . '">'
				. '<button type="submit" class="AtlasIconButton AtlasDangerButton" title="' . $this->_('Clear collection') . '" aria-label="' . $this->_('Clear collection') . '">' . wireIconMarkup('trash-o') . '</button>'
				. '</form>';
			$cards .= '<article class="AtlasCollectionCard uk-card uk-card-default" data-atlas-filter-item data-filter-value="' . $name . '"><a class="AtlasCollectionMain" href="' . $viewUrl . '"><span class="AtlasCollectionIcon">' . wireIconMarkup('folder-open') . '</span><span><strong><code>' . $name . '</code></strong><small class="uk-text-meta">' . $countLabel . '</small></span>' . wireIconMarkup('angle-right') . '</a><div class="AtlasCollectionActions">' . $search . $clear . '</div></article>';
		}
		$cards .= '</div>';
		return $out . $cards . '<p id="AtlasCollectionFilterEmpty" class="AtlasFilterEmpty AtlasPanel pw-wrap" hidden>' . $this->_('No collections match this filter.') . '</p></section>';
	}

}
