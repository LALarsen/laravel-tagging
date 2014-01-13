<?php namespace Rtconner\Tagging;

use Illuminate\Support\Str;

trait Taggable {

	/**
	 * Return collection of tags related to the tagged model
	 * 
	 * @return Illuminate\Database\Eloquent\Collection
	 */
	public function tagged() {
		return $this->morphMany('Rtconner\Tagging\Tagged', 'taggable');
	}
	
	/**
	 * Perform the action of tagging the model with the given string
	 * 
	 * @param $tagName string
	 */
	public function tag($tagName) {
		$tagName = trim($tagName);
		if(!strlen($tagName)) { return; }

		$tagSlug = Str::slug($tagName);
		
		$previousCount = $this->tagged()->where('tag_slug', '=', $tagSlug)->take(1)->count();
		if($previousCount >= 1) { return; }
		
		$tagged = new Tagged(array(
			'tag_name'=>Str::title($tagName),
			'tag_slug'=>$tagSlug,
		));
		
		$this->tagged()->save($tagged);

		Tag::incrementCount($tagName, $tagSlug, 1);
	}
	
	/**
	 * Return array of the tag names related to the current model
	 * 
	 * @return array
	 */
	public function tagNames() {
		$tagNames = array();
		$taggedIterator = $this->tagged()->select(array('tag_name'));

		foreach($taggedIterator->get() as $tagged) {
			$tagNames[] = $tagged->tag_name;
		}
		
		return $tagNames;
	}
	
	/**
	 * Remove the tag from this model
	 * 
	 * @param $tagName string
	 */
	public function untag($tagName) {
		$tagName = trim($tagName);
		$tagSlug = Str::slug($tagName);
		
		$count = $this->tagged()->where('tag_slug', '=', $tagSlug)->delete();
		
		Tag::decrementCount($tagName, $tagSlug, $count);
	}
	
	/**
	 * Filter model to subset with the given tag
	 * 
	 * @param unknown $tagName
	 */
	public static function withTag($tagName) {
		$tagSlug = Str::slug($tagName);
		
		return static::whereHas('tagged', function($q) use($tagSlug) {
			$q->where('tag_slug', '=', $tagSlug);
		});
	}
	
}