<?php

namespace Conner\Tagging;

use Conner\Tagging\Contracts\TaggingUtility;
use Conner\Tagging\Events\TagAdded;
use Conner\Tagging\Events\TagRemoved;
use Conner\Tagging\Model\Tag;
use Conner\Tagging\Model\Tagged;
use Conner\Tagging\Model\TagGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Copyright (C) 2014 Robert Conner
 */
trait Taggable
{
	/** @var \Conner\Tagging\Contracts\TaggingUtility **/
	static $taggingUtility;

    /**
     * Temp storage for auto tag
     *
     * @var mixed
     * @access protected
     */
    protected $autoTagTmp;

    /**
     * Track if auto tag has been manually set
     *
     * @var boolean
     * @access protected
     */
    protected $autoTagSet = false;
	
	/**
	 * Boot the soft taggable trait for a model.
	 *
	 * @return void
	 */
	public static function bootTaggable()
	{
		if(static::untagOnDelete()) {
			static::deleting(function($model) {
				$model->untag();
			});
		}

        static::saved(function ($model) {
            $model->autoTagPostSave();
        });

		static::$taggingUtility = app(TaggingUtility::class);
	}
	
	/**
	 * Return collection of tagged rows related to the tagged model
	 *
	 * @param string	$groupName	Name of tag group
	 * @return Illuminate\Database\Eloquent\Collection
	 */
	public function tagged($groupName = null)
	{
		$query = $this->morphMany(config('tagging.tagged_model', 'Conner\Tagging\Model\Tagged'), 'taggable');
		if ($groupName) {
			$groupSlug = static::$taggingUtility->slug($groupName);
			$query->whereHas('tag.group', function (Builder $builder) use ($groupSlug) {
				$builder->where('slug', $groupSlug);
			});
		}
		return $query->with('tag.translations')->orderBy('sorting');
	}

	/**
	 * Return collection of tags related to the tagged model
	 * TODO : I'm sure there is a faster way to build this, but
	 * If anyone knows how to do that, me love you long time.
	 *
	 * @return Illuminate\Database\Eloquent\Collection
	 */
	public function getTagsAttribute()
	{
		return $this->tagged->map(function($item){
			return $item->tag;
		});
	}
	
	/**
	 * Set the tag names via attribute, example $model->tag_names = 'foo, bar';
	 *
	 * @param string $value
	 */
	public function getTagNamesAttribute($value)
	{
		return implode(', ', $this->tagNames());
	}
	
	/**
	 * Perform the action of tagging the model with the given string
	 *
	 * @param $tagName string or array
	 * @param string	$groupName	Name of tag group
	 * @param boolean	$useSorting	Populate the sorting column?
	 */
	public function tag($tagNames, $groupName = null, $useSorting = false)
	{
		$tagNames = static::$taggingUtility->makeTagArray($tagNames);
		
		if ($useSorting) {
			$sort = count($this->tagIds());
		} else {
			$sort = 0;
		}
		foreach($tagNames as $tagName) {
			$this->addTag($tagName, $groupName, ($useSorting ? $sort : null));
			$sort++;
		}
	}
	
	/**
	 * Perform the action of tagging the model with the given string
	 *
	 * @param $tagName string or array
	 * @param boolean	$useSorting	Populate the sorting column?
	 */
	public function tagWithId($ids, $useSorting = false)
	{
		if (!is_array($ids)) {
			$ids = [$ids];
		}
		$model = static::$taggingUtility->tagModelString();
		
		if ($useSorting) {
			$sort = count($this->tagIds());
		} else {
			$sort = 0;
		}
		foreach ($ids as $id) {
			$tag = $model::where('id', $id)->first();

			if (!$tag) {
				// Ignore
				continue;
			}
			if (!$this->hasTag($tag)) {
				$tagged = new Tagged(['tag_id' => $tag->id]);
				if ($useSorting) {
					$tagged->sorting = $sort;
				}
				$this->tagged()->save($tagged);

				unset($this->relations['tagged']);
				event(new TagAdded($this));
				$sort++;
			}
		}
	}
	
	/**
	 * Return array of the tag ids related to the current model
	 *
	 * @param string	$groupName	Name of tag group
	 * @return array
	 */
	public function tagIds($groupName = null)
	{
		return $this->tagged($groupName)->get()->map(function($item){
			return $item->tag->id;
		})->toArray();
	}
	
	/**
	 * Return array of the tag names related to the current model
	 *
	 * @param string	$groupName	Name of tag group
	 * @return array
	 */
	public function tagNames($groupName = null)
	{
		return $this->tagged($groupName)->get()->map(function($item){
			return $item->tag->name;
		})->toArray();
	}

	/**
	 * Return array of the tag slugs related to the current model
	 *
	 * @param string	$groupName	Name of tag group
	 * @return array
	 */
	public function tagSlugs($groupName = null)
	{
		return $this->tagged($groupName)->get()->map(function($item){
			return $item->tag->slug;
		})->toArray();
	}

	/**
	 * Checks whether the model already has this tag
	 *
	 * @param Tag	$tag	
	 * @return boolean
	 */
	public function hasTag(Tag $tag)
	{
		return (bool) $this->tagged()->whereHas('tag', function ($query) use ($tag) {
            $query->where('id', $tag->id);
        })->count();
	}
	
	/**
	 * Remove the tag from this model
	 *
	 * @param $tagName string or array (or null to remove all tags)
	 * @param string	$groupName	Name of tag group
	 */
	public function untag($tagNames = null, $groupName = null)
	{
		if(is_null($tagNames)) {
			$tagIds = $this->tagIds($groupName);
		} else {
			$tagIds = static::$taggingUtility->makeTagIdArray($tagNames);
		}
		
		foreach($tagIds as $tagId) {
			$this->removeTag($tagId);
		}
		
		if(static::shouldDeleteUnused()) {
			static::$taggingUtility->deleteUnusedTags();
		}
	}
	
	/**
	 * Replace the tags from this model
	 *
	 * @param $tagName string or array
	 */
	public function retag($tagNames)
	{
		$tagNames = static::$taggingUtility->makeTagArray($tagNames);
		$currentTagNames = $this->tagNames();
		
		$deletions = array_diff($currentTagNames, $tagNames);
		$additions = array_diff($tagNames, $currentTagNames);
		
		$this->untag($deletions);

		foreach($additions as $tagName) {
			$this->addTag($tagName);
		}
	}
	
	/**
	 * Filter model to subset with the given tags
	 *
	 * @param $tagNames array|string
	 */
	public function scopeWithAllTags($query, $tagNames)
	{
		if(!is_array($tagNames)) {
			$tagNames = func_get_args();
			array_shift($tagNames);
		}
		
		$tagNames = static::$taggingUtility->makeTagArray($tagNames);
		
		$normalizer = config('tagging.normalizer');
		$normalizer = $normalizer ?: [static::$taggingUtility, 'slug'];
		$className = $query->getModel()->getMorphClass();

		foreach($tagNames as $tagSlug) {
			$tags = Tagged::where('tag_slug', call_user_func($normalizer, $tagSlug))
				->where('taggable_type', $className)
				->get()->pluck('taggable_id');
		
			$primaryKey = $this->getKeyName();
			$query->whereIn($this->getTable().'.'.$primaryKey, $tags);
		}
		
		return $query;
	}
		
	/**
	 * Filter model to subset with the given tags
	 *
	 * @param $tagNames array|string
	 */
	public function scopeWithAnyTag($query, $tagNames)
	{
		if(!is_array($tagNames)) {
			$tagNames = func_get_args();
			array_shift($tagNames);
		}
		
		$tagNames = static::$taggingUtility->makeTagArray($tagNames);
		
		$normalizer = config('tagging.normalizer');
		$normalizer = $normalizer ?: [static::$taggingUtility, 'slug'];
		
		$tagNames = array_map($normalizer, $tagNames);
		$className = $query->getModel()->getMorphClass();
		
		$tags = Tagged::whereIn('tag_slug', $tagNames)
			->where('taggable_type', $className)
			->get()->pluck('taggable_id');
		
		$primaryKey = $this->getKeyName();
		return $query->whereIn($this->getTable().'.'.$primaryKey, $tags);
	}
    
    /**
	 * Filter model to subset without the given tags
	 *
	 * @param $tagNames array|string
	 */
	public function scopeWithoutTags($query, $tagNames)
	{
		if(!is_array($tagNames)) {
			$tagNames = func_get_args();
			array_shift($tagNames);
		}
		
		$tagNames = static::$taggingUtility->makeTagArray($tagNames);
		
		$normalizer = config('tagging.normalizer');
		$normalizer = $normalizer ?: [static::$taggingUtility, 'slug'];
		
		$tagNames = array_map($normalizer, $tagNames);
		$className = $query->getModel()->getMorphClass();
		
		$tags = Tagged::whereIn('tag_slug', $tagNames)
			->where('taggable_type', $className)
			->get()->pluck('taggable_id');
		
		$primaryKey = $this->getKeyName();
		return $query->whereNotIn($this->getTable().'.'.$primaryKey, $tags);
	}
	
	/**
	 * Adds a single tag
	 *
	 * @param string	$groupName	Name of tag group
	 * @param $tagName string
	 */
	private function addTag($tagName, $groupName = null)
	{
		$tagName = trim($tagName);
		
		$normalizer = config('tagging.normalizer');
		$normalizer = $normalizer ?: [static::$taggingUtility, 'slug'];

		$tagSlug = call_user_func($normalizer, $tagName);
		
		$model = static::$taggingUtility->tagModelString();
		$tag = $model::whereTranslation('slug', $tagSlug)->first();
		
		if (!$tag) {
			// New tag, add to database
			$tag = new $model;
			$tag->name = $tagName;
			$tag->slug = $tagSlug;
			if ($groupName) {
				// Find group id
				$tag->tag_group_id = TagGroup::where('slug', static::$taggingUtility->slug($groupName))->first()->id;
			}
			$tag->suggest = false;
			$tag->save();
		}
		
		$tagged = new Tagged(['tag_id' => $tag->id]);
		
		$this->tagged()->save($tagged);

		unset($this->relations['tagged']);
		event(new TagAdded($this));
	}
	
	/**
	 * Removes a single tag
	 *
	 * @param $tagName string
	 */
	private function removeTag($tagId)
	{
		$this->tagged()->where('tag_id', '=', $tagId)->delete();
		
		unset($this->relations['tagged']);
		event(new TagRemoved($this));
	}

	/**
	 * Return an array of all of the tags that are in use by this model
	 *
	 * @return Collection
	 */
	/*public static function existingTags()
	{
		return Tagged::distinct()
			->join('tagging_tags', 'tag_id', '=', 'tagging_tags.id')
			->where('taggable_type', '=', (new static)->getMorphClass())
			->orderBy('tag_slug', 'ASC')
			->get(array('tag_slug as slug', 'tag_name as name', 'tagging_tags.count as count'));
	}*/

	/**
     	* Return an array of all of the tags that are in use by this model
      	* @param $groups Array with groups names
     	* @return Collection
 	*/
 	/*public static function existingTagsInGroups(Array $groups)
 	{
 		return Tagged::distinct()
 			->join('tagging_tags', 'tag_slug', '=', 'tagging_tags.slug')
 			->join('tagging_tag_groups', 'tag_group_id', '=', 'tagging_tag_groups.id')
 			->where('taggable_type', '=', (new static)->getMorphClass())
 			->whereIn('tagging_tag_groups.name',$groups)
 			->orderBy('tag_slug', 'ASC')
			->get(array('tag_slug as slug', 'tag_name as name', 'tagging_tags.count as count'));
 	}*/
 	
	
	/**
	 * Should untag on delete
	 */
	public static function untagOnDelete()
	{
		return isset(static::$untagOnDelete)
			? static::$untagOnDelete
			: config('tagging.untag_on_delete');
	}
	
	/**
	 * Delete tags that are not used anymore
	 */
	public static function shouldDeleteUnused()
	{
		return config('tagging.delete_unused_tags');
	}

    /**
     * Set tag names to be set on save
     *
     * @param mixed $value Data for retag
     *
     * @return void
     *
     * @access public
     */
    public function setTagNamesAttribute($value)
    {
        $this->autoTagTmp = $value;
        $this->autoTagSet = true;
    }

    /**
     * AutoTag post-save hook
     *
     * Tags model based on data stored in tmp property, or untags if manually
     * set to falsey value
     *
     * @return void
     *
     * @access public
     */
    public function autoTagPostSave()
    {
        if ($this->autoTagSet) {
            if ($this->autoTagTmp) {
                $this->retag($this->autoTagTmp);
            } else {
                $this->untag();
            }
        }
    }
}
