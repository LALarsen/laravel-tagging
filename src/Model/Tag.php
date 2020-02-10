<?php namespace Conner\Tagging\Model;

use Conner\Tagging\Contracts\TaggingUtility;
use Illuminate\Database\Eloquent\Model as Eloquent;

/**
 * Copyright (C) 2014 Robert Conner
 */
class Tag extends Eloquent
{
    use ConnectionTrait;
	use \Dimsav\Translatable\Translatable;
	
    public $translatedAttributes = ['name', 'slug'];
	protected $translationForeignKey = 'tag_id';
	
	protected $table = 'tagging_tags';
    public $timestamps = false;
    protected $softDelete = false;
    public $fillable = ['name'];
    protected $taggingUtility;

    /**
     * @param array $attributes
     */
    public function __construct(array $attributes = array())
    {
        parent::__construct($attributes);
        $this->reSetConnection();

        $this->taggingUtility = app(TaggingUtility::class);
    }

    /**
     * Get instances of tagged linked to the tag
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function tagged()
    {
        $model = $this->taggingUtility->taggedModelString();
        return $this->hasMany($model);
    }
	
	/**
     * (non-PHPdoc)
     * @see \Illuminate\Database\Eloquent\Model::save()
     */
    public function save(array $options = array())
    {
        $validator = app('validator')->make(
            array('name' => $this->name),
            array('name' => 'required|min:1')
        );

        if ($validator->passes()) {
            $normalizer = config('tagging.normalizer');
            $normalizer = $normalizer ?: [$this->taggingUtility, 'slug'];

            $this->slug = call_user_func($normalizer, $this->name);
            return parent::save($options);
        } else {
            throw new \Exception('Tag Name is required');
        }
    }

    /**
     * Tag group setter
     */
    public function setGroup($group_name)
    {
        $tagGroup = TagGroup::where('slug', $this->taggingUtility->slug($group_name))->first();

        if ($tagGroup) {
            $this->group()->associate($tagGroup);
            $this->save();

            return $this;
        } else {
            throw new \Exception('No Tag Group found');
        }
    }

    /**
     * Tag group remove
     */
    public function removeGroup($group_name)
    {
        $tagGroup = TagGroup::where('slug', $this->taggingUtility->slug($group_name))->first();

        if ($tagGroup) {
            $this->group()->dissociate($tagGroup);
            $this->save();

            return $this;
        } else {
            throw new \Exception('No Tag Group found');
        }
    }

    /**
     * Tag group helper function
     */
    public function isInGroup($group_name)
    {
        if ($this->group && ($this->group->slug == $this->taggingUtility->slug($group_name))) {
            return true;
        }
        return false;
    }

    /**
     * Tag group relationship
     */
    public function group()
    {
        return $this->belongsTo('\Conner\Tagging\Model\TagGroup', 'tag_group_id');
    }

    /**
     * Get suggested tags
     */
    public function scopeSuggested($query)
    {
        return $query->where('suggest', true);
    }

    /**
     * Get suggested tags
     */
    public function scopeInGroup($query, $group_name)
    {
        $group_slug = $this->taggingUtility->slug($group_name);

        return $query->whereHas('group', function ($query) use ($group_slug) {
            $query->where('slug', $group_slug);
        });
    }

	/**
	 * Get suggested tags
	 */
	public function scopeNotTaggedTo($query, $model)
	{
		return $query->whereDoesntHave('tagged', function ($query) use ($model) {
			$className = $model->getMorphClass();

			return $query->where('taggable_type', $className)
				->where('taggable_id', $model->id);
		});
	}

    /**
     * Set the name of the tag : $tag->name = 'myname';
     *
     * @param string $value
     */
    public function setNameAttribute($value)
    {
        $displayer = config('tagging.displayer');
        $displayer = empty($displayer) ? '\Illuminate\Support\Str::title' : $displayer;

        $this->attributes['name'] = call_user_func($displayer, $value);
    }

    /**
     * Look at the tags table and delete any tags that are no longer in use by any tagable database rows.
     * Does not delete tags where 'suggest' value is true
     *
     * @return int
     */
    public static function deleteUnused()
    {
        return (new static)
			->doesntHave('tagged')
			->where('suggest', false)
            ->delete();
    }
}
