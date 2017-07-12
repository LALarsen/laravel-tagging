<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class UpdateTagsTable extends Migration {

	public function up()
	{

		Schema::table('tagging_tags', function ($table) {
			$table->dropColumn('count');
		});

	}


	public function down()
	{
		Schema::create('tagging_tags', function(Blueprint $table) {
			$table->integer('count')->unsigned()->default(0); // count of how many times this tag was used
		});
	}
}
