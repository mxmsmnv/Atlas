<?php namespace ProcessWire;

/** Internal ProcessAtlas concern; composed by the ProcessWire module entry point. */
trait ProcessAtlasSearch {
	protected function renderSearch($atlas, $input, $san): string {
		$cols = $atlas->collections();
		if ($this->hasAtlasError($atlas)) return '<section class="AtlasSection"><div class="AtlasSection-head"><div><p>' . $this->_('Retrieve the most relevant knowledge by meaning.') . '</p></div></div>' . $this->dataError($atlas) . '</section>';
		if (!$cols) return '<section class="AtlasSection"><div class="AtlasSection-head"><div><p>' . $this->_('Retrieve the most relevant knowledge by meaning.') . '</p></div></div>'
			. $this->emptyState('search', $this->_('Nothing to search yet'), $this->_('Build or add a collection before searching.'), './?section=reindex', $this->_('Build knowledge base')) . '</section>';

		$collection = $san->text((string) $input->get('collection_q'));
		if ($collection !== '' && !in_array($collection, array_column($cols, 'collection'), true)) $collection = '';
		$query      = mb_substr(trim((string) $input->get('q')), 0, 2000);
		$keyword    = mb_substr(trim((string) $input->get('keyword')), 0, 200);
		$keywordRequire = $keyword !== '' && !empty($input->get('keyword_require'));
		$mmr        = $query === '' ? true : !empty($input->get('mmr'));
		$topK       = max(1, min(50, (int) $input->get('topk') ?: 5));

		/** @var InputfieldForm $form */
		$form = $this->wire('modules')->get('InputfieldForm');
		$form->attr('method', 'get'); $form->attr('action', './');

		$set = $this->wire('modules')->get('InputfieldFieldset');
		$set->label = $this->_('Search knowledge'); $set->icon = 'search';

		$hidden = $this->wire('modules')->get('InputfieldHidden');
		$hidden->attr('name', 'section'); $hidden->attr('value', 'search');
		$set->add($hidden);

		$sel = $this->wire('modules')->get('InputfieldSelect');
		$sel->attr('name', 'collection_q'); $sel->label = $this->_('Collection'); $sel->columnWidth = 25; $sel->required = true;
		foreach ($cols as $c) $sel->addOption($c['collection'], $c['collection'] . " ({$c['count']})");
		if ($collection !== '') $sel->attr('value', $collection);
		$set->add($sel);

		$f = $this->wire('modules')->get('InputfieldText');
		$f->attr('name', 'q'); $f->attr('value', $query); $f->attr('maxlength', 2000);
		$f->label = $this->_('Semantic query'); $f->columnWidth = 40; $f->required = true;
		$set->add($f);

		$f = $this->wire('modules')->get('InputfieldText');
		$f->attr('name', 'keyword'); $f->attr('value', $keyword); $f->attr('maxlength', 200); $f->attr('data-atlas-keyword', '');
		$f->label = $this->_('Keyword boost'); $f->columnWidth = 20;
		$f->notes = $this->_('Optional — literal match ranks higher.');
		$set->add($f);

		$f = $this->wire('modules')->get('InputfieldInteger');
		$f->attr('name', 'topk'); $f->attr('value', $topK);
		$f->label = $this->_('Top K'); $f->columnWidth = 15; $f->attr('min', 1); $f->attr('max', 50);
		$set->add($f);

		$f = $this->wire('modules')->get('InputfieldCheckbox');
		$f->attr('name', 'mmr'); $f->attr('value', 1); $f->label = $this->_('Diversify results (MMR)'); $f->columnWidth = 50;
		$f->attr('checked', $mmr ? 'checked' : '');
		$f->notes = $this->_('Avoid filling the result set with near-duplicate chunks.');
		$set->add($f);

		$f = $this->wire('modules')->get('InputfieldCheckbox');
		$f->attr('name', 'keyword_require'); $f->attr('value', 1); $f->attr('data-atlas-keyword-require', ''); $f->label = $this->_('Require keyword match'); $f->columnWidth = 50;
		$f->attr('checked', $keywordRequire ? 'checked' : '');
		$f->notes = $this->_('Exclude results that do not contain the literal keyword.');
		$set->add($f);

		$s = $this->wire('modules')->get('InputfieldSubmit');
		$s->attr('value', $this->_('Search')); $s->icon = 'search';
		$set->add($s);

		$form->add($set);
		$out = '<section class="AtlasSection"><div class="AtlasSection-head pw-module-head"><div><p class="uk-text-muted">' . $this->_('Retrieve the most relevant knowledge by meaning, then refine it with an optional keyword.') . '</p></div></div><div class="AtlasPanel AtlasSearchPanel pw-wrap">' . $form->render() . '</div>';

		if ($query !== '' && $collection !== '') {
			$out .= $this->renderResults($atlas, $collection, $query, $keyword, $keywordRequire, $mmr, $topK, $san);
		}
		return $out . '</section>';
	}

	protected function renderResults($atlas, string $collection, string $query, string $keyword, bool $keywordRequire, bool $mmr, int $topK, $san): string {
		$opts = ['mmr' => $mmr];
		if ($keyword !== '') { $opts['keyword'] = $keyword; $opts['keywordRequire'] = $keywordRequire; }
		$hits = $atlas->search($collection, $query, $topK, $opts);
		if (!$hits && method_exists($atlas, 'lastError') && $atlas->lastError() !== '') {
			$detail = $san->entities($atlas->lastError());
			return '<div class="AtlasSearchError uk-alert uk-alert-danger" role="alert"><span class="AtlasSearchError-icon">' . wireIconMarkup('exclamation-triangle') . '</span><div><h3>' . $this->_('Search is temporarily unavailable') . '</h3><p>' . $this->_('Atlas could not complete this search. Check the embedding provider and database connection, then try again.') . '</p><details><summary>' . $this->_('Technical details') . '</summary><code>' . $detail . '</code></details></div></div>';
		}
		if (!$hits) return $this->emptyState('search', $this->_('No matching knowledge'), $this->_('Try a broader semantic query, remove the keyword, or search another collection.'));

		$csrf  = $this->wire('session')->CSRF->renderInput();
		$table = $this->wire('modules')->get('MarkupAdminDataTable');
		$table->setEncodeEntities(false);
		$table->headerRow(['#', $this->_('Score'), $this->_('Ref'), $this->_('Text'), '']);
		foreach ($hits as $i => $hit) {
			$ref = $san->entities($hit['ref']);
			$txt = $san->entities(mb_substr((string) $hit['text'], 0, 180)) . $this->renderMeta((array)($hit['meta'] ?? []), $san);
			$bar = '<span class="AtlasScore">' . number_format((float) $hit['score'], 3) . '</span>';
			$del = '<form method="post" action="./" class="AtlasInlineForm" data-confirm="' . $san->entities($this->_('Delete this entry? This cannot be undone.')) . '">' . $csrf
				. '<input type="hidden" name="action" value="delete"><input type="hidden" name="collection" value="' . $san->entities($collection) . '">'
				. '<input type="hidden" name="ref" value="' . $ref . '"><input type="hidden" name="return_section" value="search">'
				. '<input type="hidden" name="return_collection" value="' . $san->entities($collection) . '"><input type="hidden" name="return_q" value="' . $san->entities($query) . '">'
				. '<input type="hidden" name="return_keyword" value="' . $san->entities($keyword) . '"><input type="hidden" name="return_keyword_require" value="' . (int)$keywordRequire . '">'
				. '<input type="hidden" name="return_mmr" value="' . (int)$mmr . '"><input type="hidden" name="return_topk" value="' . $topK . '">'
				. '<button type="submit" class="uk-button uk-button-default uk-button-small" title="' . $this->_('Delete') . '">' . wireIconMarkup('times') . '</button>'
				. '</form>';
			$table->row([(string) ($i + 1), $bar, $this->renderRef($hit['ref'], $san), $txt, $del]);
		}
		$count = count($hits);
		$mode = $mmr ? $this->_n('diversified match', 'diversified matches', $count) : $this->_n('match by score', 'matches by score', $count);
		return '<div class="AtlasResults"><div class="AtlasPanel-head"><div><h3 class="uk-h4">' . sprintf($this->_('Results for “%s”'), $san->entities($query)) . '</h3><p class="uk-text-muted">' . sprintf($this->_('%1$d %2$s'), $count, $mode) . '</p></div></div><div class="AtlasTable pw-table-panel uk-overflow-auto">' . $table->render() . '</div></div>';
	}

}
