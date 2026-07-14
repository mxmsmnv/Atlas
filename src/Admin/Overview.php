<?php namespace ProcessWire;

/** Internal ProcessAtlas concern; composed by the ProcessWire module entry point. */
trait ProcessAtlasOverview {
	protected function renderOverview($atlas): string {
		$cols = $atlas->collections();
		if ($this->hasAtlasError($atlas)) return '<section class="AtlasSection"><div class="AtlasSection-head"><div><p>' . $this->_('Monitor and manage your Atlas knowledge base.') . '</p></div></div>' . $this->dataError($atlas) . '</section>';
		$total = array_sum(array_column($cols, 'count'));
		$collectionCount = count($cols);
		$average = $collectionCount ? (int) round($total / $collectionCount) : 0;
		$ready = $atlas->isReady();
		$largest = $cols;
		usort($largest, fn($a, $b) => $b['count'] <=> $a['count']);
		$cards = $this->metricCard($this->_('Collections'), number_format($collectionCount), 'folder-open')
			. $this->metricCard($this->_('Entries'), number_format($total), 'file-text-o')
			. $this->metricCard($this->_('Average size'), number_format($average), 'bar-chart')
			. $this->metricCard($this->_('Embeddings'), $ready ? $this->_('Available') : $this->_('Setup needed'), $ready ? 'check-circle' : 'exclamation-triangle');
		$out = '<section class="AtlasSection"><div class="AtlasOverviewHero pw-module-head"><div><span class="AtlasEyebrow uk-text-meta">' . $this->_('Knowledge dashboard') . '</span><h2 class="uk-h3">'
			. $this->_('Your knowledge base, at a glance') . '</h2><p class="uk-text-muted">' . $this->_('Monitor coverage, inspect collections and keep semantic search ready for your applications.')
			. '</p></div><div class="AtlasActions pw-module-actions"><a class="uk-button uk-button-primary" href="./?section=search">'
			. wireIconMarkup('search') . ' ' . $this->_('Search knowledge') . '</a><a class="uk-button uk-button-default" href="./?section=reindex">'
			. wireIconMarkup('refresh') . ' ' . $this->_('Reindex site') . '</a></div></div><div class="AtlasMetrics">' . $cards . '</div>';
		if (!$cols) return $out . $this->renderOverviewEmpty($ready) . '</section>';
		$out .= '<div class="AtlasDashboardGrid"><div class="AtlasPanel AtlasOverviewCollections pw-wrap"><div class="AtlasPanel-head"><div><h3 class="uk-h4">' . $this->_('Knowledge distribution') . '</h3><p class="uk-text-meta">' . $this->_('Largest collections by entry count') . '</p></div><a href="./?section=collections">' . $this->_('View all') . ' ' . wireIconMarkup('angle-right') . '</a></div><div class="AtlasCollectionList">';
		foreach (array_slice($largest, 0, 5) as $c) {
			$name = $this->wire('sanitizer')->entities($c['collection']);
			$count = (int) $c['count'];
			$share = $total ? max(2, round($count / $total * 100, 1)) : 0;
			$countLabel = sprintf($this->_n('%1$d entry', '%1$d entries', $count), $count);
			$out .= "<a href='./?section=collections&view=" . urlencode($c['collection']) . "'><span class='AtlasDistributionMain'><span><code>{$name}</code><small>" . $countLabel . "</small></span><span class='AtlasDistributionTrack' aria-hidden='true'><i style='width:{$share}%'></i></span></span><span class='AtlasDistributionShare'>" . round($count / $total * 100) . '% ' . wireIconMarkup('angle-right') . '</span></a>';
		}
		$providerLabel = $ready ? $this->_('Available via Squad') : $this->_('Configuration required');
		$providerClass = $ready ? '' : ' is-warning';
		$out .= '</div></div><aside class="AtlasPanel AtlasOverviewStatus pw-wrap"><div class="AtlasPanel-head"><div><h3 class="uk-h4">' . $this->_('System status') . '</h3><p class="uk-text-meta">' . $this->_('Atlas services and data readiness') . '</p></div></div>'
			. '<div class="AtlasStatusList"><div><span class="AtlasStatusIndicator' . $providerClass . '">' . wireIconMarkup($ready ? 'check' : 'exclamation') . '</span><span><strong>' . $this->_('Embedding provider') . '</strong><small>' . $providerLabel . '</small></span></div>'
			. '<div><span class="AtlasStatusIndicator">' . wireIconMarkup('check') . '</span><span><strong>' . $this->_('Knowledge store') . '</strong><small>' . sprintf($this->_n('%1$d entry available', '%1$d entries available', $total), $total) . '</small></span></div>'
			. '<div><span class="AtlasStatusIndicator">' . wireIconMarkup('check') . '</span><span><strong>' . $this->_('Semantic search') . '</strong><small>' . ($ready ? $this->_('Ready for queries') : $this->_('Waiting for embeddings')) . '</small></span></div></div>'
			. '<a class="AtlasStatusLink" href="./?section=reindex">' . wireIconMarkup('refresh') . ' <span><strong>' . $this->_('Maintain the index') . '</strong><small>' . $this->_('Review or rebuild site knowledge') . '</small></span>' . wireIconMarkup('angle-right') . '</a></aside></div>'
			. '<div class="AtlasQuickActions"><a href="./?section=collections"><span>' . wireIconMarkup('folder-open') . '</span><span><strong>' . $this->_('Browse collections') . '</strong><small>' . $this->_('Inspect namespaces and entries') . '</small></span>' . wireIconMarkup('angle-right') . '</a>'
			. '<a href="./?section=collections&add=1#AtlasAddEntry"><span>' . wireIconMarkup('plus') . '</span><span><strong>' . $this->_('Add knowledge') . '</strong><small>' . $this->_('Create a manual entry') . '</small></span>' . wireIconMarkup('angle-right') . '</a>'
			. '<a href="./?section=search"><span>' . wireIconMarkup('search') . '</span><span><strong>' . $this->_('Test retrieval') . '</strong><small>' . $this->_('Run a semantic search') . '</small></span>' . wireIconMarkup('angle-right') . '</a></div></section>';
		return $out;
	}

	protected function metricCard(string $label, string $value, string $icon): string {
		return '<div><div class="AtlasMetric uk-card uk-card-default uk-card-small uk-card-body"><span class="AtlasMetric-icon">' . wireIconMarkup($icon) . '</span><div><span class="uk-text-meta">' . $label . '</span><strong>' . $value . '</strong></div></div></div>';
	}

	protected function renderOverviewEmpty(bool $ready): string {
		$configUrl = $this->wire('config')->urls->admin . 'module/edit?name=Squad';
		$primaryUrl = $ready ? './?section=reindex' : $this->wire('sanitizer')->entities($configUrl);
		$primaryIcon = $ready ? 'refresh' : 'plug';
		$primaryLabel = $ready ? $this->_('Build knowledge base') : $this->_('Configure Squad');
		$intro = $ready
			? $this->_('Embeddings are ready. Build a collection from your site pages or add knowledge manually.')
			: $this->_('Connect an embedding provider first, then Atlas can turn your site content into searchable knowledge.');
		$providerClass = $ready ? ' is-complete' : ' is-current';
		$providerMarker = $ready ? wireIconMarkup('check') : '<span>1</span>';

		return '<div class="AtlasOverviewEmpty uk-card uk-card-default"><div class="AtlasOverviewEmpty-icon">' . wireIconMarkup('database') . '</div>'
			. '<div class="AtlasOverviewEmpty-main"><span class="AtlasEyebrow uk-text-meta">' . $this->_('Getting started') . '</span><h3 class="uk-h3">' . $this->_('No knowledge indexed yet') . '</h3><p>' . $intro . '</p>'
			. '<div class="AtlasOnboardingSteps"><div class="AtlasOnboardingStep' . $providerClass . '"><span class="AtlasOnboardingStep-marker">' . $providerMarker . '</span><span><strong>' . $this->_('Connect embeddings') . '</strong><small>' . ($ready ? $this->_('Embedding provider is available') : $this->_('Add a provider key and embedding model in Squad')) . '</small></span></div>'
			. '<div class="AtlasOnboardingStep"><span class="AtlasOnboardingStep-marker"><span>2</span></span><span><strong>' . $this->_('Build a collection') . '</strong><small>' . $this->_('Index public pages or add entries manually') . '</small></span></div>'
			. '<div class="AtlasOnboardingStep"><span class="AtlasOnboardingStep-marker"><span>3</span></span><span><strong>' . $this->_('Retrieve knowledge') . '</strong><small>' . $this->_('Search by meaning or use Atlas from your application') . '</small></span></div></div>'
			. '<div class="AtlasActions"><a class="uk-button uk-button-primary" href="' . $primaryUrl . '">' . wireIconMarkup($primaryIcon) . ' ' . $primaryLabel . '</a>'
			. ($ready ? '<a class="uk-button uk-button-default" href="./?section=collections&add=1#AtlasAddEntry">' . wireIconMarkup('plus') . ' ' . $this->_('Add entry') . '</a>' : '') . '</div></div></div>';
	}

}
