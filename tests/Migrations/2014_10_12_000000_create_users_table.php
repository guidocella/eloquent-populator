<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email');

            $table->smallInteger('smallint');
            $table->integer('integer');
            $table->bigInteger('bigint');
            $table->decimal('decimal', 6, 4);
            $table->float('float');
            $table->string('string');
            $table->text('text');
            $table->date('date');
            $table->dateTime('datetime');
            $table->time('time');
            $table->timestamp('timestamp');
            $table->boolean('boolean');

            $table->integer('company_id')->unsigned()->nullable();
            $table->foreign('company_id')->references('id')->on('companies');
        });
    }
}
