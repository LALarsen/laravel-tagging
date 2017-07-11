<?php

namespace LALarsen\Common\Models;

class TagTranslation extends \LALarsen\Common\Model {

	/**
	 * The table associated with the model.
	 *
	 * @var string
	 */
	protected $table = 'tagging_tags_translations';

	/**
	 * Get the log subject to use.
	 */
	public function logSubject() {
		return $this->belongsTo('LALarsen\Common\Models\Tag', 'tag_id')->first();
	}

	/**
	 * Get a descriptive string for event logging
	 *
	 * @return array
	 */
	public function getDescriptionForEvent(string $eventName): string {
		switch ($eventName) {
			case 'updated':
				return 'Oppdaterte tag/nøkkelord oversettelse (' . $this->locale . ')';
			case 'created':
				return 'Lagde ny tag/nøkkelord oversettelse (' . $this->locale . ')';
			case 'deleted':
				return 'Slettet tag/nøkkelord oversettelse (' . $this->locale . ')';
		}
	}

}
